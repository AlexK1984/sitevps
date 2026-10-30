<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentGatewayException;
use App\Jobs\ProvisionSubscription;
use App\Models\Order;
use App\Models\PaymentSettings;
use App\Models\Tariff;
use App\Services\CheckoutService;
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

    public function webhook(Request $request, YooKassaClient $yooKassa): JsonResponse
    {
        $paymentId = $request->input('object.id');

        if (! is_string($paymentId) || $paymentId === '') {
            return response()->json(['error' => 'Invalid notification'], 400);
        }

        try {
            $payment = $yooKassa->getPayment($paymentId);
        } catch (PaymentGatewayException) {
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
            return response()->json(['error' => 'Payment amount mismatch'], 422);
        }

        if (data_get($payment, 'status') !== 'succeeded') {
            return response()->json(['received' => true]);
        }

        $shouldProvision = DB::transaction(function () use ($order): bool {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->paid_at) {
                return in_array($lockedOrder->status, ['paid_unassigned', 'provisioning_failed'], true);
            }

            $lockedOrder->update([
                'paid_at' => now(),
                'status' => 'paid_unassigned',
                'error_message' => null,
            ]);

            return true;
        });

        if ($shouldProvision) {
            ProvisionSubscription::dispatch($order->id);
        }

        return response()->json(['received' => true]);
    }
}
