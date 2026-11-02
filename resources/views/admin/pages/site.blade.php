@extends('layouts.admin')

@section('title', 'Главная страница')

@section('admin-content')
<section class="dashboard-head admin-page-heading">
    <div><span class="eyebrow">СОДЕРЖИМОЕ САЙТА</span><h1>Главная страница</h1></div>
    <a class="button button-outline" href="{{ route('home') }}">На сайт →</a>
</section>
@include('admin.home-content')
@endsection
