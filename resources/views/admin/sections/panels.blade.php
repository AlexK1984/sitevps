<section class="admin-section">
    <div class="section-heading"><div><span class="eyebrow">СЕРВЕРЫ ДОСТУПА</span><h2>Панели 3x-ui</h2></div></div>
    <p class="muted">Панельный пароль хранится в зашифрованном виде и не отображается повторно. Используйте адрес с HTTPS.</p>
    <form class="panel-card admin-form" method="post" action="{{ route('admin.panels.default.update') }}">
        @csrf @method('PUT')
        <h3>Панель по умолчанию</h3>
        <p class="muted">Назначается новым пользователям и пользователям без подписки при создании заказа. Существующие подписки автоматически не переносятся.</p>
        <label>Автоматическое назначение
            <select name="default_panel_id">
                <option value="">Выключено</option>
                @foreach($onlinePanels as $onlinePanel)
                    <option value="{{ $onlinePanel->id }}" @selected((int) $defaultPanelId === $onlinePanel->id)>{{ $onlinePanel->name }}</option>
                @endforeach
            </select>
        </label>
        @if($onlinePanels->isEmpty())
            <p class="muted">Нет панелей со статусом «Онлайн». Сначала проверьте подключение панели.</p>
        @endif
        <button class="button button-outline button-full" type="submit">Сохранить</button>
    </form>
    <div class="admin-grid">
        <form class="panel-card admin-form" method="post" action="{{ route('admin.panels.store') }}">
            @csrf<h3>Добавить панель</h3>
            <label>Название<input name="name" required></label><label>HTTPS URL<input type="url" name="base_url" placeholder="https://panel.example.com" required></label>
            <label>Префикс ссылки подписки<input type="url" name="subscription_url_prefix" placeholder="https://vpn.example.com:2096/sub" required></label>
            <label>Логин<input name="username" required autocomplete="off"></label><label>Пароль<input type="password" name="password" required autocomplete="new-password"></label>
            <p class="muted">Приложение автоматически найдёт все активные inbound VLESS, VMess, Trojan и Shadowsocks и включит их в подписку пользователя.</p>
            <label>Flow для поддерживающих его подключений<input name="flow" placeholder="(если требуется)"></label>
            <button class="button button-full" type="submit">Добавить панель</button>
        </form>
        <div class="admin-list">
            @forelse($panels as $panel)
                <form class="panel-card admin-form" method="post" action="{{ route('admin.panels.update', $panel) }}">
                    @csrf @method('PUT')<div class="card-heading"><h3>{{ $panel->name }}</h3><span class="status-pill">Автовыбор inbound</span></div>
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
                    <p class="muted">В подписку включаются все активные inbound VLESS, VMess, Trojan и Shadowsocks.</p>
                    <label>Flow для поддерживающих его подключений<input name="flow" value="{{ $panel->flow }}"></label>
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
