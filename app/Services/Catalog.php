<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class Catalog
{
    public static function listings(): Builder
    {
        $availability = DB::table('variants')->where('active', true)->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(MIN(CASE WHEN stock > 0 THEN price END), MIN(price)) as price, SUM(stock) as stock');

        return DB::table('products')->where('products.active', true)
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoinSub($availability, 'availability', 'availability.product_id', '=', 'products.id')
            ->select('products.id', 'products.name', 'products.slug', 'products.description', 'products.images',
                'products.prep_minutes', 'categories.name as category', 'categories.slug as category_slug',
                'availability.price', 'availability.stock');
    }

    public static function images(object $product): object
    {
        $product->images = json_decode($product->images, true, flags: JSON_THROW_ON_ERROR);

        return $product;
    }
}
