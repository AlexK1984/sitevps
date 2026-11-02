<?php

namespace App\Http\Controllers;

use App\Exceptions\InternalTestPaymentException;
use App\Exceptions\PanelApiException;
use App\Jobs\ProvisionSubscription;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\Panel;
use App\Models\PaymentEvent;
use App\Models\PaymentSettings;
use App\Models\SiteContent;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use App\Services\InternalTestPaymentService;
use App\Services\PaymentEventLogger;
use App\Services\ThreeXUiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(Request $request): View
    {
        $settings = PaymentSettings::current();
        $yookassaCredentials = $settings->yookassaCredentials();
        $eventProvider = $request->query('event_provider');
        if (! in_array($eventProvider, ['yookassa', 'telegram_stars', 'internal_test'], true)) {
            $eventProvider = null;
        }

        $paymentEvents = PaymentEvent::query()
            ->with(['order.user', 'actor'])
            ->when($eventProvider, fn ($query) => $query->where('provider', $eventProvider))
            ->latest()
            ->paginate(30, ['*'], 'payment_events')
            ->withQueryString();

        return view('admin.index', [
            'users' => User::query()
                ->with('assignedPanel')
                ->when($request->filled('user_search'), function ($query) use ($request): void {
                    $search = trim((string) $request->query('user_search'));
                    $query->where(function ($query) use ($search): void {
                        $query->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    });
                })
                ->orderBy('id')
                ->paginate(30, ['*'], 'users')
                ->withQueryString(),
            'siteContent' => SiteContent::current(),
            'tariffs' => Tariff::query()->orderBy('id')->get(),
            'panels' => Panel::query()->orderBy('name')->get(),
            'orders' => Order::query()->with(['user', 'tariff', 'panel'])->latest()->limit(100)->get(),
            'adminAuditLogs' => AdminAuditLog::query()->with(['actor', 'subjectUser'])->latest()->limit(50)->get(),
            'paymentEvents' => $paymentEvents,
            'eventProvider' => $eventProvider,
            'testPaymentUsers' => User::query()->whereNotNull('assigned_panel_id')->orderBy('email')->get(['id', 'email']),
            'testPaymentTariffs' => Tariff::query()->where('is_active', true)->orderBy('id')->get(['id', 'name']),
            'testPaymentIdempotencyKey' => (string) Str::uuid(),
            'paymentSettings' => (object) [
                'yookassa_enabled' => $settings->yookassa_enabled,
                'telegram_stars_enabled' => $settings->telegram_stars_enabled,
                'internal_test_payments_enabled' => $settings->internal_test_payments_enabled,
                'crypto_asset' => $settings->crypto_asset,
                'crypto_network' => $settings->crypto_network,
                'crypto_confirmations' => $settings->crypto_confirmations,
                'crypto_payment_ttl_minutes' => $settings->crypto_payment_ttl_minutes,
                'crypto_admin_notes' => $settings->crypto_admin_notes,
                'yookassa_configured' => filled($yookassaCredentials['shop_id']) && filled($yookassaCredentials['secret_key']),
                'yookassa_configured_in_admin' => $settings->yookassaConfiguredInAdmin(),
                'telegram_configured' => filled($settings->telegramBotToken()),
                'telegram_configured_in_admin' => $settings->telegramConfiguredInAdmin(),
            ],
        ]);
    }

    public function editUser(User $user): View
    {
        return view('admin.users.edit', [
            'user' => $user->load(['assignedPanel', 'subscription.tariff', 'subscription.panel']),
            'siteContent' => SiteContent::current(),
            'userOrders' => $user->orders()->with('tariff')->latest()->limit(20)->get(),
            'panels' => Panel::query()->orderBy('name')->get(),
            'tariffs' => Tariff::query()->orderBy('name')->get(),
        ]);
    }

    public function updateUser(Request $request, User $user, ThreeXUiClient $client): RedirectResponse
    {
        $request->merge(['email' => is_string($request->input('email')) ? Str::lower(trim($request->input('email'))) : $request->input('email')]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'is_admin' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'assigned_panel_id' => ['nullable', 'integer', 'exists:panels,id'],
            'subscription' => ['sometimes', 'array'],
            'subscription.tariff_id' => ['nullable', 'integer', 'exists:tariffs,id'],
            'subscription.expires_at' => ['required_with:subscription', 'date'],
            'subscription.traffic_limit_gb' => ['required_with:subscription', 'integer', 'min:1', 'max:100000'],
            'subscription.connection_limit' => ['required_with:subscription', 'integer', 'min:1', 'max:100'],
        ]);

        $syncSubscriptionId = null;
        $syncPanelId = null;
        $auditChanges = [];

        DB::transaction(function () use ($request, $user, $validated, &$syncSubscriptionId, &$syncPanelId, &$auditChanges): void {
            $admins = User::query()->where('is_admin', true)->orderBy('id')->lockForUpdate()->get(['id', 'is_active']);
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $oldPanelId = $lockedUser->assigned_panel_id;
            $newPanelId = isset($validated['assigned_panel_id']) ? (int) $validated['assigned_panel_id'] : null;

            if ($oldPanelId !== $newPanelId
                && $lockedUser->subscription()
                    ->where('status', 'active')
                    ->where('expires_at', '>', now())
                    ->exists()) {
                throw ValidationException::withMessages([
                    'assigned_panel_id' => 'Нельзя менять панель у активной подписки: конфигурация уже выдана на старом сервере.',
                ]);
            }

            if ($lockedUser->subscription()->where('status', 'provisioning')->exists()
                && ($oldPanelId !== $newPanelId || array_key_exists('subscription', $validated))) {
                throw ValidationException::withMessages([
                    'subscription' => 'Дождитесь завершения текущей выдачи, затем редактируйте подписку.',
                ]);
            }

            $newAdmin = (bool) $validated['is_admin'];
            $newActive = (bool) $validated['is_active'];
            if ($newAdmin && ! $newActive) {
                throw ValidationException::withMessages([
                    'is_active' => 'Отключённая учётная запись не может быть администратором.',
                ]);
            }

            if ($lockedUser->is_admin && $lockedUser->is_active && (! $newAdmin || ! $newActive)) {
                $otherActiveAdmins = $admins->where('id', '!=', $lockedUser->id)
                    ->where('is_active', true)
                    ->count();

                if ($otherActiveAdmins === 0) {
                    throw ValidationException::withMessages([
                        'is_admin' => 'Нельзя снять права или отключить последнего активного администратора.',
                    ]);
                }
            }

            $lockedUser->fill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'is_admin' => $newAdmin,
                'is_active' => $newActive,
                'assigned_panel_id' => $newPanelId,
            ]);
            $userChanges = array_keys($lockedUser->getDirty());
            if ($userChanges !== []) {
                $lockedUser->save();
                $auditChanges[] = 'Профиль: '.implode(', ', $userChanges).'.';
            }

            $subscription = $lockedUser->subscription()->lockForUpdate()->first();
            if (isset($validated['subscription']) && $subscription) {
                $values = $validated['subscription'];
                $subscription->fill([
                    'tariff_id' => $values['tariff_id'] ?? null,
                    'expires_at' => filled($values['expires_at'] ?? null) ? $values['expires_at'] : null,
                    'traffic_limit_bytes' => (int) $values['traffic_limit_gb'] * 1_000_000_000,
                    'connection_limit' => (int) $values['connection_limit'],
                    'panel_id' => $newPanelId,
                ]);
                $subscriptionChanges = array_keys($subscription->getDirty());
                if ($subscriptionChanges !== []) {
                    $subscription->save();
                    $auditChanges[] = 'Подписка: '.implode(', ', $subscriptionChanges).'.';
                    $syncSubscriptionId = $subscription->id;
                    $syncPanelId = $newPanelId;
                }
            }

            if ($auditChanges !== []) {
                AdminAuditLog::query()->create([
                    'actor_id' => $request->user()->id,
                    'subject_user_id' => $lockedUser->id,
                    'action' => 'user_updated',
                    'summary' => implode(' ', $auditChanges),
                ]);
            }
        });

        if ($syncSubscriptionId && $syncPanelId) {
            $subscription = Subscription::query()->findOrFail($syncSubscriptionId);
            if (in_array($subscription->status, ['active', 'provisioning_failed'], true)) {
                try {
                    $client->provision(Panel::query()->findOrFail($syncPanelId), $subscription);
                    $subscription->forceFill(['last_error' => null])->save();
                } catch (PanelApiException $exception) {
                    $subscription->forceFill(['last_error' => $exception->getMessage()])->save();
                    AdminAuditLog::query()->create([
                        'actor_id' => $request->user()->id,
                        'subject_user_id' => $user->id,
                        'action' => 'subscription_sync_failed',
                        'summary' => 'Изменения сохранены в сайте, но панель 3x-ui не подтвердила синхронизацию: '.$exception->getMessage(),
                    ]);

                    return back()->with('status', 'Изменения сохранены, но синхронизация с панелью не удалась. Проверьте ошибку в карточке подписки.');
                }
            }
        }

        return redirect()->route('admin.users.edit', $user)->with('status', 'Данные пользователя обновлены.');
    }

    public function updateHomeContent(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'site_name' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'meta_title' => ['required', 'string', 'max:70', 'not_regex:/[<>]/'],
            'meta_description' => ['required', 'string', 'max:180', 'not_regex:/[<>]/'],
            'canonical_url' => [
                'nullable', 'url:https', 'not_regex:/[@?#]/', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (filled($value) && trim((string) parse_url($value, PHP_URL_PATH), '/') !== '') {
                        $fail('Канонический URL должен указывать на корень сайта без пути.');
                    }
                },
            ],
            'og_image_url' => ['nullable', 'url:https', 'not_regex:/[@?#]/', 'max:500'],
            'hero_badge' => ['required', 'string', 'max:100', 'not_regex:/[<>]/'],
            'hero_title' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'hero_body' => ['required', 'string', 'max:1000', 'not_regex:/[<>]/'],
            'hero_cta_label' => ['required', 'string', 'max:60', 'not_regex:/[<>]/'],
            'hero_cta_target' => ['required', 'in:#plans,account'],
            'hero_fact_one' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'hero_fact_two' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'plans_eyebrow' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'plans_title' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'plans_description' => ['required', 'string', 'max:500', 'not_regex:/[<>]/'],
            'steps_eyebrow' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'steps_title' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'step_one_title' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'step_one_body' => ['required', 'string', 'max:500', 'not_regex:/[<>]/'],
            'step_two_title' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'step_two_body' => ['required', 'string', 'max:500', 'not_regex:/[<>]/'],
            'step_three_title' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'step_three_body' => ['required', 'string', 'max:500', 'not_regex:/[<>]/'],
            'footer_text' => ['required', 'string', 'max:180', 'not_regex:/[<>]/'],
        ]);

        if (filled($validated['canonical_url'] ?? null)) {
            $validated['canonical_url'] = rtrim($validated['canonical_url'], '/');
        }

        $content = SiteContent::query()->find(1);
        $content ??= new SiteContent(SiteContent::defaults());
        $content->id = 1;
        $content->fill($validated);
        $changed = array_keys($content->getDirty());
        $content->save();

        if ($changed !== []) {
            AdminAuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'action' => 'homepage_content_updated',
                'summary' => 'Изменены поля главной страницы: '.implode(', ', $changed).'.',
            ]);
        }

        return back()->with('status', 'Тексты главной страницы и SEO-настройки сохранены.');
    }

    public function storeTariff(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash:ascii', 'max:120', 'unique:tariffs,slug'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_rubles' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'price_stars' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'traffic_gb' => ['required', 'integer', 'min:1', 'max:100000'],
            'connection_limit' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $validated['price_kopecks'] = $this->priceToKopecks($validated['price_rubles']);
        unset($validated['price_rubles']);
        Tariff::create($validated + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Тариф создан.');
    }

    public function updateTariff(Request $request, Tariff $tariff): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash:ascii', 'max:120', 'unique:tariffs,slug,'.$tariff->id],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_rubles' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'price_stars' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'traffic_gb' => ['required', 'integer', 'min:1', 'max:100000'],
            'connection_limit' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $validated['price_kopecks'] = $this->priceToKopecks($validated['price_rubles']);
        unset($validated['price_rubles']);
        $tariff->update($validated + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Тариф обновлён.');
    }

    public function updatePaymentSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'yookassa_enabled' => ['required', 'boolean'],
            'telegram_stars_enabled' => ['required', 'boolean'],
            'internal_test_payments_enabled' => ['sometimes', 'boolean'],
            'crypto_asset' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
            'crypto_network' => ['nullable', 'string', 'max:80'],
            'crypto_confirmations' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'crypto_payment_ttl_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'crypto_admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $settings = PaymentSettings::current();
        $wasTestModeEnabled = $settings->internal_test_payments_enabled;
        $validated['internal_test_payments_enabled'] = $request->boolean('internal_test_payments_enabled');
        $settings->update($validated);

        if ($wasTestModeEnabled !== $settings->internal_test_payments_enabled) {
            app(PaymentEventLogger::class)->record(
                'internal_test',
                $settings->internal_test_payments_enabled ? 'test_mode_enabled' : 'test_mode_disabled',
                $settings->internal_test_payments_enabled
                    ? 'Администратор включил внутренний тестовый режим. Симуляция выдаёт реальный доступ на назначенной панели.'
                    : 'Администратор выключил внутренний тестовый режим.',
                actorId: $request->user()->id,
            );
        }

        return back()->with('status', 'Настройки способов оплаты сохранены.');
    }

    public function createTestPayment(
        Request $request,
        InternalTestPaymentService $testPayments,
    ): RedirectResponse {
        abort_unless(PaymentSettings::current()->internal_test_payments_enabled, 404);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'tariff_id' => ['required', 'integer', 'exists:tariffs,id'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $user = User::query()->findOrFail($validated['user_id']);
        $tariff = Tariff::query()->findOrFail($validated['tariff_id']);

        try {
            [$order, $created] = $testPayments->simulate(
                $user,
                $tariff,
                $request->user(),
                $validated['idempotency_key'],
            );
        } catch (InternalTestPaymentException $exception) {
            return back()->withErrors(['test_payment' => $exception->getMessage()]);
        }

        if ($created) {
            ProvisionSubscription::dispatch($order->id);
        }

        return back()->with('status', $created
            ? 'Тестовый заказ создан и передан в обычную очередь выдачи на реальную панель.'
            : 'Этот тестовый запрос уже был обработан; повторная выдача не запускалась.');
    }

    public function updatePaymentCredentials(Request $request): RedirectResponse
    {
        $secretFields = ['yookassa_shop_id', 'yookassa_secret_key', 'telegram_bot_token'];
        $validator = Validator::make($request->all(), [
            'yookassa_shop_id' => ['nullable', 'string', 'max:255'],
            'yookassa_secret_key' => ['nullable', 'string', 'max:500'],
            'telegram_bot_token' => ['nullable', 'string', 'max:500'],
            'clear_yookassa' => ['sometimes', 'boolean'],
            'clear_telegram' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput($request->except($secretFields));
        }

        $validated = $validator->validated();
        $settings = PaymentSettings::current();
        $updates = [];

        if ($request->boolean('clear_yookassa')) {
            $updates['yookassa_shop_id'] = null;
            $updates['yookassa_secret_key'] = null;
        } else {
            $newShopId = trim((string) ($validated['yookassa_shop_id'] ?? ''));
            $newSecretKey = trim((string) ($validated['yookassa_secret_key'] ?? ''));
            $hasStoredPair = filled($settings->yookassa_shop_id) && filled($settings->yookassa_secret_key);

            if (($newShopId !== '' || $newSecretKey !== '')
                && ! $hasStoredPair
                && ($newShopId === '' || $newSecretKey === '')) {
                return back()
                    ->withErrors(['yookassa_shop_id' => 'Для первого сохранения введите Shop ID и секретный ключ вместе.'])
                    ->withInput($request->except($secretFields));
            }

            if ($newShopId !== '') {
                $updates['yookassa_shop_id'] = $newShopId;
            }

            if ($newSecretKey !== '') {
                $updates['yookassa_secret_key'] = $newSecretKey;
            }
        }

        if ($request->boolean('clear_telegram')) {
            $updates['telegram_bot_token'] = null;
        } else {
            $newTelegramToken = trim((string) ($validated['telegram_bot_token'] ?? ''));

            if ($newTelegramToken !== '') {
                $updates['telegram_bot_token'] = $newTelegramToken;
            }
        }

        if ($updates !== []) {
            $settings->update($updates);
        }

        return back()->with('status', 'Учётные данные сохранены. Секреты не отображаются повторно.');
    }

    public function storePanel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['required', 'url:https', 'not_regex:/[?#]/', 'max:255'],
            'subscription_url_prefix' => ['required', 'url:https', 'not_regex:/[?#]/', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:500'],
            'flow' => ['nullable', 'string', 'max:120'],
        ]);

        $validated['base_url'] = rtrim($validated['base_url'], '/');
        Panel::create($validated);

        return back()->with('status', 'Панель добавлена.');
    }

    public function updatePanel(Request $request, Panel $panel): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['required', 'url:https', 'not_regex:/[?#]/', 'max:255'],
            'subscription_url_prefix' => ['required', 'url:https', 'not_regex:/[?#]/', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'flow' => ['nullable', 'string', 'max:120'],
        ]);

        $validated['base_url'] = rtrim($validated['base_url'], '/');

        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        if ($panel->subscriptions()->exists()
            && $validated['base_url'] !== $panel->base_url) {
            return back()->withErrors([
                'panel' => 'Нельзя менять адрес панели с существующими подписками. Перенесите клиентов отдельно.',
            ]);
        }

        $connectionSettingsChanged = $validated['base_url'] !== $panel->base_url
            || $validated['username'] !== $panel->username
            || (($validated['password'] ?? null) !== null && $validated['password'] !== $panel->password);

        $panel->update($validated);

        if ($connectionSettingsChanged) {
            $panel->forceFill([
                'connection_status' => null,
                'connection_diagnostic' => null,
                'connection_http_status' => null,
                'connection_checked_at' => null,
            ])->save();
        }

        return back()->with('status', 'Панель обновлена.');
    }

    public function checkPanelConnection(Panel $panel, ThreeXUiClient $client): RedirectResponse
    {
        $result = $client->testConnection($panel);

        $panel->forceFill([
            'connection_status' => $result->online ? 'online' : 'offline',
            'connection_diagnostic' => $result->diagnostic,
            'connection_http_status' => $result->httpStatus,
            'connection_checked_at' => now(),
        ])->save();

        return back()->with('status', $result->message());
    }

    public function assignPanel(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_panel_id' => ['nullable', 'integer', 'exists:panels,id'],
        ]);

        if ($user->subscription()->where('status', 'active')->where('expires_at', '>', now())->exists()
            && (int) $user->assigned_panel_id !== (int) ($validated['assigned_panel_id'] ?? 0)) {
            return back()->withErrors([
                'panel' => 'У пользователя есть активная подписка. Перенесите клиента со старой панели отдельно.',
            ]);
        }

        $previousPanelId = $user->assigned_panel_id;
        $user->update($validated);

        if ((int) ($previousPanelId ?? 0) !== (int) ($user->assigned_panel_id ?? 0)) {
            AdminAuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'subject_user_id' => $user->id,
                'action' => 'user_panel_assigned',
                'summary' => 'Изменена назначенная панель пользователя.',
            ]);
        }

        if ($user->assigned_panel_id) {
            $user->orders()
                ->where('status', 'paid_unassigned')
                ->pluck('id')
                ->each(function (int $orderId) use ($request): void {
                    $order = Order::query()->findOrFail($orderId);
                    app(PaymentEventLogger::class)->record(
                        $order->is_test ? 'internal_test' : $order->payment_method,
                        'provisioning_queued',
                        'Панель назначена; оплаченный заказ поставлен в очередь выдачи.',
                        $order,
                        $request->user()->id,
                        'paid_unassigned',
                        'paid_unassigned',
                    );
                    ProvisionSubscription::dispatch($orderId);
                });
        }

        return back()->with('status', 'Назначение панели обновлено.');
    }

    public function retryProvisioning(Order $order): RedirectResponse
    {
        abort_unless(in_array($order->status, ['paid_unassigned', 'provisioning_failed'], true), 409);

        if (! $order->user()->value('assigned_panel_id')) {
            return back()->withErrors(['panel' => 'Сначала назначьте панель этому пользователю.']);
        }

        app(PaymentEventLogger::class)->record(
            $order->is_test ? 'internal_test' : $order->payment_method,
            'provisioning_retry_requested',
            'Администратор запросил повторную выдачу доступа.',
            $order,
            auth()->id(),
            $order->status,
            $order->status,
        );
        ProvisionSubscription::dispatch($order->id);

        return back()->with('status', 'Выдача доступа поставлена в очередь.');
    }

    public function deletePanel(Panel $panel): RedirectResponse
    {
        if ($panel->subscriptions()->exists()) {
            return back()->withErrors([
                'panel' => 'Нельзя удалить панель с существующими подписками. Сначала перенесите клиентов.',
            ]);
        }

        $panel->delete();

        return back()->with('status', 'Панель удалена; назначения на неё очищены.');
    }

    private function priceToKopecks(string $price): int
    {
        [$rubles, $kopecks] = array_pad(explode('.', $price, 2), 2, '0');

        return ((int) $rubles * 100) + (int) str_pad($kopecks, 2, '0');
    }
}
