<section class="admin-section" id="tariffs">
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
