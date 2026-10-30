<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'VPN подписки') — VPN</title>
    <link rel="stylesheet" href="{{ asset('css/site.css') }}">
</head>
<body>
<header class="topbar">
    <a class="brand" href="{{ route('home') }}"><span class="brand-mark">V</span> vpshield</a>
    <nav>
        @auth
            <a href="{{ route('dashboard') }}">Личный кабинет</a>
            @if(auth()->user()->is_admin)
                <a href="{{ route('admin.index') }}">Администрирование</a>
            @endif
            <form method="post" action="{{ route('logout') }}" class="inline-form">
                @csrf
                <button class="nav-button" type="submit">Выйти</button>
            </form>
        @else
            <a href="{{ route('login') }}">Войти</a>
            <a class="button button-small" href="{{ route('register') }}">Создать аккаунт</a>
        @endauth
    </nav>
</header>

<main class="page">
    @if(session('status'))
        <div class="notice notice-success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="notice notice-error">
            <strong>Не удалось выполнить действие.</strong>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @yield('content')
</main>

<footer class="footer">Безопасное подключение. Понятные условия. Поддержка через личный кабинет.</footer>
</body>
</html>
