<section class="admin-section" id="payment-events">
    <div class="section-heading">
        <div><span class="eyebrow">АУДИТ</span><h2>Журнал платёжных событий</h2></div>
        <form method="get" action="{{ route('admin.payment-events') }}">
            <label>Метод
                <select name="event_provider" onchange="this.form.submit()">
                    <option value="">Все методы</option>
                    <option value="yookassa" @selected($eventProvider === 'yookassa')>ЮKassa</option>
                    <option value="nowpayments" @selected($eventProvider === 'nowpayments')>NOWPayments</option>
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
