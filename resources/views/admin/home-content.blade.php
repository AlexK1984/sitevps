<section class="admin-section" id="home-content">
    <div class="section-heading"><div><span class="eyebrow">СОДЕРЖИМОЕ И SEO</span><h2>Главная страница</h2></div></div>
    <p class="muted">Только обычный текст: HTML и JavaScript запрещены. SEO-настройки помогают поисковым системам понять страницу, но не гарантируют конкретную позицию в выдаче.</p>
    <form class="panel-card admin-form content-editor" method="post" action="{{ route('admin.home-content.update') }}">
        @csrf @method('PUT')
        <h3>Поисковая и социальная информация</h3>
        <div class="form-grid">
            <label>Название сайта<input name="site_name" value="{{ $siteContent->site_name }}" required maxlength="80"></label>
            <label>SEO title (до 70 символов)<input name="meta_title" value="{{ $siteContent->meta_title }}" required maxlength="70"></label>
        </div>
        <label>Meta description (до 180 символов)<textarea name="meta_description" rows="2" required maxlength="180">{{ $siteContent->meta_description }}</textarea></label>
        <div class="form-grid">
            <label>Canonical URL корня сайта<input type="url" name="canonical_url" value="{{ $siteContent->canonical_url }}" placeholder="{{ config('app.url') }}" maxlength="255"></label>
            <label>Изображение Open Graph (HTTPS URL, необязательно)<input type="url" name="og_image_url" value="{{ $siteContent->og_image_url }}" maxlength="500"></label>
        </div>
        <h3>Первый экран</h3>
        <div class="form-grid">
            <label>Небольшой заголовок<input name="hero_badge" value="{{ $siteContent->hero_badge }}" required maxlength="100"></label>
            <label>Заголовок страницы<input name="hero_title" value="{{ $siteContent->hero_title }}" required maxlength="120"></label>
        </div>
        <label>Описание<textarea name="hero_body" rows="3" required maxlength="1000">{{ $siteContent->hero_body }}</textarea></label>
        <div class="form-grid">
            <label>Текст основной кнопки<input name="hero_cta_label" value="{{ $siteContent->hero_cta_label }}" required maxlength="60"></label>
            <label>Действие кнопки
                <select name="hero_cta_target" required>
                    <option value="#plans" @selected($siteContent->hero_cta_target === '#plans')>К тарифам на этой странице</option>
                    <option value="account" @selected($siteContent->hero_cta_target === 'account')>К регистрации / личному кабинету</option>
                </select>
            </label>
        </div>
        <div class="form-grid">
            <label>Факт под кнопкой 1<input name="hero_fact_one" value="{{ $siteContent->hero_fact_one }}" required maxlength="80"></label>
            <label>Факт под кнопкой 2<input name="hero_fact_two" value="{{ $siteContent->hero_fact_two }}" required maxlength="80"></label>
        </div>
        <h3>Тарифы и описание секции</h3>
        <div class="form-grid">
            <label>Надзаголовок<input name="plans_eyebrow" value="{{ $siteContent->plans_eyebrow }}" required maxlength="80"></label>
            <label>Заголовок секции<input name="plans_title" value="{{ $siteContent->plans_title }}" required maxlength="120"></label>
        </div>
        <label>Описание секции тарифов<textarea name="plans_description" rows="2" required maxlength="500">{{ $siteContent->plans_description }}</textarea></label>
        <h3>Как начать: три шага</h3>
        <div class="form-grid">
            <label>Надзаголовок<input name="steps_eyebrow" value="{{ $siteContent->steps_eyebrow }}" required maxlength="80"></label>
            <label>Заголовок секции<input name="steps_title" value="{{ $siteContent->steps_title }}" required maxlength="120"></label>
        </div>
        <div class="form-grid">
            <label>Шаг 1 — заголовок<input name="step_one_title" value="{{ $siteContent->step_one_title }}" required maxlength="80"></label>
            <label>Шаг 1 — текст<textarea name="step_one_body" rows="2" required maxlength="500">{{ $siteContent->step_one_body }}</textarea></label>
            <label>Шаг 2 — заголовок<input name="step_two_title" value="{{ $siteContent->step_two_title }}" required maxlength="80"></label>
            <label>Шаг 2 — текст<textarea name="step_two_body" rows="2" required maxlength="500">{{ $siteContent->step_two_body }}</textarea></label>
            <label>Шаг 3 — заголовок<input name="step_three_title" value="{{ $siteContent->step_three_title }}" required maxlength="80"></label>
            <label>Шаг 3 — текст<textarea name="step_three_body" rows="2" required maxlength="500">{{ $siteContent->step_three_body }}</textarea></label>
        </div>
        <label>Текст внизу сайта<input name="footer_text" value="{{ $siteContent->footer_text }}" required maxlength="180"></label>
        <button class="button button-full" type="submit">Сохранить главную и SEO</button>
    </form>
</section>
