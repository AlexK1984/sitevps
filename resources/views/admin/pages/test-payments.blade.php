@extends('layouts.admin')

@section('title', 'Тестовые оплаты')

@section('admin-content')
<section class="dashboard-head admin-page-heading">
    <div><span class="eyebrow">УПРАВЛЕНИЕ ТЕСТИРОВАНИЕМ</span><h1>Тестовые оплаты</h1><p>Настройка доступности тестовой симуляции для пользователей.</p></div>
    <a class="button button-outline" href="{{ route('admin.orders') }}">Заказы и платежи</a>
</section>

<section class="admin-section">
    <form class="panel-card admin-form" method="post" action="{{ route('admin.test-payments.update') }}">
        @csrf @method('PUT')
        <h2>Доступ из личного кабинета</h2>
        <input type="hidden" name="enabled" value="0">
        <label class="checkbox"><input type="checkbox" name="enabled" value="1" @checked($paymentSettings->internal_test_payments_enabled)> Разрешить пользователям создавать тестовые оплаты</label>
        <p class="notice">Симуляция не вызывает платёжные сервисы и не списывает деньги. Но тестовый заказ помечается TEST и запускает реальную выдачу или замену доступа на назначенной панели, по установленным правилам срока и трафика. Выключено по умолчанию.</p>
        <button class="button button-full" type="submit">Сохранить настройку</button>
    </form>
</section>

@include('admin.sections.test-payment')
@endsection
