@extends('layouts.app')

@section('content')
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="admin-sidebar-brand" href="{{ route('admin.index') }}">
            <span class="eyebrow">УПРАВЛЕНИЕ СЕРВИСОМ</span>
            <strong>Администрирование</strong>
        </a>
        <nav class="admin-menu" aria-label="Разделы администрирования">
            <a class="admin-nav-link" href="{{ route('admin.index') }}" @if(request()->routeIs('admin.index')) aria-current="page" @endif>Обзор</a>
            <div class="admin-nav-group">
                <span>Сервис</span>
                <a class="admin-nav-link" href="{{ route('admin.users') }}" @if(request()->routeIs('admin.users', 'admin.users.edit')) aria-current="page" @endif>Пользователи</a>
                <a class="admin-nav-link" href="{{ route('admin.tariffs') }}" @if(request()->routeIs('admin.tariffs')) aria-current="page" @endif>Тарифы</a>
                <a class="admin-nav-link" href="{{ route('admin.panels') }}" @if(request()->routeIs('admin.panels')) aria-current="page" @endif>Панели 3x-ui</a>
            </div>
            <div class="admin-nav-group">
                <span>Операции</span>
                <a class="admin-nav-link" href="{{ route('admin.orders') }}" @if(request()->routeIs('admin.orders')) aria-current="page" @endif>Заказы</a>
                <a class="admin-nav-link" href="{{ route('admin.payment-events') }}" @if(request()->routeIs('admin.payment-events')) aria-current="page" @endif>Платёжные события</a>
            </div>
            <div class="admin-nav-group">
                <span>Настройки</span>
                <a class="admin-nav-link" href="{{ route('admin.site') }}" @if(request()->routeIs('admin.site')) aria-current="page" @endif>Главная страница</a>
                <a class="admin-nav-link" href="{{ route('admin.seo') }}" @if(request()->routeIs('admin.seo')) aria-current="page" @endif>SEO</a>
                <a class="admin-nav-link" href="{{ route('admin.payment-settings') }}" @if(request()->routeIs('admin.payment-settings')) aria-current="page" @endif>Оплаты</a>
                <a class="admin-nav-link" href="{{ route('admin.mail-settings') }}" @if(request()->routeIs('admin.mail-settings')) aria-current="page" @endif>Почта и SMTP</a>
                <a class="admin-nav-link" href="{{ route('admin.system') }}" @if(request()->routeIs('admin.system')) aria-current="page" @endif>Система и аудит</a>
            </div>
        </nav>
    </aside>
    <div class="admin-workspace">
        @yield('admin-content')
    </div>
</div>
@endsection
