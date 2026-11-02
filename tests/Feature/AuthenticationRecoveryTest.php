<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_captcha_rejects_wrong_answers_and_never_flashes_sensitive_fields(): void
    {
        $captcha = $this->getCaptcha('/register');
        $response = $this->post('/register', array_merge($this->registrationData(), $captcha, [
            'captcha_answer' => '999999',
        ]))->assertSessionHasErrors('captcha_answer');

        $this->assertArrayNotHasKey('captcha_answer', session()->get('_old_input', []));
        $this->assertArrayNotHasKey('captcha_challenge', session()->get('_old_input', []));
        $this->assertDatabaseCount('users', 0);
        $this->assertFalse(str_contains((string) $response->getContent(), $captcha['captcha_challenge']));
    }

    public function test_local_captcha_challenge_is_bound_to_session_and_csrf_token(): void
    {
        $captcha = $this->getCaptcha('/login');
        $user = User::factory()->create(['email' => 'session@example.test']);
        $this->withSession(['_token' => str_repeat('x', 40)])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
                ...$captcha,
            ])->assertSessionHasErrors('captcha_answer');

        $this->assertGuest();
    }

    public function test_local_captcha_allows_login_and_rejects_replayed_challenge(): void
    {
        $captcha = $this->getCaptcha('/login');
        $user = User::factory()->create(['email' => 'captcha@example.test']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            ...$captcha,
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            ...$captcha,
        ])->assertSessionHasErrors('captcha_answer');
        $this->assertGuest();
    }

    public function test_honeypot_is_rejected(): void
    {
        $captcha = $this->getCaptcha('/register');
        $this->post('/register', array_merge($this->registrationData(), $captcha, [
            'website' => 'bot-filled-this',
        ]))->assertSessionHasErrors('captcha_answer');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_submission_before_minimum_completion_time_is_rejected(): void
    {
        $captcha = $this->getCaptcha('/register', 0);

        $this->post('/register', array_merge($this->registrationData(), $captcha))
            ->assertSessionHasErrors('captcha_answer');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_captcha_expiry_is_enforced(): void
    {
        $captcha = $this->getCaptcha('/register');
        Carbon::setTestNow(now()->addMinutes(6));

        $this->post('/register', array_merge($this->registrationData(), $captcha))
            ->assertSessionHasErrors('captcha_answer');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_auth_forms_use_server_generated_accessible_challenges_without_external_captcha_scripts(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Решите пример:')
            ->assertSee('captcha_answer')
            ->assertDontSee('google.com/recaptcha');
        $this->get('/register')->assertOk()
            ->assertSee('Решите пример:')
            ->assertSee('captcha_answer')
            ->assertDontSee('google.com/recaptcha');
        Http::assertNothingSent();
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

    private function getCaptcha(string $path, int $elapsedSeconds = 3): array
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00'));
        $response = $this->get($path)->assertOk();
        $html = (string) $response->getContent();

        preg_match('/name="captcha_challenge" value="([^"]+)"/', $html, $challengeMatch);
        preg_match('/Решите пример: (\d+) ([+-]) (\d+) = \?/', $html, $questionMatch);
        $this->assertNotEmpty($challengeMatch[1] ?? null);
        $this->assertNotEmpty($questionMatch);
        $stored = session('auth_captcha');
        $this->assertIsArray($stored);
        $this->assertSame(hash('sha256', $challengeMatch[1]), $stored['challenge_hash']);
        $this->assertSame(
            hash('sha256', session()->token()),
            $stored['binding_hash'],
        );
        $this->assertIsInt($stored['issued_at']);
        $this->assertIsInt($stored['expires_at']);

        $left = (int) $questionMatch[1];
        $right = (int) $questionMatch[3];
        $answer = $questionMatch[2] === '+' ? $left + $right : $left - $right;
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00')->addSeconds($elapsedSeconds));

        return [
            'captcha_challenge' => $challengeMatch[1],
            'captcha_answer' => (string) $answer,
        ];
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
    private function registrationData(): array
    {
        return [
            'name' => 'Captcha user',
            'email' => 'captcha@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
