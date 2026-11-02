@extends('layouts.admin')

@section('title', 'SEO')

@section('admin-content')
<section class="dashboard-head admin-page-heading">
    <div><span class="eyebrow">ПОИСКОВАЯ ОПТИМИЗАЦИЯ</span><h1>SEO и социальная информация</h1></div>
    <a class="button button-outline" href="{{ route('home') }}">На сайт →</a>
</section>
<section class="admin-section">
    <div class="section-heading"><div><span class="eyebrow">ПОИСКОВАЯ ОПТИМИЗАЦИЯ</span><h2>SEO и социальная информация</h2></div></div>
    <p class="muted">Эти метаданные используются в поисковой выдаче и при публикации ссылки. Они не гарантируют конкретную позицию в поиске.</p>
    <form class="panel-card admin-form content-editor" method="post" action="{{ route('admin.seo.update') }}">
        @csrf @method('PUT')
        <label>Название сайта<input name="site_name" value="{{ $siteContent->site_name }}" required maxlength="80"></label>
        <label>SEO title (до 70 символов)<input name="meta_title" value="{{ $siteContent->meta_title }}" required maxlength="70"></label>
        <label>Meta description (до 180 символов)<textarea name="meta_description" rows="3" required maxlength="180">{{ $siteContent->meta_description }}</textarea></label>
        <label>Canonical URL корня сайта<input type="url" name="canonical_url" value="{{ $siteContent->canonical_url }}" placeholder="{{ config('app.url') }}" maxlength="255"></label>
        <label>Изображение Open Graph (HTTPS URL, необязательно)<input type="url" name="og_image_url" value="{{ $siteContent->og_image_url }}" maxlength="500"></label>
        <button class="button button-full" type="submit">Сохранить SEO-настройки</button>
    </form>
</section>
@endsection
