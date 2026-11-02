<section class="admin-section" id="payment-settings">
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
            <input type="hidden" name="nowpayments_enabled" value="0">
            <label class="checkbox"><input type="checkbox" name="nowpayments_enabled" value="1" @checked($paymentSettings->nowpayments_enabled)> Разрешить оплату через NOWPayments</label>
            <p class="muted">Включение доступно только после сохранения реквизитов и проверки поддержки RUB через API NOWPayments.</p>
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
        <div class="panel-card admin-form">
            <h3>Настройки криптооплаты</h3>
            <p class="notice">Заказ создаётся как hosted invoice в RUB; покупатель выбирает доступную монету на странице NOWPayments. Сеть, курс и срок оплаты определяются провайдером. Сайт выдаёт доступ только после подтверждения статуса finished через подписанный IPN и дополнительную проверку платежа по API.</p>
        </div>
    </div>
    <form class="panel-card admin-form" method="post" action="{{ route('admin.payment-settings.nowpayments-rub-check') }}">
        @csrf
        <button class="button button-outline button-full" type="submit">Проверить поддержку RUB через NOWPayments</button>
    </form>
    <form class="panel-card admin-form" method="post" action="{{ route('admin.payment-credentials.update') }}" autocomplete="off">
        @csrf @method('PUT')
        <h3>NOWPayments</h3>
        <p class="muted">
            @if($paymentSettings->nowpayments_configured_in_admin)
                API key и IPN secret сохранены в админ-панели
            @else
                API key и/или IPN secret не заданы
            @endif
        </p>
        <p class="muted">
            Поддержка RUB:
            @if($paymentSettings->nowpayments_rub_verified)
                подтверждена API
            @else
                ещё не проверена
            @endif
        </p>
        <label>API key<input type="password" name="nowpayments_api_key" maxlength="500" autocomplete="new-password" placeholder="Оставьте пустым, чтобы не менять"></label>
        <label>IPN secret<input type="password" name="nowpayments_ipn_secret" maxlength="500" autocomplete="new-password" placeholder="Оставьте пустым, чтобы не менять"></label>
        <label class="checkbox"><input type="checkbox" name="clear_nowpayments" value="1"> Удалить сохранённые реквизиты NOWPayments</label>
        <p class="fine-print">Оба значения шифруются ключом APP_KEY и не выводятся повторно. При смене реквизитов оплату потребуется проверить и включить заново. IPN URL: <code>{{ route('webhooks.nowpayments', absolute: true) }}</code></p>
        <button class="button button-full" type="submit">Сохранить реквизиты NOWPayments</button>
    </form>
</section>
