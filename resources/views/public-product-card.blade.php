<article class="product-card sf-product-card"><a class="sf-card-link" href="{{ $rendering->productUrl($item, $filters ?? []) }}">
    <div class="product-photo sf-card-photo">
        @include('public-image', ['imageProduct' => $item, 'imageUrl' => $item['images'][0] ?? null, 'imageAlt' => $item['name'], 'imagePriority' => false])
        @if(isset($item['price']) && ($item['stock'] ?? 0) <= 0)<span class="photo-badge">Hết hàng</span>@endif
        @if(empty($item['images_approved']))<span class="sf-image-note">Ảnh minh họa / chờ xác minh</span>@endif
    </div>
    <div class="product-body"><span class="product-category">{{ $item['category'] ?? '' }}</span><h3>{{ $item['name'] }}</h3>
        <p>{{ $item['description'] }}</p><div class="sf-card-meta">{{ empty($item['details_verified']) ? 'Thông tin chờ tiệm xác minh' : 'Xem cỡ và thông tin bánh' }}</div>
        <div class="product-bottom"><strong>{{ isset($item['price']) ? ((!empty($item['price_varies']) ? 'Từ ' : '').$rendering->money($item['price'])) : 'Chưa mở bán' }}</strong><span aria-hidden="true">→</span></div>
    </div>
</a></article>
