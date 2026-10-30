<?php

namespace Tests\Feature;

use App\Exceptions\PanelApiException;
use App\Jobs\ProvisionSubscription;
use App\Models\Order;
use App\Models\Panel;
use App\Models\PaymentSettings;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\InternalTestPaymentService;
use App\Services\TelegramBotClient;
use App\Services\ThreeXUiClient;
use App\Services\YooKassaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SubscriptionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_and_registration_are_available(): void
    {
        $this->get('/')->assertOk();

        $response = $this->post('/register', [
            'name' => 'VPN customer',
            'email' => 'Customer@Example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'customer@example.com', 'is_admin' => false]);
    }

    public function test_non_admin_cannot_open_admin_panel(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
        $panel = $this->createPanel('Panel');
        $this->actingAs(User::factory()->create())->post('/admin/panels/'.$panel->id.'/connection-test')->assertForbidden();
        $this->actingAs(User::factory()->create())->put('/admin/payment-credentials', [
            'yookassa_shop_id' => 'not-authorized',
            'yookassa_secret_key' => 'not-authorized',
            'telegram_bot_token' => 'not-authorized',
        ])->assertForbidden();
    }

    public function test_admin_can_create_a_tariff_with_ruble_pricing(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post('/admin/tariffs', [
            'name' => 'Monthly',
            'slug' => 'monthly',
            'description' => 'Monthly access',
            'price_rubles' => '12.99',
            'duration_days' => 30,
            'traffic_gb' => 100,
            'connection_limit' => 3,
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('tariffs', [
            'slug' => 'monthly',
            'price_kopecks' => 1299,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_configure_stars_and_provider_neutral_crypto_preferences(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        config(['services.telegram.bot_token' => 'browser-must-not-see-this']);

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('Настройки оплат')
            ->assertSee('Криптооплата отключена')
            ->assertDontSee('browser-must-not-see-this');

        $this->actingAs($admin)->put('/admin/payment-settings', [
            'yookassa_enabled' => '1',
            'telegram_stars_enabled' => '1',
            'crypto_asset' => 'USDT',
            'crypto_network' => 'TRC20',
            'crypto_confirmations' => 12,
            'crypto_payment_ttl_minutes' => 30,
            'crypto_admin_notes' => 'Choose provider before launch.',
        ])->assertRedirect();

        $this->assertDatabaseHas('payment_settings', [
            'id' => 1,
            'telegram_stars_enabled' => true,
            'crypto_asset' => 'USDT',
            'crypto_network' => 'TRC20',
            'crypto_confirmations' => 12,
            'crypto_payment_ttl_minutes' => 30,
            'crypto_admin_notes' => 'Choose provider before launch.',
        ]);
        $this->assertDatabaseHas('payment_settings', [
            'id' => 1,
            'internal_test_payments_enabled' => false,
        ]);
    }

    public function test_internal_test_payments_are_disabled_by_default_and_admin_only(): void
    {
        $this->assertFalse(PaymentSettings::current()->internal_test_payments_enabled);
        $tariff = $this->createTariff();
        $panel = $this->createPanel('Panel');
        $user = User::factory()->create(['assigned_panel_id' => $panel->id]);
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->create();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('внутреннюю тестовую оплату')
            ->assertDontSee('Создать заказ TEST и выдать реальный доступ');

        $payload = [
            'user_id' => $user->id,
            'tariff_id' => $tariff->id,
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->actingAs($customer)->post('/admin/payments/test', $payload)->assertForbidden();
        $this->actingAs($admin)->post('/admin/payments/test', $payload)->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_events', 0);
    }

    public function test_admin_internal_test_payment_is_clearly_marked_idempotent_and_never_calls_a_provider(): void
    {
        Queue::fake();
        Http::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');
        $user = User::factory()->create(['assigned_panel_id' => $panel->id]);
        $tariff = $this->createTariff();
        $key = (string) Str::uuid();

        $this->actingAs($admin)->put('/admin/payment-settings', [
            'yookassa_enabled' => '1',
            'telegram_stars_enabled' => '0',
            'internal_test_payments_enabled' => '1',
        ])->assertRedirect();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('тестовая симуляция не обращается к ЮKassa или Telegram', false)
            ->assertSee('Создать заказ TEST и выдать реальный доступ');

        $payload = [
            'user_id' => $user->id,
            'tariff_id' => $tariff->id,
            'idempotency_key' => $key,
        ];

        $this->actingAs($user)->post('/admin/payments/test', $payload)->assertForbidden();
        $this->assertDatabaseCount('orders', 0);

        $this->actingAs($admin)->post('/admin/payments/test', $payload)
            ->assertRedirect()
            ->assertSessionHas('status', 'Тестовый заказ создан и передан в обычную очередь выдачи на реальную панель.');
        $this->actingAs($admin)->post('/admin/payments/test', $payload)
            ->assertRedirect()
            ->assertSessionHas('status', 'Этот тестовый запрос уже был обработан; повторная выдача не запускалась.');

        $order = Order::query()->where('test_idempotency_key', $key)->firstOrFail();
        $this->assertTrue($order->is_test);
        $this->assertSame('internal_test', $order->payment_method);
        $this->assertSame('TST', $order->payment_currency);
        $this->assertSame('paid_unassigned', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->payment_id);
        $this->assertNull($order->payment_url);
        $this->assertSame('TEST · без списания', $order->paidAmountLabel());
        $this->assertSame('TEST · внутренняя симуляция', $order->paymentMethodLabel());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_events', 2);
        $this->assertDatabaseHas('payment_events', [
            'order_id' => $order->id,
            'actor_id' => $admin->id,
            'provider' => 'internal_test',
            'event_type' => 'test_simulated',
            'status_to' => 'paid_unassigned',
        ]);
        Queue::assertPushed(ProvisionSubscription::class, 1);
        Http::assertNothingSent();

        $this->get('/admin')
            ->assertOk()
            ->assertSee('TEST')
            ->assertSee('Тестовая оплата смоделирована')
            ->assertDontSee('test-simulation:'.$key);
    }

    public function test_internal_test_payment_uses_the_normal_idempotent_provisioning_path(): void
    {
        Http::fake();
        PaymentSettings::current()->update(['internal_test_payments_enabled' => true]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');
        $user = User::factory()->create(['assigned_panel_id' => $panel->id]);
        $order = app(InternalTestPaymentService::class)
            ->simulate($user, $this->createTariff(), $admin, (string) Str::uuid())[0];

        $client = Mockery::mock(ThreeXUiClient::class);
        $client->shouldReceive('provision')->once();
        $this->app->instance(ThreeXUiClient::class, $client);

        ProvisionSubscription::dispatchSync($order->id);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'active', 'is_test' => true]);
        $this->assertDatabaseHas('payment_events', [
            'order_id' => $order->id,
            'event_type' => 'provisioning_completed',
            'provider' => 'internal_test',
            'status_to' => 'active',
        ]);
        $this->assertDatabaseCount('subscriptions', 1);
        Http::assertNothingSent();
    }

    public function test_admin_credentials_are_encrypted_and_never_returned_to_the_browser(): void
    {
        config([
            'services.yookassa.shop_id' => null,
            'services.yookassa.secret_key' => null,
            'services.telegram.bot_token' => null,
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put('/admin/payment-credentials', [
            'yookassa_shop_id' => 'private-shop-id',
            'yookassa_secret_key' => 'private-yookassa-key',
            'telegram_bot_token' => 'private-telegram-token',
        ])->assertRedirect();

        $settings = PaymentSettings::current();
        $raw = DB::table('payment_settings')->where('id', 1)->first();
        $this->assertSame('private-shop-id', $settings->yookassa_shop_id);
        $this->assertSame('private-yookassa-key', $settings->yookassa_secret_key);
        $this->assertSame('private-telegram-token', $settings->telegram_bot_token);
        $this->assertNotSame('private-shop-id', $raw->yookassa_shop_id);
        $this->assertNotSame('private-yookassa-key', $raw->yookassa_secret_key);
        $this->assertNotSame('private-telegram-token', $raw->telegram_bot_token);
        $this->assertArrayNotHasKey('yookassa_shop_id', $settings->toArray());
        $this->assertArrayNotHasKey('yookassa_secret_key', $settings->toArray());
        $this->assertArrayNotHasKey('telegram_bot_token', $settings->toArray());

        $this->get('/admin')
            ->assertOk()
            ->assertDontSee('private-shop-id')
            ->assertDontSee('private-yookassa-key')
            ->assertDontSee('private-telegram-token')
            ->assertSee('настроена в админ-панели')
            ->assertSee('токен задан в админ-панели');
    }

    public function test_clearing_admin_credentials_falls_back_to_existing_environment_configuration(): void
    {
        config([
            'services.yookassa.shop_id' => 'legacy-shop-id',
            'services.yookassa.secret_key' => 'legacy-yookassa-key',
            'services.telegram.bot_token' => 'legacy-telegram-token',
        ]);
        PaymentSettings::current()->update([
            'yookassa_shop_id' => 'admin-shop-id',
            'yookassa_secret_key' => 'admin-yookassa-key',
            'telegram_bot_token' => 'admin-telegram-token',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put('/admin/payment-credentials', [
            'clear_yookassa' => '1',
            'clear_telegram' => '1',
        ])->assertRedirect();

        $settings = PaymentSettings::current()->fresh();
        $this->assertNull($settings->yookassa_shop_id);
        $this->assertNull($settings->yookassa_secret_key);
        $this->assertNull($settings->telegram_bot_token);
        $this->assertSame([
            'shop_id' => 'legacy-shop-id',
            'secret_key' => 'legacy-yookassa-key',
        ], $settings->yookassaCredentials());
        $this->assertSame('legacy-telegram-token', $settings->telegramBotToken());
    }

    public function test_invalid_credential_submission_does_not_flash_secret_fields_to_session(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put('/admin/payment-credentials', [
            'yookassa_shop_id' => 'private-shop-id',
            'yookassa_secret_key' => 'private-yookassa-key',
            'telegram_bot_token' => str_repeat('x', 501),
        ])->assertSessionHasErrors('telegram_bot_token');

        $this->assertFalse(session()->hasOldInput('yookassa_shop_id'));
        $this->assertFalse(session()->hasOldInput('yookassa_secret_key'));
        $this->assertFalse(session()->hasOldInput('telegram_bot_token'));
        $this->assertDatabaseCount('payment_settings', 0);
    }

    public function test_yookassa_uses_admin_credentials_as_a_pair_and_not_environment_credentials(): void
    {
        config([
            'services.yookassa.shop_id' => 'legacy-shop-id',
            'services.yookassa.secret_key' => 'legacy-secret',
        ]);
        PaymentSettings::current()->update([
            'yookassa_shop_id' => 'admin-shop-id',
            'yookassa_secret_key' => 'admin-secret',
        ]);
        $tariff = $this->createTariff();
        $order = $this->createOrder($tariff, User::factory()->create());

        Http::fake([
            'https://api.yookassa.ru/v3/payments' => Http::response([
                'id' => 'admin-credentials-payment',
                'confirmation' => ['confirmation_url' => 'https://pay.example.com/confirm'],
            ]),
        ]);

        app(YooKassaClient::class)->createPayment($order->load(['tariff', 'user']), 'https://site.example.com/dashboard');

        Http::assertSent(fn (ClientRequest $request) => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('admin-shop-id:admin-secret'),
        ));
    }

    public function test_telegram_bot_uses_admin_token_instead_of_environment_token(): void
    {
        config(['services.telegram.bot_token' => 'legacy-telegram-token']);
        PaymentSettings::current()->update(['telegram_bot_token' => 'admin-telegram-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        app(TelegramBotClient::class)->sendMessage('123', 'Test message');

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/botadmin-telegram-token/sendMessage')
            && ! str_contains($request->url(), 'legacy-telegram-token'));
    }

    public function test_admin_can_set_a_tariff_price_in_telegram_stars(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tariff = $this->createTariff();

        $this->actingAs($admin)->put('/admin/tariffs/'.$tariff->id, [
            'name' => $tariff->name,
            'slug' => $tariff->slug,
            'description' => $tariff->description,
            'price_rubles' => '12.99',
            'price_stars' => 50,
            'duration_days' => $tariff->duration_days,
            'traffic_gb' => $tariff->traffic_gb,
            'connection_limit' => $tariff->connection_limit,
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('tariffs', ['id' => $tariff->id, 'price_stars' => 50]);
    }

    public function test_admin_panel_view_does_not_expose_panel_password(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = Panel::create([
            'name' => 'Panel one',
            'base_url' => 'https://panel.example.com',
            'subscription_url_prefix' => 'https://vpn.example.com:2096/sub',
            'username' => 'admin',
            'password' => 'panel-secret',
            'inbound_id' => 1,
            'protocol' => 'vless',
        ]);

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertDontSee('panel-secret');

        $this->assertSame('panel-secret', $panel->fresh()->password);
    }

    public function test_admin_can_check_panel_connectivity_without_mutating_the_panel(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response(['success' => true], 200, ['Set-Cookie' => '3x-ui=session; path=/']),
            'https://panel.example.com/panel/api/inbounds/list' => Http::response([
                'success' => true,
                'obj' => [['id' => 1]],
            ]),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)
            ->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 200: Панель доступна: вход и read-only запрос списка inbound успешны.');

        $panel->refresh();
        $this->assertSame('online', $panel->connection_status);
        $this->assertSame('ok', $panel->connection_diagnostic);
        $this->assertSame(200, $panel->connection_http_status);
        $this->assertNotNull($panel->connection_checked_at);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Проверить подключение')
            ->assertSee('Онлайн')
            ->assertSee($panel->connection_checked_at->format('d.m.Y H:i:s'))
            ->assertDontSee('panel-secret');

        Http::assertSentCount(3);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/login')
            && $request->method() === 'POST'
            && $request->hasHeader('X-CSRF-Token', 'test-csrf')
            && $request->hasHeader('Cookie', '3x-ui=session')
            && $request['username'] === 'admin'
            && $request['password'] === 'panel-secret');
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/panel/api/inbounds/list')
            && $request->method() === 'GET'
            && $request->hasHeader('X-Requested-With', 'XMLHttpRequest')
            && $request->hasHeader('Cookie', '3x-ui=session'));
        Http::assertNotSent(fn (ClientRequest $request) => $request->method() !== 'GET'
            && ! str_ends_with($request->url(), '/login'));
    }

    public function test_panel_api_errors_are_recorded_as_offline_without_exposing_response_details(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response(['success' => true]),
            'https://panel.example.com/panel/api/inbounds/list' => Http::response([
                'success' => false,
                'msg' => 'panel-secret private API details',
            ], 503),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 503: Панель вернула внутреннюю HTTP-ошибку. Проверьте состояние панели и её reverse proxy.');

        $panel->refresh();
        $this->assertSame('offline', $panel->connection_status);
        $this->assertSame('server_error', $panel->connection_diagnostic);
        $this->assertNotNull($panel->connection_checked_at);

        $this->get('/admin')->assertSee('Офлайн')->assertSee('Панель вернула внутреннюю HTTP-ошибку')
            ->assertDontSee('private API details')->assertDontSee('panel-secret');
    }

    public function test_panel_authentication_failure_is_recorded_as_offline_without_exposing_details(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response([
                'success' => false,
                'msg' => 'invalid password panel-secret',
            ]),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 200: 3x-ui вернула success=false для входа (возможны учётные данные, 2FA или ограничение входа).');

        $panel->refresh();
        $this->assertSame('offline', $panel->connection_status);
        $this->assertSame('login_rejected', $panel->connection_diagnostic);
        $this->assertSame(200, $panel->connection_http_status);
        $this->assertNotNull($panel->connection_checked_at);

        $this->get('/admin')->assertSee('Офлайн')->assertSee('HTTP 200: 3x-ui вернула success=false')
            ->assertDontSee('invalid password')->assertDontSee('panel-secret');
        Http::assertSentCount(2);
    }

    public function test_panel_http_unauthorized_is_reported_as_authentication_failure(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response(['error' => 'private authentication details'], 401),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 401: Endpoint входа вернул HTTP 401. Панель отклонила запрос входа; проверьте настройки входа и 2FA.');

        $this->assertSame('login_unauthorized', $panel->fresh()->connection_diagnostic);
        $this->assertSame(401, $panel->fresh()->connection_http_status);
        $this->get('/admin')->assertSee('HTTP 401: Endpoint входа вернул HTTP 401')
            ->assertDontSee('private authentication details');
        Http::assertSentCount(2);
    }

    public function test_panel_login_forbidden_is_distinguished_from_bad_credentials(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response(['error' => 'private'], 403),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 403: Endpoint входа вернул HTTP 403. Доступ к входу запрещён политикой панели или reverse proxy.');

        $this->assertSame('login_forbidden', $panel->fresh()->connection_diagnostic);
        $this->assertSame(403, $panel->fresh()->connection_http_status);
        $this->get('/admin')->assertSee('HTTP 403: Endpoint входа вернул HTTP 403')
            ->assertDontSee('private');
    }

    public function test_panel_login_redirect_is_not_followed_or_misreported_as_bad_credentials(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response('', 302, ['Location' => '/secret-panel/login']),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 302: Endpoint входа перенаправил запрос вместо JSON-ответа. Проверьте base URL и reverse-proxy path.');

        $this->assertSame('login_redirect', $panel->fresh()->connection_diagnostic);
        $this->assertSame(302, $panel->fresh()->connection_http_status);
        $this->get('/admin')->assertSee('HTTP 302: Endpoint входа перенаправил запрос')
            ->assertDontSee('secret-panel');
        Http::assertSentCount(2);
    }

    public function test_panel_login_html_response_is_reported_as_base_path_or_proxy_mismatch(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response('<html>private panel login</html>', 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 200: Endpoint входа вернул HTML-страницу вместо JSON API (возможно, неверен base URL или reverse-proxy path).');

        $this->assertSame('login_html', $panel->fresh()->connection_diagnostic);
        $this->assertSame(200, $panel->fresh()->connection_http_status);
        $this->get('/admin')->assertSee('HTTP 200: Endpoint входа вернул HTML')
            ->assertDontSee('private panel login');
        Http::assertSentCount(2);
    }

    public function test_panel_login_malformed_json_is_not_reported_as_authentication_failure(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response(['unexpected' => 'private'], 200),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 200: Endpoint входа ответил, но JSON не соответствует формату 3x-ui.');

        $this->assertSame('login_malformed', $panel->fresh()->connection_diagnostic);
        $this->assertSame(200, $panel->fresh()->connection_http_status);
        $this->get('/admin')->assertSee('HTTP 200: Endpoint входа ответил')
            ->assertDontSee('private');
        Http::assertSentCount(2);
    }

    public function test_panel_base_path_and_cookie_path_are_preserved_for_login_and_read_only_api(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/secret-panel/csrf-token' => Http::response(
                ['success' => true, 'obj' => 'prefix-csrf'],
                200,
                ['Set-Cookie' => '3x-ui=prefix-session; Path=/secret-panel; HttpOnly'],
            ),
            'https://panel.example.com/secret-panel/login' => Http::response(
                ['success' => true]
            ),
            'https://panel.example.com/secret-panel/panel/api/inbounds/list' => Http::response([
                'success' => true,
                'obj' => [['id' => 1]],
            ]),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel', [
            'base_url' => 'https://panel.example.com/secret-panel/',
        ]);

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 200: Панель доступна: вход и read-only запрос списка inbound успешны.');

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://panel.example.com/secret-panel/csrf-token');
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://panel.example.com/secret-panel/login'
            && $request->hasHeader('Cookie', '3x-ui=prefix-session')
            && $request->hasHeader('X-CSRF-Token', 'prefix-csrf'));
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://panel.example.com/secret-panel/panel/api/inbounds/list'
            && $request->hasHeader('Cookie', '3x-ui=prefix-session'));
    }

    public function test_panel_api_redirect_is_reported_as_session_or_path_issue(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response(['success' => true]),
            'https://panel.example.com/panel/api/inbounds/list' => Http::response('', 302, [
                'Location' => '/secret-panel/login',
            ]),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 302: API списка inbound перенаправил запрос (возможно, на страницу входа). Проверьте base URL и cookie path.');

        $this->assertSame('api_redirect', $panel->fresh()->connection_diagnostic);
        $this->assertSame(302, $panel->fresh()->connection_http_status);
        $this->get('/admin')->assertSee('HTTP 302: API списка inbound перенаправил запрос')
            ->assertDontSee('secret-panel');
    }

    public function test_panel_api_unauthorized_is_reported_as_rejected_session_not_bad_password(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response(['success' => true]),
            'https://panel.example.com/panel/api/inbounds/list' => Http::response(['success' => false], 401),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 401: API списка inbound вернул HTTP 401: панель не приняла сессию входа.');

        $this->assertSame('api_unauthorized', $panel->fresh()->connection_diagnostic);
        $this->assertSame(401, $panel->fresh()->connection_http_status);
        $this->get('/admin')->assertSee('HTTP 401: API списка inbound вернул HTTP 401')
            ->assertDontSee('panel-secret');
    }

    public function test_panel_api_forbidden_is_distinguished_from_an_unauthorized_session(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response(['success' => true]),
            'https://panel.example.com/panel/api/inbounds/list' => Http::response(['success' => false], 403),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 403: API списка inbound вернул HTTP 403: доступ запрещён политикой панели.');

        $this->assertSame('api_forbidden', $panel->fresh()->connection_diagnostic);
        $this->assertSame(403, $panel->fresh()->connection_http_status);
        $this->get('/admin')->assertSee('HTTP 403: API списка inbound вернул HTTP 403')
            ->assertDontSee('panel-secret');
    }

    public function test_panel_api_html_is_reported_as_login_page_or_proxy_response(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response(['success' => true]),
            'https://panel.example.com/panel/api/inbounds/list' => Http::response(
                '<html>private login page</html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 200: API списка inbound вернул HTML вместо JSON (возможно, сессию перенаправило на страницу входа).');

        $this->assertSame('api_html', $panel->fresh()->connection_diagnostic);
        $this->assertSame(200, $panel->fresh()->connection_http_status);
        $this->get('/admin')->assertSee('HTTP 200: API списка inbound вернул HTML')
            ->assertDontSee('private login page');
    }

    public function test_panel_connection_timeout_is_recorded_safely_as_offline(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::failedConnection('private timeout details'),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'Истекло время ожидания ответа панели. Проверьте доступность и firewall.');

        $panel->refresh();
        $this->assertSame('offline', $panel->connection_status);
        $this->assertSame('connect_timeout', $panel->connection_diagnostic);
        $this->assertNotNull($panel->connection_checked_at);

        $this->get('/admin')->assertSee('Офлайн')->assertSee('Истекло время ожидания ответа панели')
            ->assertDontSee('private timeout details')->assertDontSee('panel-secret');
    }

    public function test_panel_connection_check_classifies_tls_failure_without_leaking_details(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tlsPanel = $this->createPanel('Panel');
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::failedConnection(
                'cURL error 60: SSL certificate secret-path?token=private',
            ),
        ]);

        $this->actingAs($admin)->post('/admin/panels/'.$tlsPanel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'Ошибка TLS-сертификата панели. Проверьте сертификат и имя хоста; не отключайте проверку TLS.');
        $this->assertSame('tls_error', $tlsPanel->fresh()->connection_diagnostic);
        $this->get('/admin')->assertSee('Ошибка TLS-сертификата панели')
            ->assertDontSee('secret-path')->assertDontSee('token=private');
    }

    public function test_panel_connection_check_classifies_dns_failure_without_leaking_details(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::failedConnection(
                'cURL error 6: Could not resolve host private-panel.example',
            ),
        ]);
        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'Не удалось разрешить DNS-имя панели. Проверьте DNS с сервера сайта.');
        $this->assertSame('dns_error', $panel->fresh()->connection_diagnostic);
        $this->get('/admin')->assertSee('Не удалось разрешить DNS-имя панели')
            ->assertDontSee('private-panel.example');
    }

    public function test_panel_connection_check_reports_missing_api_without_raw_response(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response(['success' => true]),
            'https://panel.example.com/panel/api/inbounds/list' => Http::response([
                'success' => false,
                'msg' => 'version-specific private response',
            ], 404),
        ]);

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 404: Не найден API endpoint списка inbound. Проверьте base URL, reverse-proxy path и версию 3x-ui.');
        $this->assertSame('api_endpoint_not_found', $panel->fresh()->connection_diagnostic);
        $this->get('/admin')->assertSee('Не найден API endpoint списка inbound')
            ->assertDontSee('version-specific private response');
    }

    public function test_panel_connection_check_reports_missing_inbound(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response(['success' => true]),
            'https://panel.example.com/panel/api/inbounds/list' => Http::response([
                'success' => true,
                'obj' => [['id' => 999]],
            ]),
        ]);
        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 200: Вход в панель выполнен, но настроенный inbound ID не найден.');
        $this->assertSame('inbound_not_found', $panel->fresh()->connection_diagnostic);
    }

    public function test_panel_csrf_forbidden_is_reported_before_attempting_login(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/csrf-token' => Http::response(['success' => false], 403),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 403: Endpoint CSRF вернул HTTP 403. Проверьте правила доступа reverse proxy.');

        $this->assertSame('csrf_forbidden', $panel->fresh()->connection_diagnostic);
        $this->assertSame(403, $panel->fresh()->connection_http_status);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/login'));
    }

    public function test_panel_without_csrf_endpoint_uses_legacy_login_flow(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/csrf-token' => Http::response('', 404),
            'https://panel.example.com/login' => Http::response(['success' => true]),
            'https://panel.example.com/panel/api/inbounds/list' => Http::response([
                'success' => true,
                'obj' => [['id' => 1]],
            ]),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');

        $this->actingAs($admin)->post('/admin/panels/'.$panel->id.'/connection-test')
            ->assertRedirect()
            ->assertSessionHas('status', 'HTTP 200: Панель доступна: вход и read-only запрос списка inbound успешны.');

        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/login')
            && ! $request->hasHeader('X-CSRF-Token'));
    }

    public function test_admin_can_create_a_secure_remote_panel(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post('/admin/panels', [
            'name' => 'Remote panel',
            'base_url' => 'https://panel.example.com',
            'subscription_url_prefix' => 'https://vpn.example.com:2096/sub',
            'username' => 'panel-admin',
            'password' => 'panel-secret',
            'inbound_id' => 1,
            'protocol' => 'vless',
        ])->assertRedirect();

        $panel = Panel::query()->firstOrFail();
        $this->assertSame('panel-secret', $panel->password);
        $this->assertNotSame('panel-secret', DB::table('panels')->where('id', $panel->id)->value('password'));
    }

    public function test_admin_can_save_a_custom_panel_base_path(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post('/admin/panels', [
            'name' => 'Panel with base path',
            'base_url' => 'https://panel.example.com/custom-prefix/',
            'subscription_url_prefix' => 'https://vpn.example.com:2096/sub',
            'username' => 'panel-admin',
            'password' => 'panel-secret',
            'inbound_id' => 1,
            'protocol' => 'vless',
        ])->assertRedirect();

        $this->assertDatabaseHas('panels', [
            'name' => 'Panel with base path',
            'base_url' => 'https://panel.example.com/custom-prefix',
        ]);
    }

    public function test_changing_panel_connection_settings_clears_the_previous_check_result(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel('Panel');
        $panel->forceFill([
            'connection_status' => 'online',
            'connection_checked_at' => now(),
        ])->save();

        $this->actingAs($admin)->put('/admin/panels/'.$panel->id, [
            'name' => $panel->name,
            'base_url' => $panel->base_url,
            'subscription_url_prefix' => $panel->subscription_url_prefix,
            'username' => $panel->username,
            'password' => 'replacement-secret',
            'inbound_id' => $panel->inbound_id,
            'protocol' => $panel->protocol,
            'flow' => $panel->flow,
        ])->assertRedirect();

        $panel->refresh();
        $this->assertNull($panel->connection_status);
        $this->assertNull($panel->connection_diagnostic);
        $this->assertNull($panel->connection_http_status);
        $this->assertNull($panel->connection_checked_at);
        $this->assertSame('replacement-secret', $panel->password);
    }

    public function test_admin_cannot_reassign_a_panel_for_an_active_subscription(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $firstPanel = $this->createPanel('First');
        $secondPanel = $this->createPanel('Second');
        $user = User::factory()->create(['assigned_panel_id' => $firstPanel->id]);
        Subscription::create([
            'user_id' => $user->id,
            'panel_id' => $firstPanel->id,
            'client_uuid' => (string) Str::uuid(),
            'sub_id' => 'ActiveSub123',
            'client_email' => 'active-user@sitevps',
            'expires_at' => now()->addDays(30),
            'traffic_limit_bytes' => 100_000_000_000,
            'connection_limit' => 3,
            'status' => 'active',
        ]);

        $this->actingAs($admin)->put('/admin/users/'.$user->id.'/panel', [
            'assigned_panel_id' => $secondPanel->id,
        ])->assertSessionHasErrors('panel');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'assigned_panel_id' => $firstPanel->id]);
    }

    public function test_checkout_creates_a_yookassa_payment_with_an_idempotency_key(): void
    {
        config([
            'services.yookassa.shop_id' => 'shop-test',
            'services.yookassa.secret_key' => 'secret-test',
            'services.yookassa.receipt_vat_code' => '4',
            'services.yookassa.tax_system_code' => '2',
        ]);

        $tariff = $this->createTariff();
        $user = User::factory()->create();

        Http::fake([
            'https://api.yookassa.ru/v3/payments' => Http::response([
                'id' => 'payment-created',
                'confirmation' => ['confirmation_url' => 'https://pay.example.com/confirm'],
            ]),
        ]);

        $this->actingAs($user)->post('/checkout/'.$tariff->id)
            ->assertRedirect('https://pay.example.com/confirm');

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'tariff_id' => $tariff->id,
            'amount_kopecks' => 1299,
            'payment_id' => 'payment-created',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('payment_events', [
            'provider' => 'yookassa',
            'event_type' => 'payment_request_created',
            'status_to' => 'pending',
        ]);

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.yookassa.ru/v3/payments'
            && $request->hasHeader('Idempotence-Key')
            && data_get($request->data(), 'amount.value') === '12.99'
            && data_get($request->data(), 'amount.currency') === 'RUB'
            && data_get($request->data(), 'receipt.items.0.vat_code') === 4
            && data_get($request->data(), 'receipt.customer.email') === $user->email);
    }

    public function test_yookassa_webhook_verifies_amount_before_marking_order_paid(): void
    {
        config([
            'services.yookassa.shop_id' => 'shop-test',
            'services.yookassa.secret_key' => 'secret-test',
        ]);

        $tariff = $this->createTariff();
        $order = $this->createOrder($tariff, User::factory()->create());

        Http::fake([
            'https://api.yookassa.ru/v3/payments/payment-test' => Http::response([
                'id' => 'payment-test',
                'status' => 'succeeded',
                'amount' => ['value' => '99.00', 'currency' => 'RUB'],
                'metadata' => ['order_reference' => $order->reference],
            ]),
        ]);

        $this->postJson('/webhooks/yookassa', ['object' => ['id' => 'payment-test']])
            ->assertUnprocessable();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending', 'paid_at' => null]);
        $this->assertDatabaseHas('payment_events', [
            'order_id' => $order->id,
            'provider' => 'yookassa',
            'event_type' => 'webhook_rejected',
        ]);
    }

    public function test_yookassa_webhook_marks_verified_payment_and_is_idempotent(): void
    {
        config([
            'services.yookassa.shop_id' => 'shop-test',
            'services.yookassa.secret_key' => 'secret-test',
        ]);

        $tariff = $this->createTariff();
        $order = $this->createOrder($tariff, User::factory()->create());
        $order->update(['amount_kopecks' => 1299]);

        Http::fake([
            'https://api.yookassa.ru/v3/payments/payment-test' => Http::response([
                'id' => 'payment-test',
                'status' => 'succeeded',
                'amount' => ['value' => '12.99', 'currency' => 'RUB'],
                'metadata' => ['order_reference' => $order->reference],
            ]),
        ]);

        $this->postJson('/webhooks/yookassa', ['object' => ['id' => 'payment-test']])->assertOk();
        $paidAt = Order::query()->findOrFail($order->id)->paid_at;

        $this->postJson('/webhooks/yookassa', ['object' => ['id' => 'payment-test']])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid_unassigned',
            'paid_at' => $paidAt,
        ]);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseHas('payment_events', [
            'order_id' => $order->id,
            'provider' => 'yookassa',
            'event_type' => 'webhook_verified',
            'status_to' => 'paid_unassigned',
        ]);
    }

    public function test_failed_provisioning_retry_does_not_extend_subscription_twice(): void
    {
        Carbon::setTestNow('2026-10-29 12:00:00');
        $user = User::factory()->create();
        $panel = Panel::create([
            'name' => 'Panel one',
            'base_url' => 'https://panel.example.com',
            'subscription_url_prefix' => 'https://vpn.example.com:2096/sub',
            'username' => 'admin',
            'password' => 'panel-secret',
            'inbound_id' => 1,
            'protocol' => 'vless',
        ]);
        $user->update(['assigned_panel_id' => $panel->id]);
        $tariff = $this->createTariff();
        $order = $this->createOrder($tariff, $user, 'paid_unassigned');
        $order->update(['paid_at' => now()]);

        $failedClient = Mockery::mock(ThreeXUiClient::class);
        $failedClient->shouldReceive('provision')->once()->andThrow(new PanelApiException('Panel unavailable.'));
        $this->app->instance(ThreeXUiClient::class, $failedClient);

        ProvisionSubscription::dispatchSync($order->id);

        $subscription = Subscription::query()->firstOrFail();
        $originalExpiry = $subscription->expires_at->copy();
        $originalTraffic = $subscription->traffic_limit_bytes;

        $successfulClient = Mockery::mock(ThreeXUiClient::class);
        $successfulClient->shouldReceive('provision')->once();
        $this->app->instance(ThreeXUiClient::class, $successfulClient);

        ProvisionSubscription::dispatchSync($order->id);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'active']);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => 'active',
            'expires_at' => $originalExpiry->toDateTimeString(),
            'traffic_limit_bytes' => $originalTraffic,
        ]);
        $this->assertDatabaseHas('payment_events', [
            'order_id' => $order->id,
            'event_type' => 'provisioning_failed',
            'provider' => 'yookassa',
        ]);
        $this->assertDatabaseHas('payment_events', [
            'order_id' => $order->id,
            'event_type' => 'provisioning_completed',
            'provider' => 'yookassa',
        ]);
        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('https://vpn.example.com:2096/sub/'.$subscription->sub_id);
        $this->assertSame('panel-secret', Panel::query()->findOrFail($panel->id)->password);
        $this->assertNotSame('panel-secret', DB::table('panels')->where('id', $panel->id)->value('password'));
        Carbon::setTestNow();
    }

    public function test_telegram_one_time_link_binds_the_account(): void
    {
        config([
            'services.telegram.bot_token' => 'bot-token',
            'services.telegram.bot_name' => 'vpn_test_bot',
            'services.telegram.webhook_secret' => 'webhook-secret',
        ]);

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $user = User::factory()->create();
        $token = 'oneTimeLinkToken';
        TelegramLinkToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/webhooks/telegram', [
            'message' => [
                'chat' => ['id' => 321],
                'from' => ['id' => 654],
                'text' => '/start '.$token,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret'])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'telegram_user_id' => '654']);
        $this->assertDatabaseHas('telegram_link_tokens', ['user_id' => $user->id, 'used_at' => now()]);
    }

    public function test_telegram_stars_callback_creates_a_real_xtr_invoice_for_the_linked_user(): void
    {
        config([
            'services.telegram.bot_token' => 'bot-token',
            'services.telegram.webhook_secret' => 'webhook-secret',
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        PaymentSettings::current()->update([
            'telegram_stars_enabled' => true,
            'yookassa_enabled' => false,
        ]);
        $user = User::factory()->create(['telegram_user_id' => '654']);
        $tariff = $this->createTariff();

        $this->postJson('/webhooks/telegram', [
            'callback_query' => [
                'id' => 'callback-1',
                'from' => ['id' => 654],
                'message' => ['chat' => ['id' => 321]],
                'data' => 'stars:'.$tariff->id,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret'])->assertOk();

        $order = Order::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('telegram_stars', $order->payment_method);
        $this->assertSame('XTR', $order->payment_currency);
        $this->assertSame(50, $order->payment_amount_units);
        $this->assertSame('pending', $order->status);

        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/sendInvoice')
            && data_get($request->data(), 'currency') === 'XTR'
            && data_get($request->data(), 'provider_token') === ''
            && data_get($request->data(), 'prices.0.amount') === 50
            && data_get($request->data(), 'payload') === $order->reference);
    }

    public function test_telegram_stars_invoice_api_failure_does_not_mark_order_paid(): void
    {
        config([
            'services.telegram.bot_token' => 'bot-token',
            'services.telegram.webhook_secret' => 'webhook-secret',
        ]);
        Http::fake(fn (ClientRequest $request) => str_ends_with($request->url(), '/sendInvoice')
            ? Http::response(['ok' => false], 400)
            : Http::response(['ok' => true]));
        PaymentSettings::current()->update(['telegram_stars_enabled' => true]);
        $user = User::factory()->create(['telegram_user_id' => '654']);
        $tariff = $this->createTariff();

        $this->postJson('/webhooks/telegram', [
            'callback_query' => [
                'id' => 'callback-failed-invoice',
                'from' => ['id' => 654],
                'message' => ['chat' => ['id' => 321]],
                'data' => 'stars:'.$tariff->id,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret'])->assertOk();

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'payment_method' => 'telegram_stars',
            'status' => 'payment_failed',
            'payment_id' => null,
            'paid_at' => null,
        ]);
    }

    public function test_telegram_stars_checks_pre_checkout_and_processes_a_successful_payment_once(): void
    {
        config([
            'services.telegram.bot_token' => 'bot-token',
            'services.telegram.webhook_secret' => 'webhook-secret',
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        PaymentSettings::current()->update(['telegram_stars_enabled' => true]);
        $user = User::factory()->create(['telegram_user_id' => '654']);
        $order = app(CheckoutService::class)->createTelegramStars($user, $this->createTariff());

        $preCheckout = [
            'pre_checkout_query' => [
                'id' => 'pre-checkout-1',
                'from' => ['id' => 654],
                'currency' => 'XTR',
                'total_amount' => 50,
                'invoice_payload' => $order->reference,
            ],
        ];
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret'];

        $this->postJson('/webhooks/telegram', $preCheckout, $headers)->assertOk();
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'telegram_pre_checkout_id' => 'pre-checkout-1',
            'paid_at' => null,
        ]);

        $duplicatePreCheckout = $preCheckout;
        $duplicatePreCheckout['pre_checkout_query']['id'] = 'pre-checkout-2';
        $this->postJson('/webhooks/telegram', $duplicatePreCheckout, $headers)->assertOk();
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'telegram_pre_checkout_id' => 'pre-checkout-1',
            'paid_at' => null,
        ]);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/answerPreCheckoutQuery')
            && data_get($request->data(), 'pre_checkout_query_id') === 'pre-checkout-2'
            && data_get($request->data(), 'ok') === false);

        Queue::fake();
        $successfulUpdate = [
            'message' => [
                'chat' => ['id' => 321],
                'from' => ['id' => 654],
                'successful_payment' => [
                    'currency' => 'XTR',
                    'total_amount' => 50,
                    'invoice_payload' => $order->reference,
                    'telegram_payment_charge_id' => 'charge-123',
                ],
            ],
        ];

        $this->postJson('/webhooks/telegram', $successfulUpdate, $headers)->assertOk();
        $paidAt = Order::query()->findOrFail($order->id)->paid_at;
        $this->postJson('/webhooks/telegram', $successfulUpdate, $headers)->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_id' => 'telegram-stars:charge-123',
            'status' => 'paid_unassigned',
            'paid_at' => $paidAt,
        ]);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseHas('payment_events', [
            'order_id' => $order->id,
            'provider' => 'telegram_stars',
            'event_type' => 'webhook_verified',
            'status_to' => 'paid_unassigned',
        ]);
    }

    public function test_telegram_stars_rejects_mismatched_pre_checkout_without_authorizing_order(): void
    {
        config([
            'services.telegram.bot_token' => 'bot-token',
            'services.telegram.webhook_secret' => 'webhook-secret',
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        PaymentSettings::current()->update(['telegram_stars_enabled' => true]);
        $user = User::factory()->create(['telegram_user_id' => '654']);
        $order = app(CheckoutService::class)->createTelegramStars($user, $this->createTariff());

        $this->postJson('/webhooks/telegram', [
            'pre_checkout_query' => [
                'id' => 'pre-checkout-wrong',
                'from' => ['id' => 999],
                'currency' => 'XTR',
                'total_amount' => 49,
                'invoice_payload' => $order->reference,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret'])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
            'telegram_pre_checkout_id' => null,
            'paid_at' => null,
        ]);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/answerPreCheckoutQuery')
            && data_get($request->data(), 'ok') === false);
    }

    public function test_telegram_stars_does_not_mark_a_success_event_paid_when_amount_does_not_match(): void
    {
        config([
            'services.telegram.bot_token' => 'bot-token',
            'services.telegram.webhook_secret' => 'webhook-secret',
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        PaymentSettings::current()->update(['telegram_stars_enabled' => true]);
        $user = User::factory()->create(['telegram_user_id' => '654']);
        $order = app(CheckoutService::class)->createTelegramStars($user, $this->createTariff());
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret'];

        $this->postJson('/webhooks/telegram', [
            'pre_checkout_query' => [
                'id' => 'pre-checkout-amount-check',
                'from' => ['id' => 654],
                'currency' => 'XTR',
                'total_amount' => 50,
                'invoice_payload' => $order->reference,
            ],
        ], $headers)->assertOk();

        $this->postJson('/webhooks/telegram', [
            'message' => [
                'chat' => ['id' => 321],
                'from' => ['id' => 654],
                'successful_payment' => [
                    'currency' => 'XTR',
                    'total_amount' => 49,
                    'invoice_payload' => $order->reference,
                    'telegram_payment_charge_id' => 'charge-wrong-amount',
                ],
            ],
        ], $headers)->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
            'payment_id' => null,
            'paid_at' => null,
        ]);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains(data_get($request->data(), 'text', ''), 'не удалось сопоставить'));
    }

    public function test_three_x_ui_client_uses_the_configured_inbound_and_client_limits(): void
    {
        $this->fakePanelHttp([
            'https://panel.example.com/login' => Http::response(['success' => true], 200, ['Set-Cookie' => '3x-ui=session; path=/']),
            'https://panel.example.com/panel/api/inbounds/list' => Http::response([
                'success' => true,
                'obj' => [['id' => 1, 'settings' => json_encode(['clients' => []])]],
            ]),
            'https://panel.example.com/panel/api/inbounds/addClient' => Http::response(['success' => true]),
        ]);

        $panel = Panel::create([
            'name' => 'Panel one',
            'base_url' => 'https://panel.example.com',
            'subscription_url_prefix' => 'https://vpn.example.com:2096/sub',
            'username' => 'admin',
            'password' => 'panel-secret',
            'inbound_id' => 1,
            'protocol' => 'vless',
        ]);
        $user = User::factory()->create();
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'panel_id' => $panel->id,
            'client_uuid' => (string) Str::uuid(),
            'sub_id' => 'AbC123SubId',
            'client_email' => 'user-1@sitevps',
            'expires_at' => now()->addDays(30),
            'traffic_limit_bytes' => 100_000_000_000,
            'connection_limit' => 3,
            'status' => 'provisioning',
        ]);

        app(ThreeXUiClient::class)->provision($panel, $subscription);

        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/panel/api/inbounds/addClient')
            && $request->hasHeader('X-CSRF-Token', 'test-csrf')
            && $request->data()['id'] === 1
            && data_get(json_decode($request->data()['settings'], true), 'clients.0.limitIp') === 3
            && data_get(json_decode($request->data()['settings'], true), 'clients.0.totalGB') === 100_000_000_000);
    }

    private function createTariff(): Tariff
    {
        return Tariff::create([
            'name' => 'Monthly',
            'slug' => 'monthly',
            'price_kopecks' => 1299,
            'price_stars' => 50,
            'duration_days' => 30,
            'traffic_gb' => 100,
            'connection_limit' => 3,
            'is_active' => true,
        ]);
    }

    private function fakePanelHttp(array $responses): void
    {
        Http::fake($responses + [
            'https://panel.example.com/csrf-token' => Http::response(
                ['success' => true, 'obj' => 'test-csrf'],
                200,
                ['Set-Cookie' => '3x-ui=session; Path=/; HttpOnly'],
            ),
        ]);
    }

    private function createPanel(string $name, array $attributes = []): Panel
    {
        return Panel::create(array_merge([
            'name' => $name,
            'base_url' => 'https://'.strtolower($name).'.example.com',
            'subscription_url_prefix' => 'https://vpn.example.com:2096/sub',
            'username' => 'admin',
            'password' => 'panel-secret',
            'inbound_id' => 1,
            'protocol' => 'vless',
        ], $attributes));
    }

    private function createOrder(Tariff $tariff, User $user, string $status = 'pending'): Order
    {
        return Order::create([
            'reference' => (string) Str::uuid(),
            'user_id' => $user->id,
            'tariff_id' => $tariff->id,
            'amount_kopecks' => $tariff->price_kopecks,
            'status' => $status,
            'payment_id' => 'payment-test',
        ]);
    }
}
