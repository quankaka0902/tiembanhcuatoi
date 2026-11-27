<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicSeo
{
    public const INFO_PAGES = [
        'about' => ['/gioi-thieu', 'Giới thiệu'], 'contact' => ['/lien-he', 'Liên hệ'],
        'faq' => ['/cau-hoi-thuong-gap', 'Câu hỏi thường gặp'], 'location' => ['/cua-hang', 'Cửa hàng'],
    ];

    public static function origin(): ?string
    {
        $url = trim((string) config('app.url'));
        $parts = parse_url($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public static function indexingEnabled(): bool
    {
        $origin = self::origin();
        $host = strtolower((string) parse_url($origin ?? '', PHP_URL_HOST));

        return app()->environment('production') && (bool) config('bakery.seo.indexing_enabled')
            && ! Branches::enabled()
            && ! config('bakery.demo_seed') && ! config('bakery.rehearsal') && str_starts_with($origin ?? '', 'https://')
            && ! LaunchReadiness::loopback($host) && ! str_ends_with($host, '.localhost');
    }

    public static function url(string $path): ?string
    {
        $origin = self::origin();
        if ($origin === null || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '#')) {
            return null;
        }

        return $origin.$path;
    }

    public static function description(?string $text): string
    {
        return Str::limit(preg_replace('/\s+/u', ' ', trim(strip_tags($text ?? ''))) ?? '', 160, '…');
    }

    public static function make(string $title, string $description, string $path, bool $approved = false, array $jsonLd = [], ?array $image = null): array
    {
        $canonical = self::url($path);
        $indexable = self::indexingEnabled() && $approved && $canonical !== null;
        $robots = $indexable ? 'index, follow' : 'noindex, follow';
        request()->attributes->set('public_seo_indexable', $indexable);
        request()->attributes->set('public_seo_robots', $robots);
        $description = self::description($description);
        $og = ['title' => $title, 'description' => $description, 'url' => $canonical, 'type' => 'website'];
        if ($image && $image['url']) {
            $og['image'] = $image['url'];
            if ($image['width'] && $image['height']) {
                $og['image:width'] = $image['width'];
                $og['image:height'] = $image['height'];
            }
        }

        return compact('title', 'description', 'canonical', 'robots', 'og', 'jsonLd', 'indexable');
    }

    public static function home(): array
    {
        $settings = Store::settings();
        $description = $settings['public_content_approved'] && $settings['about_text']
            ? $settings['about_text'] : 'Xem thực đơn, lựa chọn bánh và theo dõi thông tin đặt bánh của '.$settings['name'].'.';
        $schema = self::bakery($settings);

        return self::make($settings['name'].' | Thực đơn và đặt bánh', $description, '/', (bool) $settings['business_verified'] && ! request()->query(),
            $schema ? [$schema] : [], self::approvedImage([$settings['logo']], (bool) $settings['logo_approved']));
    }

    public static function catalog(?object $category, int $page, bool $filtered): array
    {
        $settings = Store::settings();
        $path = $category ? '/thuc-don/'.$category->slug : '/thuc-don';
        $canonical = $path.(! $filtered && $page > 1 ? '?page='.$page : '');
        $label = $category?->name ?? 'Thực đơn';
        $title = $label.($page > 1 ? ' · Trang '.$page : '').' | '.$settings['name'];
        $crumbs = [['name' => $settings['name'], 'path' => '/'], ['name' => 'Thực đơn', 'path' => '/thuc-don']];
        if ($category) {
            $crumbs[] = ['name' => $category->name, 'path' => $path];
        }

        return self::make($title, 'Xem '.$label.' tại '.$settings['name'].'. Chọn phiên bản, giá và thời gian nhận phù hợp.', $canonical,
            (bool) $settings['business_verified'] && ! $filtered, self::breadcrumbs($crumbs));
    }

    public static function product(object $product): array
    {
        $settings = Store::settings();
        $path = '/banh/'.$product->slug;
        $image = self::approvedImage($product->images, (bool) $product->images_approved);
        $approved = (bool) $product->details_verified && $image !== null && $product->variants->isNotEmpty();
        $crumbs = [
            ['name' => $settings['name'], 'path' => '/'], ['name' => 'Thực đơn', 'path' => '/thuc-don'],
            ['name' => $product->category, 'path' => '/thuc-don/'.$product->category_slug],
            ['name' => $product->name, 'path' => $path],
        ];
        $schema = self::breadcrumbs($crumbs);
        if ($approved && self::url($path)) {
            $item = [
                '@context' => 'https://schema.org', '@type' => 'Product', '@id' => self::url($path).'#product',
                'name' => $product->name, 'description' => self::description($product->description), 'url' => self::url($path), 'image' => [$image['url']],
            ];
            if ($settings['accepting_orders'] && ! Branches::enabled()) {
                $item['offers'] = $product->variants->map(fn ($variant) => [
                    '@type' => 'Offer', 'name' => $variant->name, 'url' => self::url($path),
                    'priceCurrency' => 'VND', 'price' => (int) $variant->price,
                    'availability' => 'https://schema.org/'.($variant->stock > 0 ? 'InStock' : 'OutOfStock'),
                ])->values()->all();
            }
            $schema[] = $item;
        }
        $result = self::make($product->name.' | '.$settings['name'], $product->description, $path,
            (bool) $settings['business_verified'] && $approved && ! request()->query(), $schema, $image);
        $result['og']['type'] = 'product';

        return $result;
    }

    public static function policy(): array
    {
        $settings = Store::settings();

        return self::make('Chính sách đặt bánh | '.$settings['name'], $settings['order_policy'] ?: 'Thông tin chính sách đặt bánh và sử dụng dữ liệu tại '.$settings['name'].'.',
            '/chinh-sach-dat-banh', (bool) $settings['business_verified'] && (bool) $settings['public_content_approved'] && filled($settings['order_policy']) && ! request()->query());
    }

    public static function info(string $kind): array
    {
        $settings = Store::settings();
        [$path, $title] = self::INFO_PAGES[$kind];
        $business = (bool) $settings['business_verified'];
        $content = (bool) $settings['public_content_approved'];
        $intro = match ($kind) {
            'about' => $content ? $settings['about_text'] : null,
            'contact' => $content ? $settings['contact_text'] : null,
            default => null,
        };
        $approved = match ($kind) {
            'about' => $content && filled($intro), 'faq' => $content && count($settings['faq_items']) > 0,
            default => $business,
        };
        $store = ['name' => $settings['name']] + array_fill_keys(['address', 'phone', 'email', 'hours', 'maps_url'], null);
        if ($business) {
            foreach (['address', 'phone', 'email', 'hours', 'maps_url'] as $key) {
                $store[$key] = $settings[$key];
            }
            $store['maps_url'] = self::safeMapsUrl(is_string($store['maps_url']) ? $store['maps_url'] : null);
        }
        $info = [
            'kind' => $kind, 'title' => $title, 'intro' => $intro, 'approved' => $approved,
            'faqs' => $content ? $settings['faq_items'] : [], 'store' => $store,
            'openingHours' => $business ? $settings['business_opening_hours'] : [],
        ];
        $schemas = self::breadcrumbs([['name' => $settings['name'], 'path' => '/'], ['name' => $title, 'path' => $path]]);
        if ($kind === 'location' && ($bakery = self::bakery($settings))) {
            $schemas[] = $bakery;
        }
        $seo = self::make($title.' | '.$settings['name'], $intro ?: $title.' của '.$settings['name'].'.', $path,
            $business && $approved && ! request()->query(), $schemas, self::approvedImage([$settings['logo']], (bool) $settings['logo_approved']));

        return compact('info', 'seo');
    }

    public static function bakery(array $settings): ?array
    {
        if (! $settings['business_verified'] || ! filled($settings['business_street_address']) || ! filled($settings['business_locality'])
            || ! filled($settings['business_country_code']) || ! self::url('/cua-hang')) {
            return null;
        }
        $address = ['@type' => 'PostalAddress', 'streetAddress' => $settings['business_street_address'],
            'addressLocality' => $settings['business_locality'], 'addressCountry' => $settings['business_country_code']];
        foreach (['business_region' => 'addressRegion', 'business_postal_code' => 'postalCode'] as $from => $to) {
            if (filled($settings[$from])) {
                $address[$to] = $settings[$from];
            }
        }
        $schema = ['@context' => 'https://schema.org', '@type' => 'Bakery', '@id' => self::url('/cua-hang').'#bakery',
            'name' => $settings['name'], 'url' => self::url('/cua-hang'), 'address' => $address];
        foreach (['phone' => 'telephone', 'email' => 'email'] as $from => $to) {
            if (filled($settings[$from])) {
                $schema[$to] = $settings[$from];
            }
        }
        if ($settings['business_opening_hours']) {
            $days = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            $schema['openingHoursSpecification'] = array_map(fn ($row) => [
                '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => array_map(fn ($day) => 'https://schema.org/'.$days[(int) $day], $row['days']),
                'opens' => $row['opens'], 'closes' => $row['closes'],
            ], $settings['business_opening_hours']);
        }

        return $schema;
    }

    public static function breadcrumbs(array $crumbs): array
    {
        if (! self::origin()) {
            return [];
        }

        return [['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_map(
            fn ($crumb, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name'], 'item' => self::url($crumb['path'])], $crumbs, array_keys($crumbs))]];
    }

    public static function approvedImage(array $images, bool $approved): ?array
    {
        if (! $approved || ! self::origin()) {
            return null;
        }
        $imagesService = app(ResponsiveImages::class);
        foreach ($images as $source) {
            if (! is_string($source) || ! $imagesService->sourcePath($source)) {
                continue;
            }
            $asset = $imagesService->asset($source);
            if ($asset && $imagesService->sourcePath($asset['src'])) {
                return ['url' => self::url($asset['src']), 'width' => $asset['width'], 'height' => $asset['height']];
            }
        }

        return null;
    }

    public static function sitemapUrls(): array
    {
        $settings = Store::settings();
        if (! self::indexingEnabled() || ! $settings['business_verified']) {
            return [];
        }
        $paths = ['/', '/thuc-don', '/lien-he', '/cua-hang'];
        if ($settings['public_content_approved']) {
            if (filled($settings['about_text'])) {
                $paths[] = '/gioi-thieu';
            }
            if ($settings['faq_items']) {
                $paths[] = '/cau-hoi-thuong-gap';
            }
            if (filled($settings['order_policy'])) {
                $paths[] = '/chinh-sach-dat-banh';
            }
        }
        $categories = DB::table('categories')->whereExists(fn ($q) => $q->selectRaw('1')->from('products')->whereColumn('products.category_id', 'categories.id')->where('products.active', true))->orderBy('id')->pluck('slug');
        foreach ($categories as $slug) {
            $paths[] = '/thuc-don/'.$slug;
        }
        $products = DB::table('products')->where('active', true)->where('details_verified', true)->where('images_approved', true)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('variants')->whereColumn('variants.product_id', 'products.id')->where('variants.active', true))->orderBy('id')->get(['slug', 'images']);
        foreach ($products as $product) {
            if (self::approvedImage(json_decode($product->images, true, flags: JSON_THROW_ON_ERROR), true)) {
                $paths[] = '/banh/'.$product->slug;
            }
        }

        return array_values(array_filter(array_map(self::url(...), array_unique($paths))));
    }

    public static function settingsRules(): array
    {
        return [
            'business_verified' => 'sometimes|boolean', 'public_content_approved' => 'sometimes|boolean', 'logo_approved' => 'sometimes|boolean',
            'about_text' => 'sometimes|nullable|string|max:6000', 'contact_text' => 'sometimes|nullable|string|max:3000',
            'faq_items' => 'sometimes|nullable|array|list|max:30', 'faq_items.*' => 'required|array:question,answer',
            'faq_items.*.question' => 'required|string|max:250', 'faq_items.*.answer' => 'required|string|max:2000',
            'maps_url' => ['sometimes', 'nullable', 'string', 'max:1000', function ($attribute, $value, $fail) {
                if (self::safeMapsUrl(is_string($value) ? $value : null) === null) {
                    $fail('Dùng liên kết bản đồ HTTPS đã được tiệm xác nhận, không chứa khóa hoặc thông tin xác thực.');
                }
            }],
            'business_street_address' => 'sometimes|nullable|string|max:250', 'business_locality' => 'sometimes|nullable|string|max:120',
            'business_region' => 'sometimes|nullable|string|max:120', 'business_postal_code' => 'sometimes|nullable|string|max:20',
            'business_country_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/D'],
            'business_opening_hours' => 'sometimes|nullable|array|list|max:28', 'business_opening_hours.*' => 'required|array:days,opens,closes',
            'business_opening_hours.*.days' => 'required|array|list|min:1|max:7', 'business_opening_hours.*.days.*' => 'required|integer|distinct|between:1,7',
            'business_opening_hours.*.opens' => 'required|date_format:H:i', 'business_opening_hours.*.closes' => 'required|date_format:H:i',
        ];
    }

    public static function safeMapsUrl(?string $url): ?string
    {
        if ($url === null || strlen($url) > 1000 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $hosts = ['google.com', 'www.google.com', 'maps.google.com', 'www.google.com.vn', 'maps.google.com.vn',
            'maps.app.goo.gl', 'goo.gl', 'maps.apple.com', 'openstreetmap.org', 'www.openstreetmap.org'];
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443) || ! in_array($host, $hosts, true)
            || (in_array($host, ['google.com', 'www.google.com', 'www.google.com.vn', 'goo.gl'], true)
                && ! preg_match('~^/maps(?:/|$)~', $parts['path'] ?? ''))) {
            return null;
        }
        foreach ([$parts['query'] ?? '', $parts['fragment'] ?? ''] as $query) {
            foreach (preg_split('/[&;]/', $query) as $pair) {
                $name = explode('=', $pair, 2)[0];
                for ($depth = 0; $depth < 5; $depth++) {
                    $decoded = urldecode($name);
                    if ($decoded === $name) {
                        break;
                    }
                    $name = $decoded;
                }
                if (preg_match('/%[0-9a-f]{2}/i', $name)) {
                    return null;
                }
                $name = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
                if (preg_match('/(?:^|_)(?:key|apikey|token|accesstoken|secret|signature|auth|authorization|password|credential|credentials)(?:_|$)/', $name)) {
                    return null;
                }
            }
        }

        return $url;
    }

    public static function settingsFields(array $fields, array $current): array
    {
        foreach (['faq_items', 'business_opening_hours'] as $key) {
            if (array_key_exists($key, $fields)) {
                $fields[$key] ??= [];
            }
        }
        if (isset($fields['business_opening_hours'])) {
            foreach ($fields['business_opening_hours'] as $i => &$row) {
                if ($row['opens'] === $row['closes']) {
                    throw ValidationException::withMessages(["business_opening_hours.{$i}.closes" => 'Giờ đóng cần khác giờ mở. Chỉ nhập giờ đã được tiệm xác nhận.']);
                }
                $row['days'] = array_map('intval', $row['days']);
            }
            unset($row);
        }
        foreach ([
            'business_verified' => ['name', 'address', 'phone', 'email', 'hours', 'maps_url', 'business_street_address', 'business_locality',
                'business_region', 'business_postal_code', 'business_country_code', 'business_opening_hours'],
            'public_content_approved' => ['about_text', 'contact_text', 'faq_items'],
        ] as $approval => $keys) {
            $changed = collect($keys)->contains(fn ($key) => array_key_exists($key, $fields) && $fields[$key] !== $current[$key]);
            $fields[$approval] = array_key_exists($approval, $fields) ? (bool) $fields[$approval] : (! $changed && (bool) $current[$approval]);
        }
        $logoChanged = isset($fields['logo']) || $fields['remove_logo'];
        $fields['logo_approved'] = array_key_exists('logo_approved', $fields) ? (bool) $fields['logo_approved'] : (! $logoChanged && (bool) $current['logo_approved']);
        if ($fields['logo_approved'] && ! isset($fields['logo']) && ($fields['remove_logo'] || ! $current['logo'])) {
            throw ValidationException::withMessages(['logo_approved' => 'Cần logo đã được phép dùng trước khi xác nhận quyền sử dụng.']);
        }
        $merged = array_replace($current, $fields);
        if ($fields['business_verified']) {
            foreach (['address', 'phone', 'business_street_address', 'business_locality', 'business_country_code'] as $key) {
                if (! filled($merged[$key])) {
                    throw ValidationException::withMessages([$key => 'Điền dữ liệu cơ sở thật đã được tiệm đối chiếu trước khi xác minh.']);
                }
            }
        }
        if ($fields['public_content_approved'] && ! filled($merged['about_text']) && ! filled($merged['contact_text']) && ! $merged['faq_items']) {
            throw ValidationException::withMessages(['public_content_approved' => 'Chưa có nội dung giới thiệu, liên hệ hoặc FAQ để duyệt.']);
        }

        return $fields;
    }
}
