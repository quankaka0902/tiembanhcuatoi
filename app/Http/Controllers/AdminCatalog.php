<?php

namespace App\Http\Controllers;

use App\Services\Catalog;
use App\Services\Inventory;
use App\Services\ProductMetadata;
use App\Services\ResponsiveImages;
use App\Services\Store;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminCatalog extends Controller
{
    public const IMAGE_RULES = 'file|image|mimes:jpg,jpeg,png,webp|max:4096|dimensions:max_width=5000,max_height=5000';

    public function index()
    {
        return Inertia::render('AdminProducts', [
            'products' => DB::table('products')->join('categories', 'categories.id', '=', 'products.category_id')
                ->select('products.id', 'products.name', 'products.active', 'products.featured', 'products.details_verified', 'products.images_approved', 'categories.name as category')
                ->latest('products.id')->paginate(20),
            'categories' => DB::table('categories')->orderBy('id')->get(),
        ]);
    }

    public function edit(?int $product = null)
    {
        $row = $product ? DB::table('products')->find($product) : null;
        abort_if($product && ! $row, 404);
        if ($row) {
            $row = ProductMetadata::hydrate(Catalog::images($row));
            $row->variants = DB::table('variants')->where('product_id', $product)->orderBy('id')->get();
            $row->accessories = ProductMetadata::accessories($product, publicOnly: false);
        }

        return Inertia::render('AdminProduct', ['product' => $row, 'categories' => DB::table('categories')->orderBy('id')->get()]);
    }

    public function inventory(Request $request)
    {
        $filters = $request->validate([
            'reason' => ['nullable', Rule::in(array_keys(Inventory::REASONS))],
            'product' => 'nullable|integer|exists:products,id',
        ]);
        $product = isset($filters['product']) ? DB::table('products')->find($filters['product'], ['id', 'name']) : null;
        $movements = DB::table('stock_movements as moves')
            ->join('variants', 'variants.id', '=', 'moves.variant_id')
            ->join('products', 'products.id', '=', 'variants.product_id')
            ->leftJoin('orders', 'orders.id', '=', 'moves.order_id')
            ->leftJoin('users', 'users.id', '=', 'moves.actor_id')
            ->when($product, fn ($query) => $query->where('products.id', $product->id))
            ->when($filters['reason'] ?? null, fn ($query, $reason) => $query->where('moves.reason', $reason))
            ->orderByDesc('moves.created_at')->orderByDesc('moves.id')
            ->paginate(20, [
                'moves.*', 'products.id as product_id', 'products.name as product_name', 'variants.name as variant_name',
                'orders.code as order_code', 'users.name as actor',
            ])->withQueryString();

        return Inertia::render('AdminInventory', [
            'movements' => $movements, 'filters' => $filters, 'reasons' => Inventory::REASONS, 'product' => $product,
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, ?int $product = null)
    {
        foreach (['occasions', 'flavors'] as $field) {
            if ($request->exists($field)) {
                $request->merge([$field => ProductMetadata::listInput($request->input($field))]);
            }
        }
        $data = $request->validate([
            'name' => 'required|string|max:150', 'category_id' => 'required|exists:categories,id',
            'slug' => ['sometimes', 'nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', 'max:170'],
            'description' => 'required|string|max:3000', 'ingredients' => 'nullable|string|max:1200', 'allergens' => 'nullable|string|max:800',
            'prep_minutes' => 'required|integer|min:0|max:14400', 'message_fee' => 'required|integer|min:0|max:1000000',
            'active' => 'required|boolean', 'featured' => 'required|boolean', 'customizable' => 'required|boolean',
            'uploads' => 'nullable|array|max:5', 'uploads.*' => self::IMAGE_RULES,
            'removed' => 'nullable|array|max:5', 'removed.*' => 'string|max:255',
            'variants' => 'required|array|min:1|max:30', 'variants.*' => 'required|array:id,name,price,stock,expected_stock,active,stock_note,size_label,servings_min,servings_max,servings_verified',
            'variants.*.id' => 'nullable|integer|distinct', 'variants.*.name' => 'required|string|max:100',
            'variants.*.price' => 'required|integer|min:0|max:100000000',
            'variants.*.stock' => 'required|integer|min:0|max:1000000', 'variants.*.expected_stock' => 'nullable|integer|min:0',
            'variants.*.active' => 'required|boolean',
            'variants.*.stock_note' => 'nullable|string|max:250',
            ...ProductMetadata::rules(),
        ]);

        $actorId = $request->user()->id;
        $id = DB::transaction(function () use ($data, $product, $actorId) {
            Store::settings(lock: true);
            $existing = $product ? DB::table('products')->find($product) : null;
            abort_if($product && ! $existing, 404);
            $slug = $data['slug'] ?? $existing?->slug;
            if (! $slug) {
                $base = Str::slug($data['name']) ?: 'banh';
                $slug = substr($base, 0, 145).'-'.Str::lower(Str::random(8));
            }
            if (DB::table('products')->where('slug', $slug)->when($product, fn ($q) => $q->where('id', '<>', $product))->exists()
                || DB::table('product_slug_history')->where('slug', $slug)->when($product, fn ($q) => $q->where('product_id', '<>', $product))->exists()) {
                throw ValidationException::withMessages(['slug' => 'Đường dẫn đang dùng hoặc thuộc lịch sử của bánh khác.']);
            }
            $previous = $existing ? DB::table('variants')->where('product_id', $product)->get()->keyBy('id') : collect();
            foreach ($data['variants'] as $index => $variant) {
                $variantId = $variant['id'] ?? null;
                if (! $variantId) {
                    continue;
                }
                $stored = $previous->get($variantId);
                if (! $stored || ! isset($variant['expected_stock'])) {
                    throw ValidationException::withMessages(['variants' => 'Phiên bản bánh không hợp lệ. Vui lòng tải lại trang.']);
                }
                if ((int) $variant['expected_stock'] !== (int) $variant['stock'] && (int) $stored->stock !== (int) $variant['expected_stock']) {
                    throw ValidationException::withMessages(["variants.{$index}.stock" => 'Tồn kho vừa thay đổi. Hãy tải lại trang trước khi sửa tồn.']);
                }
            }
            $accessories = $existing ? ProductMetadata::accessories($product, publicOnly: false)->keyBy('id') : collect();
            foreach ($data['accessories'] ?? [] as $index => $accessory) {
                if (! empty($accessory['id']) && ! $accessories->has($accessory['id'])) {
                    throw ValidationException::withMessages(["accessories.{$index}.id" => 'Phụ kiện không thuộc bánh đang sửa. Vui lòng tải lại trang.']);
                }
            }
            $images = $existing ? json_decode($existing->images, true, flags: JSON_THROW_ON_ERROR) : [];
            $originalImages = $images;
            $images = array_values(array_diff($images, $data['removed'] ?? []));
            if (count($images) + count($data['uploads'] ?? []) > 5) {
                throw ValidationException::withMessages(['uploads' => 'Mỗi sản phẩm có tối đa 5 ảnh.']);
            }
            $metadata = ProductMetadata::productFields($data, $existing, count($images) + count($data['uploads'] ?? []), $originalImages !== $images || ! empty($data['uploads']));
            $variantMetadata = [];
            foreach ($data['variants'] as $index => $variant) {
                $variantMetadata[$index] = ProductMetadata::variantFields($variant, ! empty($variant['id']) ? $previous->get($variant['id']) : null, $index);
            }
            foreach ($data['uploads'] ?? [] as $file) {
                $images[] = $this->uploadedImage($file);
            }

            $fields = array_intersect_key($data, array_flip(['name', 'category_id', 'description', 'ingredients', 'allergens', 'prep_minutes', 'message_fee', 'active', 'featured', 'customizable']));
            $fields += $metadata + ['images' => json_encode($images, JSON_THROW_ON_ERROR), 'updated_at' => now()];
            $fields['slug'] = $slug;
            $fields['search_text'] = Catalog::searchText($data['name'], $data['description'], ProductMetadata::stringList($metadata['occasions']), ProductMetadata::stringList($metadata['flavors']));
            if ($existing) {
                if ($existing->slug !== $slug) {
                    DB::table('product_slug_history')->insertOrIgnore(['product_id' => $product, 'slug' => $existing->slug, 'created_at' => now()]);
                }
                DB::table('products')->where('id', $product)->update($fields);
            } else {
                $product = DB::table('products')->insertGetId($fields + [
                    'created_at' => now(),
                ]);
            }

            foreach ($data['variants'] as $index => $variant) {
                $fields = array_intersect_key($variant, array_flip(['name', 'price', 'active', 'stock'])) + $variantMetadata[$index];
                if (! empty($variant['id'])) {
                    $id = (int) $variant['id'];
                    $after = (int) $variant['stock'];
                    if ($after === (int) $variant['expected_stock']) {
                        unset($fields['stock']);
                    } else {
                        Inventory::record($id, (int) $previous->get($id)->stock, $after, 'manual', actorId: $actorId, note: $variant['stock_note'] ?? null);
                    }
                    DB::table('variants')->where('id', $id)->update($fields);
                } else {
                    $id = DB::table('variants')->insertGetId($fields + ['product_id' => $product]);
                    Inventory::record($id, 0, (int) $variant['stock'], 'initial', actorId: $actorId, note: $variant['stock_note'] ?? null);
                }
            }
            if (array_key_exists('accessories', $data)) {
                $retained = [];
                foreach ($data['accessories'] as $accessory) {
                    $fields = array_intersect_key($accessory, array_flip(['name', 'price', 'active', 'approved'])) + ['updated_at' => now()];
                    if (! empty($accessory['id'])) {
                        $accessoryId = (int) $accessory['id'];
                        DB::table('product_accessories')->where('product_id', $product)->where('id', $accessoryId)->update($fields);
                    } else {
                        $accessoryId = DB::table('product_accessories')->insertGetId($fields + ['product_id' => $product, 'created_at' => now()]);
                    }
                    $retained[] = $accessoryId;
                }
                DB::table('product_accessories')->where('product_id', $product)->whereNotIn('id', $retained)->update(['active' => false, 'approved' => false, 'updated_at' => now()]);
            }

            return $product;
        });

        return redirect('/quan-tri/san-pham/'.$id)->with('notice', 'Đã lưu sản phẩm.');
    }

    public function category(Request $request, ?int $category = null)
    {
        if ($category) {
            abort_unless(DB::table('categories')->find($category), 404);
        }
        $data = $request->validate([
            'name' => 'required|string|max:100', 'slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:120', Rule::unique('categories')->ignore($category)],
            'icon' => 'required|in:cake,croissant,cupcake,cookie',
        ]);
        DB::transaction(function () use ($data, $category) {
            Store::settings(lock: true);
            $previous = $category ? DB::table('categories')->find($category) : null;
            abort_if($category && ! $previous, 404);
            if (DB::table('categories')->where('slug', $data['slug'])->when($category, fn ($q) => $q->where('id', '<>', $category))->exists()
                || DB::table('category_slug_history')->where('slug', $data['slug'])->when($category, fn ($q) => $q->where('category_id', '<>', $category))->exists()) {
                throw ValidationException::withMessages(['slug' => 'Đường dẫn đang dùng hoặc thuộc lịch sử của danh mục khác.']);
            }
            if ($previous) {
                if ($previous->slug !== $data['slug']) {
                    DB::table('category_slug_history')->insertOrIgnore(['category_id' => $category, 'slug' => $previous->slug, 'created_at' => now()]);
                }
                DB::table('categories')->where('id', $category)->update($data);
            } else {
                DB::table('categories')->insert($data);
            }
        }, 5);

        return back()->with('notice', 'Đã lưu danh mục.');
    }

    public function uploadedImage(UploadedFile $file): string
    {
        $path = $file->store('bakery', 'public');
        abort_unless($path, 500);
        $url = '/anh/'.basename($path);
        app(ResponsiveImages::class)->generate($url);

        return $url;
    }
}
