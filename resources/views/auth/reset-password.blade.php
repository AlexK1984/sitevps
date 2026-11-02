@extends('layouts.app')

@section('title', 'Новый пароль')

@section('content')
<section class="auth-wrap">
    <form class="auth-card" method="post" action="{{ route('password.update') }}">
        @csrf
        <span class="eyebrow">ВОССТАНОВЛЕНИЕ ДОСТУПА</span>
        <h1>Задать новый пароль</h1>
        <p>Придумайте новый пароль длиной не менее 10 символов.</p>
        <input type="hidden" name="token" id="reset-token" value="{{ $token }}">
        <label>Электронная почта<input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email"></label>
        <label>Новый пароль<input type="password" name="password" required autocomplete="new-password" minlength="10"></label>
        <label>Повторите пароль<input type="password" name="password_confirmation" required autocomplete="new-password"></label>
        <button class="button button-full" type="submit">Сохранить пароль</button>
        <div class="auth-switch"><a href="{{ route('login') }}">Вернуться ко входу</a></div>
    </form>
</section>
<script>
    const resetToken = document.getElementById('reset-token');
    if (resetToken && !resetToken.value && window.location.hash.length > 1) {
        resetToken.value = window.location.hash.slice(1);
    }
</script>
@endsection
