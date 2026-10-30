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
        <button class="button button-full" type="submit">Войти <span aria-hidden="true">→</span></button>
        <div class="auth-switch">Нет аккаунта? <a href="{{ route('register') }}">Зарегистрироваться</a></div>
    </form>
</section>
@endsection
