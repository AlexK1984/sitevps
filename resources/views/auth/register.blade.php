@extends('layouts.app')

@section('title', 'Регистрация')

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
        <button class="button button-full" type="submit">Зарегистрироваться <span aria-hidden="true">→</span></button>
        <div class="auth-switch">Уже есть аккаунт? <a href="{{ route('login') }}">Войти</a></div>
    </form>
</section>
@endsection
