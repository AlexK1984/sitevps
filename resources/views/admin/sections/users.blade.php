<section class="admin-section" id="users">
    <div class="section-heading"><div><span class="eyebrow">НАЗНАЧЕНИЯ</span><h2>Пользователи</h2></div></div>
    <form class="admin-user-search" method="get" action="{{ route('admin.users') }}">
        <label>Поиск по имени или email<input name="user_search" value="{{ request('user_search') }}" maxlength="255"></label>
        <button class="button button-small button-outline" type="submit">Найти</button>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Пользователь</th><th>Email</th><th>Статус сайта</th><th>Назначенная панель</th><th>Управление</th></tr></thead><tbody>
        @foreach($users as $user)
            <tr>
                <td>{{ $user->name }} @if($user->is_admin)<span class="status-pill">Администратор</span>@endif</td>
                <td>{{ $user->email }}</td>
                <td><span class="status-pill {{ $user->is_active ? 'status-active' : 'status-offline' }}">{{ $user->is_active ? 'Активна' : 'Отключена' }}</span></td>
                <td>{{ $user->assignedPanel?->name ?? 'Не назначена' }}</td>
                <td><a class="button button-small button-outline" href="{{ route('admin.users.edit', $user) }}">Редактировать</a></td>
            </tr>
        @endforeach
    </tbody></table></div>
    <div class="pagination">{{ $users->links() }}</div>
</section>
