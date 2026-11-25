@php
    $current = $location ?? $shop;
    $regions = $current['regions'] ?? [];
    $compact = $compact ?? false;
@endphp
<section class="sf-location panel {{ $compact ? 'sf-location-compact' : '' }}" @if(!$compact) id="co-so-dang-phuc-vu" @endif aria-label="Cơ sở đang phục vụ">
    <div class="sf-location-heading"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="2"/></svg><div><small>Cơ sở đang phục vụ</small><h2>{{ $current['name'] ?? $name }}</h2></div></div>
    <div class="sf-location-details"><p>{{ ($current['address'] ?? '') ?: 'Tiệm chưa cập nhật địa chỉ nhận bánh.' }}</p><p><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>{{ ($current['hours'] ?? '') ?: 'Liên hệ tiệm để xác nhận giờ mở cửa.' }}</p></div>
    @if($compact)<details class="sf-location-delivery"><summary>Vùng giao và phí giao</summary>@if($regions)<ul>@foreach($regions as $region)<li>{{ $region['name'] }} · {{ $rendering->money($region['fee']) }}</li>@endforeach</ul>@else<p>Tiệm chưa công bố vùng giao hàng.</p>@endif @if(isset($current['free_from']))<p>Miễn phí giao từ {{ $rendering->money($current['free_from']) }} tiền bánh sau giảm giá.</p>@endif</details>
    @else<div class="sf-location-regions"><strong>Khu vực giao hàng</strong>@if($regions)<ul>@foreach($regions as $region)<li>{{ $region['name'] }}<span> · {{ $rendering->money($region['fee']) }}</span></li>@endforeach</ul>@if(isset($current['free_from']))<p class="muted">Miễn phí giao từ {{ $rendering->money($current['free_from']) }} tiền bánh sau giảm giá.</p>@endif @else<p class="muted">Tiệm chưa công bố vùng giao hàng. Xem lựa chọn giao hoặc nhận khi đặt bánh.</p>@endif</div><p class="muted">Giờ nhận còn chỗ và phí giao được kiểm tra lại ở bước đặt bánh.</p>@endif
</section>
