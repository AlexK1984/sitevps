<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentEvent;

class PaymentEventLogger
{
    public function record(
        string $provider,
        string $eventType,
        string $summary,
        ?Order $order = null,
        ?int $actorId = null,
        ?string $statusFrom = null,
        ?string $statusTo = null,
        ?string $idempotencyKey = null,
    ): void {
        PaymentEvent::query()->create([
            'order_id' => $order?->id,
            'actor_id' => $actorId,
            'provider' => $provider,
            'event_type' => $eventType,
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'summary' => $summary,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
