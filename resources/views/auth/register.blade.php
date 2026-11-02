@extends('layouts.app')

@section('title', 'Регистрация')

@if(config('services.recaptcha.site_key'))
    @push('head')
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endpush
@endif

@section('content')
<section class="auth-wrap">
    <form class="auth-card" method="post" action="{{ route('register') }}">
        @csrf
        <span class="eyebrow">НАЧНИТЕ СЕЙЧАС</span>
        <h1>Создать аккаунт</h1>
        <p>Регистрация займёт всего минуту.</p>
        <label>Имя<input type="text" name="name" value="{{ old('name') }}" required autocomplete="name" maxlength="120"></label>
        <label>Электронная почта<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
        <label>Пароль<input type="password" name="password" required autocomplete="new-password" minlength="10"></label>
        <label>Повторите пароль<input type="password" name="password_confirmation" required autocomplete="new-password"></label>
        @if(config('services.recaptcha.site_key'))
            <div class="captcha-wrap"><div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div></div>
            <p class="captcha-info">Для проверки загружается reCAPTCHA Google; Google получает данные, необходимые для проверки, включая IP-адрес.</p>
        @else
            <p class="captcha-unconfigured">Защита CAPTCHA пока не настроена администратором.</p>
        @endif
        <button class="button button-full" type="submit">Зарегистрироваться <span aria-hidden="true">→</span></button>
        <div class="auth-switch">Уже есть аккаунт? <a href="{{ route('login') }}">Войти</a></div>
    </form>
</section>
@endsection
