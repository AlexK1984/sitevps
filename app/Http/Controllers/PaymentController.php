<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentGatewayException;
use App\Jobs\ProvisionSubscription;
use App\Models\Order;
use App\Models\PaymentSettings;
use App\Models\Tariff;
use App\Services\CheckoutService;
use App\Services\PaymentEventLogger;
use App\Services\YooKassaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function create(Request $request, Tariff $tariff, CheckoutService $checkout): RedirectResponse
    {
        abort_unless($tariff->is_active, 404);

        if (! PaymentSettings::current()->yookassa_enabled) {
            return back()->withErrors(['payment' => 'Оплата через ЮKassa временно отключена.']);
        }

        try {
            $order = $checkout->create($request->user(), $tariff, route('dashboard', absolute: true));
        } catch (PaymentGatewayException) {
            return back()->withErrors(['payment' => 'Оплата временно недоступна. Попробуйте позже.']);
        }

        return redirect()->away($order->payment_url);
    }

    public function webhook(Request $request, YooKassaClient $yooKassa, PaymentEventLogger $events): JsonResponse
    {
        $paymentId = $request->input('object.id');

        if (! is_string($paymentId) || $paymentId === '') {
            return response()->json(['error' => 'Invalid notification'], 400);
        }

        $knownOrder = Order::query()->where('payment_id', $paymentId)->first();
        try {
            $payment = $yooKassa->getPayment($paymentId);
        } catch (PaymentGatewayException) {
            if ($knownOrder) {
                $events->record(
                    'yookassa',
                    'webhook_rejected',
                    'Не удалось подтвердить уведомление запросом к ЮKassa.',
                    $knownOrder,
                );
            }

            return response()->json(['error' => 'Payment verification unavailable'], 503);
        }

        $reference = data_get($payment, 'metadata.order_reference');
        $order = is_string($reference)
            ? Order::query()->where('reference', $reference)->first()
            : null;

        if (! $order || $order->payment_id !== data_get($payment, 'id')) {
            return response()->json(['error' => 'Unknown payment'], 404);
        }

        $amount = data_get($payment, 'amount.value');
        $expectedAmount = number_format($order->amount_kopecks / 100, 2, '.', '');

        if (data_get($payment, 'amount.currency') !== 'RUB' || $amount !== $expectedAmount) {
            $events->record(
                'yookassa',
                'webhook_rejected',
                'Проверенная сумма или валюта платежа не совпала с заказом.',
                $order,
                statusFrom: $order->status,
                statusTo: $order->status,
            );

            return response()->json(['error' => 'Payment amount mismatch'], 422);
        }

        if (data_get($payment, 'status') !== 'succeeded') {
            $events->record(
                'yookassa',
                'payment_status_received',
                'ЮKassa подтвердила статус, который ещё не означает успешную оплату.',
                $order,
                statusFrom: $order->status,
                statusTo: $order->status,
            );

            return response()->json(['received' => true]);
        }

        $shouldProvision = DB::transaction(function () use ($order, $events): bool {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->paid_at) {
                return in_array($lockedOrder->status, ['paid_unassigned', 'provisioning_failed'], true);
            }

            $oldStatus = $lockedOrder->status;
            $lockedOrder->update([
                'paid_at' => now(),
                'status' => 'paid_unassigned',
                'error_message' => null,
            ]);
            $events->record(
                'yookassa',
                'webhook_verified',
                'Успешная оплата подтверждена запросом к ЮKassa.',
                $lockedOrder,
                statusFrom: $oldStatus,
                statusTo: 'paid_unassigned',
                idempotencyKey: 'yookassa-paid-order:'.$lockedOrder->id,
            );

            return true;
        });

        if ($shouldProvision) {
            ProvisionSubscription::dispatch($order->id);
        }

        return response()->json(['received' => true]);
    }
}
