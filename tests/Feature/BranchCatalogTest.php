<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Branches;
use App\Services\Catalog;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BranchCatalogTest extends TestCase
{
    use RefreshDatabase;

    private object $variant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bakery.demo_seed' => true, 'inertia.testing.ensure_pages_exist' => false]);
        $this->seed();
        $settings = Store::settings();
        DB::table('chain_settings')->where('id', 1)->update(['data' => json_encode(array_intersect_key($settings, array_flip(Store::BRAND_KEYS)))]);
        DB::table('branches')->where('id', 1)->update(['settings' => json_encode($settings)]);
        DB::table('branches')->insert(['id' => 2, 'code' => 'TEST-B', 'slug' => 'test-b', 'name' => '[THỬ] B', 'timezone' => 'Asia/Ho_Chi_Minh', 'active' => true, 'settings' => json_encode($settings), 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('variants')->get() as $variant) {
            foreach ([1, 2] as $branch) {
                DB::table('branch_variants')->insert(['branch_id' => $branch, 'variant_id' => $variant->id, 'stock' => $variant->stock, 'active' => $variant->active, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('branch_migration_state')->where('id', 1)->update(['mode' => 'branch']);
        $this->variant = DB::table('variants')->orderBy('id')->first();
        User::whereIn('role', ['admin', 'staff'])->each(fn (User $user) => $user->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'])->save());
        Branches::use(1);
    }

    private function staff(string $role = 'manager', int $branch = 1): User
    {
        $user = User::where('email', $role === 'admin' ? 'admin@tiembanh.test' : 'nhanvien@tiembanh.test')->firstOrFail();
        if ($role !== 'admin') {
            DB::table('branch_memberships')->where('user_id', $user->id)->delete();
            DB::table('branch_memberships')->insert(['user_id' => $user->id, 'branch_id' => $branch, 'role' => $role, 'active' => true, 'can_reconcile' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
        $user = $user->fresh();
        $this->actingAs($user)->withSession(['admin_branch_id' => $branch, 'staff_mfa_verified' => $user->staffProof()]);

        return $user;
    }

    public function test_catalog_price_stock_and_filter_all_use_the_selected_branch_variant(): void
    {
        DB::table('branch_variants')->where('branch_id', 2)->update(['active' => false]);
        DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->variant->id)->update(['price' => 765000, 'stock' => 3, 'active' => true, 'prep_minutes' => 175]);
        Branches::use(2);
        $listing = Catalog::listings(['min' => 700000, 'max' => 800000, 'availability' => 'available'])->first();
        $this->assertSame((int) $this->variant->product_id, (int) $listing->id);
        $this->assertSame(765000, (int) $listing->price);
        $this->assertSame(3, (int) $listing->stock);
        $this->assertSame(0, Catalog::listings(['max' => 600000])->count());
        $this->withSession(['branch_id' => 2])->get('/banh/'.DB::table('products')->where('id', $this->variant->product_id)->value('slug'))
            ->assertInertia(fn (Assert $page) => $page->where('product.variants.0.price', 765000)->where('product.variants.0.stock', 3)->where('product.variants.0.prep_minutes', 175)
                ->where('shop.branch.id', 2)->where('seo.indexable', false));
        Branches::use(1);
        $this->assertSame((int) $this->variant->price, (int) Catalog::variants()->where('id', $this->variant->id)->value('price'));
        $this->assertSame((int) $this->variant->stock, (int) Catalog::variants()->where('id', $this->variant->id)->value('stock'));
    }

    public function test_branch_switch_requires_cart_confirmation_and_rejects_hidden_branch_and_stale_tab(): void
    {
        $cart = ['line' => ['variant_id' => $this->variant->id, 'quantity' => 1]];
        $this->withSession(['cart' => $cart, 'cart_branch_id' => 1, 'branch_id' => 1, 'checkout_key' => 'old-key'])
            ->post('/co-so/chon', ['branch_id' => 2])->assertSessionHasErrors('branch_id')->assertSessionHas('cart', $cart);
        $this->post('/co-so/chon', ['branch_id' => 2, 'confirm_clear' => true])->assertRedirect('/thuc-don')
            ->assertSessionHas('branch_id', 2)->assertSessionMissing('cart')->assertSessionMissing('checkout_key');
        $this->post('/gio-hang', ['branch_id' => 1, 'variant_id' => $this->variant->id, 'quantity' => 1])->assertSessionHasErrors('branch_id');
        DB::table('branches')->where('id', 1)->update(['active' => false]);
        $this->post('/co-so/chon', ['branch_id' => 1, 'confirm_clear' => true])->assertNotFound();
        $this->withSession(['cart' => $cart, 'cart_branch_id' => 1, 'branch_id' => 2])->get('/dat-banh')->assertSessionHasErrors('cart');
        $this->post('/co-so/chon', ['branch_id' => 2, 'confirm_clear' => true])->assertRedirect('/thuc-don')->assertSessionMissing('cart');
    }

    public function test_manager_updates_only_selected_branch_inventory_with_conflict_check_and_audit(): void
    {
        $this->staff('manager', 2);
        $beforeA = DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->variant->id)->value('stock');
        $beforeB = DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->variant->id)->value('stock');
        $fields = ['active' => true, 'price' => 789000, 'prep_minutes' => 120, 'stock' => $beforeB + 2, 'expected_stock' => $beforeB, 'note' => 'Nhập lô kiểm thử'];
        $url = '/quan-tri/thuc-don-co-so/'.$this->variant->id;
        $this->patch($url, $fields)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('branch_variants', ['branch_id' => 2, 'variant_id' => $this->variant->id, 'price' => 789000, 'stock' => $beforeB + 2]);
        $this->assertSame((int) $beforeA, (int) DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->variant->id)->value('stock'));
        $this->assertDatabaseHas('stock_movements', ['branch_id' => 2, 'variant_id' => $this->variant->id, 'stock_before' => $beforeB, 'stock_after' => $beforeB + 2]);
        $this->assertDatabaseHas('branch_audits', ['branch_id' => 2, 'action' => 'variant.updated']);
        $this->patch($url, $fields)->assertSessionHasErrors('stock');
        $this->get('/quan-tri/san-pham')->assertForbidden();
        $this->staff('staff', 1);
        $this->patch($url, $fields)->assertForbidden();
    }

    public function test_manager_saves_branch_settings_but_cannot_change_chain_brand(): void
    {
        $this->staff('manager', 2);
        Branches::use(2);
        $fields = Store::settings() + ['remove_logo' => false];
        unset($fields['logo']);
        $fields['address'] = '[THỬ] Địa chỉ B';
        $fields['calendar_exceptions'] = [
            ['date' => '2026-12-10', 'closed' => false, 'slots' => ['10:00'], 'slot_limits' => ['10:00' => 2]],
            ['date' => '2026-12-11', 'closed' => false, 'slots' => ['10:00'], 'slot_limits' => ['10:00' => 3]],
        ];
        $this->post('/quan-tri/cai-dat', $fields)->assertSessionHasNoErrors();
        $this->assertSame('[THỬ] Địa chỉ B', Store::settings(branchId: 2)['address']);
        $this->assertNotSame('[THỬ] Địa chỉ B', Store::settings(branchId: 1)['address']);
        $this->assertCount(2, Store::settings(branchId: 2)['calendar_exceptions']);
        $fields['name'] = 'Tên bị sửa trái phép';
        $this->post('/quan-tri/cai-dat', $fields)->assertForbidden();
        $this->assertNotSame($fields['name'], Store::settings()['name']);
    }

    public function test_chain_admin_creates_paused_draft_without_inventing_approved_store_data(): void
    {
        $this->staff('admin');
        $this->post('/quan-tri/co-so', ['code' => 'TEST-C', 'slug' => 'test-c', 'name' => '[THỬ] C', 'active' => true, 'timezone' => 'Asia/Ho_Chi_Minh'])->assertSessionHasNoErrors();
        $branch = DB::table('branches')->where('code', 'TEST-C')->first();
        $settings = Store::settings(branchId: $branch->id);
        $this->assertFalse((bool) $branch->active);
        $this->assertFalse($settings['accepting_orders']);
        $this->assertFalse($settings['business_verified']);
        $this->assertNull($settings['address']);
        $this->assertNull($settings['bank_account']);
        $this->assertSame([], $settings['regions']);
        $this->assertSame(0, Branches::run($branch->id, fn () => Catalog::listings()->count()));
        $this->assertDatabaseHas('branch_audits', ['branch_id' => $branch->id, 'action' => 'branch.created']);
    }

    public function test_membership_change_invalidates_authorization_proof_and_is_audited(): void
    {
        $admin = $this->staff('admin');
        $target = User::where('email', 'nhanvien@tiembanh.test')->firstOrFail();
        $proof = $target->staffProof();
        $this->post('/quan-tri/phan-cong-co-so', ['branch_id' => 2, 'user_id' => $target->id, 'role' => 'kitchen', 'active' => true, 'can_reconcile' => true])->assertSessionHasErrors('can_reconcile');
        $this->post('/quan-tri/phan-cong-co-so', ['branch_id' => 2, 'user_id' => $target->id, 'role' => 'kitchen', 'active' => true, 'can_reconcile' => false])->assertSessionHasNoErrors();
        $this->assertSame(1, (int) $target->fresh()->authorization_version);
        $this->assertNotSame($proof, $target->fresh()->staffProof());
        $this->assertDatabaseHas('branch_memberships', ['branch_id' => 2, 'user_id' => $target->id, 'role' => 'kitchen']);
        $this->assertDatabaseHas('branch_audits', ['branch_id' => 2, 'actor_id' => $admin->id, 'action' => 'membership.updated']);
        $this->actingAs($target->fresh())->withSession(['admin_branch_id' => 2, 'staff_mfa_verified' => $proof])->get('/quan-tri/lich-lam-banh')->assertRedirect('/dang-nhap');
    }

    public function test_global_user_permission_changes_revoke_factor_and_audit_without_secrets(): void
    {
        $this->staff('admin');
        $target = User::where('email', 'nhanvien@tiembanh.test')->firstOrFail();
        $before = $target->staffProof();
        $version = $target->authorization_version;
        $this->post('/quan-tri/nhan-su/'.$target->id, ['name' => $target->name, 'email' => $target->email, 'role' => 'staff', 'active' => true, 'can_reconcile' => true])->assertSessionHasNoErrors();
        $this->assertSame($version + 1, $target->fresh()->authorization_version);
        $this->assertNotSame($before, $target->fresh()->staffProof());
        $audit = DB::table('branch_audits')->where('action', 'user.updated')->first();
        $this->assertNotNull($audit);
        foreach (['two_factor', 'remember_token', $target->password, $target->email] as $secret) {
            $this->assertStringNotContainsString($secret, $audit->data);
        }
        $this->assertTrue((bool) json_decode($audit->data, true)['after']['can_reconcile']);
    }

    public function test_hiding_then_restoring_branch_does_not_reopen_intake(): void
    {
        $this->staff('admin');
        $fields = ['code' => 'TEST-B', 'slug' => 'test-b', 'name' => '[THỬ] B', 'active' => false, 'timezone' => 'Asia/Ho_Chi_Minh'];
        $this->post('/quan-tri/co-so/2', $fields)->assertSessionHasNoErrors();
        $this->assertFalse(Store::settings(branchId: 2)['accepting_orders']);
        $fields['active'] = true;
        $this->post('/quan-tri/co-so/2', $fields)->assertSessionHasNoErrors();
        $this->assertFalse(Store::settings(branchId: 2)['accepting_orders']);
    }

    public function test_chain_coupon_is_visible_editable_and_disable_preserves_shared_usage_limit(): void
    {
        $this->staff('admin', 2);
        $coupon = DB::table('coupons')->whereNull('branch_id')->firstOrFail();
        DB::table('coupons')->where('id', $coupon->id)->update(['used' => 3, 'usage_limit' => 7]);
        $this->get('/quan-tri/ma-giam?scope=chain')->assertInertia(fn (Assert $page) => $page->where('couponScope', 'chain')->has('coupons.data', DB::table('coupons')->whereNull('branch_id')->count()));
        $this->get('/quan-tri/ma-giam/'.$coupon->id.'?scope=chain')->assertInertia(fn (Assert $page) => $page->where('coupon.id', $coupon->id)->where('coupon.used', 3)->where('coupon.usage_limit', 7));
        $fields = ['scope' => 'chain', 'branch_id' => null, 'code' => $coupon->code, 'kind' => $coupon->kind, 'amount' => $coupon->amount,
            'minimum' => $coupon->minimum, 'usage_limit' => 7, 'starts_on' => $coupon->starts_on, 'ends_on' => $coupon->ends_on, 'active' => false];
        $this->post('/quan-tri/ma-giam/'.$coupon->id, $fields)->assertRedirect('/quan-tri/ma-giam?scope=chain')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'branch_id' => null, 'active' => false, 'used' => 3, 'usage_limit' => 7]);
        $this->staff('manager', 2);
        $this->get('/quan-tri/ma-giam?scope=chain')->assertForbidden();
        $this->get('/quan-tri/ma-giam/'.$coupon->id.'?scope=branch')->assertNotFound();
        $this->post('/quan-tri/ma-giam/'.$coupon->id, $fields)->assertForbidden();
        $fields = array_replace($fields, ['scope' => 'branch', 'branch_id' => 2, 'code' => 'LOCAL-B']);
        $this->post('/quan-tri/ma-giam', $fields)->assertRedirect('/quan-tri/ma-giam?scope=branch')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('coupons', ['code' => 'LOCAL-B', 'branch_id' => 2]);
    }

    public function test_order_location_snapshot_and_current_support_stay_with_order_branch_when_storefront_selection_differs(): void
    {
        foreach ([1, 2] as $id) {
            $settings = Store::settings(branchId: $id);
            $settings['address'] = '[THỬ] Điểm nhận hiện tại '.$id;
            $settings['phone'] = '090000000'.$id;
            $settings['email'] = 'hotro'.$id.'@example.test';
            DB::table('branches')->where('id', $id)->update(['settings' => json_encode($settings)]);
        }
        $order = DB::table('orders')->where('code', 'NB-DEMO002')->firstOrFail();
        $snapshot = ['id' => 2, 'name' => '[THỬ] B lúc đặt', 'address' => '[THỬ] Điểm nhận B lúc đặt', 'phone' => '0900000022'];
        DB::table('orders')->where('id', $order->id)->update(['branch_id' => 2, 'fulfillment' => 'pickup', 'branch_snapshot' => json_encode($snapshot)]);
        $url = '/tra-cuu/'.$order->tracking_token;
        $this->withSession(['branch_id' => 1])->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('shop.branch.id', 1)->where('shop.address', '[THỬ] Điểm nhận hiện tại 1')
            ->where('order.branch_snapshot', $snapshot)->where('order.branch_contact.id', 2)
            ->where('order.branch_contact.name', '[THỬ] B')->where('order.branch_contact.phone', '0900000002')
            ->where('order.branch_contact.email', 'hotro2@example.test')->missing('order.branch_contact.address'));
        DB::table('orders')->where('id', $order->id)->update(['branch_snapshot' => null]);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('shop.branch.id', 1)
            ->where('order.branch_snapshot.historical_details_known', false)->missing('order.branch_snapshot.address')
            ->where('order.branch_contact.id', 2)->where('order.branch_contact.phone', '0900000002')->missing('order.branch_contact.address'));
    }
}
