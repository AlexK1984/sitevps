@extends('layouts.admin')

@section('title', 'Редактирование пользователя')

@section('admin-content')
<section class="dashboard-head admin-page-heading">
    <div><span class="eyebrow">ПОЛЬЗОВАТЕЛИ</span><h1>Редактирование учётной записи</h1><p>{{ $user->email }}</p></div>
    <a class="button button-outline" href="{{ route('admin.users') }}">К списку пользователей</a>
</section>

<form class="panel-card admin-form user-editor" method="post" action="{{ route('admin.users.update', $user) }}">
    @csrf @method('PUT')
    <h2>Профиль и доступ к сайту</h2>
    <div class="form-grid">
        <label>Имя<input name="name" value="{{ old('name', $user->name) }}" required maxlength="120"></label>
        <label>Email<input type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255"></label>
        <label>Статус учётной записи
            <select name="is_active" required>
                <option value="1" @selected((string) old('is_active', (int) $user->is_active) === '1')>Активна</option>
                <option value="0" @selected((string) old('is_active', (int) $user->is_active) === '0')>Отключена</option>
            </select>
        </label>
        <label>Роль
            <select name="is_admin" required>
                <option value="0" @selected((string) old('is_admin', (int) $user->is_admin) === '0')>Пользователь</option>
                <option value="1" @selected((string) old('is_admin', (int) $user->is_admin) === '1')>Администратор</option>
            </select>
        </label>
        <label>Назначенная панель
            <select name="assigned_panel_id">
                <option value="">Не назначена</option>
                @foreach($panels as $panel)
                    <option value="{{ $panel->id }}" @selected((string) old('assigned_panel_id', $user->assigned_panel_id) === (string) $panel->id)>{{ $panel->name }}</option>
                @endforeach
            </select>
        </label>
        <div><span class="field-label">Telegram</span><p>{{ $user->telegram_user_id ? 'Аккаунт привязан' : 'Не привязан' }}</p></div>
    </div>
    <p class="notice">Блокировка запрещает вход в сайт и отключает VPN-клиента на назначенной панели, если панель подтверждает операцию. Мягкое удаление сохраняет профиль, заказы и платёжную историю для аудита. Пароли здесь не просматриваются и не редактируются.</p>
    @if($user->id !== auth()->id())
        <div class="admin-user-actions">
            <form method="post" action="{{ route($user->is_active ? 'admin.users.ban' : 'admin.users.restore', $user) }}">
                @csrf
                <button class="button {{ $user->is_active ? 'button-danger' : 'button-outline' }}" type="submit">{{ $user->is_active ? 'Заблокировать пользователя' : 'Снять блокировку / восстановить' }}</button>
            </form>
            <form method="post" action="{{ route('admin.users.delete', $user) }}" onsubmit="return confirm('Мягко удалить пользователя? Вход будет запрещён, а профиль, заказы и платёжная история останутся в системе.')">
                @csrf @method('DELETE')
                <button class="button button-danger" type="submit">Мягко удалить</button>
            </form>
        </div>
    @endif

    @if($user->subscription)
        <h2>Подписка</h2>
        <p>Текущий статус: <strong>{{ $user->subscription->statusLabel() }}</strong>. Панель выдачи: <strong>{{ $user->subscription->panel?->name ?? 'не назначена' }}</strong>.</p>
        <div class="form-grid">
            <label>Тариф
                <select name="subscription[tariff_id]">
                    <option value="">Не выбран</option>
                    @foreach($tariffs as $tariff)
                        <option value="{{ $tariff->id }}" @selected((string) old('subscription.tariff_id', $user->subscription->tariff_id) === (string) $tariff->id)>{{ $tariff->name }}{{ $tariff->is_active ? '' : ' (неактивен)' }}</option>
                    @endforeach
                </select>
            </label>
            <label>Действует до<input type="datetime-local" name="subscription[expires_at]" value="{{ old('subscription.expires_at', $user->subscription->expires_at?->format('Y-m-d\TH:i')) }}" required></label>
            <label>Лимит трафика, ГБ<input type="number" name="subscription[traffic_limit_gb]" value="{{ old('subscription.traffic_limit_gb', (int) round($user->subscription->traffic_limit_bytes / 1_000_000_000)) }}" min="1" max="100000" required></label>
            <label>Одновременные IP (ориентировочно)<input type="number" name="subscription[connection_limit]" value="{{ old('subscription.connection_limit', $user->subscription->connection_limit) }}" min="1" max="100" required></label>
        </div>
        @if($user->subscription->last_error)<p class="notice notice-error">{{ $user->subscription->last_error }}</p>@endif
        <p class="muted">Изменения подписки синхронизируются с назначенной панелью; при отключённой учётной записи клиент остаётся выключенным. Статус оплаты и состояние выдачи не редактируются вручную.</p>
    @else
        <p class="muted">Подписки ещё нет. Создание подписки вручную не выполняется: доступ оформляется только после подтверждения заказа.</p>
    @endif
    <button class="button button-full" type="submit">Сохранить изменения</button>
</form>

<section class="admin-section">
    <div class="section-heading"><div><span class="eyebrow">ВОССТАНОВЛЕНИЕ ДОСТУПА</span><h2>Сброс пароля по email</h2></div></div>
    <p class="muted">Отправьте пользователю стандартную одноразовую ссылку сброса пароля. Пароль не просматривается и не задаётся администратором; ссылка действует 60 минут и отправляется через настроенную SMTP-почту.</p>
    <form method="post" action="{{ route('admin.users.password-reset', $user) }}">
        @csrf
        <button class="button button-outline" type="submit">Отправить ссылку сброса пароля</button>
    </form>
</section>

<section class="admin-section">
    <div class="section-heading"><div><span class="eyebrow">ИСТОРИЯ ЗАКАЗОВ</span><h2>Последние заказы пользователя</h2></div></div>
    <div class="table-wrap"><table><thead><tr><th>Дата</th><th>Тариф</th><th>Сумма</th><th>Статус</th></tr></thead><tbody>
        @forelse($userOrders as $order)
            <tr><td>{{ $order->created_at->format('d.m.Y H:i') }}</td><td>{{ $order->tariff?->name ?? '—' }}</td><td>{{ $order->paidAmountLabel() }}</td><td>{{ $order->statusLabel() }}</td></tr>
        @empty<tr><td colspan="4" class="empty-cell">Заказов пока нет.</td>@endforelse
    </tbody></table></div>
</section>
@endsection
