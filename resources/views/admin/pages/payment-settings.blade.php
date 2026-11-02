@extends('layouts.admin')

@section('title', 'Настройки оплат')

@section('admin-content')
<section class="dashboard-head admin-page-heading">
    <div><span class="eyebrow">ПЛАТЁЖНЫЕ МЕТОДЫ</span><h1>Настройки оплат</h1></div>
    <a class="button button-outline" href="{{ route('home') }}">На сайт →</a>
</section>
@include('admin.sections.payment-settings')
@endsection
