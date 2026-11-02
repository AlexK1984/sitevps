<?php

namespace Tests\Feature;

use App\Exceptions\PanelApiException;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\Panel;
use App\Models\PaymentSettings;
use App\Models\SiteContent;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use App\Services\ThreeXUiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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
            ->assertSee('Разделы администрирования')
            ->assertSee(route('admin.users'), false)
            ->assertSee('Панель управления')
            ->assertDontSee('panel-secret');

        $this->get(route('admin.users'))
            ->assertOk()
            ->assertSee(route('admin.users.edit', $user), false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('Главная страница')
            ->assertSee('SEO');

        $this->get(route('admin.users.edit', $user))
            ->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSee('name="is_active"', false)
            ->assertSee('name="is_admin"', false)
            ->assertSee('Назначенная панель')
            ->assertSee('Мягко удалить')
            ->assertSee('Заблокировать пользователя')
            ->assertDontSee('name="password"', false)
            ->assertDontSee('name="telegram_user_id"', false);
    }

    public function test_admin_sections_have_distinct_routes_and_a_shared_active_sidebar(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $routes = [
            'admin.tariffs' => 'Тарифы',
            'admin.panels' => 'Панели 3x-ui',
            'admin.orders' => 'Заказы и платежи',
            'admin.payment-events' => 'Журнал платёжных событий',
            'admin.payment-settings' => 'Настройки оплат',
            'admin.test-payments' => 'Тестовые оплаты',
            'admin.mail-settings' => 'Настройки SMTP',
            'admin.site' => 'Главная страница',
            'admin.seo' => 'SEO и социальная информация',
            'admin.system' => 'Аудит изменений',
        ];

        $this->actingAs($admin);
        foreach ($routes as $route => $heading) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee($heading)
                ->assertSee('aria-current="page"', false)
                ->assertSee('aria-label="Разделы администрирования"', false);
        }

    }

    public function test_admin_can_request_a_private_one_time_password_reset_email_for_a_user(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['email' => 'reset-target@example.test']);
        PaymentSettings::current()->update([
            'smtp_enabled' => true,
            'smtp_host' => 'smtp.admin.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_from_address' => 'reset@example.test',
            'smtp_from_name' => 'VPN service',
        ]);

        $this->actingAs($admin)->get(route('admin.users.edit', $user))
            ->assertOk()
            ->assertSee(route('admin.users.password-reset', $user), false)
            ->assertDontSee('name="password"', false)
            ->assertDontSee('name="current_password"', false);

        $response = $this->post(route('admin.users.password-reset', $user))
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($user, PasswordResetNotification::class);
        $notification = Notification::sent($user, PasswordResetNotification::class)->first();
        $token = (string) parse_url($notification->toMail($user)->actionUrl, PHP_URL_FRAGMENT);
        $this->assertNotSame('', $token);
        $this->assertStringNotContainsString($token, (string) $response->getContent());
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'subject_user_id' => $user->id,
            'action' => 'user_password_reset_requested',
        ]);
        $audit = AdminAuditLog::query()->where('action', 'user_password_reset_requested')->firstOrFail();
        $this->assertStringNotContainsString($token, $audit->summary);
        $this->assertSame('Сброс пароля', $notification->toMail($user)->subject);
    }

    public function test_admin_password_reset_requires_configured_mail_and_does_not_store_a_token(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.users.password-reset', $user))
            ->assertRedirect()
            ->assertSessionHasErrors('password_reset');

        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_admin_can_update_seo_from_its_dedicated_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $content = SiteContent::defaults();
        $content['site_name'] = 'Новое название';
        $content['meta_title'] = 'Обновлённый title';
        $content['meta_description'] = 'Обновлённое описание страницы.';
        $content['canonical_url'] = 'https://vpn.example.test/';
        $content['og_image_url'] = 'https://vpn.example.test/social.png';

        $this->actingAs($admin)->get(route('admin.seo'))
            ->assertOk()
            ->assertSee('action="'.route('admin.seo.update').'"', false)
            ->assertSee('name="meta_title"', false);

        $this->put(route('admin.seo.update'), $content)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('site_contents', [
            'id' => 1,
            'site_name' => 'Новое название',
            'meta_title' => 'Обновлённый title',
            'canonical_url' => 'https://vpn.example.test',
            'og_image_url' => 'https://vpn.example.test/social.png',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'seo_settings_updated',
        ]);
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
        $client->shouldReceive('setEnabled')->once()->withArgs(function (Panel $sentPanel, Subscription $sentSubscription, bool $enabled) use ($panel, $subscription, $newExpiry): bool {
            return $sentPanel->is($panel)
                && $sentSubscription->is($subscription)
                && $enabled
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

        $this->post(route('admin.users.ban', $admin))->assertSessionHasErrors('user');
        $this->delete(route('admin.users.delete', $admin))->assertSessionHasErrors('user');
        $this->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'is_admin' => '1',
            'is_active' => '0',
            'assigned_panel_id' => '',
        ])->assertSessionHasErrors('is_active');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'is_admin' => true,
            'is_active' => true,
        ]);
    }

    public function test_non_admin_cannot_ban_or_soft_delete_users(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($user)->post(route('admin.users.ban', $target))->assertForbidden();
        $this->delete(route('admin.users.delete', $target))->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
    }

    public function test_panel_failure_during_ban_is_reported_and_audited_without_reactivating_account(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel();
        $user = User::factory()->create(['assigned_panel_id' => $panel->id]);
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'panel_id' => $panel->id,
            'client_uuid' => (string) Str::uuid(),
            'sub_id' => 'FailedBanSub123',
            'client_email' => 'failed-ban@sitevps',
            'expires_at' => now()->addDays(30),
            'traffic_limit_bytes' => 100_000_000_000,
            'connection_limit' => 2,
            'status' => 'active',
        ]);
        $client = Mockery::mock(ThreeXUiClient::class);
        $client->shouldReceive('setEnabled')->once()->withArgs(
            fn (Panel $sentPanel, Subscription $sentSubscription, bool $enabled): bool => $sentPanel->is($panel)
                && $sentSubscription->is($subscription)
                && ! $enabled,
        )->andThrow(new PanelApiException('Panel unavailable.'));
        $this->app->instance(ThreeXUiClient::class, $client);

        $this->actingAs($admin)->post(route('admin.users.ban', $user))
            ->assertRedirect()
            ->assertSessionHas('status', 'Учётная запись отключена, но VPN-клиент не удалось заблокировать на панели. Проверьте ошибку подписки.');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id, 'last_error' => 'Panel unavailable.']);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'subject_user_id' => $user->id,
            'action' => 'user_access_sync_failed',
        ]);
    }

    public function test_profile_status_deactivation_also_disables_the_panel_client(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel();
        $user = User::factory()->create(['assigned_panel_id' => $panel->id]);
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'panel_id' => $panel->id,
            'client_uuid' => (string) Str::uuid(),
            'sub_id' => 'StatusFieldSub123',
            'client_email' => 'status-field@sitevps',
            'expires_at' => now()->addDays(30),
            'traffic_limit_bytes' => 100_000_000_000,
            'connection_limit' => 2,
            'status' => 'active',
        ]);
        $client = Mockery::mock(ThreeXUiClient::class);
        $client->shouldReceive('setEnabled')->once()->withArgs(
            fn (Panel $sentPanel, Subscription $sentSubscription, bool $enabled): bool => $sentPanel->is($panel)
                && $sentSubscription->is($subscription)
                && ! $enabled,
        );
        $this->app->instance(ThreeXUiClient::class, $client);

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => '0',
            'is_active' => '0',
            'assigned_panel_id' => (string) $panel->id,
        ])->assertRedirect(route('admin.users.edit', $user));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'subject_user_id' => $user->id,
            'action' => 'user_updated',
        ]);
    }

    public function test_admin_can_ban_soft_delete_and_restore_user_with_audit_and_preserved_history(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $panel = $this->createPanel();
        $user = User::factory()->create(['assigned_panel_id' => $panel->id]);
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'panel_id' => $panel->id,
            'client_uuid' => (string) Str::uuid(),
            'sub_id' => 'BanAccess123',
            'client_email' => 'ban-user@sitevps',
            'expires_at' => now()->addDays(30),
            'traffic_limit_bytes' => 100_000_000_000,
            'connection_limit' => 2,
            'status' => 'active',
        ]);
        $tariff = Tariff::query()->create([
            'name' => 'History tariff',
            'slug' => 'history-tariff',
            'price_kopecks' => 1299,
            'duration_days' => 30,
            'traffic_gb' => 100,
            'connection_limit' => 2,
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'reference' => (string) Str::uuid(),
            'user_id' => $user->id,
            'tariff_id' => $tariff->id,
            'amount_kopecks' => 1299,
            'status' => 'active',
        ]);

        $client = Mockery::mock(ThreeXUiClient::class);
        $client->shouldReceive('setEnabled')->times(3)->withArgs(
            fn (Panel $sentPanel, Subscription $sentSubscription, bool $enabled): bool => $sentPanel->is($panel)
                && $sentSubscription->is($subscription)
                && $enabled === (bool) ($sentSubscription->user->fresh()->is_active),
        );
        $this->app->instance(ThreeXUiClient::class, $client);

        $this->actingAs($admin)->post(route('admin.users.ban', $user))
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);

        $this->delete(route('admin.users.delete', $user))
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->post(route('admin.users.restore', $user))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'subject_user_id' => $user->id, 'action' => 'user_banned']);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'subject_user_id' => $user->id, 'action' => 'user_soft_deleted']);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'subject_user_id' => $user->id, 'action' => 'user_unbanned']);
    }

    public function test_disabled_account_cannot_log_in_or_continue_using_an_existing_session(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $captcha = $this->localCaptchaFields('/login');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            ...$captcha,
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

    public function test_admin_can_update_homepage_text_without_changing_seo_and_text_is_rendered_escaped(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $seoDefaults = SiteContent::defaults();
        $content = $seoDefaults;
        $content['site_name'] = 'VPN пример';
        $content['meta_title'] = 'VPN-доступ и тарифы | VPN пример';
        $content['meta_description'] = 'Описание тарифа и порядка оформления VPN-подписки.';
        $content['canonical_url'] = 'https://vpn.example.test/';
        $content['hero_title'] = 'Новые условия подписки';

        $this->actingAs($admin)->get(route('admin.site'))
            ->assertOk()
            ->assertDontSee('name="meta_title"', false)
            ->assertDontSee('name="canonical_url"', false);

        $this->actingAs($admin)->put(route('admin.home-content.update'), $content)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('site_contents', [
            'id' => 1,
            'hero_title' => 'Новые условия подписки',
        ]);
        $this->assertDatabaseHas('site_contents', [
            'id' => 1,
            'site_name' => $seoDefaults['site_name'],
            'meta_title' => $seoDefaults['meta_title'],
            'meta_description' => $seoDefaults['meta_description'],
            'canonical_url' => $seoDefaults['canonical_url'],
        ]);

        $this->get('/')
            ->assertOk()
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

    /**
     * @return array{captcha_challenge: string, captcha_answer: string}
     */
    private function localCaptchaFields(string $path): array
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00'));
        $html = (string) $this->get($path)->assertOk()->getContent();
        preg_match('/name="captcha_challenge" value="([^"]+)"/', $html, $challengeMatch);
        preg_match('/Решите пример: (\d+) ([+-]) (\d+) = \?/', $html, $questionMatch);
        $this->assertNotEmpty($challengeMatch[1] ?? null);
        $this->assertNotEmpty($questionMatch);

        $left = (int) $questionMatch[1];
        $right = (int) $questionMatch[3];
        $answer = $questionMatch[2] === '+' ? $left + $right : $left - $right;
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:03'));

        return [
            'captcha_challenge' => $challengeMatch[1],
            'captcha_answer' => (string) $answer,
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
