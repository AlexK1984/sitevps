@extends('layouts.admin')

@section('title', 'Администрирование')

@section('admin-content')
<section class="dashboard-head admin-page-heading">
    <div><span class="eyebrow">УПРАВЛЕНИЕ СЕРВИСОМ</span><h1>Панель управления</h1></div>
    <a class="button button-outline" href="{{ route('home') }}">На сайт →</a>
</section>
<div class="admin-overview-grid">
    <a class="panel-card admin-overview-card" href="{{ route('admin.users') }}"><span class="eyebrow">АККАУНТЫ</span><strong>{{ $userCount }}</strong><span>Пользователей</span></a>
    <a class="panel-card admin-overview-card" href="{{ route('admin.tariffs') }}"><span class="eyebrow">КАТАЛОГ</span><strong>{{ $tariffCount }}</strong><span>Тарифов</span></a>
    <a class="panel-card admin-overview-card" href="{{ route('admin.panels') }}"><span class="eyebrow">ИНФРАСТРУКТУРА</span><strong>{{ $panelCount }}</strong><span>Панелей 3x-ui</span></a>
    <a class="panel-card admin-overview-card" href="{{ route('admin.orders') }}"><span class="eyebrow">ОПЕРАЦИИ</span><strong>{{ $orderCount }}</strong><span>Заказов</span></a>
</div>
<section class="admin-section">
    <div class="section-heading"><div><span class="eyebrow">БЫСТРЫЙ ДОСТУП</span><h2>Настройки и журналы</h2></div></div>
    <div class="admin-quick-links">
        <a href="{{ route('admin.site') }}">Редактор главной страницы</a><a href="{{ route('admin.seo') }}">SEO</a>
        <a href="{{ route('admin.payment-settings') }}">Способы оплаты</a><a href="{{ route('admin.mail-settings') }}">Почта и SMTP</a>
        <a href="{{ route('admin.payment-events') }}">Платёжные события</a><a href="{{ route('admin.system') }}">Система и аудит</a>
    </div>
</section>
@endsection
