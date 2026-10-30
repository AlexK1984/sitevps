<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'price_kopecks', 'price_stars', 'duration_days', 'traffic_gb', 'connection_limit', 'is_active'])]
class Tariff extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function priceLabel(): string
    {
        return number_format($this->price_kopecks / 100, 2, ',', ' ').' ₽';
    }
}
