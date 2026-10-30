@extends('layouts.app')

@section('title', 'Администрирование')

@section('content')
<section class="dashboard-head">
    <div><span class="eyebrow">УПРАВЛЕНИЕ СЕРВИСОМ</span><h1>Администрирование</h1><p>Пользователи, тарифы, платежи и удалённые панели 3x-ui.</p></div>
    <a class="button button-outline" href="{{ route('home') }}">На сайт →</a>
</section>

<section class="admin-section">
    <div class="section-heading"><div><span class="eyebrow">ПЛАТЁЖНЫЕ МЕТОДЫ</span><h2>Настройки оплат</h2></div></div>
    <div class="admin-grid">
        <form class="panel-card admin-form" method="post" action="{{ route('admin.payment-settings.update') }}">
            @csrf @method('PUT')
            <h3>Доступные способы</h3>
            <input type="hidden" name="yookassa_enabled" value="0">
            <label class="checkbox"><input type="checkbox" name="yookassa_enabled" value="1" @checked($paymentSettings->yookassa_enabled)> Разрешить оплату через ЮKassa</label>
            <p class="muted">Платёжные реквизиты задаются ниже или остаются в серверном файле .env.</p>
            <input type="hidden" name="telegram_stars_enabled" value="0">
            <label class="checkbox"><input type="checkbox" name="telegram_stars_enabled" value="1" @checked($paymentSettings->telegram_stars_enabled)> Разрешить Telegram Stars в боте</label>
            <p class="muted">Токен бота задаётся выше или в .env; секрет webhook остаётся в .env. Для каждого тарифа укажите цену в Stars ниже.</p>
            <input type="hidden" name="internal_test_payments_enabled" value="0">
            <label class="checkbox"><input type="checkbox" name="internal_test_payments_enabled" value="1" @checked($paymentSettings->internal_test_payments_enabled)> Разрешить внутреннюю тестовую оплату</label>
            <p class="notice">ВНИМАНИЕ: тестовая симуляция не обращается к ЮKassa или Telegram и не списывает деньги, но создаёт оплаченный заказ и выдаёт/продлевает реальный доступ на назначенной панели. Тестовые заказы будут помечены «TEST».</p>
            <button class="button button-full" type="submit">Сохранить способы оплаты</button>
        </form>
        <form class="panel-card admin-form" method="post" action="{{ route('admin.payment-credentials.update') }}" autocomplete="off">
            @csrf @method('PUT')
            <h3>Учётные данные сервисов</h3>
            <p class="muted">
                ЮKassa:
                @if($paymentSettings->yookassa_configured_in_admin)
                    настроена в админ-панели
                @elseif($paymentSettings->yookassa_configured)
                    используется конфигурация сервера (.env)
                @else
                    не настроена
                @endif
            </p>
            <label>Shop ID<input type="password" name="yookassa_shop_id" maxlength="255" autocomplete="new-password" placeholder="Оставьте пустым, чтобы не менять"></label>
            <label>Секретный ключ<input type="password" name="yookassa_secret_key" maxlength="500" autocomplete="new-password" placeholder="Оставьте пустым, чтобы не менять"></label>
            <label class="checkbox"><input type="checkbox" name="clear_yookassa" value="1"> Удалить сохранённые данные ЮKassa и вернуться к .env</label>

            <p class="muted">
                Telegram Bot:
                @if($paymentSettings->telegram_configured_in_admin)
                    токен задан в админ-панели
                @elseif($paymentSettings->telegram_configured)
                    используется конфигурация сервера (.env)
                @else
                    не настроен
                @endif
            </p>
            <label>Токен Telegram-бота<input type="password" name="telegram_bot_token" maxlength="500" autocomplete="new-password" placeholder="Оставьте пустым, чтобы не менять"></label>
            <label class="checkbox"><input type="checkbox" name="clear_telegram" value="1"> Удалить сохранённый токен и вернуться к .env</label>
            <p class="fine-print">Значения шифруются ключом APP_KEY в базе. Секрет webhook Telegram по-прежнему задаётся только на сервере в .env. Секретные поля не сохраняются при ошибке проверки формы.</p>
            <button class="button button-full" type="submit">Сохранить учётные данные</button>
        </form>
        <form class="panel-card admin-form" method="post" action="{{ route('admin.payment-settings.update') }}">
            @csrf @method('PUT')
            <input type="hidden" name="yookassa_enabled" value="{{ $paymentSettings->yookassa_enabled ? '1' : '0' }}">
            <input type="hidden" name="telegram_stars_enabled" value="{{ $paymentSettings->telegram_stars_enabled ? '1' : '0' }}">
            <input type="hidden" name="internal_test_payments_enabled" value="{{ $paymentSettings->internal_test_payments_enabled ? '1' : '0' }}">
            <h3>Подготовка криптооплаты</h3>
            <p class="notice">Криптооплата отключена: провайдер и API ещё не выбраны. Эти общие параметры сохраняются для последующей интеграции и не создают платёжные адреса или подтверждения.</p>
            <label>Актив / валюта<input name="crypto_asset" value="{{ $paymentSettings->crypto_asset }}" placeholder="Например, USDT" maxlength="20"></label>
            <label>Сеть<input name="crypto_network" value="{{ $paymentSettings->crypto_network }}" placeholder="Например, сеть токена" maxlength="80"></label>
            <div class="form-grid">
                <label>Требуемые подтверждения<input type="number" name="crypto_confirmations" value="{{ $paymentSettings->crypto_confirmations }}" min="1" max="1000"></label>
                <label>Время на оплату, минут<input type="number" name="crypto_payment_ttl_minutes" value="{{ $paymentSettings->crypto_payment_ttl_minutes }}" min="1" max="1440"></label>
            </div>
            <label>Внутренняя заметка<textarea name="crypto_admin_notes" rows="3" maxlength="2000">{{ $paymentSettings->crypto_admin_notes }}</textarea></label>
            <button class="button button-outline button-full" type="submit">Сохранить подготовительные настройки</button>
        </form>
    </div>
</section>

@if($paymentSettings->internal_test_payments_enabled)
<section class="admin-section">
    <div class="section-heading"><div><span class="eyebrow">ВНУТРЕННЯЯ СИМУЛЯЦИЯ</span><h2>Тестовая оплата</h2></div></div>
    <p class="notice">Только для администраторов. Доступ будет реально выдан или продлён через обычную очередь на назначенной панели. Платёжный провайдер не вызывается, списания нет. Заказ будет помечен «TEST». Проверьте, что выбраны правильные пользователь и тариф.</p>
    <form class="panel-card admin-form" method="post" action="{{ route('admin.payments.test') }}">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ $testPaymentIdempotencyKey }}">
        <label>Пользователь с назначенной панелью
            <select name="user_id" required>
                <option value="">Выберите пользователя</option>
                @foreach($testPaymentUsers as $testUser)
                    <option value="{{ $testUser->id }}">{{ $testUser->email }}</option>
                @endforeach
            </select>
        </label>
        <label>Активный тариф
            <select name="tariff_id" required>
                <option value="">Выберите тариф</option>
                @foreach($testPaymentTariffs as $testTariff)
                    <option value="{{ $testTariff->id }}">{{ $testTariff->name }}</option>
                @endforeach
            </select>
        </label>
        @if($testPaymentUsers->isEmpty() || $testPaymentTariffs->isEmpty())
            <p class="muted">Для теста нужен пользователь с назначенной панелью и хотя бы один активный тариф.</p>
        @endif
        <button class="button button-full" type="submit" @disabled($testPaymentUsers->isEmpty() || $testPaymentTariffs->isEmpty())>Создать заказ TEST и выдать реальный доступ</button>
    </form>
</section>
@endif

<section class="admin-section">
    <div class="section-heading"><div><span class="eyebrow">КАТАЛОГ</span><h2>Тарифы</h2></div></div>
    <div class="admin-grid">
        <form class="panel-card admin-form" method="post" action="{{ route('admin.tariffs.store') }}">
            @csrf<h3>Новый тариф</h3>
            <label>Название<input name="name" required maxlength="120"></label>
            <label>Идентификатор<input name="slug" required pattern="[A-Za-z0-9_-]+" maxlength="120"></label>
            <label>Описание<textarea name="description" rows="2" maxlength="1000"></textarea></label>
            <div class="form-grid"><label>Цена, ₽<input type="number" name="price_rubles" min="0.01" step="0.01" required></label><label>Срок, дней<input type="number" name="duration_days" min="1" max="3650" required></label></div>
            <label>Цена в Telegram Stars<input type="number" name="price_stars" min="1" max="10000000" placeholder="Необязательно"></label>
            <div class="form-grid"><label>Трафик, ГБ<input type="number" name="traffic_gb" min="1" required></label><label>Одновременные IP<input type="number" name="connection_limit" min="1" max="100" value="1" required></label></div>
            <label class="checkbox"><input type="checkbox" name="is_active" value="1" checked> Активен</label>
            <button class="button button-full" type="submit">Создать тариф</button>
        </form>
        <div class="admin-list">
            @forelse($tariffs as $tariff)
                <form class="panel-card admin-form" method="post" action="{{ route('admin.tariffs.update', $tariff) }}">
                    @csrf @method('PUT')
                    <div class="card-heading"><h3>{{ $tariff->name }}</h3><span class="status-pill">{{ $tariff->is_active ? 'Активен' : 'Скрыт' }}</span></div>
                    <label>Название<input name="name" value="{{ $tariff->name }}" required maxlength="120"></label>
                    <label>Идентификатор<input name="slug" value="{{ $tariff->slug }}" required></label>
                    <label>Описание<textarea name="description" rows="2">{{ $tariff->description }}</textarea></label>
                    <div class="form-grid"><label>Цена, ₽<input type="number" name="price_rubles" value="{{ number_format($tariff->price_kopecks / 100, 2, '.', '') }}" min="0.01" step="0.01" required></label><label>Срок, дней<input type="number" name="duration_days" value="{{ $tariff->duration_days }}" min="1" max="3650" required></label></div>
                    <label>Цена в Telegram Stars<input type="number" name="price_stars" value="{{ $tariff->price_stars }}" min="1" max="10000000" placeholder="Необязательно"></label>
                    <div class="form-grid"><label>Трафик, ГБ<input type="number" name="traffic_gb" value="{{ $tariff->traffic_gb }}" min="1" required></label><label>Одновременные IP<input type="number" name="connection_limit" value="{{ $tariff->connection_limit }}" min="1" max="100" required></label></div>
                    <label class="checkbox"><input type="checkbox" name="is_active" value="1" @checked($tariff->is_active)> Активен</label>
                    <button class="button button-outline button-full" type="submit">Сохранить</button>
                </form>
            @empty<div class="empty-state">Добавьте первый тариф.</div>@endforelse
        </div>
    </div>
</section>

<section class="admin-section">
    <div class="section-heading"><div><span class="eyebrow">СЕРВЕРЫ ДОСТУПА</span><h2>Панели 3x-ui</h2></div></div>
    <p class="muted">Панельный пароль хранится в зашифрованном виде и не отображается повторно. Используйте адрес с HTTPS.</p>
    <div class="admin-grid">
        <form class="panel-card admin-form" method="post" action="{{ route('admin.panels.store') }}">
            @csrf<h3>Добавить панель</h3>
            <label>Название<input name="name" required></label><label>HTTPS URL<input type="url" name="base_url" placeholder="https://panel.example.com" required></label>
            <label>Префикс ссылки подписки<input type="url" name="subscription_url_prefix" placeholder="https://vpn.example.com:2096/sub" required></label>
            <label>Логин<input name="username" required autocomplete="off"></label><label>Пароль<input type="password" name="password" required autocomplete="new-password"></label>
            <label>ID inbound<input type="number" name="inbound_id" min="1" required></label>
            <div class="form-grid"><label>Протокол<select name="protocol"><option>vless</option><option>vmess</option><option>trojan</option><option>shadowsocks</option></select></label><label>Flow<input name="flow" placeholder="(если требуется)"></label></div>
            <button class="button button-full" type="submit">Добавить панель</button>
        </form>
        <div class="admin-list">
            @forelse($panels as $panel)
                <form class="panel-card admin-form" method="post" action="{{ route('admin.panels.update', $panel) }}">
                    @csrf @method('PUT')<div class="card-heading"><h3>{{ $panel->name }}</h3><span class="status-pill">{{ $panel->protocol }}</span></div>
                    <p class="panel-connection-status">
                        Статус подключения:
                        @if($panel->connection_status === 'online')
                            <span class="status-pill status-active">Онлайн</span>
                        @elseif($panel->connection_status === 'offline')
                            <span class="status-pill status-offline">Офлайн</span>
                        @else
                            <span class="status-pill">Не проверялась</span>
                        @endif
                        <br><small>Последняя проверка: {{ $panel->connection_checked_at?->format('d.m.Y H:i:s') ?? 'нет' }}</small>
                        @if($panel->connection_status === 'offline' && $panel->connectionDiagnosticMessage())
                            <br><small>{{ $panel->connectionDiagnosticMessage() }}</small>
                        @endif
                    </p>
                    <label>Название<input name="name" value="{{ $panel->name }}" required></label><label>HTTPS URL<input type="url" name="base_url" value="{{ $panel->base_url }}" required></label>
                    <label>Префикс ссылки подписки<input type="url" name="subscription_url_prefix" value="{{ $panel->subscription_url_prefix }}" placeholder="https://vpn.example.com:2096/sub" required></label>
                    <label>Логин<input name="username" value="{{ $panel->username }}" required></label><label>Новый пароль<input type="password" name="password" autocomplete="new-password" placeholder="Оставьте пустым без изменений"></label>
                    <label>ID inbound<input type="number" name="inbound_id" value="{{ $panel->inbound_id }}" min="1" required></label>
                    <div class="form-grid"><label>Протокол<select name="protocol">@foreach(['vless','vmess','trojan','shadowsocks'] as $protocol)<option value="{{ $protocol }}" @selected($panel->protocol === $protocol)>{{ $protocol }}</option>@endforeach</select></label><label>Flow<input name="flow" value="{{ $panel->flow }}"></label></div>
                    <button class="button button-outline button-full" type="submit">Сохранить</button>
                    <button class="button button-outline button-full" type="submit" form="check-panel-{{ $panel->id }}">Проверить подключение</button>
                    <button class="button button-danger button-full" type="submit" form="delete-panel-{{ $panel->id }}">Удалить панель</button>
                </form>
                <form id="check-panel-{{ $panel->id }}" method="post" action="{{ route('admin.panels.connection-test', $panel) }}">@csrf</form>
                <form id="delete-panel-{{ $panel->id }}" method="post" action="{{ route('admin.panels.delete', $panel) }}" onsubmit="return confirm('Удалить панель и очистить её назначения?')">@csrf @method('DELETE')</form>
            @empty<div class="empty-state">Панели ещё не добавлены.</div>@endforelse
        </div>
    </div>
</section>

<section class="admin-section">
    <div class="section-heading"><div><span class="eyebrow">НАЗНАЧЕНИЯ</span><h2>Пользователи</h2></div></div>
    <div class="table-wrap"><table><thead><tr><th>Пользователь</th><th>Email</th><th>Панель</th><th>Действие</th></tr></thead><tbody>
        @foreach($users as $user)
            <tr><td>{{ $user->name }} @if($user->is_admin)<span class="status-pill">Администратор</span>@endif</td><td>{{ $user->email }}</td><td>
                <form id="assign-{{ $user->id }}" method="post" action="{{ route('admin.users.panel', $user) }}">@csrf @method('PUT')
                    <select name="assigned_panel_id"><option value="">Не назначена</option>@foreach($panels as $panel)<option value="{{ $panel->id }}" @selected($user->assigned_panel_id === $panel->id)>{{ $panel->name }}</option>@endforeach</select>
                </form></td><td><button class="button button-small button-outline" type="submit" form="assign-{{ $user->id }}">Сохранить</button></td></tr>
        @endforeach
    </tbody></table></div>
    <div class="pagination">{{ $users->links() }}</div>
</section>

<section class="admin-section">
    <div class="section-heading"><div><span class="eyebrow">ПЛАТЕЖИ И ЗАКАЗЫ</span><h2>Последние 100 заказов</h2></div></div>
    <div class="table-wrap"><table><thead><tr><th>Дата</th><th>Пользователь</th><th>Тариф</th><th>Сумма</th><th>Панель</th><th>Статус</th><th></th></tr></thead><tbody>
        @forelse($orders as $order)
            <tr><td>{{ $order->created_at->format('d.m.Y H:i') }}</td><td>{{ $order->user->email }}</td><td>{{ $order->tariff->name }}</td><td>@if($order->is_test)<span class="status-pill status-test">TEST</span><br>@endif{{ $order->paidAmountLabel() }}<br><small>{{ $order->paymentMethodLabel() }}</small></td><td>{{ $order->panel?->name ?? '—' }}</td><td title="{{ $order->error_message }}">{{ $order->statusLabel() }}</td><td>
                @if(in_array($order->status, ['paid_unassigned','provisioning_failed'], true))
                    <form method="post" action="{{ route('admin.orders.retry', $order) }}">@csrf<button class="button button-small button-outline" type="submit">Повторить выдачу</button></form>
                @endif
            </td></tr>
        @empty<tr><td colspan="7" class="empty-cell">Заказов пока нет.</td>@endforelse
    </tbody></table></div>
</section>

<section class="admin-section">
    <div class="section-heading">
        <div><span class="eyebrow">АУДИТ</span><h2>Журнал платёжных событий</h2></div>
        <form method="get" action="{{ route('admin.index') }}">
            <label>Метод
                <select name="event_provider" onchange="this.form.submit()">
                    <option value="">Все методы</option>
                    <option value="yookassa" @selected($eventProvider === 'yookassa')>ЮKassa</option>
                    <option value="telegram_stars" @selected($eventProvider === 'telegram_stars')>Telegram Stars</option>
                    <option value="internal_test" @selected($eventProvider === 'internal_test')>Внутренний тест</option>
                </select>
            </label>
        </form>
    </div>
    <div class="table-wrap"><table><thead><tr><th>Время</th><th>Заказ</th><th>Пользователь</th><th>Метод</th><th>Событие</th><th>Статус</th><th>Описание</th><th>Администратор</th></tr></thead><tbody>
        @forelse($paymentEvents as $event)
            <tr>
                <td>{{ $event->created_at->format('d.m.Y H:i:s') }}</td>
                <td>{{ $event->order?->reference ? \Illuminate\Support\Str::limit($event->order->reference, 12) : '—' }} @if($event->order?->is_test)<span class="status-pill status-test">TEST</span>@endif</td>
                <td>{{ $event->order?->user?->email ?? '—' }}</td>
                <td>{{ $event->providerLabel() }}</td>
                <td>{{ $event->eventTypeLabel() }}</td>
                <td>{{ $event->status_from ?? '—' }} → {{ $event->status_to ?? '—' }}</td>
                <td>{{ $event->summary }}</td>
                <td>{{ $event->actor?->email ?? '—' }}</td>
            </tr>
        @empty<tr><td colspan="8" class="empty-cell">Событий пока нет.</td>@endforelse
    </tbody></table></div>
    <div class="pagination">{{ $paymentEvents->links() }}</div>
</section>
@endsection
