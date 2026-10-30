<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionOrderApplication extends Model
{
    protected $fillable = ['order_id', 'subscription_id'];
}
