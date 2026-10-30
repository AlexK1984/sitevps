<?php

namespace App\Http\Controllers;

use App\Exceptions\InternalTestPaymentException;
use App\Jobs\ProvisionSubscription;
use App\Models\Order;
use App\Models\Panel;
use App\Models\PaymentEvent;
use App\Models\PaymentSettings;
use App\Models\Tariff;
use App\Models\User;
use App\Services\InternalTestPaymentService;
use App\Services\PaymentEventLogger;
use App\Services\ThreeXUiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
            'users' => User::query()->with('assignedPanel')->orderBy('id')->paginate(30, ['*'], 'users'),
            'tariffs' => Tariff::query()->orderBy('id')->get(),
            'panels' => Panel::query()->orderBy('name')->get(),
            'orders' => Order::query()->with(['user', 'tariff', 'panel'])->latest()->limit(100)->get(),
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
            'inbound_id' => ['required', 'integer', 'min:1'],
            'protocol' => ['required', 'in:vless,vmess,trojan,shadowsocks'],
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
            'inbound_id' => ['required', 'integer', 'min:1'],
            'protocol' => ['required', 'in:vless,vmess,trojan,shadowsocks'],
            'flow' => ['nullable', 'string', 'max:120'],
        ]);

        $validated['base_url'] = rtrim($validated['base_url'], '/');

        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        if ($panel->subscriptions()->exists()
            && ($validated['base_url'] !== $panel->base_url
                || (int) $validated['inbound_id'] !== (int) $panel->inbound_id
                || $validated['protocol'] !== $panel->protocol)) {
            return back()->withErrors([
                'panel' => 'Нельзя менять сервер или inbound панели с активными подписками. Перенесите клиентов отдельно.',
            ]);
        }

        $connectionSettingsChanged = $validated['base_url'] !== $panel->base_url
            || $validated['username'] !== $panel->username
            || (int) $validated['inbound_id'] !== (int) $panel->inbound_id
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

        $user->update($validated);

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
