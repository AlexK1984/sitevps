<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentGatewayException;
use App\Jobs\ProvisionSubscription;
use App\Models\Order;
use App\Models\PaymentSettings;
use App\Models\Tariff;
use App\Services\CheckoutService;
use App\Services\NowPaymentsCheckoutService;
use App\Services\NowPaymentsClient;
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
        return $this->createYooKassaCheckout($request, $tariff, $checkout);
    }

    public function createReplacement(Request $request, Tariff $tariff, CheckoutService $checkout): RedirectResponse
    {
        return $this->createYooKassaCheckout($request, $tariff, $checkout, replacement: true);
    }

    private function createYooKassaCheckout(
        Request $request,
        Tariff $tariff,
        CheckoutService $checkout,
        bool $replacement = false,
    ): RedirectResponse {
        abort_unless($tariff->is_active, 404);

        if (! $this->checkoutActionAllowed($request, $tariff, $replacement)) {
            return back()->withErrors(['payment' => 'Для смены активного тарифа выберите «Сменить на этот тариф»; обычная оплата предназначена для его продления.']);
        }

        if (! PaymentSettings::current()->yookassa_enabled) {
            return back()->withErrors(['payment' => 'Оплата через ЮKassa временно отключена.']);
        }

        try {
            $order = $checkout->create($request->user(), $tariff, route('dashboard', absolute: true), $replacement);
        } catch (PaymentGatewayException) {
            return back()->withErrors(['payment' => 'Оплата временно недоступна. Попробуйте позже.']);
        }

        return redirect()->away($order->payment_url);
    }

    public function createNowPayments(
        Request $request,
        Tariff $tariff,
        NowPaymentsCheckoutService $checkout,
    ): RedirectResponse {
        return $this->createNowPaymentsCheckout($request, $tariff, $checkout);
    }

    public function createNowPaymentsReplacement(
        Request $request,
        Tariff $tariff,
        NowPaymentsCheckoutService $checkout,
    ): RedirectResponse {
        return $this->createNowPaymentsCheckout($request, $tariff, $checkout, replacement: true);
    }

    private function createNowPaymentsCheckout(
        Request $request,
        Tariff $tariff,
        NowPaymentsCheckoutService $checkout,
        bool $replacement = false,
    ): RedirectResponse {
        abort_unless($tariff->is_active, 404);

        if (! $this->checkoutActionAllowed($request, $tariff, $replacement)) {
            return back()->withErrors(['payment' => 'Для смены активного тарифа выберите «Сменить на этот тариф»; обычная оплата предназначена для его продления.']);
        }

        $settings = PaymentSettings::current();
        if (! $settings->nowpayments_enabled
            || ! $settings->nowpayments_rub_verified
            || ! $settings->nowpaymentsConfiguredInAdmin()) {
            return back()->withErrors(['payment' => 'Оплата через NOWPayments временно недоступна.']);
        }

        try {
            $order = $checkout->create($request->user(), $tariff, $replacement);
        } catch (PaymentGatewayException) {
            return back()->withErrors(['payment' => 'Не удалось создать счёт. Попробуйте позже.']);
        }

        return redirect()->away($order->payment_url);
    }

    private function checkoutActionAllowed(Request $request, Tariff $tariff, bool $replacement): bool
    {
        $subscription = $request->user()->subscription()->first();
        $hasActiveSubscription = $subscription?->isActive() ?? false;
        $isDifferentTariff = $hasActiveSubscription && $subscription->tariff_id !== $tariff->id;

        return $replacement
            ? $isDifferentTariff
            : ! $isDifferentTariff;
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

    public function nowPaymentsWebhook(
        Request $request,
        NowPaymentsClient $nowPayments,
        PaymentEventLogger $events,
    ): JsonResponse {
        $rawBody = $request->getContent();
        $signature = $request->header('x-nowpayments-sig');
        $settings = PaymentSettings::current();

        if (strlen($rawBody) > 65536
            || ! is_string($signature)
            || ! preg_match('/^[a-f0-9]{128}$/i', $signature)
            || ! filled($settings->nowpayments_ipn_secret)) {
            return response()->json(['error' => 'Invalid notification'], 401);
        }

        $notification = json_decode($rawBody, true);
        if (! is_array($notification) || array_is_list($notification)) {
            return response()->json(['error' => 'Invalid notification'], 400);
        }

        ksort($notification);
        try {
            $canonicalBody = json_encode(
                $notification,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return response()->json(['error' => 'Invalid notification'], 400);
        }

        $expectedSignature = hash_hmac('sha512', $canonicalBody, $settings->nowpayments_ipn_secret);
        if (! hash_equals($expectedSignature, strtolower($signature))) {
            return response()->json(['error' => 'Invalid notification'], 401);
        }

        $paymentId = $notification['payment_id'] ?? null;
        $reference = $notification['order_id'] ?? null;
        if ((! is_string($paymentId) && ! is_int($paymentId))
            || (string) $paymentId === ''
            || ! is_string($reference)
            || $reference === '') {
            return response()->json(['error' => 'Invalid notification'], 400);
        }

        $order = Order::query()->where('reference', $reference)->first();
        if (! $order || $order->payment_method !== 'nowpayments') {
            return response()->json(['error' => 'Unknown payment'], 404);
        }

        try {
            $payment = $nowPayments->getPayment((string) $paymentId);
        } catch (PaymentGatewayException) {
            $events->record(
                'nowpayments',
                'webhook_rejected',
                'Не удалось подтвердить уведомление запросом к NOWPayments.',
                $order,
            );

            return response()->json(['error' => 'Payment verification unavailable'], 503);
        }

        $invoiceId = (string) ($payment['invoice_id'] ?? '');
        $verifiedPaymentId = (string) ($payment['payment_id'] ?? '');
        $verifiedReference = $payment['order_id'] ?? null;
        $paidAmountKopecks = $this->decimalAmountToKopecks($payment['price_amount'] ?? null);
        if ($verifiedPaymentId !== (string) $paymentId
            || $invoiceId === ''
            || $invoiceId !== $order->nowpayments_invoice_id
            || $verifiedReference !== $order->reference
            || strtolower((string) ($payment['price_currency'] ?? '')) !== 'rub'
            || $paidAmountKopecks !== $order->amount_kopecks) {
            $events->record(
                'nowpayments',
                'webhook_rejected',
                'Проверенные реквизиты платежа не совпали с заказом.',
                $order,
                statusFrom: $order->status,
                statusTo: $order->status,
            );

            return response()->json(['error' => 'Payment details mismatch'], 422);
        }

        if (Order::query()
            ->where('payment_id', (string) $paymentId)
            ->where('id', '!=', $order->id)
            ->exists()) {
            return response()->json(['error' => 'Payment already associated with another order'], 422);
        }

        $providerStatus = $payment['payment_status'] ?? null;
        if (in_array($providerStatus, ['failed', 'expired'], true)) {
            $changedToFailed = DB::transaction(function () use ($order): bool {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                if ($lockedOrder->paid_at || $lockedOrder->status === 'payment_failed') {
                    return false;
                }

                $lockedOrder->update([
                    'status' => 'payment_failed',
                    'error_message' => 'Счёт NOWPayments истёк или платёж был отклонён.',
                ]);

                return true;
            });

            if ($changedToFailed) {
                $events->record(
                    'nowpayments',
                    'payment_terminal_received',
                    'NOWPayments подтвердил окончание неоплаченного счёта.',
                    $order,
                    statusFrom: $order->status,
                    statusTo: 'payment_failed',
                );
            }

            return response()->json(['received' => true]);
        }

        if ($providerStatus !== 'finished') {
            $events->record(
                'nowpayments',
                'payment_status_received',
                'NOWPayments подтвердил статус, который ещё не означает завершённую оплату.',
                $order,
                statusFrom: $order->status,
                statusTo: $order->status,
            );

            return response()->json(['received' => true]);
        }

        $paymentState = DB::transaction(function () use ($order, $paymentId, $events): string {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->paid_at) {
                return $lockedOrder->payment_id === (string) $paymentId ? 'already_paid' : 'different_payment';
            }

            $oldStatus = $lockedOrder->status;
            $lockedOrder->update([
                'payment_id' => (string) $paymentId,
                'paid_at' => now(),
                'status' => 'paid_unassigned',
                'error_message' => null,
            ]);
            $events->record(
                'nowpayments',
                'webhook_verified',
                'Оплата завершена и подтверждена запросом к NOWPayments.',
                $lockedOrder,
                statusFrom: $oldStatus,
                statusTo: 'paid_unassigned',
                idempotencyKey: 'nowpayments-paid-order:'.$lockedOrder->id,
            );

            return 'paid';
        });

        if ($paymentState === 'different_payment') {
            $events->record(
                'nowpayments',
                'webhook_rejected',
                'Заказ уже связан с другим платежом.',
                $order,
            );

            return response()->json(['error' => 'Order already paid by another payment'], 422);
        }

        if ($paymentState === 'paid') {
            ProvisionSubscription::dispatch($order->id);
        }

        return response()->json(['received' => true]);
    }

    private function decimalAmountToKopecks(mixed $amount): ?int
    {
        if ((! is_string($amount) && ! is_int($amount) && ! is_float($amount))
            || ! preg_match('/^\d+(?:\.\d+)?$/', (string) $amount)) {
            return null;
        }

        [$rubles, $kopecks] = array_pad(explode('.', (string) $amount, 2), 2, '');
        if (strlen($kopecks) > 2 && trim(substr($kopecks, 2), '0') !== '') {
            return null;
        }

        $minorUnits = ltrim($rubles, '0').str_pad(substr($kopecks, 0, 2), 2, '0');
        $minorUnits = ltrim($minorUnits, '0');

        if ($minorUnits === '' || strlen($minorUnits) > 18) {
            return $minorUnits === '' ? 0 : null;
        }

        return (int) $minorUnits;
    }
}
