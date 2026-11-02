@extends('layouts.admin')

@section('title', 'Платёжные события')

@section('admin-content')
<section class="dashboard-head admin-page-heading">
    <div><span class="eyebrow">ПЛАТЕЖИ</span><h1>Журнал платёжных событий</h1></div>
    <a class="button button-outline" href="{{ route('home') }}">На сайт →</a>
</section>
@include('admin.sections.payment-events')
@endsection
