@extends('layouts.app')

@section('title', 'Личный кабинет')

@section('content')
<section class="dashboard-head">
    <div><span class="eyebrow">ЛИЧНЫЙ КАБИНЕТ</span><h1>Здравствуйте, {{ $user->name }}</h1><p>Управляйте подпиской, оплатами и Telegram-привязкой.</p></div>
    @if($user->is_admin)<a class="button button-outline" href="{{ route('admin.index') }}">Открыть админ-панель →</a>@endif
</section>

<div class="dashboard-grid">
    <section class="panel-card subscription-card">
        <div class="card-heading">                <div><span class="eyebrow">ВАША ПОДПИСКА</span><h2>Текущий доступ</h2></div><span class="status-pill {{ $subscription?->isActive() ? 'status-active' : '' }}">{{ $subscription?->statusLabel() ?? 'Не активна' }}</span></div>
        @if($subscription)
            <div class="subscription-details">
                <div><span>Тариф</span><strong>{{ $subscription->tariff?->name ?? '—' }}</strong></div>
                <div><span>Действует до</span><strong>{{ $subscription->expires_at?->format('d.m.Y H:i') ?? '—' }}</strong></div>
                <div><span>Трафик</span><strong>{{ number_format($subscription->traffic_limit_bytes / 1_000_000_000, 0, ',', ' ') }} ГБ</strong></div>
                <div><span>Подключения*</span><strong>до {{ $subscription->connection_limit }}</strong></div>
                <div><span>Панель</span><strong>{{ $subscription->panel?->name ?? 'Назначается администратором' }}</strong></div>
            </div>
            @if($subscription->isActive() && $subscription->panel?->subscription_url_prefix)
                <a class="button button-outline" href="{{ rtrim($subscription->panel->subscription_url_prefix, '/') }}/{{ $subscription->sub_id }}" target="_blank" rel="noopener noreferrer">Открыть конфигурацию подключения →</a>
            @elseif($subscription->isActive())
                <p class="muted">Ссылка подключения не настроена для панели. Обратитесь к администратору.</p>
            @endif
            @if($subscription->last_error)<p class="notice notice-error">{{ $subscription->last_error }}</p>@endif
        @else
            <p class="muted">Подписка пока не оформлена. Выберите тариф ниже.</p>
        @endif
        <p class="fine-print">* Лимит подключений приблизительный и зависит от настроек сервера и сети.</p>
    </section>

    <section class="panel-card telegram-card">
        <span class="eyebrow">УПРАВЛЕНИЕ В TELEGRAM</span><h2>Покупка и продление в боте</h2>
        <p>Привяжите аккаунт, чтобы выбирать тарифы и оплачивать их в Telegram или через ЮKassa.</p>
        @if($telegramBotName)
            <form method="post" action="{{ route('telegram.link') }}">@csrf<button class="button button-outline" type="submit">Создать ссылку привязки</button></form>
            @if(session('telegram_link'))<div class="link-box"><span>Ссылка действует 10 минут</span><a href="{{ session('telegram_link') }}">Открыть Telegram →</a></div>@endif
            @if($user->telegram_user_id)<span class="status-pill status-active">Telegram привязан</span>@endif
        @else
            <p class="muted">Бот пока не настроен.</p>
        @endif
    </section>
</div>

<section class="section compact-section">
    <div class="section-heading"><div><span class="eyebrow">ПРОДЛИТЬ ИЛИ НАЧАТЬ</span><h2>Тарифы</h2></div></div>
    @if($tariffs->isEmpty())<div class="empty-state">Активных тарифов пока нет.</div>@else
        <div class="plan-grid plan-grid-small">
            @foreach($tariffs as $tariff)
                <article class="plan-card">
                    <span class="plan-icon">✳</span><h3>{{ $tariff->name }}</h3>
                    <div class="plan-price">{{ $tariff->priceLabel() }} <small>/ {{ $tariff->duration_days }} дней</small></div>
                    <ul class="feature-list"><li><span>↗</span> {{ $tariff->traffic_gb }} ГБ</li><li><span>◉</span> До {{ $tariff->connection_limit }} IP*</li></ul>
                    @if($yookassaAvailable)
                        <form method="post" action="{{ route('checkout', $tariff) }}">@csrf<button class="button button-full" type="submit">Оплатить через ЮKassa →</button></form>
                    @else
                        <p class="muted">Оплата через сайт временно недоступна.</p>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</section>

<section class="section compact-section">
    <div class="section-heading"><div><span class="eyebrow">ИСТОРИЯ</span><h2>Последние заказы</h2></div></div>
    <div class="table-wrap"><table><thead><tr><th>Тариф</th><th>Сумма</th><th>Статус</th><th>Дата</th><th></th></tr></thead><tbody>
        @forelse($orders as $order)
            <tr><td>{{ $order->tariff->name }}</td><td>{{ $order->paidAmountLabel() }}<br><small>{{ $order->paymentMethodLabel() }}</small></td><td><span class="status-pill">{{ $order->statusLabel() }}</span></td><td>{{ $order->created_at->format('d.m.Y H:i') }}</td><td>@if($order->status === 'pending' && $order->payment_url)<a href="{{ $order->payment_url }}" target="_blank" rel="noopener noreferrer">Продолжить оплату →</a>@endif</td></tr>
        @empty
            <tr><td colspan="5" class="empty-cell">Заказов пока нет.</td></tr>
        @endforelse
    </tbody></table></div>
</section>
@endsection
