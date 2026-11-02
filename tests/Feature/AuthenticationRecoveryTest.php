<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recaptcha_rejects_missing_or_wrong_host_tokens_without_flashing_them(): void
    {
        $this->configureRecaptcha();
        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'hostname' => 'attacker.example',
            ]),
        ]);

        $this->post('/register', $this->registrationData())
            ->assertSessionHasErrors('g-recaptcha-response');

        $response = $this->post('/register', $this->registrationData('forged-token'))
            ->assertSessionHasErrors('g-recaptcha-response');

        $this->assertArrayNotHasKey('g-recaptcha-response', session()->get('_old_input', []));
        $this->assertDatabaseCount('users', 0);
        Http::assertSentCount(1);
        $this->assertFalse(str_contains($response->getSession()->get('_old_input.email', ''), 'forged-token'));
    }

    public function test_recaptcha_fails_closed_when_server_secret_is_missing(): void
    {
        config(['services.recaptcha.secret_key' => null]);
        Http::fake();

        $this->post('/login', [
            'email' => 'user@example.test',
            'password' => 'password',
            'g-recaptcha-response' => 'client-supplied-token',
        ])->assertSessionHasErrors('g-recaptcha-response');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_successful_recaptcha_allows_auth_and_google_rejects_replayed_tokens(): void
    {
        $this->configureRecaptcha();
        Http::fakeSequence('https://www.google.com/recaptcha/api/siteverify')
            ->push(['success' => true, 'hostname' => 'vpn.example.test'])
            ->push(['success' => true, 'hostname' => 'vpn.example.test'])
            ->push(['success' => true, 'hostname' => 'vpn.example.test']);

        $this->post('/register', $this->registrationData('registration-token'))
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->url() === 'https://www.google.com/recaptcha/api/siteverify'
                && $request['secret'] === 'test-secret'
                && $request['response'] === 'registration-token'
                && is_string($request['remoteip']);
        });

        $this->post('/logout')->assertRedirect(route('home'));
        $user = User::query()->where('email', 'captcha@example.test')->firstOrFail();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
            'g-recaptcha-response' => 'single-use-token',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
            'g-recaptcha-response' => 'single-use-token',
        ])->assertSessionHasErrors('g-recaptcha-response');
        $this->assertGuest();
        Http::assertSentCount(3);
    }

    public function test_password_reset_response_does_not_disclose_account_existence_and_token_is_one_time(): void
    {
        $this->configureSmtpForTesting();
        Notification::fake();
        $user = User::factory()->create(['email' => 'member@example.test']);
        $message = 'Если аккаунт с таким адресом существует и почта настроена, мы отправили ссылку для сброса пароля.';

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', $message);
        $this->post(route('password.email'), ['email' => 'unknown@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status', $message);

        Notification::assertSentTo($user, PasswordResetNotification::class);
        Notification::assertCount(1);
        $notification = Notification::sent($user, PasswordResetNotification::class)->first();
        $mail = $notification->toMail($user);
        $this->assertSame('Сброс пароля', $mail->subject);
        $this->assertStringContainsString('60 минут', implode(' ', [...$mail->introLines, ...$mail->outroLines]));

        $token = (string) parse_url($mail->actionUrl, PHP_URL_FRAGMENT);
        $this->assertNotSame('', $token);
        $this->assertStringNotContainsString($token, (string) parse_url($mail->actionUrl, PHP_URL_PATH));
        $this->assertStringNotContainsString($token, (string) parse_url($mail->actionUrl, PHP_URL_QUERY));
        $this->assertStringNotContainsString($token, (string) $this->post(route('password.email'), [
            'email' => 'unknown@example.test',
        ])->getContent());
        $this->get($mail->actionUrl)->assertOk()->assertSee('Задать новый пароль');

        $resetData = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-correct-horse',
            'password_confirmation' => 'new-correct-horse',
        ];
        $this->post(route('password.update'), $resetData)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Пароль изменён. Теперь войдите с новым паролем.');
        $this->assertTrue(Hash::check('new-correct-horse', $user->fresh()->password));

        $this->post(route('password.update'), $resetData)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('new-correct-horse', $user->fresh()->password));
    }

    public function test_password_reset_tokens_expire_after_sixty_minutes(): void
    {
        $this->configureSmtpForTesting();
        Notification::fake();
        $user = User::factory()->create(['email' => 'expiry@example.test']);

        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
        Notification::assertCount(1);
        $notification = Notification::sent($user, PasswordResetNotification::class)->first();
        $expiredToken = (string) parse_url($notification->toMail($user)->actionUrl, PHP_URL_FRAGMENT);

        DB::table('password_reset_tokens')->where('email', $user->email)->update([
            'created_at' => Carbon::now()->subMinutes(61),
        ]);

        $this->post(route('password.update'), [
            'token' => $expiredToken,
            'email' => $user->email,
            'password' => 'another-correct-pass',
            'password_confirmation' => 'another-correct-pass',
        ])->assertSessionHasErrors('email');
    }

    public function test_password_reset_requests_are_rate_limited_by_ip(): void
    {
        config([
            'mail.default' => 'log',
            'mail.from.address' => 'no-reply@example.test',
        ]);

        $this->post(route('password.email'), ['email' => 'first@example.test'])->assertSessionHas('status');
        $this->post(route('password.email'), ['email' => 'second@example.test'])->assertSessionHas('status');
        $this->post(route('password.email'), ['email' => 'third@example.test'])->assertSessionHas('status');
        $this->post(route('password.email'), ['email' => 'fourth@example.test'])->assertTooManyRequests();
    }

    public function test_password_reset_does_not_create_or_log_tokens_when_mail_is_not_configured(): void
    {
        config([
            'mail.default' => 'log',
            'mail.from.address' => 'no-reply@example.test',
        ]);
        User::factory()->create(['email' => 'mail-disabled@example.test']);

        $this->post(route('password.email'), ['email' => 'mail-disabled@example.test'])
            ->assertSessionHas('status', 'Если аккаунт с таким адресом существует и почта настроена, мы отправили ссылку для сброса пароля.');

        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    private function configureRecaptcha(): void
    {
        config([
            'app.url' => 'https://vpn.example.test',
            'services.recaptcha.site_key' => 'test-site-key',
            'services.recaptcha.secret_key' => 'test-secret',
            'services.recaptcha.hostname' => 'vpn.example.test',
        ]);
    }

    private function configureSmtpForTesting(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.from.address' => 'no-reply@example.test',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function registrationData(string $captchaToken = ''): array
    {
        return [
            'name' => 'Captcha user',
            'email' => 'captcha@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'g-recaptcha-response' => $captchaToken,
        ];
    }
}
