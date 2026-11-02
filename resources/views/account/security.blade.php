@extends('layouts.app')

@section('title', 'Безопасность аккаунта')

@section('content')
<section class="dashboard-head">
    <div><span class="eyebrow">АККАУНТ</span><h1>Безопасность и пароль</h1><p>Для смены пароля подтвердите текущий пароль. Изменение защищено CSRF и ограничением частоты запросов.</p></div>
    <a class="button button-outline" href="{{ route('dashboard') }}">В личный кабинет</a>
</section>

<form class="panel-card admin-form" method="post" action="{{ route('account.password.update') }}" autocomplete="off">
    @csrf @method('PUT')
    <label>Текущий пароль<input type="password" name="current_password" required autocomplete="current-password"></label>
    <label>Новый пароль<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
    <label>Повторите новый пароль<input type="password" name="password_confirmation" required minlength="10" autocomplete="new-password"></label>
    <button class="button button-full" type="submit">Изменить пароль</button>
</form>
<p class="muted">Если текущий пароль забыт, выйдите из аккаунта и запросите ссылку для сброса «Забыли пароль?» на странице входа.</p>
@endsection
