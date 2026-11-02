<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'site_name',
    'meta_title',
    'meta_description',
    'canonical_url',
    'og_image_url',
    'hero_badge',
    'hero_title',
    'hero_body',
    'hero_cta_label',
    'hero_cta_target',
    'hero_fact_one',
    'hero_fact_two',
    'plans_eyebrow',
    'plans_title',
    'plans_description',
    'steps_eyebrow',
    'steps_title',
    'step_one_title',
    'step_one_body',
    'step_two_title',
    'step_two_body',
    'step_three_title',
    'step_three_body',
    'footer_text',
])]
class SiteContent extends Model
{
    protected $table = 'site_contents';

    public static function defaults(): array
    {
        return [
            'site_name' => 'vpshield',
            'meta_title' => 'VPN-подписка онлайн: тарифы и управление доступом',
            'meta_description' => 'Выберите VPN-тариф, оплатите его онлайн и управляйте подпиской в личном кабинете. Срок и объём трафика указаны для каждого тарифа.',
            'canonical_url' => null,
            'og_image_url' => null,
            'hero_badge' => 'VPN-подписка с личным кабинетом',
            'hero_title' => 'VPN-доступ с понятными условиями',
            'hero_body' => 'Выберите тариф с указанным сроком и объёмом трафика. После подтверждения оплаты данные подписки появятся в личном кабинете.',
            'hero_cta_label' => 'Посмотреть тарифы',
            'hero_cta_target' => '#plans',
            'hero_fact_one' => 'Управление в личном кабинете',
            'hero_fact_two' => 'Доступ после подтверждения оплаты',
            'plans_eyebrow' => 'ТАРИФЫ И УСЛОВИЯ',
            'plans_title' => 'Выберите подходящий тариф',
            'plans_description' => 'Срок и объём трафика указаны для каждого тарифа. Число одновременных подключений ориентировочное.',
            'steps_eyebrow' => 'КАК НАЧАТЬ',
            'steps_title' => 'Три шага до подключения',
            'step_one_title' => 'Создайте аккаунт',
            'step_one_body' => 'Личный кабинет нужен для управления заказами и подпиской.',
            'step_two_title' => 'Выберите тариф',
            'step_two_body' => 'Сравните срок, объём трафика и условия подключения.',
            'step_three_title' => 'Получите конфигурацию',
            'step_three_body' => 'После подтверждения оплаты ссылка появится в личном кабинете.',
            'footer_text' => 'Понятные условия подписки и управление доступом через личный кабинет.',
        ];
    }

    public static function current(): self
    {
        return self::query()->find(1) ?? new self(self::defaults());
    }
}
