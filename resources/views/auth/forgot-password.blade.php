@extends('layouts.app')

@section('title', 'Восстановление пароля')

@section('content')
<section class="auth-wrap">
    <form class="auth-card" method="post" action="{{ route('password.email') }}">
        @csrf
        <span class="eyebrow">ВОССТАНОВЛЕНИЕ ДОСТУПА</span>
        <h1>Сбросить пароль</h1>
        <p>Укажите адрес аккаунта. Если он существует и почта настроена, мы отправим ссылку для сброса пароля.</p>
        <label>Электронная почта<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus></label>
        <button class="button button-full" type="submit">Отправить ссылку</button>
        <div class="auth-switch"><a href="{{ route('login') }}">Вернуться ко входу</a></div>
    </form>
</section>
@endsection
