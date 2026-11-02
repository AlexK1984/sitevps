<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('meta_title', trim($__env->yieldContent('title', 'Личный кабинет')).' — '.$siteContent->site_name)</title>
    @hasSection('meta_description')
        <meta name="description" content="@yield('meta_description')">
        <link rel="canonical" href="@yield('canonical_url')">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $siteContent->site_name }}">
        <meta property="og:title" content="@yield('meta_title')">
        <meta property="og:description" content="@yield('meta_description')">
        <meta property="og:url" content="@yield('canonical_url')">
        @if($siteContent->og_image_url)<meta property="og:image" content="{{ $siteContent->og_image_url }}">@endif
        <meta name="twitter:card" content="{{ $siteContent->og_image_url ? 'summary_large_image' : 'summary' }}">
        <meta name="twitter:title" content="@yield('meta_title')">
        <meta name="twitter:description" content="@yield('meta_description')">
        @if($siteContent->og_image_url)<meta name="twitter:image" content="{{ $siteContent->og_image_url }}">@endif
        <script type="application/ld+json">{!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $siteContent->site_name,
            'url' => trim($__env->yieldContent('canonical_url')),
            'description' => trim($__env->yieldContent('meta_description')),
            'inLanguage' => 'ru-RU',
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endif
    @if(request()->routeIs('admin.*', 'account.*') || request()->is('dashboard', 'login', 'register', 'forgot-password', 'reset-password/*'))
        <meta name="robots" content="noindex,nofollow">
    @endif
    @stack('head')
    <link rel="stylesheet" href="{{ asset('css/site.css') }}">
</head>
<body>
<header class="topbar">
    <a class="brand" href="{{ route('home') }}"><span class="brand-mark">V</span> {{ $siteContent->site_name }}</a>
    <nav>
        @auth
            <a href="{{ route('dashboard') }}">Личный кабинет</a>
            <a href="{{ route('account.security') }}">Безопасность</a>
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

<footer class="footer">{{ $siteContent->footer_text }}</footer>
</body>
</html>
