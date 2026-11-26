<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Catalog
{
    public static function searchText(string $name, string $description, array $occasions = [], array $flavors = []): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(Str::ascii(implode(' ', [$name, $description, ...$occasions, ...$flavors]), 'vi'))));
    }

    public static function filterRules(): array
    {
        return [
            'q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:120',
            'occasion' => 'nullable|string|max:80', 'flavor' => 'nullable|string|max:80',
            'guests' => 'nullable|integer|min:1|max:200',
            'min' => 'nullable|integer|min:0|max:100000000',
            'max' => 'nullable|integer|min:0|max:100000000',
            'availability' => 'nullable|in:all,available,sold_out',
            'sort' => 'nullable|in:featured,new,price_asc,price_desc', 'page' => 'nullable|integer|min:1',
        ];
    }

    public static function variants(array $filters = []): Builder
    {
        $variants = DB::table('variants')->where('variants.active', true);
        if (Branches::enabled()) {
            $variants->join('branch_variants as branch', fn ($join) => $join->on('branch.variant_id', '=', 'variants.id')->where('branch.branch_id', Branches::id()))
                ->join('products as variant_product', 'variant_product.id', '=', 'variants.product_id')
                ->where('branch.active', true)
                ->select('variants.id', 'variants.product_id', 'variants.name', 'variants.active', 'variants.size_label', 'variants.servings_min', 'variants.servings_max', 'variants.servings_verified')
                ->selectRaw('COALESCE(branch.price, variants.price) as price, branch.stock, COALESCE(branch.prep_minutes, variant_product.prep_minutes) as prep_minutes');
            $variants = DB::query()->fromSub($variants, 'variants');
        }

        return $variants
            ->when(isset($filters['guests']), fn ($q) => $q->where('servings_verified', true)
                ->where('servings_min', '<=', (int) $filters['guests'])->where('servings_max', '>=', (int) $filters['guests']))
            ->when(isset($filters['min']), fn ($q) => $q->where('price', '>=', (int) $filters['min']))
            ->when(isset($filters['max']), fn ($q) => $q->where('price', '<=', (int) $filters['max']));
    }

    public static function listings(array $filters = []): Builder
    {
        // Serving count, price and availability must describe the same variant.
        $availability = self::variants($filters)->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(MIN(CASE WHEN stock > 0 THEN price END), MIN(price)) as price,
                MIN(price) as min_variant_price, MAX(price) as max_price, SUM(stock) as stock');

        $query = DB::table('products')->where('products.active', true)
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoinSub($availability, 'availability', 'availability.product_id', '=', 'products.id')
            ->select('products.id', 'products.name', 'products.slug', 'products.description', 'products.images',
                'products.prep_minutes', 'products.occasions', 'products.flavors', 'products.details_verified', 'products.images_approved',
                'categories.name as category', 'categories.slug as category_slug',
                'availability.price', 'availability.min_variant_price', 'availability.max_price', 'availability.stock');
        if (Branches::enabled()) {
            $query->whereNotNull('availability.product_id');
        }
        if (isset($filters['guests']) || isset($filters['min']) || isset($filters['max'])) {
            $query->whereNotNull('availability.product_id');
        }
        if (($filters['availability'] ?? 'all') === 'available') {
            $query->where('availability.stock', '>', 0);
        } elseif (($filters['availability'] ?? 'all') === 'sold_out') {
            $query->where('availability.stock', 0);
        }
        foreach (['occasion' => 'occasions', 'flavor' => 'flavors'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where('products.details_verified', true)->whereJsonContains('products.'.$column, $filters[$filter]);
            }
        }
        if (! empty($filters['category'])) {
            $query->where('categories.slug', $filters['category']);
        }
        if (! empty($filters['q'])) {
            $normalized = self::searchText($filters['q'], '');
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $normalized).'%';
            $original = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->whereRaw("products.search_text LIKE ? ESCAPE '!'", [$search])
                ->orWhereRaw("products.name LIKE ? ESCAPE '!'", [$original])
                ->orWhereRaw("products.description LIKE ? ESCAPE '!'", [$original]));
        }

        return $query;
    }

    public static function filterOptions(): array
    {
        $products = DB::table('products')->where('active', true)->where('details_verified', true)
            ->when(Branches::enabled(), fn ($query) => $query->whereExists(self::variants()->whereColumn('variants.product_id', 'products.id')->selectRaw('1')))
            ->get(['occasions', 'flavors']);
        $result = [];
        foreach (['occasions', 'flavors'] as $field) {
            $result[$field] = $products->flatMap(fn ($row) => ProductMetadata::stringList($row->{$field}))->unique()->sort()->values()->all();
        }

        return $result;
    }

    public static function currentLocation(): array
    {
        $settings = Store::settings();

        $branch = Branches::enabled() ? Branches::find() : null;

        return array_intersect_key($settings, array_flip(['name', 'address', 'hours', 'regions', 'free_from']))
            + ['branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null];
    }

    public static function images(object $product): object
    {
        $product->images = is_string($product->images) ? json_decode($product->images, true, flags: JSON_THROW_ON_ERROR) : ($product->images ?? []);
        $product->image_assets = app(ResponsiveImages::class)->assets($product->images);
        $product = ProductMetadata::hydrate($product);
        if (property_exists($product, 'price')) {
            $product->price = $product->price === null ? null : (int) $product->price;
            $product->stock = (int) ($product->stock ?? 0);
            $product->max_price = $product->max_price === null ? null : (int) $product->max_price;
            $product->price_varies = $product->price !== null && (int) $product->max_price > (int) $product->min_variant_price;
        }

        return $product;
    }
}
