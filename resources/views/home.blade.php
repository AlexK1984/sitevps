@extends('layouts.app')

@section('title', 'VPN-подписка')
@section('meta_title', $siteContent->meta_title)
@section('meta_description', $siteContent->meta_description)
@section('canonical_url', $canonicalUrl)

@section('content')
<section class="hero">
    <div class="hero-copy">
        <span class="eyebrow"><span class="pulse"></span> {{ $siteContent->hero_badge }}</span>
        <h1>{{ $siteContent->hero_title }}</h1>
        <p>{{ $siteContent->hero_body }}</p>
        @php
            $ctaHref = match ($siteContent->hero_cta_target) {
                'account' => auth()->check() ? route('dashboard') : route('register'),
                default => '#plans',
            };
        @endphp
        <a class="button" href="{{ $ctaHref }}">{{ $siteContent->hero_cta_label }} <span aria-hidden="true">→</span></a>
        <div class="hero-facts"><span>● {{ $siteContent->hero_fact_one }}</span><span>● {{ $siteContent->hero_fact_two }}</span></div>
    </div>
    <div class="hero-art" aria-hidden="true">
        <div class="orbit orbit-one"></div><div class="orbit orbit-two"></div>
        <div class="shield"><span>V</span></div>
        <div class="art-caption"><span class="pulse"></span> доступ по подписке</div>
    </div>
</section>

<section id="plans" class="section">
    <div class="section-heading">
        <div><span class="eyebrow">{{ $siteContent->plans_eyebrow }}</span><h2>{{ $siteContent->plans_title }}</h2></div>
        <p>{{ $siteContent->plans_description }}</p>
    </div>
    @if($tariffs->isEmpty())
        <div class="empty-state">Тарифы скоро появятся. Загляните позже.</div>
    @else
        <div class="plan-grid">
            @foreach($tariffs as $tariff)
                <article class="plan-card">
                    <span class="plan-icon">✳</span>
                    <h3>{{ $tariff->name }}</h3>
                    <p class="plan-description">{{ $tariff->description ?: 'Доступ к VPN по условиям выбранного тарифа.' }}</p>
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
    <div><span class="eyebrow">{{ $siteContent->steps_eyebrow }}</span><h2>{{ $siteContent->steps_title }}</h2></div>
    <div class="steps">
        <div><span>01</span><h3>{{ $siteContent->step_one_title }}</h3><p>{{ $siteContent->step_one_body }}</p></div>
        <div><span>02</span><h3>{{ $siteContent->step_two_title }}</h3><p>{{ $siteContent->step_two_body }}</p></div>
        <div><span>03</span><h3>{{ $siteContent->step_three_title }}</h3><p>{{ $siteContent->step_three_body }}</p></div>
    </div>
</section>
@endsection
