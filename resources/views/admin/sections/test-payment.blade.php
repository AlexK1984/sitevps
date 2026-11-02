@if($paymentSettings->internal_test_payments_enabled)
<section class="admin-section" id="internal-test-payment">
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
