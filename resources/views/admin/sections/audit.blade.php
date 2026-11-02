<section class="admin-section" id="admin-audit">
    <div class="section-heading"><div><span class="eyebrow">АДМИНИСТРАТИВНЫЙ АУДИТ</span><h2>Изменения пользователей и сайта</h2></div></div>
    <div class="table-wrap"><table><thead><tr><th>Время</th><th>Администратор</th><th>Пользователь</th><th>Действие</th><th>Изменения</th></tr></thead><tbody>
        @forelse($adminAuditLogs as $audit)
            <tr>
                <td>{{ $audit->created_at->format('d.m.Y H:i:s') }}</td>
                <td>{{ $audit->actor?->email ?? '—' }}</td>
                <td>{{ $audit->subjectUser?->email ?? '—' }}</td>
                <td>{{ $audit->actionLabel() }}</td>
                <td>{{ $audit->summary }}</td>
            </tr>
        @empty<tr><td colspan="5" class="empty-cell">Записей пока нет.</td>@endforelse
    </tbody></table></div>
</section>
