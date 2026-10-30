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
use App\Services\ThreeXUiClient;
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
        Http::fake([
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

    private function createPanel(string $name): Panel
    {
        return Panel::create([
            'name' => $name,
            'base_url' => 'https://'.strtolower($name).'.example.com',
            'subscription_url_prefix' => 'https://vpn.example.com:2096/sub',
            'username' => 'admin',
            'password' => 'panel-secret',
            'inbound_id' => 1,
            'protocol' => 'vless',
        ]);
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
