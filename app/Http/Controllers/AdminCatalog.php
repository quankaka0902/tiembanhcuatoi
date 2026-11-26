<?php

namespace App\Http\Controllers;

use App\Services\Branches;
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
        Branches::authorize(request()->user(), 'shared_catalog');

        return Inertia::render('AdminProducts', [
            'products' => DB::table('products')->join('categories', 'categories.id', '=', 'products.category_id')
                ->select('products.id', 'products.name', 'products.active', 'products.featured', 'products.details_verified', 'products.images_approved', 'categories.name as category')
                ->latest('products.id')->paginate(20),
            'categories' => DB::table('categories')->orderBy('id')->get(),
        ]);
    }

    public function edit(?int $product = null)
    {
        Branches::authorize(request()->user(), 'shared_catalog');
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
        Branches::authorize($request->user(), 'inventory');
        $filters = $request->validate([
            'reason' => ['nullable', Rule::in(array_keys(Inventory::REASONS))],
            'product' => 'nullable|integer|exists:products,id',
        ]);
        $product = isset($filters['product']) ? DB::table('products')->find($filters['product'], ['id', 'name']) : null;
        $movements = DB::table('stock_movements as moves')
            ->when(Branches::enabled(), fn ($query) => $query->where('moves.branch_id', Branches::id()))
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
        Branches::authorize($request->user(), 'shared_catalog');
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
            Branches::authorize(request()->user(), 'shared_catalog');
            $this->lockCatalog();
            $existing = $product ? DB::table('products')->where('id', $product)->lockForUpdate()->first() : null;
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
            $previous = $existing ? DB::table('variants')->where('product_id', $product)->orderBy('id')->lockForUpdate()->get()->keyBy('id') : collect();
            foreach ($data['variants'] as $index => $variant) {
                $variantId = $variant['id'] ?? null;
                if (! $variantId) {
                    continue;
                }
                $stored = $previous->get($variantId);
                if (! $stored || ! isset($variant['expected_stock'])) {
                    throw ValidationException::withMessages(['variants' => 'Phiên bản bánh không hợp lệ. Vui lòng tải lại trang.']);
                }
                if (! Branches::enabled() && (int) $variant['expected_stock'] !== (int) $variant['stock'] && (int) $stored->stock !== (int) $variant['expected_stock']) {
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
                if (Branches::enabled()) {
                    unset($fields['stock']);
                }
                if (! empty($variant['id'])) {
                    $id = (int) $variant['id'];
                    $after = (int) $variant['stock'];
                    if (Branches::enabled() || $after === (int) $variant['expected_stock']) {
                        unset($fields['stock']);
                    } else {
                        Inventory::record($id, (int) $previous->get($id)->stock, $after, 'manual', actorId: $actorId, note: $variant['stock_note'] ?? null);
                    }
                    DB::table('variants')->where('id', $id)->update($fields);
                } else {
                    $id = DB::table('variants')->insertGetId($fields + ['product_id' => $product, 'stock' => 0]);
                    if (! Branches::enabled()) {
                        Inventory::record($id, 0, (int) $variant['stock'], 'initial', actorId: $actorId, note: $variant['stock_note'] ?? null);
                    }
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
        }, 5);

        return redirect('/quan-tri/san-pham/'.$id)->with('notice', 'Đã lưu sản phẩm.');
    }

    public function category(Request $request, ?int $category = null)
    {
        Branches::authorize($request->user(), 'shared_catalog');
        if ($category) {
            abort_unless(DB::table('categories')->find($category), 404);
        }
        $data = $request->validate([
            'name' => 'required|string|max:100', 'slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:120', Rule::unique('categories')->ignore($category)],
            'icon' => 'required|in:cake,croissant,cupcake,cookie',
        ]);
        DB::transaction(function () use ($data, $category) {
            Branches::authorize(request()->user(), 'shared_catalog');
            $this->lockCatalog();
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

    private function lockCatalog(): void
    {
        if (Branches::enabled()) {
            // Lock every branch before the shared chain row. Interleaving branch/chain
            // locks could deadlock with a concurrent configuration change in branch B.
            $branches = DB::table('branches')->orderBy('id')->lockForUpdate()->get(['id']);
            foreach ($branches as $branch) {
                $id = $branch->id;
                Store::settings(lock: true, branchId: $id);
            }
        } else {
            Store::settings(lock: true);
        }
    }

    public function branchCatalog()
    {
        Branches::authorize(request()->user(), 'branch_catalog');
        abort_unless(Branches::enabled(), 404);
        $variants = DB::table('variants')->join('products', 'products.id', '=', 'variants.product_id')
            ->leftJoin('branch_variants as branch', fn ($join) => $join->on('branch.variant_id', '=', 'variants.id')->where('branch.branch_id', Branches::id()))
            ->orderBy('products.name')->orderBy('variants.id')
            ->get(['variants.id', 'variants.name', 'variants.price as base_price', 'variants.active as variant_active', 'products.active as product_active', 'products.name as product_name', 'products.prep_minutes as base_prep_minutes', 'branch.price as branch_price', 'branch.prep_minutes as branch_prep_minutes', 'branch.stock', 'branch.active as branch_active', 'branch.updated_at as branch_updated_at']);

        return Inertia::render('AdminBranchCatalog', ['variants' => $variants]);
    }

    public function saveBranchVariant(Request $request, int $variant)
    {
        Branches::authorize($request->user(), 'branch_catalog');
        abort_unless(Branches::enabled(), 404);
        $fields = $request->validate([
            'active' => 'required|boolean', 'price' => 'nullable|integer|min:0|max:100000000',
            'prep_minutes' => 'nullable|integer|min:0|max:14400', 'stock' => 'required|integer|min:0|max:1000000',
            'expected_stock' => 'required|integer|min:0|max:1000000', 'note' => 'required|string|min:3|max:250',
        ]);
        DB::transaction(function () use ($request, $variant, $fields) {
            Branches::authorize($request->user(), 'branch_catalog');
            Store::settings(lock: true);
            abort_unless(DB::table('variants')->find($variant), 404);
            DB::table('branch_variants')->insertOrIgnore(['branch_id' => Branches::id(), 'variant_id' => $variant, 'stock' => 0, 'active' => false, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('branch_variants')->where('branch_id', Branches::id())->where('variant_id', $variant)->lockForUpdate()->first();
            if ((int) $row->stock !== (int) $fields['expected_stock']) {
                throw ValidationException::withMessages(['stock' => 'Tồn của cơ sở vừa thay đổi. Tải lại trước khi điều chỉnh.']);
            }
            Inventory::move($variant, (int) $fields['stock'] - (int) $row->stock, 'manual', actorId: $request->user()->id, note: $fields['note'], branchId: Branches::id());
            DB::table('branch_variants')->where('id', $row->id)->update(array_intersect_key($fields, array_flip(['active', 'price', 'prep_minutes'])) + ['updated_at' => now()]);
            DB::table('branch_audits')->insert(['actor_id' => $request->user()->id, 'branch_id' => Branches::id(), 'action' => 'variant.updated', 'data' => json_encode(['variant_id' => $variant, 'before' => ['active' => (bool) $row->active, 'price' => $row->price, 'prep_minutes' => $row->prep_minutes, 'stock' => $row->stock], 'after' => $fields], JSON_THROW_ON_ERROR), 'created_at' => now()]);
        }, 5);

        return back()->with('notice', 'Đã lưu giá, tồn và trạng thái bán riêng của cơ sở.');
    }
}
