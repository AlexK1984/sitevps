@extends('layouts.admin')

@section('title', 'Тарифы')

@section('admin-content')
<section class="dashboard-head admin-page-heading">
    <div><span class="eyebrow">КАТАЛОГ</span><h1>Тарифы</h1></div>
    <a class="button button-outline" href="{{ route('home') }}">На сайт →</a>
</section>
@include('admin.sections.tariffs')
@endsection
