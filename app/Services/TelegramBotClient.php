<?php

namespace App\Services;

use App\Models\PaymentSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotClient
{
    public function configured(): bool
    {
        return filled(PaymentSettings::current()->telegramBotToken());
    }

    public function sendMessage(string $chatId, string $text, array $keyboard = []): void
    {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true];

        if ($keyboard !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        $this->call('sendMessage', $payload);
    }

    public function answerCallback(string $callbackId, string $text): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text]);
    }

    public function sendInvoice(string $chatId, string $title, string $description, string $payload, int $stars): void
    {
        $this->call('sendInvoice', [
            'chat_id' => $chatId,
            'title' => mb_substr($title, 0, 32),
            'description' => mb_substr($description, 0, 255),
            'payload' => $payload,
            'provider_token' => '',
            'currency' => 'XTR',
            'prices' => [['label' => mb_substr($title, 0, 32), 'amount' => $stars]],
        ]);
    }

    public function answerPreCheckoutQuery(string $queryId, bool $ok, ?string $errorMessage = null): void
    {
        $payload = ['pre_checkout_query_id' => $queryId, 'ok' => $ok];

        if (! $ok && $errorMessage !== null) {
            $payload['error_message'] = $errorMessage;
        }

        $this->call('answerPreCheckoutQuery', $payload, 4);
    }

    private function call(string $method, array $payload, int $timeoutSeconds = 10): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        $token = PaymentSettings::current()->telegramBotToken();

        if (! filled($token)) {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        $response = Http::asJson()
            ->timeout($timeoutSeconds)
            ->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload);

        if (! $response->successful() || ! $response->json('ok')) {
            throw new RuntimeException('Telegram API request failed.');
        }
    }
}
