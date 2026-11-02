@extends('layouts.app')

@section('title', 'Вход')

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
        <label>{{ $captcha['question'] }}<input type="text" name="captcha_answer" inputmode="numeric" pattern="[0-9]+" required autocomplete="off" aria-describedby="captcha-help"></label>
        <input type="hidden" name="captcha_challenge" value="{{ $captcha['challenge'] }}">
        <p id="captcha-help" class="captcha-info">Проверка выполняется на сервере. Обновите страницу, чтобы получить новый пример.</p>
        <label class="captcha-honeypot" aria-hidden="true">Не заполняйте это поле<input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>
        <button class="button button-full" type="submit">Войти <span aria-hidden="true">→</span></button>
        <div class="auth-switch"><a href="{{ route('password.request') }}">Забыли пароль?</a></div>
        <div class="auth-switch">Нет аккаунта? <a href="{{ route('register') }}">Зарегистрироваться</a></div>
    </form>
</section>
@endsection
