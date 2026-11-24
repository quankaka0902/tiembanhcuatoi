<?php

namespace Tests\Feature;

use App\Services\Catalog;
use App\Services\Orders;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Normalizer;
use Tests\TestCase;

class StorefrontDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private object $product;

    private object $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 24)->setTime(8, 0));
        config(['bakery.demo_seed' => true, 'inertia.testing.ensure_pages_exist' => false]);
        $this->seed();
        $this->product = DB::table('products')->where('slug', 'banh-kem-dau')->first();
        $this->variant = DB::table('variants')->where('product_id', $this->product->id)->orderBy('id')->first();
    }

    public function test_vietnamese_keywords_match_accents_case_and_decomposed_unicode_without_like_wildcards(): void
    {
        DB::table('products')->where('id', $this->product->id)->update([
            'name' => 'Bánh Đậu Đỏ', 'description' => 'Món kiểm thử tìm kiếm.',
            'search_text' => Catalog::searchText('Bánh Đậu Đỏ', 'Món kiểm thử tìm kiếm.'),
        ]);
        foreach (['bánh đậu đỏ', 'BANH DAU DO', Normalizer::normalize('Bánh Đậu Đỏ', Normalizer::FORM_D)] as $keyword) {
            $this->get('/thuc-don?'.http_build_query(['q' => $keyword]))->assertInertia(fn (Assert $page) => $page
                ->where('products.total', 1)->where('products.data.0.id', $this->product->id));
        }
        foreach (['%', '_'] as $keyword) {
            $this->get('/thuc-don?'.http_build_query(['q' => $keyword]))->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
        }
    }

    public function test_guests_budget_and_stock_must_match_the_same_verified_variant(): void
    {
        $variants = DB::table('variants')->where('product_id', $this->product->id)->orderBy('id')->get();
        DB::table('variants')->where('id', $variants[0]->id)->update(['servings_min' => 4, 'servings_max' => 6, 'servings_verified' => true, 'price' => 300000, 'stock' => 5]);
        DB::table('variants')->where('id', $variants[1]->id)->update(['servings_min' => 8, 'servings_max' => 10, 'servings_verified' => true, 'price' => 500000, 'stock' => 1]);
        $this->get('/thuc-don?guests=8&max=400000&availability=available')->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
        $this->get('/thuc-don?guests=8&max=600000&availability=available')->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.id', $this->product->id)->where('products.data.0.price', 500000));
        $this->get('/banh/'.$this->product->slug.'?guests=8&max=600000&availability=available')
            ->assertInertia(fn (Assert $page) => $page->where('suggestedVariantId', $variants[1]->id));
        DB::table('variants')->where('id', $variants[1]->id)->update(['stock' => 0]);
        $this->get('/thuc-don?guests=8&availability=available')->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
        $this->get('/thuc-don?guests=8&availability=sold_out')->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.stock', 0));
        DB::table('variants')->where('id', $variants[1]->id)->update(['servings_verified' => false]);
        $this->get('/thuc-don?guests=8')->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
    }

    public function test_filter_urls_survive_pagination_and_unverified_tags_do_not_become_facets(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('occasions', [])
            ->where('currentLocation.address', Store::settings()['address'])->where('currentLocation.regions', Store::settings()['regions']));
        $this->get('/banh/'.$this->product->slug)->assertInertia(fn (Assert $page) => $page
            ->where('product.details_verified', false)->where('product.images_approved', false)
            ->where('product.variants.0.servings_min', null)->where('product.occasions', []));
        DB::table('products')->update(['details_verified' => true, 'occasions' => json_encode(['Sinh nhật']), 'flavors' => json_encode(['Dâu'])]);
        DB::table('variants')->update(['servings_min' => 8, 'servings_max' => 10, 'servings_verified' => true]);
        $filters = ['guests' => 8, 'occasion' => 'Sinh nhật', 'flavor' => 'Dâu', 'min' => 0, 'max' => 900000, 'availability' => 'all', 'sort' => 'price_desc'];
        $this->get('/thuc-don?'.http_build_query($filters))->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 9)->where('filters.guests', '8')
            ->where('products.next_page_url', function ($url) use ($filters) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                foreach ($filters as $key => $value) {
                    if ((string) $value !== (string) ($query[$key] ?? '')) {
                        return false;
                    }
                }

                return (string) $query['page'] === '2';
            }));
        DB::table('products')->update(['details_verified' => false]);
        $this->get('/thuc-don?'.http_build_query(['occasion' => 'Sinh nhật']))->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 0)->where('filterOptions.occasions', []));
    }

    public function test_price_from_flag_uses_variant_variation_even_when_one_size_is_sold_out(): void
    {
        DB::table('variants')->where('product_id', $this->product->id)->where('id', '<>', $this->variant->id)->update(['stock' => 0]);
        $product = Catalog::images(Catalog::listings()->where('products.id', $this->product->id)->first());
        $this->assertTrue($product->price_varies);
        $this->assertSame($this->variant->price, (int) $product->price);
        DB::table('variants')->where('product_id', $this->product->id)->update(['price' => $this->variant->price]);
        $this->assertFalse(Catalog::images(Catalog::listings()->where('products.id', $this->product->id)->first())->price_varies);
    }

    private function accessory(int $price = 25000, bool $approved = true, ?int $productId = null): int
    {
        return DB::table('product_accessories')->insertGetId([
            'product_id' => $productId ?? $this->product->id, 'name' => '[THỬ] Bộ nến', 'price' => $price,
            'active' => true, 'approved' => $approved, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fields(): array
    {
        $this->get('/dat-banh')->assertOk();
        $quote = $this->postJson('/dat-banh/bao-gia', ['fulfillment' => 'pickup'])->assertOk();

        return ['key' => session('checkout_key'), 'name' => 'Khách thử không tài khoản', 'email' => 'khach@tiembanh.test', 'phone' => '0900123456',
            'fulfillment' => 'pickup', 'slot' => $quote->json('slots.0'), 'method' => 'cod', 'quote_token' => $quote->json('quote_token')];
    }

    public function test_accessories_use_server_prices_and_immutable_order_snapshots_for_guest_checkout(): void
    {
        $id = $this->accessory();
        $this->post('/gio-hang', ['variant_id' => $this->variant->id, 'quantity' => 2, 'message' => 'Chúc mừng',
            'accessories' => [$id], 'accessory_fee' => 1, 'price' => 1])->assertSessionHasNoErrors();
        $fields = $this->fields();
        $total = 2 * ($this->variant->price + 15000 + 25000);
        $this->post('/dat-banh', [...$fields, 'total' => 1, 'accessory_fee' => 1])->assertSessionHasNoErrors();
        $order = Orders::query()->latest('id')->first();
        $this->assertNull($order->user_id);
        $this->assertSame($total, $order->total);
        $item = DB::table('order_items')->where('order_id', $order->id)->first();
        $this->assertSame(25000, $item->accessory_fee);
        DB::table('product_accessories')->where('id', $id)->update(['name' => 'Tên đổi sau mua', 'price' => 99000, 'approved' => false]);
        $this->get('/tra-cuu/'.$order->tracking_token)->assertInertia(fn (Assert $page) => $page
            ->where('order.items.0.accessories_snapshot.0.name', '[THỬ] Bộ nến')
            ->where('order.items.0.accessories_snapshot.0.price', 25000)->where('order.items.0.line_total', $total));
    }

    public function test_unapproved_foreign_or_malformed_accessories_are_rejected(): void
    {
        $unapproved = $this->accessory(1000, false);
        $otherProduct = DB::table('products')->where('id', '<>', $this->product->id)->value('id');
        $foreign = $this->accessory(1000, true, $otherProduct);
        foreach ([[$unapproved], [$foreign]] as $ids) {
            $this->post('/gio-hang', ['variant_id' => $this->variant->id, 'quantity' => 1, 'accessories' => $ids])->assertSessionHasErrors('accessories');
        }
        $this->post('/gio-hang', ['variant_id' => $this->variant->id, 'quantity' => 1, 'accessories' => [['id' => $foreign, 'price' => 1]]])->assertSessionHasErrors('accessories.0');
        $this->assertEmpty(session('cart', []));
    }

    public function test_changed_accessory_quote_requires_confirmation_without_creating_an_order(): void
    {
        $id = $this->accessory();
        $this->post('/gio-hang', ['variant_id' => $this->variant->id, 'quantity' => 1, 'accessories' => [$id]])->assertSessionHasNoErrors();
        $fields = $this->fields();
        $before = Orders::query()->count();
        DB::table('product_accessories')->where('id', $id)->update(['price' => 35000]);
        $this->post('/dat-banh', $fields)->assertSessionHasErrors('quote_token');
        $this->assertSame($before, Orders::query()->count());
        $this->assertNotEmpty(session('cart'));
        $newQuote = $this->postJson('/dat-banh/bao-gia', ['fulfillment' => 'pickup'])->assertOk();
        $this->assertNotSame($fields['quote_token'], $newQuote->json('quote_token'));
        $this->post('/dat-banh', [...$fields, 'quote_token' => $newQuote->json('quote_token')])->assertSessionHasNoErrors();
        $this->assertSame($before + 1, Orders::query()->count());
    }

    public function test_a_removed_accessory_can_be_cleared_without_removing_the_cake_or_customer_cart(): void
    {
        $id = $this->accessory();
        $this->post('/gio-hang', ['variant_id' => $this->variant->id, 'quantity' => 1, 'accessories' => [$id]])->assertSessionHasNoErrors();
        $key = array_key_first(session('cart'));
        DB::table('product_accessories')->where('id', $id)->update(['approved' => false]);
        $this->get('/gio-hang')->assertInertia(fn (Assert $page) => $page->where('lines.0.accessories_available', false)
            ->where('lines.0.stock_available', true)->where('lines.0.accessories', []));
        $this->postJson('/dat-banh/bao-gia', ['fulfillment' => 'pickup'])->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->patch('/gio-hang/'.$key, ['variant_id' => $this->variant->id, 'quantity' => 1, 'accessories' => []])->assertSessionHasNoErrors();
        $this->get('/gio-hang')->assertInertia(fn (Assert $page) => $page->where('lines.0.available', true)->where('lines.0.accessory_fee', 0));
        $this->assertSame($this->variant->id, session('cart')[$key]['variant_id']);
    }

    public function test_lost_slot_refreshes_available_choices_and_keeps_cart_and_customer_old_input(): void
    {
        $this->post('/gio-hang', ['variant_id' => $this->variant->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $fields = $this->fields();
        $settings = Store::settings();
        $settings['slot_limits'][substr($fields['slot'], 11)] = 1;
        DB::table('store_settings')->update(['data' => json_encode($settings)]);
        DB::table('orders')->where('code', 'NB-DEMO001')->update(['scheduled_at' => $fields['slot'].':00']);
        $this->post('/dat-banh', $fields)->assertSessionHasErrors('slot')->assertSessionHasInput('name', $fields['name']);
        $quote = $this->postJson('/dat-banh/bao-gia', ['fulfillment' => 'pickup'])->assertOk();
        $this->assertNotContains($fields['slot'], $quote->json('slots'));
        $this->assertNotEmpty(session('cart'));
    }
}
