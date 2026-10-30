<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentGatewayException;
use App\Jobs\ProvisionSubscription;
use App\Models\Order;
use App\Models\PaymentSettings;
use App\Models\Tariff;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentEventLogger;
use App\Services\TelegramBotClient;
use App\Services\YooKassaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramBotClient $bot,
        CheckoutService $checkout,
        PaymentEventLogger $events,
    ): JsonResponse {
        $secret = (string) config('services.telegram.webhook_secret');
        $providedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        abort_if($secret === '' || ! hash_equals($secret, $providedSecret), 403);

        $update = $request->all();
        $preCheckoutQuery = data_get($update, 'pre_checkout_query');

        if (is_array($preCheckoutQuery)) {
            $queryId = data_get($preCheckoutQuery, 'id');

            if (is_string($queryId) && $queryId !== '') {
                $accepted = $this->authorizeStarsPayment($preCheckoutQuery, $events);
                $bot->answerPreCheckoutQuery(
                    $queryId,
                    $accepted,
                    $accepted ? null : 'Платёж не прошёл проверку. Выберите тариф заново.',
                );
            }

            return response()->json(['ok' => true]);
        }

        $message = data_get($update, 'message');
        $callback = data_get($update, 'callback_query');
        $chatId = data_get($message, 'chat.id', data_get($callback, 'message.chat.id'));
        $telegramUserId = data_get($message, 'from.id', data_get($callback, 'from.id'));
        $text = trim((string) data_get($message, 'text', ''));

        if (! is_string($chatId) && ! is_int($chatId) || ! is_string($telegramUserId) && ! is_int($telegramUserId)) {
            return response()->json(['ok' => true]);
        }

        $chatId = (string) $chatId;
        $telegramUserId = (string) $telegramUserId;

        $successfulPayment = data_get($message, 'successful_payment');

        if (is_array($successfulPayment)) {
            $matched = $this->completeStarsPayment($successfulPayment, $telegramUserId, $events);
            $bot->sendMessage($chatId, $matched
                ? 'Платёж получен. Подписка появится после обработки заказа.'
                : 'Telegram сообщил об оплате, но заказ не удалось сопоставить автоматически. Свяжитесь с поддержкой и не оплачивайте повторно.');

            return response()->json(['ok' => true]);
        }

        if ($callback) {
            $callbackData = (string) data_get($callback, 'data', '');
            $bot->answerCallback((string) data_get($callback, 'id'), 'Запрос принят.');
            $text = $callbackData;
        }

        if (preg_match('/^\\/start\\s+([A-Za-z0-9]+)$/', $text, $matches)) {
            $linked = $this->linkAccount($telegramUserId, $matches[1]);
            $bot->sendMessage($chatId, $linked
                ? 'Аккаунт Telegram привязан. Доступны команды /plans и /renew.'
                : 'Ссылка недействительна или уже использована. Создайте новую в личном кабинете.');

            return response()->json(['ok' => true]);
        }

        $user = User::query()->where('telegram_user_id', $telegramUserId)->first();

        if (! $user) {
            $bot->sendMessage($chatId, 'Сначала создайте аккаунт на сайте и привяжите его через личный кабинет.');

            return response()->json(['ok' => true]);
        }

        if (in_array($text, ['/start', '/plans', '/renew'], true)) {
            $tariffs = Tariff::query()->where('is_active', true)->orderBy('price_kopecks')->get();
            $settings = PaymentSettings::current();
            $yooKassaAvailable = $settings->yookassa_enabled
                && app(YooKassaClient::class)->configured();
            $starsAvailable = $settings->telegram_stars_enabled && $bot->configured();
            $keyboard = $tariffs->map(function (Tariff $tariff) use ($yooKassaAvailable, $starsAvailable): array {
                $buttons = [];

                if ($yooKassaAvailable) {
                    $buttons[] = [
                        'text' => $tariff->name.' — '.$tariff->priceLabel(),
                        'callback_data' => 'buy:'.$tariff->id,
                    ];
                }

                if ($starsAvailable && $tariff->price_stars) {
                    $buttons[] = [
                        'text' => $tariff->name.' — '.$tariff->price_stars.' ⭐',
                        'callback_data' => 'stars:'.$tariff->id,
                    ];
                }

                return $buttons === [] ? [] : $buttons;
            })->filter()->values()->all();

            $bot->sendMessage(
                $chatId,
                $keyboard === [] ? 'Оплата пока не настроена. Обратитесь к администратору.' : 'Выберите тариф и способ оплаты:',
                $keyboard,
            );

            return response()->json(['ok' => true]);
        }

        if (preg_match('/^(buy|stars):(\d+)$/', $text, $matches)) {
            $tariff = Tariff::query()->whereKey((int) $matches[2])->where('is_active', true)->first();

            if (! $tariff) {
                $bot->sendMessage($chatId, 'Этот тариф больше недоступен. Отправьте /plans для списка тарифов.');

                return response()->json(['ok' => true]);
            }

            if ($matches[1] === 'stars') {
                if (! PaymentSettings::current()->telegram_stars_enabled || ! $bot->configured() || ! $tariff->price_stars) {
                    $bot->sendMessage($chatId, 'Оплата в Telegram Stars для этого тарифа недоступна.');

                    return response()->json(['ok' => true]);
                }

                try {
                    $order = $checkout->createTelegramStars($user, $tariff);
                    $bot->sendInvoice(
                        $chatId,
                        $tariff->name,
                        'VPN-подписка: '.$tariff->name.' на '.$tariff->duration_days.' дней',
                        $order->reference,
                        $tariff->price_stars,
                    );
                    $events->record(
                        'telegram_stars',
                        'payment_request_created',
                        'Telegram Stars invoice создан.',
                        $order,
                        statusFrom: 'pending',
                        statusTo: 'pending',
                    );
                } catch (RuntimeException $exception) {
                    if (isset($order)) {
                        $order->update([
                            'status' => 'payment_failed',
                            'error_message' => 'Не удалось создать счёт Telegram Stars.',
                        ]);
                        $events->record(
                            'telegram_stars',
                            'payment_request_failed',
                            'Не удалось создать Telegram Stars invoice.',
                            $order,
                            statusFrom: 'pending',
                            statusTo: 'payment_failed',
                        );
                    }

                    Log::warning('Telegram Stars invoice creation failed.', [
                        'order_id' => $order->id ?? null,
                        'exception' => $exception::class,
                    ]);
                    $bot->sendMessage($chatId, 'Не удалось создать счёт. Попробуйте позже.');

                    return response()->json(['ok' => true]);
                }

                return response()->json(['ok' => true]);
            }

            if (! PaymentSettings::current()->yookassa_enabled
                || ! app(YooKassaClient::class)->configured()) {
                $bot->sendMessage($chatId, 'Оплата через ЮKassa временно недоступна.');

                return response()->json(['ok' => true]);
            }

            try {
                $order = $checkout->create($user, $tariff, route('dashboard', absolute: true));
            } catch (PaymentGatewayException) {
                $bot->sendMessage($chatId, 'Оплата временно недоступна. Попробуйте позже.');

                return response()->json(['ok' => true]);
            }

            $bot->sendMessage($chatId, 'Перейдите по ссылке для безопасной оплаты: '.$order->payment_url);

            return response()->json(['ok' => true]);
        }

        $bot->sendMessage($chatId, 'Команды: /plans — тарифы, /renew — продлить подписку.');

        return response()->json(['ok' => true]);
    }

    private function authorizeStarsPayment(array $query, PaymentEventLogger $events): bool
    {
        $reference = data_get($query, 'invoice_payload');
        $telegramUserId = data_get($query, 'from.id');
        $amount = data_get($query, 'total_amount');
        $queryId = data_get($query, 'id');

        if (! is_string($reference) || ! is_string($telegramUserId) && ! is_int($telegramUserId)
            || ! is_int($amount) || ! is_string($queryId) || $queryId === ''
            || data_get($query, 'currency') !== 'XTR'
            || ! PaymentSettings::current()->telegram_stars_enabled) {
            return false;
        }

        return DB::transaction(function () use ($reference, $telegramUserId, $amount, $queryId, $events): bool {
            $order = Order::query()->where('reference', $reference)->lockForUpdate()->first();

            if (! $order) {
                return false;
            }

            if ($order->payment_method !== 'telegram_stars'
                || $order->payment_currency !== 'XTR'
                || $order->payment_amount_units !== $amount
                || (string) $order->user->telegram_user_id !== (string) $telegramUserId
                || $order->status !== 'pending'
                || $order->paid_at) {
                $events->record(
                    'telegram_stars',
                    'webhook_rejected',
                    'Telegram Stars pre-checkout не совпал с ожидаемыми параметрами заказа.',
                    $order,
                    statusFrom: $order->status,
                    statusTo: $order->status,
                );

                return false;
            }

            if ($order->telegram_pre_checkout_id && $order->telegram_pre_checkout_id !== $queryId) {
                $events->record(
                    'telegram_stars',
                    'webhook_rejected',
                    'Повторный Telegram Stars pre-checkout не прошёл проверку заказа.',
                    $order,
                    statusFrom: $order->status,
                    statusTo: $order->status,
                );

                return false;
            }

            $order->update([
                'telegram_pre_checkout_id' => $queryId,
                'telegram_pre_checkout_at' => now(),
            ]);
            $events->record(
                'telegram_stars',
                'webhook_verified',
                'Telegram Stars pre-checkout проверен по заказу и разрешён.',
                $order,
                statusFrom: 'pending',
                statusTo: 'pending',
            );

            return true;
        });
    }

    private function completeStarsPayment(
        array $payment,
        string $telegramUserId,
        PaymentEventLogger $events,
    ): bool {
        $reference = data_get($payment, 'invoice_payload');
        $chargeId = data_get($payment, 'telegram_payment_charge_id');
        $amount = data_get($payment, 'total_amount');

        if (! is_string($reference) || ! is_string($chargeId) || $chargeId === ''
            || ! is_int($amount) || data_get($payment, 'currency') !== 'XTR') {
            Log::warning('Telegram Stars successful payment has invalid fields.');

            return false;
        }

        $paymentId = 'telegram-stars:'.$chargeId;
        $result = DB::transaction(function () use ($reference, $telegramUserId, $amount, $paymentId, $events): array {
            $order = Order::query()->where('reference', $reference)->lockForUpdate()->first();

            if (! $order) {
                return [false, false];
            }

            if ($order->payment_method !== 'telegram_stars'
                || $order->payment_currency !== 'XTR'
                || $order->payment_amount_units !== $amount
                || (string) $order->user->telegram_user_id !== $telegramUserId
                || ! $order->telegram_pre_checkout_id) {
                $events->record(
                    'telegram_stars',
                    'webhook_rejected',
                    'Telegram Stars successful payment не совпал с ожидаемыми параметрами заказа.',
                    $order,
                    statusFrom: $order->status,
                    statusTo: $order->status,
                );

                return [false, false];
            }

            if ($order->paid_at) {
                return [
                    $order->payment_id === $paymentId,
                    $order->payment_id === $paymentId
                        && in_array($order->status, ['paid_unassigned', 'provisioning_failed'], true),
                ];
            }

            if ($order->status !== 'pending' || Order::query()->where('payment_id', $paymentId)->exists()) {
                $events->record(
                    'telegram_stars',
                    'webhook_rejected',
                    'Telegram Stars successful payment уже обработан или заказ не ожидает оплаты.',
                    $order,
                    statusFrom: $order->status,
                    statusTo: $order->status,
                );

                return [false, false];
            }

            $oldStatus = $order->status;
            $order->update([
                'payment_id' => $paymentId,
                'paid_at' => now(),
                'status' => 'paid_unassigned',
                'error_message' => null,
            ]);
            $events->record(
                'telegram_stars',
                'webhook_verified',
                'Успешная оплата Telegram Stars проверена и привязана к заказу.',
                $order,
                statusFrom: $oldStatus,
                statusTo: 'paid_unassigned',
                idempotencyKey: 'telegram-stars-paid-order:'.$order->id,
            );

            return [true, true];
        });

        if (! $result[0]) {
            Log::warning('Telegram Stars payment could not be matched to a pending order.', [
                'order_reference' => $reference,
            ]);

            return false;
        }

        if ($result[1]) {
            ProvisionSubscription::dispatch(Order::query()->where('reference', $reference)->value('id'));
        }

        return true;
    }

    private function linkAccount(string $telegramUserId, string $token): bool
    {
        return DB::transaction(function () use ($telegramUserId, $token): bool {
            $link = TelegramLinkToken::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $link || $link->used_at || $link->expires_at->isPast()) {
                return false;
            }

            $user = User::query()->whereKey($link->user_id)->lockForUpdate()->first();

            if (! $user || $user->telegram_user_id || User::query()->where('telegram_user_id', $telegramUserId)->exists()) {
                return false;
            }

            $user->update(['telegram_user_id' => $telegramUserId]);
            $link->update(['used_at' => now()]);

            return true;
        });
    }
}
