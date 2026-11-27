<?php

namespace Tests\Feature;

use App\Services\Branches;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BranchFixture;
use Tests\TestCase;

class BranchSelectionTest extends TestCase
{
    use BranchFixture, RefreshDatabase;

    private array $cart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 27)->setTime(8, 0));
        $this->prepareBranches();
        $variant = DB::table('variants')->find($this->branchVariantId);
        $accessory = DB::table('product_accessories')->insertGetId(['product_id' => $variant->product_id, 'name' => '[THỬ] Nến',
            'price' => 12000, 'active' => true, 'approved' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->cart = ['11111111-1111-4111-8111-111111111111' => ['variant_id' => $this->branchVariantId, 'quantity' => 1,
            'message' => 'Ca thử', 'note' => 'Giữ tùy chọn trong giỏ', 'accessories' => [$accessory]]];
        $this->withSession(['branch_id' => 1, 'cart_branch_id' => 1, 'cart' => $this->cart, 'checkout_key' => 'old-checkout-key']);
    }

    private function preview(array $fields = []): array
    {
        $response = $this->postJson('/co-so/du-kien', array_replace(['target_branch_id' => 2, 'fulfillment' => 'pickup',
            'method' => 'cod', 'slot' => '2026-11-28 10:00'], $fields));
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(1, Branches::id());

        return $response->json();
    }

    private function confirm(array $preview, array $fields = [])
    {
        return $this->post('/co-so/xac-nhan', array_replace(['branch_id' => 2, 'quote_token' => $preview['quote_token'],
            'confirm' => true, 'mode' => 'keep'], $fields));
    }

    public function test_preview_changes_nothing_and_explicit_keep_preserves_all_lines_options_and_stock(): void
    {
        DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->update(['price' => 555000]);
        $beforeStock = DB::table('branch_variants')->orderBy('id')->pluck('stock', 'id')->all();
        $preview = $this->preview();
        $this->assertTrue($preview['can_keep_cart']);
        $this->assertSame(2, $preview['to']['id']);
        $this->assertSame(555000, $preview['new']['lines'][0]['unit_price']);
        $this->assertSame($this->cart, session('cart'));
        $this->assertSame(1, session('branch_id'));
        $this->confirm($preview, ['confirm' => false])->assertSessionHasErrors('confirm');
        $this->assertSame($this->cart, session('cart'));
        $this->confirm($preview)->assertRedirect('/dat-banh')->assertSessionHasNoErrors()
            ->assertSessionHas('cart', $this->cart)->assertSessionHas('cart_branch_id', 2)->assertSessionHas('branch_id', 2);
        $this->assertNotSame('old-checkout-key', session('checkout_key'));
        $this->assertSame($beforeStock, DB::table('branch_variants')->orderBy('id')->pluck('stock', 'id')->all());
        $this->assertSame(0, DB::table('orders')->where('request_key', 'old-checkout-key')->count());
    }

    public function test_unavailable_full_cart_cannot_be_kept_but_explicit_clear_is_supported(): void
    {
        DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->update(['stock' => 0]);
        $preview = $this->preview();
        $this->assertFalse($preview['can_keep_cart']);
        $this->assertNotEmpty($preview['blocked_reason']);
        $this->confirm($preview)->assertSessionHasErrors('mode')->assertSessionHas('cart', $this->cart)->assertSessionHas('branch_id', 1);
        $this->confirm($preview, ['mode' => 'clear'])->assertRedirect('/thuc-don')->assertSessionMissing('cart')
            ->assertSessionMissing('cart_branch_id')->assertSessionHas('branch_id', 2);
        $this->assertSame(0, (int) DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->value('stock'));
    }

    public function test_tampered_expired_replayed_at_other_branch_or_stale_cart_quote_never_changes_selection(): void
    {
        $preview = $this->preview();
        $this->confirm($preview, ['quote_token' => 'changed-token'])->assertSessionHasErrors('quote_token');
        $changedCart = $this->cart;
        $changedCart[array_key_first($changedCart)]['quantity'] = 2;
        $this->withSession(['cart' => $changedCart])->confirm($preview)->assertSessionHasErrors('quote_token')->assertSessionHas('cart', $changedCart);
        $this->withSession(['cart' => $this->cart, 'branch_id' => 2, 'cart_branch_id' => 2])->confirm($preview)->assertSessionHasErrors('quote_token');
        $this->withSession(['branch_id' => 1, 'cart_branch_id' => 1]);
        $this->travel(11)->minutes();
        $this->confirm($preview)->assertSessionHasErrors('quote_token')->assertSessionHas('branch_id', 1)->assertSessionHas('cart', $this->cart);
    }

    public function test_price_or_availability_change_requires_new_confirmation_and_preserves_original_cart(): void
    {
        $preview = $this->preview();
        DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->update(['price' => 888000]);
        $this->confirm($preview)->assertSessionHasErrors('quote_token')->assertSessionHas('branch_id', 1)->assertSessionHas('cart', $this->cart);
        $fresh = $this->preview();
        $this->assertSame(888000, $fresh['new']['lines'][0]['unit_price']);
        DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->update(['stock' => 0]);
        $this->confirm($fresh)->assertSessionHasErrors('quote_token')->assertSessionHas('branch_id', 1);
    }

    public function test_preview_uses_trusted_cart_origin_and_rejects_replay_after_origin_changes(): void
    {
        DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->branchVariantId)->update(['price' => 222000]);
        DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->update(['price' => 555000]);
        $this->withSession(['branch_id' => 2, 'cart_branch_id' => 1]);
        $preview = $this->postJson('/co-so/du-kien', ['target_branch_id' => 2, 'fulfillment' => 'pickup', 'method' => 'cod', 'slot' => '2026-11-28 10:00'])
            ->assertOk()->json();
        $this->assertSame(1, $preview['from']['id']);
        $this->assertSame(222000, $preview['old']['lines'][0]['unit_price']);
        $this->assertSame(555000, $preview['new']['lines'][0]['unit_price']);
        $this->assertTrue($preview['can_keep_cart']);
        $this->withSession(['cart_branch_id' => 2])->confirm($preview)->assertSessionHasErrors('quote_token')
            ->assertSessionHas('cart', $this->cart)->assertSessionHas('branch_id', 2);
        $this->withSession(['cart_branch_id' => 1])->confirm($preview)->assertRedirect('/dat-banh')
            ->assertSessionHas('cart', $this->cart)->assertSessionHas('cart_branch_id', 2);
    }

    public function test_clear_can_start_over_when_target_does_not_serve_the_previous_delivery_area(): void
    {
        $region = Store::settings(branchId: 1)['regions'][0];
        $preview = $this->preview(['fulfillment' => 'delivery', 'area_code' => $region['area_code'] ?? $region['key'],
            'address_line' => '[THỬ] Địa chỉ vùng chỉ cơ sở nguồn phục vụ', 'country_code' => 'VN']);
        $this->assertFalse($preview['can_keep_cart']);
        $this->confirm($preview, ['mode' => 'clear'])->assertRedirect('/thuc-don')->assertSessionMissing('cart');
        $draft = json_decode(Crypt::decryptString(session('delivery_draft.encrypted')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('address_line', $draft);
        $this->assertArrayNotHasKey('pin', $draft);
        $this->assertSame(2, session('branch_id'));
    }

    public function test_delivery_draft_is_encrypted_branch_bound_expires_and_preview_validation_does_not_flash_address(): void
    {
        $private = '[THỬ] Dòng địa chỉ riêng của người nhận';
        $preview = $this->preview(['fulfillment' => 'delivery', 'area_code' => 'vung-b', 'address_line' => $private,
            'country_code' => 'VN', 'delivery_note' => 'Gọi khi đến cổng', 'pin' => ['lat' => 10.123456, 'lng' => 106.123456, 'confirmed' => true]]);
        $this->assertStringNotContainsString($private, json_encode($preview, JSON_THROW_ON_ERROR));
        $this->assertNull(session('delivery_draft'));
        $this->confirm($preview)->assertRedirect('/dat-banh');
        $stored = session('delivery_draft');
        $this->assertSame(2, $stored['branch_id']);
        $this->assertStringNotContainsString($private, json_encode($stored, JSON_THROW_ON_ERROR));
        $this->assertSame($private, json_decode(Crypt::decryptString($stored['encrypted']), true)['address_line']);
        $this->get('/dat-banh')->assertInertia(fn (Assert $page) => $page->where('deliveryDraft.address_line', $private)
            ->where('deliveryDraft.area_code', 'vung-b')->where('shop.branch.id', 2));
        $this->withSession(['branch_id' => 1, 'cart_branch_id' => 1])->get('/dat-banh')
            ->assertInertia(fn (Assert $page) => $page->where('deliveryDraft', null))->assertSessionMissing('delivery_draft');
        $this->withSession(['branch_id' => 2, 'cart_branch_id' => 2, 'delivery_draft' => $stored]);
        $this->travel(31)->minutes();
        $this->get('/dat-banh')->assertInertia(fn (Assert $page) => $page->where('deliveryDraft', null))->assertSessionMissing('delivery_draft');
        $this->postJson('/co-so/du-kien', ['target_branch_id' => 2, 'fulfillment' => 'delivery', 'method' => 'cod',
            'area_code' => 'vung-b', 'address_line' => $private, 'pin' => ['lat' => 10, 'lng' => 106, 'confirmed' => false]])->assertUnprocessable();
        $this->assertStringNotContainsString($private, json_encode(session()->all(), JSON_THROW_ON_ERROR));
    }
}
