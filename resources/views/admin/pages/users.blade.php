@extends('layouts.admin')

@section('title', 'Пользователи')

@section('admin-content')
<section class="dashboard-head admin-page-heading">
    <div><span class="eyebrow">АККАУНТЫ И ДОСТУП</span><h1>Пользователи</h1></div>
    <a class="button button-outline" href="{{ route('home') }}">На сайт →</a>
</section>
@include('admin.sections.users')
@endsection
