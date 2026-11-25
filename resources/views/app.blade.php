<!DOCTYPE html>
@php
    $rendering = app(\App\Services\PublicRendering::class);
    $ssr = app(\Inertia\Ssr\Gateway::class)->dispatch($page);
    $publicPage = $rendering->isPublic($page);
@endphp
<html lang="vi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="referrer" content="no-referrer">
        <link rel="stylesheet" href="/fonts/fonts.css">
        @include('public-head')
        @viteReactRefresh
        @vite('resources/js/app.jsx')
    </head>
    <body>
        @if($ssr)
            {!! $ssr->body !!}
        @else
            <div id="app" data-page="{{ json_encode($page, JSON_THROW_ON_ERROR) }}" data-rendering="{{ $publicPage ? 'php' : 'client' }}">
                @if($publicPage)@include('public-fallback')@else<noscript>Vui lòng bật JavaScript để sử dụng trang này.</noscript>@endif
            </div>
        @endif
    </body>
</html>
