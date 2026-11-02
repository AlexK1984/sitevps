<?php

namespace App\Http\Controllers;

use App\Models\SiteContent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetController extends Controller
{
    public function showRequestForm(): View
    {
        return view('auth.forgot-password', ['siteContent' => SiteContent::current()]);
    }

    public function showResetForm(): View
    {
        $email = request()->query('email');

        return view('auth.reset-password', [
            'siteContent' => SiteContent::current(),
            'token' => old('token', ''),
            'email' => is_string($email) ? $email : '',
        ]);
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $this->normalizeEmail($request);
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($this->safeOldInput($request));
        }

        $email = $validator->validated()['email'];

        if ($this->mailDeliveryConfigured()) {
            try {
                Password::sendResetLink(['email' => $email]);
            } catch (TransportExceptionInterface $exception) {
                DB::table(config('auth.passwords.users.table'))
                    ->where('email', $email)
                    ->delete();

                Log::warning('Password reset email delivery failed.', [
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return back()->with('status', 'Если аккаунт с таким адресом существует и почта настроена, мы отправили ссылку для сброса пароля.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->normalizeEmail($request);
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput(
                $this->safeOldInput($request, includeToken: true),
            );
        }

        $validated = $validator->validated();
        $status = Password::reset(
            $validated,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', 'Пароль изменён. Теперь войдите с новым паролем.');
        }

        return back()->withErrors([
            'email' => 'Ссылка для сброса пароля недействительна или устарела. Запросите новую ссылку.',
        ])->withInput($this->safeOldInput($request));
    }

    private function mailDeliveryConfigured(): bool
    {
        $mailer = config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport");
        $allowedTransports = ['smtp', 'sendmail', 'mailgun', 'ses', 'ses-v2', 'postmark', 'resend'];

        return in_array($transport, $allowedTransports, true)
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }

    private function normalizeEmail(Request $request): void
    {
        $email = $request->input('email');

        if (is_string($email)) {
            $request->merge(['email' => Str::lower(trim($email))]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function safeOldInput(Request $request, bool $includeToken = false): array
    {
        $input = [];

        foreach ($includeToken ? ['email', 'token'] : ['email'] as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $input[$field] = $value;
            }
        }

        return $input;
    }
}
