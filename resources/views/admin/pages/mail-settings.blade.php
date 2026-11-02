@extends('layouts.admin')

@section('title', 'Почта и SMTP')

@section('admin-content')
<section class="dashboard-head admin-page-heading">
    <div><span class="eyebrow">ПОЧТА И ВОССТАНОВЛЕНИЕ ДОСТУПА</span><h1>Настройки SMTP</h1></div>
    <a class="button button-outline" href="{{ route('home') }}">На сайт →</a>
</section>
@include('admin.sections.mail-settings')
@endsection
