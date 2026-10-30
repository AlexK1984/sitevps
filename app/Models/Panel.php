<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'base_url', 'subscription_url_prefix', 'username', 'password', 'inbound_id', 'protocol', 'flow'])]
#[Hidden(['password'])]
class Panel extends Model
{
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'connection_checked_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'assigned_panel_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
