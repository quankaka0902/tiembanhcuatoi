@php
    $seo = $page['props']['seo'] ?? [];
@endphp
<title inertia="title">{{ $seo['title'] ?? config('app.name') }}</title>
<meta inertia="robots" name="robots" content="{{ $seo['robots'] ?? 'noindex, nofollow' }}">
@if(!empty($seo['description']))<meta inertia="description" name="description" content="{{ $seo['description'] }}">@endif
@if(!empty($seo['canonical']))<link inertia="canonical" rel="canonical" href="{{ $seo['canonical'] }}">@endif
@foreach(['title', 'description', 'url', 'type', 'image', 'image:width', 'image:height'] as $property)
    @if(isset($seo['og'][$property]))<meta inertia="og:{{ $property }}" property="og:{{ $property }}" content="{{ $seo['og'][$property] }}">@endif
@endforeach
@if(!empty($seo['jsonLd']))
<script inertia="schema" type="application/ld+json">{!! json_encode($seo['jsonLd'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
@endif
