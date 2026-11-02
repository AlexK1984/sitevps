<section class="admin-section" id="orders">
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
