<?php

namespace App\Http\Controllers;

use App\Exceptions\InternalTestPaymentException;
use App\Exceptions\PanelApiException;
use App\Exceptions\PaymentGatewayException;
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
use App\Notifications\SmtpTestNotification;
use App\Services\InternalTestPaymentService;
use App\Services\NowPaymentsClient;
use App\Services\PaymentEventLogger;
use App\Services\RuntimeMailSettings;
use App\Services\ThreeXUiClient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AdminController extends Controller
{
    public function index(Request $request): View
    {
        return $this->renderPage($request, 'overview');
    }

    public function page(Request $request, string $section): View
    {
        return $this->renderPage($request, $section);
    }

    private function renderPage(Request $request, string $section): View
    {
        $data = match ($section) {
            'overview' => [
                'userCount' => User::query()->count(),
                'tariffCount' => Tariff::query()->count(),
                'panelCount' => Panel::query()->count(),
                'orderCount' => Order::query()->count(),
            ],
            'users' => [
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
            ],
            'tariffs' => ['tariffs' => Tariff::query()->orderBy('id')->get()],
            'panels' => ['panels' => Panel::query()->orderBy('name')->get()],
            'orders' => [
                'orders' => Order::query()->with(['user', 'tariff', 'panel'])->latest()->limit(100)->get(),
            ],
            'test-payments' => [
                'testPaymentUsers' => User::query()->whereNotNull('assigned_panel_id')->orderBy('email')->get(['id', 'email']),
                'testPaymentTariffs' => Tariff::query()->where('is_active', true)->orderBy('id')->get(['id', 'name']),
                'testPaymentIdempotencyKey' => (string) Str::uuid(),
                'paymentSettings' => $this->paymentSettingsViewData(),
            ],
            'payment-events' => $this->paymentEventsViewData($request),
            'payment-settings', 'mail-settings' => ['paymentSettings' => $this->paymentSettingsViewData()],
            'site', 'seo' => ['siteContent' => SiteContent::current()],
            'system' => [
                'adminAuditLogs' => AdminAuditLog::query()->with(['actor', 'subjectUser'])->latest()->limit(50)->get(),
            ],
            default => abort(404),
        };

        return view('admin.pages.'.$section, $data + ['siteContent' => SiteContent::current()]);
    }

    /**
     * @return array{paymentEvents: LengthAwarePaginator, eventProvider: ?string}
     */
    private function paymentEventsViewData(Request $request): array
    {
        $eventProvider = $request->query('event_provider');
        if (! in_array($eventProvider, ['yookassa', 'nowpayments', 'telegram_stars', 'internal_test'], true)) {
            $eventProvider = null;
        }

        return [
            'paymentEvents' => PaymentEvent::query()
                ->with(['order.user', 'actor'])
                ->when($eventProvider, fn ($query) => $query->where('provider', $eventProvider))
                ->latest()
                ->paginate(30, ['*'], 'payment_events')
                ->withQueryString(),
            'eventProvider' => $eventProvider,
        ];
    }

    private function paymentSettingsViewData(): object
    {
        $settings = PaymentSettings::current();
        $yookassaCredentials = $settings->yookassaCredentials();

        return (object) [
            'yookassa_enabled' => $settings->yookassa_enabled,
            'telegram_stars_enabled' => $settings->telegram_stars_enabled,
            'internal_test_payments_enabled' => $settings->internal_test_payments_enabled,
            'nowpayments_enabled' => $settings->nowpayments_enabled,
            'nowpayments_rub_verified' => $settings->nowpayments_rub_verified,
            'yookassa_configured' => filled($yookassaCredentials['shop_id']) && filled($yookassaCredentials['secret_key']),
            'yookassa_configured_in_admin' => $settings->yookassaConfiguredInAdmin(),
            'telegram_configured' => filled($settings->telegramBotToken()),
            'telegram_configured_in_admin' => $settings->telegramConfiguredInAdmin(),
            'smtp_enabled' => $settings->smtp_enabled,
            'smtp_host' => $settings->smtp_host,
            'smtp_port' => $settings->smtp_port,
            'smtp_encryption' => $settings->smtp_encryption,
            'smtp_from_address' => $settings->smtp_from_address,
            'smtp_from_name' => $settings->smtp_from_name,
            'smtp_configured_in_admin' => $settings->smtpConfiguredInAdmin(),
            'smtp_username_configured_in_admin' => $settings->smtpUsernameConfiguredInAdmin(),
            'smtp_password_configured_in_admin' => $settings->smtpPasswordConfiguredInAdmin(),
            'nowpayments_configured_in_admin' => $settings->nowpaymentsConfiguredInAdmin(),
        ];
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
        $syncEnabled = true;
        $auditChanges = [];

        DB::transaction(function () use ($request, $user, $validated, &$syncSubscriptionId, &$syncPanelId, &$syncEnabled, &$auditChanges): void {
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
            if (! $newActive && $request->user()->is($lockedUser)) {
                throw ValidationException::withMessages([
                    'is_active' => 'Нельзя отключить собственную учётную запись администратора.',
                ]);
            }
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
            $accessChanged = in_array('is_active', $userChanges, true);
            if ($accessChanged && $subscription?->panel_id) {
                $syncSubscriptionId = $subscription->id;
                $syncPanelId = $subscription->panel_id;
                $syncEnabled = $newActive;
            }
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
                    $syncEnabled = $newActive;
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
            $user = $user->fresh();
            $subscription = Subscription::query()->findOrFail($syncSubscriptionId);
            if (! $syncEnabled || in_array($subscription->status, ['active', 'provisioning_failed'], true)) {
                try {
                    $client->setEnabled(Panel::query()->findOrFail($syncPanelId), $subscription, $syncEnabled);
                    if ($syncEnabled && $subscription->status === 'active') {
                        $subscription->forceFill(['last_error' => null])->save();
                    }
                } catch (PanelApiException $exception) {
                    $subscription->forceFill(['last_error' => $exception->getMessage()])->save();
                    AdminAuditLog::query()->create([
                        'actor_id' => $request->user()->id,
                        'subject_user_id' => $user->id,
                        'action' => $syncEnabled ? 'subscription_sync_failed' : 'user_access_sync_failed',
                        'summary' => 'Изменения сохранены в сайте, но панель 3x-ui не подтвердила синхронизацию: '.$exception->getMessage(),
                    ]);

                    return back()->with('status', $syncEnabled
                        ? 'Изменения сохранены, но панель не подтвердила синхронизацию. Проверьте ошибку в карточке подписки.'
                        : 'Учётная запись отключена, но панель не подтвердила блокировку VPN-клиента. Проверьте ошибку в карточке подписки.');
                }
            }
        }

        return redirect()->route('admin.users.edit', $user)->with('status', 'Данные пользователя обновлены.');
    }

    public function banUser(Request $request, User $user, ThreeXUiClient $client): RedirectResponse
    {
        return $this->changeUserAccess($request, $user, false, 'user_banned', $client);
    }

    public function restoreUser(Request $request, User $user, ThreeXUiClient $client): RedirectResponse
    {
        return $this->changeUserAccess($request, $user, true, 'user_unbanned', $client);
    }

    public function softDeleteUser(Request $request, User $user, ThreeXUiClient $client): RedirectResponse
    {
        return $this->changeUserAccess($request, $user, false, 'user_soft_deleted', $client);
    }

    private function changeUserAccess(
        Request $request,
        User $user,
        bool $active,
        string $auditAction,
        ThreeXUiClient $client,
    ): RedirectResponse {
        if (! $active && $request->user()->is($user)) {
            throw ValidationException::withMessages([
                'user' => 'Нельзя заблокировать или удалить собственную учётную запись администратора.',
            ]);
        }

        DB::transaction(function () use ($request, $user, $active, $auditAction): void {
            $admins = User::query()->where('is_admin', true)->orderBy('id')->lockForUpdate()->get(['id', 'is_active']);
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! $active && $lockedUser->is_admin && $lockedUser->is_active
                && $admins->where('id', '!=', $lockedUser->id)->where('is_active', true)->isEmpty()) {
                throw ValidationException::withMessages([
                    'user' => 'Нельзя отключить или удалить последнего активного администратора.',
                ]);
            }

            $lockedUser->forceFill(['is_active' => $active])->save();
            AdminAuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'subject_user_id' => $lockedUser->id,
                'action' => $auditAction,
                'summary' => $active
                    ? 'Вход в учётную запись восстановлен; сохранённые заказы и платёжная история не менялись.'
                    : ($auditAction === 'user_soft_deleted'
                        ? 'Учётная запись мягко удалена через деактивацию; заказы и платёжная история сохранены.'
                        : 'Вход в учётную запись заблокирован; заказы и платёжная история сохранены.'),
            ]);
        });

        $user = $user->fresh();
        $subscription = $user->subscription;
        if ($subscription?->panel_id && (! $active || $subscription->isActive())) {
            try {
                $client->setEnabled($subscription->panel, $subscription, $active);
            } catch (PanelApiException $exception) {
                $subscription->forceFill(['last_error' => $exception->getMessage()])->save();
                AdminAuditLog::query()->create([
                    'actor_id' => $request->user()->id,
                    'subject_user_id' => $user->id,
                    'action' => 'user_access_sync_failed',
                    'summary' => 'Статус сайта сохранён, но панель 3x-ui не подтвердила изменение доступа: '.$exception->getMessage(),
                ]);

                return back()->with('status', $active
                    ? 'Доступ к сайту восстановлен, но VPN-клиент не удалось включить на панели. Проверьте ошибку подписки.'
                    : 'Учётная запись отключена, но VPN-клиент не удалось заблокировать на панели. Проверьте ошибку подписки.');
            }
        }

        return back()->with('status', $active
            ? 'Доступ пользователя восстановлен.'
            : ($auditAction === 'user_soft_deleted' ? 'Пользователь мягко удалён: доступ заблокирован, история сохранена.' : 'Пользователь заблокирован.'));
    }

    public function updateHomeContent(Request $request): RedirectResponse
    {
        $validated = $request->validate([
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

        return back()->with('status', 'Содержимое главной страницы сохранено.');
    }

    public function updateSeoSettings(Request $request): RedirectResponse
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
                'action' => 'seo_settings_updated',
                'summary' => 'Изменены SEO-настройки: '.implode(', ', $changed).'.',
            ]);
        }

        return back()->with('status', 'SEO-настройки сохранены.');
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
            'nowpayments_enabled' => ['sometimes', 'boolean'],
        ]);

        $settings = PaymentSettings::current();
        $wasNowPaymentsEnabled = $settings->nowpayments_enabled;
        if (array_key_exists('nowpayments_enabled', $validated)) {
            $enableNowPayments = $request->boolean('nowpayments_enabled');
            if ($enableNowPayments && ! $settings->nowpaymentsConfiguredInAdmin()) {
                throw ValidationException::withMessages([
                    'nowpayments_enabled' => 'Сначала сохраните API key и IPN secret NOWPayments.',
                ]);
            }
            if ($enableNowPayments && ! $settings->nowpayments_rub_verified) {
                throw ValidationException::withMessages([
                    'nowpayments_enabled' => 'Сначала проверьте поддержку RUB через кнопку проверки NOWPayments.',
                ]);
            }
            $validated['nowpayments_enabled'] = $enableNowPayments;
        }
        $settings->update($validated);

        if ($wasNowPaymentsEnabled !== $settings->nowpayments_enabled) {
            AdminAuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'action' => 'nowpayments_checkout_toggled',
                'summary' => $settings->nowpayments_enabled
                    ? 'Включена оплата через проверенный NOWPayments checkout в RUB.'
                    : 'Оплата через NOWPayments отключена.',
            ]);
        }

        return back()->with('status', 'Настройки способов оплаты сохранены.');
    }

    public function updateTestPaymentSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = $request->boolean('enabled');
        PaymentSettings::current();

        DB::transaction(function () use ($request, $enabled): void {
            $settings = PaymentSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $wasEnabled = $settings->internal_test_payments_enabled;
            $settings->update(['internal_test_payments_enabled' => $enabled]);

            if ($wasEnabled !== $enabled) {
                AdminAuditLog::query()->create([
                    'actor_id' => $request->user()->id,
                    'action' => 'test_payment_feature_toggled',
                    'summary' => $enabled
                        ? 'Включена тестовая оплата для личного кабинета; симуляция выдаёт реальный доступ на назначенной панели.'
                        : 'Тестовая оплата в личном кабинете выключена.',
                ]);
                app(PaymentEventLogger::class)->record(
                    'internal_test',
                    $enabled ? 'test_mode_enabled' : 'test_mode_disabled',
                    $enabled
                        ? 'Администратор включил внутренний тестовый режим.'
                        : 'Администратор выключил внутренний тестовый режим.',
                    actorId: $request->user()->id,
                );
            }
        });

        return back()->with('status', 'Настройка тестовой оплаты сохранена.');
    }

    public function sendUserPasswordReset(
        Request $request,
        User $user,
        RuntimeMailSettings $mailSettings,
    ): RedirectResponse {
        if (! $mailSettings->configure()) {
            return back()->withErrors([
                'password_reset' => 'Почта не настроена или недоступна. Настройте SMTP в админ-панели и повторите запрос.',
            ]);
        }

        try {
            $status = Password::sendResetLink(['email' => $user->email]);
        } catch (TransportExceptionInterface $exception) {
            DB::table(config('auth.passwords.users.table'))
                ->where('email', $user->email)
                ->delete();

            Log::warning('Admin-triggered password reset email delivery failed.', [
                'exception_class' => $exception::class,
            ]);

            return back()->withErrors([
                'password_reset' => 'Не удалось отправить письмо. Проверьте настройки SMTP и повторите позже.',
            ]);
        }

        if ($status === Password::RESET_THROTTLED) {
            return back()->withErrors([
                'password_reset' => 'Ссылка для пользователя уже недавно запрашивалась. Повторите позже.',
            ]);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return back()->withErrors([
                'password_reset' => 'Не удалось создать ссылку сброса пароля. Проверьте почту и повторите позже.',
            ]);
        }

        AdminAuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'subject_user_id' => $user->id,
            'action' => 'user_password_reset_requested',
            'summary' => 'Администратор запросил отправку одноразовой ссылки сброса пароля на адрес пользователя.',
        ]);

        return back()->with('status', 'Одноразовая ссылка для сброса пароля отправлена пользователю по настроенной почте.');
    }

    public function verifyNowPaymentsRuble(
        Request $request,
        NowPaymentsClient $nowPayments,
    ): RedirectResponse {
        $settings = PaymentSettings::current();
        if (! $settings->nowpaymentsConfiguredInAdmin()) {
            return back()->withErrors([
                'nowpayments' => 'Сначала сохраните API key и IPN secret NOWPayments.',
            ]);
        }

        try {
            $nowPayments->verifyRubleSupport();
        } catch (PaymentGatewayException) {
            DB::transaction(function () use ($settings, $request): void {
                $settings->update([
                    'nowpayments_enabled' => false,
                    'nowpayments_rub_verified' => false,
                ]);
                AdminAuditLog::query()->create([
                    'actor_id' => $request->user()->id,
                    'action' => 'nowpayments_rub_verification_failed',
                    'summary' => 'Проверка поддержки RUB через NOWPayments не прошла; криптооплата выключена.',
                ]);
            });

            return back()->withErrors([
                'nowpayments' => 'NOWPayments не подтвердил поддержку RUB. Оплата оставлена выключенной.',
            ]);
        }

        $settings->update([
            'nowpayments_rub_verified' => true,
        ]);
        AdminAuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'action' => 'nowpayments_rub_verified',
            'summary' => 'NOWPayments API подтвердил доступность расчёта минимальной суммы для RUB.',
        ]);

        return back()->with('status', 'Поддержка RUB подтверждена запросом к NOWPayments. Теперь можно включить криптооплату.');
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
        $secretFields = [
            'yookassa_shop_id',
            'yookassa_secret_key',
            'telegram_bot_token',
            'nowpayments_api_key',
            'nowpayments_ipn_secret',
        ];
        $validator = Validator::make($request->all(), [
            'yookassa_shop_id' => ['nullable', 'string', 'max:255'],
            'yookassa_secret_key' => ['nullable', 'string', 'max:500'],
            'telegram_bot_token' => ['nullable', 'string', 'max:500'],
            'nowpayments_api_key' => ['nullable', 'string', 'max:500'],
            'nowpayments_ipn_secret' => ['nullable', 'string', 'max:500'],
            'clear_yookassa' => ['sometimes', 'boolean'],
            'clear_telegram' => ['sometimes', 'boolean'],
            'clear_nowpayments' => ['sometimes', 'boolean'],
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

        if ($request->boolean('clear_nowpayments')) {
            $updates['nowpayments_api_key'] = null;
            $updates['nowpayments_ipn_secret'] = null;
            $updates['nowpayments_enabled'] = false;
            $updates['nowpayments_rub_verified'] = false;
        } else {
            $nowPaymentsCredentialsChanged = false;
            foreach (['nowpayments_api_key', 'nowpayments_ipn_secret'] as $field) {
                $value = trim((string) ($validated[$field] ?? ''));
                if ($value !== '') {
                    $updates[$field] = $value;
                    $nowPaymentsCredentialsChanged = true;
                }
            }
            if ($nowPaymentsCredentialsChanged) {
                $updates['nowpayments_enabled'] = false;
                $updates['nowpayments_rub_verified'] = false;
            }
        }

        if ($updates !== []) {
            DB::transaction(function () use ($settings, $updates, $request): void {
                $settings->update($updates);
                AdminAuditLog::query()->create([
                    'actor_id' => $request->user()->id,
                    'action' => 'payment_credentials_updated',
                    'summary' => 'Изменены учётные данные платёжных сервисов: '.implode(', ', array_keys($updates)).'. Секретные значения в журнал не записывались.',
                ]);
            });
        }

        return back()->with('status', 'Учётные данные сохранены. Секреты не отображаются повторно.');
    }

    public function updateMailSettings(Request $request): RedirectResponse
    {
        $secretFields = ['smtp_username', 'smtp_password'];
        $validator = Validator::make($request->all(), [
            'smtp_enabled' => ['required', 'boolean'],
            'smtp_host' => ['nullable', 'string', 'max:255', 'required_if:smtp_enabled,1'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535', 'required_if:smtp_enabled,1'],
            'smtp_encryption' => ['nullable', 'in:tls,ssl', 'required_if:smtp_enabled,1'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:1000'],
            'smtp_from_address' => ['nullable', 'email', 'max:255', 'required_if:smtp_enabled,1'],
            'smtp_from_name' => ['nullable', 'string', 'max:255'],
            'clear_smtp_username' => ['sometimes', 'boolean'],
            'clear_smtp_password' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput($request->except($secretFields));
        }

        $validated = $validator->validated();
        $settings = PaymentSettings::current();
        $updates = [
            'smtp_enabled' => $request->boolean('smtp_enabled'),
            'smtp_host' => filled($validated['smtp_host'] ?? null) ? trim($validated['smtp_host']) : null,
            'smtp_port' => $validated['smtp_port'] ?? null,
            'smtp_encryption' => $validated['smtp_encryption'] ?? null,
            'smtp_from_address' => filled($validated['smtp_from_address'] ?? null) ? trim($validated['smtp_from_address']) : null,
            'smtp_from_name' => filled($validated['smtp_from_name'] ?? null) ? trim($validated['smtp_from_name']) : null,
        ];

        if ($request->boolean('clear_smtp_username')) {
            $updates['smtp_username'] = null;
        } elseif (filled($validated['smtp_username'] ?? null)) {
            $updates['smtp_username'] = trim($validated['smtp_username']);
        }

        if ($request->boolean('clear_smtp_password')) {
            $updates['smtp_password'] = null;
        } elseif (filled($validated['smtp_password'] ?? null)) {
            $updates['smtp_password'] = $validated['smtp_password'];
        }

        $changed = array_keys($updates);
        DB::transaction(function () use ($request, $settings, $updates, $changed): void {
            $settings->update($updates);
            AdminAuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'action' => 'smtp_settings_updated',
                'summary' => 'Изменены настройки SMTP: '.implode(', ', $changed).'. Секретные значения в журнал не записывались.',
            ]);
        });

        return back()->with('status', 'Настройки SMTP сохранены. Пароль не отображается повторно.');
    }

    public function sendTestMail(Request $request, RuntimeMailSettings $mailSettings): RedirectResponse
    {
        if (! $mailSettings->configure()
            || config('mail.default') !== 'smtp'
            || config('mail.mailers.smtp.transport') !== 'smtp') {
            return back()->withErrors([
                'smtp_test' => 'Почта не настроена. Сохраните SMTP-настройки или проверьте настройки почтового транспорта на сервере.',
            ]);
        }

        try {
            Notification::route('mail', $request->user()->email)
                ->notify(new SmtpTestNotification);
        } catch (TransportExceptionInterface $exception) {
            Log::warning('SMTP test delivery failed.', [
                'exception_class' => $exception::class,
            ]);

            return back()->withErrors([
                'smtp_test' => 'Не удалось отправить тестовое письмо. Проверьте адрес сервера, порт, шифрование и учётные данные SMTP.',
            ]);
        }

        return back()->with('status', 'Тестовое письмо отправлено на адрес вашей учётной записи администратора.');
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
