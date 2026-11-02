@extends('layouts.admin')

@section('title', 'Система и аудит')

@section('admin-content')
<section class="dashboard-head admin-page-heading">
    <div><span class="eyebrow">СИСТЕМА</span><h1>Аудит изменений</h1></div>
    <a class="button button-outline" href="{{ route('home') }}">На сайт →</a>
</section>
<section class="admin-section">
    <div class="section-heading"><div><span class="eyebrow">КОНФИГУРАЦИЯ СЕРВЕРА</span><h2>Инфраструктурные настройки</h2></div></div>
    <p class="muted">Параметры, влияющие на ключи приложения, соединение с базой, домен, HTTPS и системные службы, не редактируются из браузера. Настраивайте их только на сервере с соответствующими правами; это защищает приложение от потери данных и административного захвата.</p>
    <ul class="feature-list">
        <li>APP_KEY, URL сайта и TLS/HTTPS</li>
        <li>Путь и доступ к базе данных</li>
        <li>Пользователи служб, PHP-FPM, очередь и системные службы</li>
        <li>Сессии, кэш, логирование и файловая система</li>
    </ul>
</section>
@include('admin.sections.audit')
@endsection
