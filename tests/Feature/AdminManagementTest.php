<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\SiteContent;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use App\Services\ThreeXUiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_menu_and_user_editor_hide_password_fields_and_show_editable_account_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('Меню администрирования')
            ->assertSee('Главная и SEO')
            ->assertSee(route('admin.users.edit', $user))
            ->assertDontSee('panel-secret');

        $this->get(route('admin.users.edit', $user))
            ->assertOk()
            ->assertSee('name="is_active"', false)
            ->assertSee('name="is_admin"', false)
            ->assertSee('Назначенная панель')
            ->assertDontSee('name="password"', false)
            ->assertDontSee('name="telegram_user_id"', false);
    }

    public function test_admin_can_update_user_profile_panel_and_subscription_with_audit_and_panel_sync(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel();
        $tariff = Tariff::query()->create([
            'name' => 'Monthly',
            'slug' => 'monthly',
            'description' => 'Monthly plan',
            'price_kopecks' => 1299,
            'duration_days' => 30,
            'traffic_gb' => 100,
            'connection_limit' => 2,
            'is_active' => true,
        ]);
        $user = User::factory()->create(['assigned_panel_id' => $panel->id]);
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'tariff_id' => $tariff->id,
            'panel_id' => $panel->id,
            'client_uuid' => (string) Str::uuid(),
            'sub_id' => 'AdminEditSub123',
            'client_email' => 'admin-edit@sitevps',
            'expires_at' => now()->addDays(30),
            'traffic_limit_bytes' => 100_000_000_000,
            'connection_limit' => 2,
            'status' => 'active',
        ]);
        $newExpiry = now()->addDays(60)->startOfMinute();
        $client = Mockery::mock(ThreeXUiClient::class);
        $client->shouldReceive('provision')->once()->withArgs(function (Panel $sentPanel, Subscription $sentSubscription) use ($panel, $subscription, $newExpiry): bool {
            return $sentPanel->is($panel)
                && $sentSubscription->is($subscription)
                && $sentSubscription->traffic_limit_bytes === 200_000_000_000
                && $sentSubscription->connection_limit === 4
                && $sentSubscription->expires_at->equalTo($newExpiry);
        });
        $this->app->instance(ThreeXUiClient::class, $client);

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'name' => 'Updated customer',
            'email' => 'UPDATED@example.com',
            'is_admin' => '0',
            'is_active' => '1',
            'assigned_panel_id' => (string) $panel->id,
            'subscription' => [
                'tariff_id' => (string) $tariff->id,
                'expires_at' => $newExpiry->format('Y-m-d\TH:i'),
                'traffic_limit_gb' => '200',
                'connection_limit' => '4',
            ],
        ])->assertRedirect(route('admin.users.edit', $user));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated customer',
            'email' => 'updated@example.com',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'traffic_limit_bytes' => 200_000_000_000,
            'connection_limit' => 4,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'subject_user_id' => $user->id,
            'action' => 'user_updated',
        ]);
    }

    public function test_admin_cannot_disable_or_demote_the_last_active_administrator(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);

        $this->actingAs($admin)->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'is_admin' => '0',
            'is_active' => '1',
            'assigned_panel_id' => '',
        ])->assertSessionHasErrors('is_admin');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'is_admin' => true,
            'is_active' => true,
        ]);
    }

    public function test_disabled_account_cannot_log_in_or_continue_using_an_existing_session(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        config([
            'services.recaptcha.secret_key' => 'test-secret',
            'services.recaptcha.site_key' => 'test-site-key',
        ]);
        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'hostname' => 'localhost',
            ]),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'g-recaptcha-response' => 'valid-test-token',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_disabled_account_cannot_start_a_new_telegram_purchase(): void
    {
        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.webhook_secret' => 'test-webhook-secret',
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        User::factory()->create(['telegram_user_id' => '654', 'is_active' => false]);

        $this->postJson('/webhooks/telegram', [
            'message' => [
                'chat' => ['id' => 321],
                'from' => ['id' => 654],
                'text' => '/plans',
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'])->assertOk();

        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains(data_get($request->data(), 'text', ''), 'Учётная запись отключена'));
    }

    public function test_admin_can_update_homepage_text_and_seo_and_text_is_rendered_escaped(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $content = SiteContent::defaults();
        $content['site_name'] = 'VPN пример';
        $content['meta_title'] = 'VPN-доступ и тарифы | VPN пример';
        $content['meta_description'] = 'Описание тарифа и порядка оформления VPN-подписки.';
        $content['canonical_url'] = 'https://vpn.example.test/';
        $content['hero_title'] = 'Новые условия подписки';

        $this->actingAs($admin)->put(route('admin.home-content.update'), $content)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('site_contents', [
            'id' => 1,
            'site_name' => 'VPN пример',
            'meta_title' => 'VPN-доступ и тарифы | VPN пример',
            'canonical_url' => 'https://vpn.example.test',
            'hero_title' => 'Новые условия подписки',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>VPN-доступ и тарифы | VPN пример</title>', false)
            ->assertSee('name="description" content="Описание тарифа и порядка оформления VPN-подписки."', false)
            ->assertSee('rel="canonical" href="https://vpn.example.test/"', false)
            ->assertSee('property="og:type" content="website"', false)
            ->assertSee('Новые условия подписки')
            ->assertSee('https://schema.org');

        $content['hero_title'] = '<script>alert(1)</script>';
        $this->actingAs($admin)->put(route('admin.home-content.update'), $content)
            ->assertSessionHasErrors('hero_title');

        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'homepage_content_updated',
        ]);
    }

    public function test_robots_and_sitemap_publish_only_the_public_homepage(): void
    {
        config(['app.url' => 'https://vpn.example.test']);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /admin')
            ->assertSee('Disallow: /dashboard')
            ->assertSee('Sitemap: https://vpn.example.test/sitemap.xml');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>https://vpn.example.test/</loc>', false)
            ->assertDontSee('/admin')
            ->assertDontSee('/dashboard');
    }

    private function createPanel(): Panel
    {
        return Panel::query()->create([
            'name' => 'Panel one',
            'base_url' => 'https://panel.example.test',
            'subscription_url_prefix' => 'https://vpn.example.test/sub',
            'username' => 'panel-admin',
            'password' => 'panel-secret',
        ]);
    }
}
