<?php

namespace App\Jobs;

use App\Exceptions\PanelApiException;
use App\Models\Order;
use App\Models\Panel;
use App\Models\PaymentEvent;
use App\Models\Subscription;
use App\Models\SubscriptionOrderApplication;
use App\Models\Tariff;
use App\Models\User;
use App\Services\DefaultPanelAssigner;
use App\Services\PaymentEventLogger;
use App\Services\ThreeXUiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProvisionSubscription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $orderId) {}

    public function handle(ThreeXUiClient $client, PaymentEventLogger $events, DefaultPanelAssigner $panelAssigner): void
    {
        $provisioning = DB::transaction(function () use ($events, $panelAssigner): ?array {
            $order = Order::query()->lockForUpdate()->findOrFail($this->orderId);

            if (! in_array($order->status, ['paid_unassigned', 'provisioning_failed'], true)) {
                return null;
            }

            $user = User::query()->lockForUpdate()->findOrFail($order->user_id);

            if (! $user->is_active) {
                $oldStatus = $order->status;
                $order->update([
                    'status' => 'paid_unassigned',
                    'error_message' => 'Аккаунт пользователя отключён. Восстановите доступ, затем повторите выдачу.',
                ]);
                $idempotencyKey = 'inactive-user-order:'.$order->id;
                if (! PaymentEvent::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                    $events->record(
                        $order->is_test ? 'internal_test' : $order->payment_method,
                        'provisioning_waiting_for_active_user',
                        'Оплата подтверждена; выдача приостановлена до восстановления учётной записи.',
                        $order,
                        statusFrom: $oldStatus,
                        statusTo: 'paid_unassigned',
                        idempotencyKey: $idempotencyKey,
                    );
                }

                return null;
            }

            if (! $user->assigned_panel_id) {
                $defaultPanel = $panelAssigner->assignIfEligible($user);
                if ($defaultPanel) {
                    $user->assigned_panel_id = $defaultPanel->id;
                }
            }

            $panelId = $user->assigned_panel_id;
            if (! $panelId) {
                $oldStatus = $order->status;
                $order->update(['status' => 'paid_unassigned', 'error_message' => null]);
                $events->record(
                    $order->is_test ? 'internal_test' : $order->payment_method,
                    'provisioning_waiting_for_panel',
                    'Оплата подтверждена; доступ ожидает назначения панели пользователю.',
                    $order,
                    statusFrom: $oldStatus,
                    statusTo: 'paid_unassigned',
                );

                return null;
            }

            $oldOrderStatus = $order->status;
            $subscription = Subscription::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $previousPanelId = $subscription?->panel_id;

            if ($subscription?->status === 'provisioning') {
                $order->update(['status' => 'paid_unassigned']);

                return null;
            }

            $tariff = Tariff::query()->findOrFail($order->tariff_id);

            $subscription ??= new Subscription([
                'user_id' => $user->id,
                'client_uuid' => (string) Str::uuid(),
                'sub_id' => Str::random(16),
                'client_email' => 'user-'.$user->id.'@sitevps',
            ]);

            $application = SubscriptionOrderApplication::query()->where('order_id', $order->id)->first();

            if (! $application) {
                $now = now();
                $isReplacement = $order->subscription_action === 'replacement';
                $expiryBase = ! $isReplacement && $subscription->expires_at && $subscription->expires_at->isFuture()
                    ? $subscription->expires_at->copy()
                    : $now->copy();
                $trafficLimit = $isReplacement
                    ? $tariff->traffic_gb * 1_000_000_000
                    : $subscription->traffic_limit_bytes + ($tariff->traffic_gb * 1_000_000_000);

                $subscription->fill([
                    'tariff_id' => $tariff->id,
                    'expires_at' => $expiryBase->addDays($tariff->duration_days),
                    'traffic_limit_bytes' => $trafficLimit,
                    'connection_limit' => $tariff->connection_limit,
                ]);
            }

            $subscription->fill([
                'panel_id' => $panelId,
                'status' => 'provisioning',
                'last_error' => null,
            ])->save();

            if (! $application) {
                $application = SubscriptionOrderApplication::query()->create([
                    'order_id' => $order->id,
                    'subscription_id' => $subscription->id,
                    'previous_panel_id' => $previousPanelId,
                ]);
            }

            $order->update([
                'panel_id' => $panelId,
                'status' => 'provisioning',
                'error_message' => null,
            ]);
            $events->record(
                $order->is_test ? 'internal_test' : $order->payment_method,
                'provisioning_started',
                'Обычная выдача доступа поставлена в обработку.',
                $order,
                statusFrom: $oldOrderStatus,
                statusTo: 'provisioning',
            );

            return [
                'order_id' => $order->id,
                'subscription_id' => $subscription->id,
                'panel_id' => $panelId,
                'user_id' => $user->id,
                'subscription_action' => $order->subscription_action,
                'previous_panel_id' => $application->previous_panel_id,
            ];
        });

        if (! $provisioning) {
            return;
        }

        try {
            $subscription = Subscription::query()->findOrFail($provisioning['subscription_id']);
            $panel = Panel::query()->findOrFail($provisioning['panel_id']);
            if ($provisioning['subscription_action'] === 'replacement') {
                $previousPanelId = $provisioning['previous_panel_id'];
                if ($previousPanelId && (int) $previousPanelId !== (int) $panel->id) {
                    $client->provision($panel, $subscription);
                } else {
                    $client->update($panel, $subscription);
                }

                if ($previousPanelId && (int) $previousPanelId !== (int) $panel->id) {
                    $previousPanel = Panel::query()->find($previousPanelId);
                    if (! $previousPanel) {
                        throw new PanelApiException('Не удалось проверить отключение клиента на прежней панели.');
                    }
                    $client->setEnabled($previousPanel, $subscription, false);
                }
            } else {
                $client->provision($panel, $subscription);
            }
            if (! User::query()->whereKey($provisioning['user_id'])->value('is_active')) {
                $client->setEnabled($panel, $subscription, false);
            }
        } catch (PanelApiException $exception) {
            DB::transaction(function () use ($provisioning, $exception, $events): void {
                $order = Order::query()->findOrFail($provisioning['order_id']);
                Order::query()->whereKey($provisioning['order_id'])->update([
                    'status' => 'provisioning_failed',
                    'error_message' => $exception->getMessage(),
                ]);
                Subscription::query()->whereKey($provisioning['subscription_id'])->update([
                    'status' => 'provisioning_failed',
                    'last_error' => $exception->getMessage(),
                ]);
                $events->record(
                    $order->is_test ? 'internal_test' : $order->payment_method,
                    'provisioning_failed',
                    'Панель не приняла запрос на выдачу; администратор может повторить обработку.',
                    $order,
                    statusFrom: 'provisioning',
                    statusTo: 'provisioning_failed',
                );
            });

            return;
        }

        $userId = Order::query()->findOrFail($provisioning['order_id'])->user_id;
        DB::transaction(function () use ($provisioning, $userId, $events): void {
            $order = Order::query()->findOrFail($provisioning['order_id']);
            Order::query()->whereKey($provisioning['order_id'])->update(['status' => 'active']);
            Order::query()
                ->where('user_id', $userId)
                ->where('status', 'provisioning_failed')
                ->update(['status' => 'active', 'error_message' => null]);
            Subscription::query()->whereKey($provisioning['subscription_id'])->update([
                'status' => 'active',
                'last_error' => null,
            ]);
            $events->record(
                $order->is_test ? 'internal_test' : $order->payment_method,
                'provisioning_completed',
                $order->subscription_action === 'replacement'
                    ? 'После подтверждения оплаты новый тариф применён на назначенной панели.'
                    : 'Доступ успешно выдан или продлён на назначенной панели.',
                $order,
                statusFrom: 'provisioning',
                statusTo: 'active',
            );
        });

        Order::query()->where('user_id', $userId)
            ->where('status', 'paid_unassigned')
            ->where('id', '<>', $provisioning['order_id'])
            ->pluck('id')
            ->each(fn (int $orderId) => self::dispatch($orderId));
    }

    public function failed(Throwable $exception): void
    {
        $order = Order::query()->find($this->orderId);

        if (! $order || $order->status !== 'provisioning') {
            return;
        }

        $oldStatus = $order->status;
        $order->update([
            'status' => 'provisioning_failed',
            'error_message' => 'Не удалось выдать доступ. Проверьте соединение и повторите операцию.',
        ]);
        app(PaymentEventLogger::class)->record(
            $order->is_test ? 'internal_test' : $order->payment_method,
            'provisioning_failed',
            'Очередь завершила выдачу ошибкой; администратор может повторить обработку.',
            $order,
            statusFrom: $oldStatus,
            statusTo: 'provisioning_failed',
        );

        Subscription::query()
            ->where('user_id', $order->user_id)
            ->where('status', 'provisioning')
            ->update([
                'status' => 'provisioning_failed',
                'last_error' => $order->error_message,
            ]);
    }
}
