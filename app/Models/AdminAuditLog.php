<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_id', 'subject_user_id', 'action', 'summary'])]
class AdminAuditLog extends Model
{
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'user_updated' => 'Пользователь изменён',
            'user_banned' => 'Пользователь заблокирован',
            'user_unbanned' => 'Блокировка пользователя снята',
            'user_soft_deleted' => 'Пользователь мягко удалён',
            'user_access_sync_failed' => 'Не удалось изменить доступ на панели',
            'user_panel_assigned' => 'Назначена панель',
            'subscription_sync_failed' => 'Ошибка синхронизации подписки',
            'homepage_content_updated' => 'Обновлена главная страница',
            'seo_settings_updated' => 'Обновлены SEO-настройки',
            'smtp_settings_updated' => 'Обновлены настройки SMTP',
            'payment_credentials_updated' => 'Обновлены реквизиты платежей',
            'nowpayments_checkout_toggled' => 'Изменён статус криптооплаты',
            'nowpayments_rub_verified' => 'Проверена валюта RUB',
            'nowpayments_rub_verification_failed' => 'Не пройдена проверка валюты RUB',
            default => 'Административное изменение',
        };
    }
}
