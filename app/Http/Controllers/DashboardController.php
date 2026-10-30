<?php

namespace App\Http\Controllers;

use App\Models\PaymentSettings;
use App\Models\Tariff;
use App\Models\TelegramLinkToken;
use App\Services\YooKassaClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, YooKassaClient $yooKassa): View
    {
        $user = $request->user();

        return view('dashboard', [
            'user' => $user,
            'tariffs' => Tariff::query()->where('is_active', true)->orderBy('price_kopecks')->get(),
            'subscription' => $user->subscription()->with(['panel', 'tariff'])->first(),
            'orders' => $user->orders()->with('tariff')->latest()->limit(10)->get(),
            'telegramBotName' => config('services.telegram.bot_name'),
            'yookassaAvailable' => PaymentSettings::current()->yookassa_enabled && $yooKassa->configured(),
        ]);
    }

    public function createTelegramLink(Request $request): RedirectResponse
    {
        abort_unless(filled(config('services.telegram.bot_name')), 503, 'Telegram-бот пока не настроен.');

        $token = Str::random(32);

        TelegramLinkToken::query()->where('user_id', $request->user()->id)->delete();
        TelegramLinkToken::create([
            'user_id' => $request->user()->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(10),
        ]);

        return back()->with('telegram_link', 'https://t.me/'.ltrim(config('services.telegram.bot_name'), '@').'?start='.$token);
    }
}
