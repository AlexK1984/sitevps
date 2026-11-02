<?php

namespace App\Http\Controllers;

use App\Models\SiteContent;
use App\Models\User;
use App\Services\DefaultPanelAssigner;
use App\Services\LocalCaptcha;
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
    public function showLogin(Request $request, LocalCaptcha $captcha): View
    {
        return view('auth.login', [
            'siteContent' => SiteContent::current(),
            'captcha' => $captcha->issue($request),
        ]);
    }

    public function showRegister(Request $request, LocalCaptcha $captcha): View
    {
        return view('auth.register', [
            'siteContent' => SiteContent::current(),
            'captcha' => $captcha->issue($request),
        ]);
    }

    public function register(Request $request, LocalCaptcha $captcha, DefaultPanelAssigner $panelAssigner): RedirectResponse
    {
        $this->normalizeEmail($request);
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)],
            'captcha_challenge' => ['required', 'string', 'max:80'],
            'captcha_answer' => ['required', 'string', 'max:10'],
            'website' => ['nullable', 'string', 'max:200'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($this->safeOldInput($request));
        }

        $captchaPassed = $captcha->verify($request, $request->input('captcha_challenge'), $request->input('captcha_answer'));
        if (! $captchaPassed || filled($request->input('website'))) {
            return back()->withErrors(['captcha_answer' => 'Проверка не пройдена. Обновите страницу и попробуйте ещё раз.'])
                ->withInput($this->safeOldInput($request));
        }

        $validated = $validator->validated();
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);
        $panelAssigner->assignIfEligible($user);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function login(Request $request, LocalCaptcha $captcha): RedirectResponse
    {
        $this->normalizeEmail($request);
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'captcha_challenge' => ['required', 'string', 'max:80'],
            'captcha_answer' => ['required', 'string', 'max:10'],
            'website' => ['nullable', 'string', 'max:200'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($this->safeOldInput($request));
        }

        $captchaPassed = $captcha->verify($request, $request->input('captcha_challenge'), $request->input('captcha_answer'));
        if (! $captchaPassed || filled($request->input('website'))) {
            return back()->withErrors(['captcha_answer' => 'Проверка не пройдена. Обновите страницу и попробуйте ещё раз.'])
                ->withInput($this->safeOldInput($request));
        }

        $credentials = $validator->validated();
        unset($credentials['captcha_challenge'], $credentials['captcha_answer'], $credentials['website']);

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
