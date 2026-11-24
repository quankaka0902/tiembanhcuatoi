<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProductMetadata;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StructuredMenuTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;

    private int $otherProductId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bakery.demo_seed' => true, 'inertia.testing.ensure_pages_exist' => false]);
        $this->seed();
        $this->productId = (int) DB::table('products')->where('slug', 'banh-kem-dau')->firstOrFail(['id'])->id;
        $this->otherProductId = (int) DB::table('products')->where('slug', 'croissant-bo-phap')->firstOrFail(['id'])->id;
        User::whereIn('role', ['admin', 'staff'])->each(fn (User $user) => $user->forceFill([
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
        ])->save());
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->withSession(['staff_mfa_verified' => $user instanceof User ? $user->staffProof() : null]);

        return parent::actingAs($user, $guard);
    }

    public function test_existing_menu_remains_unverified_without_guessing_servings_or_accessories(): void
    {
        $this->assertSame(0, DB::table('products')->where('details_verified', true)->count());
        $this->assertSame(0, DB::table('products')->where('images_approved', true)->count());
        $this->assertSame(0, DB::table('variants')->where('servings_verified', true)->count());
        $this->assertSame(0, DB::table('variants')->whereNotNull('servings_min')->count());
        $this->assertDatabaseCount('product_accessories', 0);
        $item = DB::table('order_items')->first();
        $this->assertNull($item->accessories_snapshot);
        $this->assertSame(0, $item->accessory_fee);
        $product = ProductMetadata::hydrate(DB::table('products')->find($this->productId));
        $this->assertSame([], $product->occasions);
        $this->assertSame([], $product->flavors);
        $this->actingAs($this->account('nhanvien'))->get('/quan-tri/san-pham/'.$this->productId)->assertForbidden();
        $this->post('/quan-tri/san-pham/'.$this->productId, $this->fields())->assertForbidden();
    }

    public function test_admin_explicitly_saves_verified_metadata_ranges_and_product_accessories(): void
    {
        $fields = $this->fields();
        $fields += [
            'occasions' => [' Sinh nhật ', 'sinh nhật', 'Tặng bạn'], 'flavors' => ['Dâu', ' Dâu ', 'Vani'],
            'storage_instructions' => 'Thông tin bảo quản thử đã được duyệt trong ca kiểm thử.',
            'details_verified' => true, 'images_approved' => true,
            'accessories' => [
                ['name' => 'Phụ kiện kiểm thử đã duyệt', 'price' => 12000, 'active' => true, 'approved' => true],
                ['name' => 'Phụ kiện kiểm thử chờ duyệt', 'price' => 8000, 'active' => true, 'approved' => false],
            ],
        ];
        $fields['variants'][0] += ['size_label' => 'Kích thước thử 16 cm', 'servings_min' => 4, 'servings_max' => 6, 'servings_verified' => true];
        $this->actingAs($this->account('admin'))->post('/quan-tri/san-pham/'.$this->productId, $fields)->assertSessionHasNoErrors();
        $product = ProductMetadata::hydrate(DB::table('products')->find($this->productId));
        $this->assertSame(['Sinh nhật', 'Tặng bạn'], $product->occasions);
        $this->assertSame(['Dâu', 'Vani'], $product->flavors);
        $this->assertTrue($product->details_verified);
        $this->assertTrue($product->images_approved);
        $this->assertStringContainsString('sinh nhat', $product->search_text);
        $this->assertDatabaseHas('variants', ['id' => $fields['variants'][0]['id'], 'size_label' => 'Kích thước thử 16 cm', 'servings_min' => 4, 'servings_max' => 6, 'servings_verified' => true]);
        $this->assertSame(['Phụ kiện kiểm thử đã duyệt'], ProductMetadata::accessories($this->productId)->pluck('name')->all());
        $this->get('/quan-tri/san-pham/'.$this->productId)->assertInertia(fn (Assert $page) => $page
            ->where('product.occasions.0', 'Sinh nhật')->where('product.details_verified', true)->has('product.accessories', 2));
    }

    public function test_verification_rejects_missing_contents_invalid_ranges_and_non_integer_prices(): void
    {
        $fields = $this->fields();
        $this->actingAs($this->account('admin'));
        $this->post('/quan-tri/san-pham/'.$this->productId, [...$fields, 'details_verified' => true, 'ingredients' => null])->assertSessionHasErrors('ingredients');
        $this->post('/quan-tri/san-pham/'.$this->productId, [...$fields, 'details_verified' => true, 'allergens' => null])->assertSessionHasErrors('allergens');
        $this->post('/quan-tri/san-pham/'.$this->productId, [...$fields, 'details_verified' => true, 'storage_instructions' => null])->assertSessionHasErrors('storage_instructions');
        $range = $fields;
        $range['variants'][0] += ['servings_min' => 6, 'servings_max' => 4, 'servings_verified' => true];
        $this->post('/quan-tri/san-pham/'.$this->productId, $range)->assertSessionHasErrors('variants.0.servings_max');
        $range['variants'][0]['servings_max'] = null;
        $this->post('/quan-tri/san-pham/'.$this->productId, $range)->assertSessionHasErrors('variants.0.servings_min');
        $range['variants'][0]['servings_min'] = 0;
        $this->post('/quan-tri/san-pham/'.$this->productId, $range)->assertSessionHasErrors('variants.0.servings_min');
        $this->post('/quan-tri/san-pham/'.$this->productId, [...$fields, 'accessories' => [[
            'name' => 'Giá không hợp lệ', 'price' => '10.5', 'active' => true, 'approved' => true,
        ]]])->assertSessionHasErrors('accessories.0.price');
        $this->assertDatabaseHas('products', ['id' => $this->productId, 'details_verified' => false, 'images_approved' => false]);
        $this->assertDatabaseCount('product_accessories', 0);
    }

    public function test_accessory_ids_are_bound_to_the_product_and_removed_options_are_hidden_without_deleting_history(): void
    {
        $foreign = DB::table('product_accessories')->insertGetId(['product_id' => $this->otherProductId, 'name' => 'Phụ kiện bánh khác', 'price' => 9000, 'active' => true, 'approved' => true]);
        $this->actingAs($this->account('admin'));
        $fields = [...$this->fields(), 'name' => 'Tên chưa được lưu khi có lỗi', 'accessories' => [[
            'id' => $foreign, 'name' => 'Gắn sai bánh', 'price' => 1, 'active' => true, 'approved' => true,
        ]]];
        $this->post('/quan-tri/san-pham/'.$this->productId, $fields)->assertSessionHasErrors('accessories.0.id');
        $this->assertNotSame($fields['name'], DB::table('products')->where('id', $this->productId)->value('name'));
        $own = DB::table('product_accessories')->insertGetId(['product_id' => $this->productId, 'name' => 'Phụ kiện kiểm thử', 'price' => 5000]);
        $this->assertCount(0, ProductMetadata::accessories($this->productId));
        DB::table('product_accessories')->where('id', $own)->update(['approved' => true]);
        $this->post('/quan-tri/san-pham/'.$this->productId, $this->fields())->assertSessionHasNoErrors();
        $this->assertCount(1, ProductMetadata::accessories($this->productId));
        $this->post('/quan-tri/san-pham/'.$this->productId, [...$this->fields(), 'accessories' => []])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('product_accessories', ['id' => $own, 'active' => false, 'approved' => false]);
        $this->assertDatabaseHas('product_accessories', ['id' => $foreign, 'name' => 'Phụ kiện bánh khác', 'price' => 9000]);
        $this->assertCount(0, ProductMetadata::accessories($this->productId));
    }

    public function test_legacy_posts_preserve_metadata_and_json_empty_arrays_clear_the_lists(): void
    {
        $variant = $this->fields()['variants'][0];
        DB::table('products')->where('id', $this->productId)->update([
            'occasions' => json_encode(['Sinh nhật']), 'flavors' => json_encode(['Dâu']),
            'storage_instructions' => 'Bảo quản thử', 'details_verified' => true,
        ]);
        DB::table('variants')->where('id', $variant['id'])->update(['size_label' => '16 cm thử', 'servings_min' => 4, 'servings_max' => 6, 'servings_verified' => true]);
        $this->actingAs($this->account('admin'))->post('/quan-tri/san-pham/'.$this->productId, $this->fields())->assertSessionHasNoErrors();
        $product = ProductMetadata::hydrate(DB::table('products')->find($this->productId));
        $this->assertSame(['Sinh nhật'], $product->occasions);
        $this->assertTrue($product->details_verified);
        $this->assertDatabaseHas('variants', ['id' => $variant['id'], 'servings_min' => 4, 'servings_max' => 6, 'servings_verified' => true]);
        $changed = $this->fields();
        $changed['ingredients'] .= ' Nội dung vừa thay đổi.';
        $changed['variants'][0]['servings_min'] = 5;
        $this->post('/quan-tri/san-pham/'.$this->productId, $changed)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['id' => $this->productId, 'details_verified' => false]);
        $this->assertDatabaseHas('variants', ['id' => $variant['id'], 'servings_min' => 5, 'servings_verified' => false]);
        $this->post('/quan-tri/san-pham/'.$this->productId, [...$this->fields(), 'occasions' => '[]', 'flavors' => '[]'])->assertSessionHasNoErrors();
        $this->assertSame([], ProductMetadata::hydrate(DB::table('products')->find($this->productId))->occasions);
        $this->post('/quan-tri/san-pham/'.$this->productId, [...$this->fields(), 'occasions' => ['key' => 'Sai hình dạng']])->assertSessionHasErrors('occasions');
        $this->post('/quan-tri/san-pham/'.$this->productId, [...$this->fields(), 'flavors' => 'Không phải JSON'])->assertSessionHasErrors('flavors');
    }

    public function test_approval_requires_an_image_and_image_changes_do_not_keep_implicit_approval(): void
    {
        $product = DB::table('products')->find($this->productId);
        $images = json_decode($product->images, true);
        $this->actingAs($this->account('admin'))->post('/quan-tri/san-pham/'.$this->productId, [...$this->fields(), 'images_approved' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['id' => $this->productId, 'images_approved' => true]);
        $this->post('/quan-tri/san-pham/'.$this->productId, [...$this->fields(), 'removed' => $images])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['id' => $this->productId, 'images_approved' => false]);
        $this->post('/quan-tri/san-pham/'.$this->productId, [...$this->fields(), 'images_approved' => true])->assertSessionHasErrors('images_approved');
    }

    private function fields(): array
    {
        $product = (array) DB::table('products')->find($this->productId);
        $variant = (array) DB::table('variants')->where('product_id', $this->productId)->orderBy('id')->first();

        return array_intersect_key($product, array_flip([
            'name', 'category_id', 'description', 'ingredients', 'allergens', 'prep_minutes', 'message_fee', 'active', 'featured', 'customizable',
        ])) + ['variants' => [array_intersect_key($variant, array_flip(['id', 'name', 'price', 'stock', 'active'])) + ['expected_stock' => $variant['stock']]]];
    }

    private function account(string $name): User
    {
        return User::where('email', $name.'@tiembanh.test')->firstOrFail();
    }
}
