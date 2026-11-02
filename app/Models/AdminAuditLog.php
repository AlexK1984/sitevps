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
            'user_panel_assigned' => 'Назначена панель',
            'subscription_sync_failed' => 'Ошибка синхронизации подписки',
            'homepage_content_updated' => 'Обновлена главная страница',
            default => 'Административное изменение',
        };
    }
}
