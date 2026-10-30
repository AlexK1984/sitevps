@extends('layouts.app')

@section('title', 'VPN-подписка')

@section('content')
<section class="hero">
    <div class="hero-copy">
        <span class="eyebrow"><span class="pulse"></span> Приватный доступ в один клик</span>
        <h1>Ваш интернет.<br><span>Ваши правила.</span></h1>
        <p>Оформите подписку онлайн — доступ появится в личном кабинете после подтверждения оплаты.</p>
        <a class="button" href="#plans">Выбрать тариф <span aria-hidden="true">→</span></a>
        <div class="hero-facts"><span>● Быстрый запуск</span><span>● Оплата через ЮKassa</span></div>
    </div>
    <div class="hero-art" aria-hidden="true">
        <div class="orbit orbit-one"></div><div class="orbit orbit-two"></div>
        <div class="shield"><span>V</span></div>
        <div class="art-caption"><span class="pulse"></span> соединение защищено</div>
    </div>
</section>

<section id="plans" class="section">
    <div class="section-heading">
        <div><span class="eyebrow">ПРОСТЫЕ УСЛОВИЯ</span><h2>Выберите свой тариф</h2></div>
        <p>Трафик и срок указаны для каждого тарифа.<br>Лимит подключений ориентировочный.</p>
    </div>
    @if($tariffs->isEmpty())
        <div class="empty-state">Тарифы скоро появятся. Загляните позже.</div>
    @else
        <div class="plan-grid">
            @foreach($tariffs as $tariff)
                <article class="plan-card">
                    <span class="plan-icon">✳</span>
                    <h3>{{ $tariff->name }}</h3>
                    <p class="plan-description">{{ $tariff->description ?: 'Надёжный VPN-доступ на каждый день.' }}</p>
                    <div class="plan-price">{{ $tariff->priceLabel() }} <small>/ {{ $tariff->duration_days }} дней</small></div>
                    <ul class="feature-list">
                        <li><span>↗</span> {{ number_format($tariff->traffic_gb, 0, ',', ' ') }} ГБ трафика</li>
                        <li><span>◉</span> До {{ $tariff->connection_limit }} одновременных IP*</li>
                    </ul>
                    <a class="button button-outline" href="{{ auth()->check() ? route('dashboard') : route('register') }}">Продолжить <span aria-hidden="true">→</span></a>
                    <small class="fine-print">* Реальное число устройств зависит от сети и настроек.</small>
                </article>
            @endforeach
        </div>
    @endif
</section>

<section class="how-it-works">
    <div><span class="eyebrow">ВСЁ ПРОСТО</span><h2>Подключение за три шага</h2></div>
    <div class="steps">
        <div><span>01</span><h3>Создайте аккаунт</h3><p>Личный кабинет хранит подписки и платежи.</p></div>
        <div><span>02</span><h3>Выберите тариф</h3><p>Безопасно оплатите удобным способом через ЮKassa.</p></div>
        <div><span>03</span><h3>Подключитесь</h3><p>Доступ появится после подтверждения оплаты.</p></div>
    </div>
</section>
@endsection
