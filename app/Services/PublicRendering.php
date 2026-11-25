<?php

namespace App\Services;

use Illuminate\Support\Arr;

class PublicRendering
{
    private const FIELDS = [
        'Home' => ['categories', 'featured', 'occasions', 'currentLocation'],
        'Catalog' => ['products', 'categories', 'filters', 'filterOptions', 'currentLocation', 'currentCategory'],
        'Product' => ['product', 'suggestedVariantId', 'selectionFilters'],
        'OrderPolicy' => ['policy', 'privacy'],
        'PublicInfo' => ['info'],
    ];

    public function isPublic(array $page): bool
    {
        $path = parse_url($page['url'] ?? '', PHP_URL_PATH);

        return match ($page['component'] ?? '') {
            'Home' => $path === '/',
            'Catalog' => (bool) preg_match('~^/thuc-don(?:/[a-z0-9-]+)?$~D', (string) $path),
            'Product' => (bool) preg_match('~^/banh/[a-z0-9-]+$~D', (string) $path),
            'OrderPolicy' => $path === '/chinh-sach-dat-banh',
            'PublicInfo' => in_array($path, ['/gioi-thieu', '/lien-he', '/cau-hoi-thuong-gap', '/cua-hang'], true),
            default => false,
        };
    }

    public function props(array $page): array
    {
        $props = json_decode(json_encode($page['props'] ?? [], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        if (($page['component'] ?? '') === 'Catalog' && isset($props['products'])) {
            // Paginator URLs must not carry an attacker-controlled Host or unknown query.
            $products = Arr::only($props['products'], ['data', 'current_page', 'last_page', 'per_page', 'total', 'from', 'to']);
            $current = max(1, (int) ($products['current_page'] ?? 1));
            $last = max(1, (int) ($products['last_page'] ?? 1));
            $filters = $props['filters'] ?? [];
            $category = $props['currentCategory'] ?? null;
            $url = fn (int $number) => $this->catalogUrl(array_replace($filters, ['page' => $number]), $category);
            $props['products'] = $products + [
                'path' => $this->catalogUrl([], $category),
                'first_page_url' => $url(1), 'last_page_url' => $url($last),
                'prev_page_url' => $current > 1 ? $url($current - 1) : null,
                'next_page_url' => $current < $last ? $url($current + 1) : null,
            ];
        }

        return $props;
    }

    public function ssrPage(array $page): ?array
    {
        if (! $this->isPublic($page)) {
            return null;
        }
        $props = $this->props($page);
        if (! empty($props['auth']) || ! empty($props['cartCount']) || ! empty($props['notice']) || ! empty($props['errors'])) {
            return null;
        }
        $public = Arr::only($props, [...self::FIELDS[$page['component']], 'seo', 'shop']);
        $public += ['auth' => null, 'cartCount' => 0, 'notice' => null, 'errors' => []];
        // Do not pass arbitrary shared props or unvalidated query strings to Node.
        parse_str((string) parse_url($page['url'], PHP_URL_QUERY), $requestedKeys);
        $filters = array_intersect_key($props['filters'] ?? $props['selectionFilters'] ?? [], $requestedKeys);
        $query = $this->query($filters);

        return [
            'component' => $page['component'], 'props' => $public,
            'url' => parse_url($page['url'], PHP_URL_PATH).($query ? '?'.$query : ''),
            'version' => $page['version'] ?? null,
            'clearHistory' => (bool) ($page['clearHistory'] ?? false),
            'encryptHistory' => (bool) ($page['encryptHistory'] ?? false),
        ];
    }

    public function query(array $filters): string
    {
        return http_build_query(array_filter(Arr::only($filters,
            ['q', 'category', 'occasion', 'guests', 'flavor', 'min', 'max', 'availability', 'sort', 'page']),
            fn ($value) => is_scalar($value) && $value !== ''), '', '&', PHP_QUERY_RFC3986);
    }

    public function catalogUrl(array $filters = [], ?array $category = null): string
    {
        $path = '/thuc-don';
        if ($category) {
            $path .= '/'.rawurlencode($category['slug']);
            unset($filters['category']);
        }
        $query = $this->query($filters);

        return $path.($query ? '?'.$query : '');
    }

    public function productUrl(array $product, array $filters = []): string
    {
        $query = $this->query($filters);

        return '/banh/'.rawurlencode($product['slug']).($query ? '?'.$query : '');
    }

    public function money(int|string|null $value): string
    {
        return number_format((int) $value, 0, ',', '.').' ₫';
    }

    public function image(array $product, ?string $url): array
    {
        $asset = $product['image_assets'][$url ?? ''] ?? [];
        $src = $asset['src'] ?? $url;
        if (! is_string($src) || ! preg_match('~^(?:/(?!/)|https?://)~i', $src)) {
            return [];
        }

        return ['src' => $src, 'srcset' => $asset['srcset'] ?? null,
            'width' => $asset['width'] ?? null, 'height' => $asset['height'] ?? null];
    }

    public function primaryImage(array $page): array
    {
        $props = $this->props($page);
        if (($page['component'] ?? '') === 'Product') {
            $product = $props['product'];

            return $this->image($product, $product['images'][0] ?? null);
        }
        if (($page['component'] ?? '') === 'Home') {
            foreach ($props['featured'] ?? [] as $product) {
                if (! empty($product['images_approved']) && ! empty($product['images'][0])) {
                    return $this->image($product, $product['images'][0]);
                }
            }
        }

        return [];
    }
}
