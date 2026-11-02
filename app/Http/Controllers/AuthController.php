<?php

namespace App\Http\Controllers;

use App\Models\SiteContent;
use App\Models\User;
use App\Services\RecaptchaVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login', ['siteContent' => SiteContent::current()]);
    }

    public function showRegister(): View
    {
        return view('auth.register', ['siteContent' => SiteContent::current()]);
    }

    public function register(Request $request, RecaptchaVerifier $captcha): RedirectResponse
    {
        $this->normalizeEmail($request);
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)],
            'g-recaptcha-response' => ['required', 'string', 'max:4096'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($this->safeOldInput($request));
        }

        if (! $captcha->verify($request->input('g-recaptcha-response'), $request->ip())) {
            return back()->withErrors(['g-recaptcha-response' => 'Подтвердите проверку CAPTCHA и попробуйте снова.'])
                ->withInput($this->safeOldInput($request));
        }

        $validated = $validator->validated();
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function login(Request $request, RecaptchaVerifier $captcha): RedirectResponse
    {
        $this->normalizeEmail($request);
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'g-recaptcha-response' => ['required', 'string', 'max:4096'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($this->safeOldInput($request));
        }

        if (! $captcha->verify($request->input('g-recaptcha-response'), $request->ip())) {
            return back()->withErrors(['g-recaptcha-response' => 'Подтвердите проверку CAPTCHA и попробуйте снова.'])
                ->withInput($this->safeOldInput($request));
        }

        $credentials = $validator->validated();
        unset($credentials['g-recaptcha-response']);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Неверный адрес почты или пароль.'])->onlyInput('email');
        }

        if (! $request->user()->is_active) {
            Auth::logout();

            return back()->withErrors(['email' => 'Учетная запись отключена. Обратитесь к администратору.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function normalizeEmail(Request $request): void
    {
        $email = $request->input('email');

        if (is_string($email)) {
            $request->merge(['email' => Str::lower(trim($email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function safeOldInput(Request $request): array
    {
        $input = [];

        foreach (['name', 'email'] as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $input[$field] = $value;
            }
        }

        return $input;
    }
}
