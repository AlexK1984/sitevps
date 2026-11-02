@extends('layouts.app')

@section('title', 'Вход')

@if(config('services.recaptcha.site_key'))
    @push('head')
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endpush
@endif

@section('content')
<section class="auth-wrap">
    <form class="auth-card" method="post" action="{{ route('login') }}">
        @csrf
        <span class="eyebrow">С ВОЗВРАЩЕНИЕМ</span>
        <h1>Войти в аккаунт</h1>
        <p>Управляйте подпиской и платежами в одном месте.</p>
        <label>Электронная почта<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus></label>
        <label>Пароль<input type="password" name="password" required autocomplete="current-password"></label>
        <label class="checkbox"><input type="checkbox" name="remember" value="1"> Запомнить меня</label>
        @if(config('services.recaptcha.site_key'))
            <div class="captcha-wrap"><div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div></div>
            <p class="captcha-info">Для проверки загружается reCAPTCHA Google; Google получает данные, необходимые для проверки, включая IP-адрес.</p>
        @else
            <p class="captcha-unconfigured">Защита CAPTCHA пока не настроена администратором.</p>
        @endif
        <button class="button button-full" type="submit">Войти <span aria-hidden="true">→</span></button>
        <div class="auth-switch"><a href="{{ route('password.request') }}">Забыли пароль?</a></div>
        <div class="auth-switch">Нет аккаунта? <a href="{{ route('register') }}">Зарегистрироваться</a></div>
    </form>
</section>
@endsection
