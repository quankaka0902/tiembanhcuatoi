@php
    $asset = $rendering->image($imageProduct ?? [], $imageUrl ?? null);
@endphp
@if($asset)
<img src="{{ $asset['src'] }}" alt="{{ $imageAlt ?? '' }}" loading="{{ ($imagePriority ?? false) ? 'eager' : 'lazy' }}" decoding="async"
    @if($imagePriority ?? false) fetchpriority="high" @endif
    @if($asset['srcset']) srcset="{{ $asset['srcset'] }}" sizes="{{ $imageSizes ?? '(max-width: 600px) 50vw, 25vw' }}" @endif
    @if($asset['width'] && $asset['height']) width="{{ $asset['width'] }}" height="{{ $asset['height'] }}" @endif>
@else
<span class="sf-photo-placeholder" role="img" aria-label="{{ $imageAlt ?? 'Ảnh đang được cập nhật' }}"><span>Ảnh đang được cập nhật</span></span>
@endif
