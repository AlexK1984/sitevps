<section class="admin-section" id="mail-settings">
    <div class="section-heading"><div><span class="eyebrow">ПОЧТА И ВОССТАНОВЛЕНИЕ ДОСТУПА</span><h2>Настройки SMTP</h2></div></div>
    <div class="admin-grid">
        <form class="panel-card admin-form" method="post" action="{{ route('admin.mail-settings.update') }}" autocomplete="off">
            @csrf @method('PUT')
            <h3>Подключение почты</h3>
            <input type="hidden" name="smtp_enabled" value="0">
            <label class="checkbox"><input type="checkbox" name="smtp_enabled" value="1" @checked($paymentSettings->smtp_enabled)> Использовать SMTP из админ-панели вместо серверного .env</label>
            <div class="form-grid">
                <label>SMTP host<input name="smtp_host" value="{{ $paymentSettings->smtp_host }}" maxlength="255" autocomplete="off"></label>
                <label>Порт<input type="number" name="smtp_port" value="{{ $paymentSettings->smtp_port }}" min="1" max="65535"></label>
                <label>Шифрование
                    <select name="smtp_encryption">
                        <option value="tls" @selected($paymentSettings->smtp_encryption === 'tls')>STARTTLS</option>
                        <option value="ssl" @selected($paymentSettings->smtp_encryption === 'ssl')>SSL/TLS</option>
                    </select>
                </label>
                <label>Адрес отправителя<input type="email" name="smtp_from_address" value="{{ $paymentSettings->smtp_from_address }}" maxlength="255" autocomplete="off"></label>
            </div>
            <label>Имя отправителя<input name="smtp_from_name" value="{{ $paymentSettings->smtp_from_name }}" maxlength="255" autocomplete="off"></label>
            <label>SMTP логин
                <input type="password" name="smtp_username" maxlength="255" autocomplete="new-password" placeholder="{{ $paymentSettings->smtp_username_configured_in_admin ? 'Логин сохранён; оставьте пустым, чтобы не менять' : 'Необязательно' }}">
            </label>
            <label class="checkbox"><input type="checkbox" name="clear_smtp_username" value="1"> Удалить сохранённый SMTP логин</label>
            <label>SMTP пароль
                <input type="password" name="smtp_password" maxlength="1000" autocomplete="new-password" placeholder="{{ $paymentSettings->smtp_password_configured_in_admin ? 'Пароль сохранён; оставьте пустым, чтобы не менять' : 'Необязательно' }}">
            </label>
            <label class="checkbox"><input type="checkbox" name="clear_smtp_password" value="1"> Удалить сохранённый SMTP пароль</label>
            <p class="fine-print">
                @if($paymentSettings->smtp_configured_in_admin)
                    Активно подключение из админ-панели.
                @elseif($paymentSettings->smtp_enabled)
                    SMTP override включён, но настроен не полностью; отправка блокируется, чтобы не использовать скрытую резервную конфигурацию.
                @else
                    Используется конфигурация почты сервера из .env; SMTP override не включён.
                @endif
                Логин и пароль шифруются ключом APP_KEY, после сохранения в браузер не возвращаются. Изменения применяются при следующей отправке; APP_KEY и настройки сервера не меняются.
            </p>
            <button class="button button-full" type="submit">Сохранить настройки SMTP</button>
        </form>
        <form class="panel-card admin-form" method="post" action="{{ route('admin.mail-settings.test') }}">
            @csrf
            <h3>Проверка отправки</h3>
            <p class="muted">Тестовое письмо будет отправлено только на адрес вашей учётной записи администратора: {{ auth()->user()->email }}.</p>
            <button class="button button-outline button-full" type="submit">Отправить тестовое письмо</button>
        </form>
    </div>
</section>
