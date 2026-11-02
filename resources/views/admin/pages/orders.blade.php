@extends('layouts.admin')

@section('title', 'Заказы и платежи')

@section('admin-content')
<section class="dashboard-head admin-page-heading">
    <div><span class="eyebrow">ОПЕРАЦИИ</span><h1>Заказы и платежи</h1></div>
    <a class="button button-outline" href="{{ route('home') }}">На сайт →</a>
</section>
@include('admin.sections.orders')
@endsection
