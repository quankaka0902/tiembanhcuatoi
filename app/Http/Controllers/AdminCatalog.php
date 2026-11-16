<?php

namespace App\Http\Controllers;

use App\Services\Catalog;
use App\Services\Inventory;
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
                ->select('products.id', 'products.name', 'products.active', 'products.featured', 'categories.name as category')
                ->latest('products.id')->paginate(20),
            'categories' => DB::table('categories')->orderBy('id')->get(),
        ]);
    }

    public function edit(?int $product = null)
    {
        $row = $product ? DB::table('products')->find($product) : null;
        abort_if($product && ! $row, 404);
        if ($row) {
            $row = Catalog::images($row);
            $row->variants = DB::table('variants')->where('product_id', $product)->orderBy('id')->get();
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
        $data = $request->validate([
            'name' => 'required|string|max:150', 'category_id' => 'required|exists:categories,id',
            'description' => 'required|string|max:3000', 'ingredients' => 'nullable|string|max:1200', 'allergens' => 'nullable|string|max:800',
            'prep_minutes' => 'required|integer|min:0|max:14400', 'message_fee' => 'required|integer|min:0|max:1000000',
            'active' => 'required|boolean', 'featured' => 'required|boolean', 'customizable' => 'required|boolean',
            'uploads' => 'nullable|array|max:5', 'uploads.*' => self::IMAGE_RULES,
            'removed' => 'nullable|array|max:5', 'removed.*' => 'string|max:255',
            'variants' => 'required|array|min:1|max:30', 'variants.*' => 'required|array:id,name,price,stock,expected_stock,active,stock_note',
            'variants.*.id' => 'nullable|integer|distinct', 'variants.*.name' => 'required|string|max:100',
            'variants.*.price' => 'required|integer|min:0|max:100000000',
            'variants.*.stock' => 'required|integer|min:0|max:1000000', 'variants.*.expected_stock' => 'nullable|integer|min:0',
            'variants.*.active' => 'required|boolean',
            'variants.*.stock_note' => 'nullable|string|max:250',
        ]);

        $actorId = $request->user()->id;
        $id = DB::transaction(function () use ($data, $product, $actorId) {
            Store::settings(lock: true);
            $existing = $product ? DB::table('products')->find($product) : null;
            abort_if($product && ! $existing, 404);
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
            $images = $existing ? json_decode($existing->images, true, flags: JSON_THROW_ON_ERROR) : [];
            $images = array_values(array_diff($images, $data['removed'] ?? []));
            if (count($images) + count($data['uploads'] ?? []) > 5) {
                throw ValidationException::withMessages(['uploads' => 'Mỗi sản phẩm có tối đa 5 ảnh.']);
            }
            foreach ($data['uploads'] ?? [] as $file) {
                $images[] = $this->uploadedImage($file);
            }

            $fields = array_intersect_key($data, array_flip(['name', 'category_id', 'description', 'ingredients', 'allergens', 'prep_minutes', 'message_fee', 'active', 'featured', 'customizable']));
            $fields += ['images' => json_encode($images, JSON_THROW_ON_ERROR), 'updated_at' => now()];
            if ($existing) {
                DB::table('products')->where('id', $product)->update($fields);
            } else {
                $slug = Str::slug($data['name']) ?: 'banh';
                $product = DB::table('products')->insertGetId($fields + [
                    'slug' => substr($slug, 0, 145).'-'.Str::lower(Str::random(8)), 'created_at' => now(),
                ]);
            }

            foreach ($data['variants'] as $variant) {
                $fields = array_intersect_key($variant, array_flip(['name', 'price', 'active', 'stock']));
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
        if ($category) {
            DB::table('categories')->where('id', $category)->update($data);
        } else {
            DB::table('categories')->insert($data);
        }

        return back()->with('notice', 'Đã lưu danh mục.');
    }

    public function uploadedImage(UploadedFile $file): string
    {
        $path = $file->store('bakery', 'public');
        abort_unless($path, 500);

        return '/anh/'.basename($path);
    }
}
