@extends('layouts.admin')

@section('title', 'Панели 3x-ui')

@section('admin-content')
<section class="dashboard-head admin-page-heading">
    <div><span class="eyebrow">СЕРВЕРЫ ДОСТУПА</span><h1>Панели 3x-ui</h1></div>
    <a class="button button-outline" href="{{ route('home') }}">На сайт →</a>
</section>
@include('admin.sections.panels')
@endsection
