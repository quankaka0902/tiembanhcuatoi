<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Checkout;
use App\Services\Orders;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(8, 0));
        config(['bakery.demo_seed' => true, 'inertia.testing.ensure_pages_exist' => false]);
        $this->seed();
        $this->variantId = DB::table('variants')->orderBy('id')->value('id');
        User::whereIn('role', ['admin', 'staff'])->each(fn ($user) => $user->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'])->save());
    }

    private function staff(string $handle = 'nhanvien'): self
    {
        $user = User::where('email', $handle.'@tiembanh.test')->firstOrFail();
        $this->actingAs($user)->withSession(['staff_mfa_verified' => $user->staffProof()]);

        return $this;
    }

    private function createOrder(string $method = 'cod', int $quantity = 1, bool $delivery = false): object
    {
        $checkout = app(Checkout::class);
        $cart = [(string) Str::uuid() => ['variant_id' => $this->variantId, 'quantity' => $quantity, 'message' => null, 'note' => 'Ghi chú thử']];
        $fields = ['key' => (string) Str::uuid(), 'name' => 'Khách thử', 'email' => 'khach@tiembanh.test', 'phone' => '0900123456',
            'fulfillment' => $delivery ? 'delivery' : 'pickup', 'region' => 'quan-1', 'address' => 'Địa chỉ thử',
            'slot' => now()->addDays(2)->format('Y-m-d').' 14:00', 'method' => $method, 'coupon' => 'NHAMINH10'];
        $lines = $checkout->cart($cart);
        $fields['quote_token'] = $checkout->quoteToken($checkout->quote($lines, $fields, Store::settings()), $lines, $fields['key']);

        return $checkout->place($cart, $fields, User::where('role', 'customer')->value('id'))[0];
    }

    private function change(object $order, string $value, array $extra = [])
    {
        return $this->patch('/quan-tri/don-hang/'.$order->id, ['kind' => 'status', 'value' => $value, ...$extra]);
    }

    public function test_new_lead_time_is_frozen_but_new_schedule_uses_current_calendar(): void
    {
        $order = $this->createOrder();
        $this->assertSame(120, $order->prep_minutes_snapshot);
        DB::table('products')->where('id', DB::table('variants')->where('id', $this->variantId)->value('product_id'))->update(['prep_minutes' => 10080]);
        $url = '/quan-tri/don-hang/'.$order->id.'/doi-gio';
        $fields = ['previous_slot' => substr($order->scheduled_at, 0, 16), 'slot' => '2026-11-24 11:00', 'note' => 'Khách đổi lịch'];
        $this->staff()->patch($url, $fields)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'kind' => 'schedule', 'value' => $fields['slot'], 'previous_value' => $fields['previous_slot']]);
        $this->assertDatabaseHas('order_notifications', ['order_id' => $order->id, 'type' => 'schedule']);
        $settings = array_replace(Store::settings(), ['closed_dates' => ['2026-11-25']]);
        DB::table('store_settings')->update(['data' => json_encode($settings)]);
        $this->patch($url, ['previous_slot' => $fields['slot'], 'slot' => '2026-11-25 14:00', 'note' => 'Ngày tiệm nghỉ'])->assertSessionHasErrors('slot');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'scheduled_at' => '2026-11-24 11:00:00', 'prep_minutes_snapshot' => 120]);
    }

    public function test_legacy_lead_time_requires_admin_verification_and_cancelled_history_stays_closed(): void
    {
        $order = $this->createOrder();
        DB::table('orders')->where('id', $order->id)->update(['prep_minutes_snapshot' => null]);
        $url = '/quan-tri/don-hang/'.$order->id;
        $this->staff()->patch($url, ['kind' => 'lead_time', 'value' => '120', 'note' => 'Đã kiểm tra'])->assertForbidden();
        $this->staff('admin')->patch($url, ['kind' => 'lead_time', 'value' => '120'])->assertSessionHasErrors('note');
        $this->patch($url, ['kind' => 'lead_time', 'value' => '120', 'note' => 'Đã kiểm tra công thức và lịch làm'])->assertSessionHasNoErrors();
        $this->assertTrue(Orders::canCustomerRequest(Orders::query()->find($order->id)));
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'kind' => 'lead_time', 'value' => '120']);
        $old = DB::table('orders')->where('code', 'NB-DEMO007')->first();
        $before = DB::table('variants')->pluck('stock', 'id')->all();
        $this->change($old, 'cancelled', ['note' => 'Không chạy lại'])->assertSessionHasErrors('value');
        $this->post('/quan-tri/don-hang/'.$old->id.'/ton-huy', [
            'items' => [['item_id' => DB::table('order_items')->where('order_id', $old->id)->value('id'), 'unmade' => 1, 'sellable' => 0, 'waste' => 0]], 'note' => 'Không hồi tố',
        ])->assertSessionHasErrors('items');
        $this->assertSame($before, DB::table('variants')->pluck('stock', 'id')->all());
    }

    public function test_early_cancel_releases_reservation_once_and_coupon_rule_is_independent(): void
    {
        $before = DB::table('variants')->where('id', $this->variantId)->value('stock');
        $order = $this->createOrder();
        $settings = array_replace(Store::settings(), ['coupon_return_on_early_cancel' => false]);
        DB::table('store_settings')->update(['data' => json_encode($settings)]);
        $this->staff();
        $this->change($order, 'cancelled')->assertSessionHasErrors('note');
        $this->change($order, 'cancelled', ['note' => 'Khách xin hủy'])->assertSessionHasNoErrors();
        $this->change($order, 'cancelled')->assertSessionHasErrors('value');
        $this->assertSame($before, DB::table('variants')->where('id', $this->variantId)->value('stock'));
        $this->assertDatabaseHas('coupons', ['id' => $order->coupon_id, 'used' => 1]);
        $this->assertSame(1, DB::table('stock_movements')->where('order_id', $order->id)->where('reason', 'cancel')->count());
        $this->assertDatabaseHas('order_notifications', ['order_id' => $order->id, 'type' => 'cancelled']);
    }

    public function test_prepared_cancel_requires_manager_and_checked_quantities_never_double_restock(): void
    {
        $before = DB::table('variants')->where('id', $this->variantId)->value('stock');
        $order = $this->createOrder(quantity: 3);
        $this->staff();
        foreach (['confirmed', 'preparing'] as $status) {
            $this->change($order, $status)->assertSessionHasNoErrors();
        }
        $this->change($order, 'cancelled', ['note' => 'Đã bắt đầu làm'])->assertForbidden();
        $this->staff('admin');
        $this->change($order, 'cancelled')->assertSessionHasErrors('note');
        $this->change($order, 'cancelled', ['note' => 'Duyệt ngoại lệ theo trao đổi khách'])->assertSessionHasNoErrors();
        $this->assertSame($before - 3, DB::table('variants')->where('id', $this->variantId)->value('stock'));
        $this->assertDatabaseHas('coupons', ['id' => $order->coupon_id, 'used' => 1]);
        $this->get('/quan-tri/viec-can-xu-ly?filter=inventory')->assertInertia(fn (Assert $page) => $page->where('inventoryCount', 1));
        $url = '/quan-tri/don-hang/'.$order->id;
        $this->patch($url, ['kind' => 'payment', 'value' => 'refunded'])->assertSessionHasErrors('value');
        $itemId = DB::table('order_items')->where('order_id', $order->id)->value('id');
        $fields = ['items' => [['item_id' => $itemId, 'unmade' => 1, 'sellable' => 1, 'waste' => 1]], 'note' => 'Đã kiểm tra 1 chưa làm, 1 còn bán được, 1 tiêu hao'];
        $this->staff()->post($url.'/ton-huy', $fields)->assertForbidden();
        $this->staff('admin')->post($url.'/ton-huy', $fields)->assertSessionHasNoErrors();
        $this->post($url.'/ton-huy', $fields)->assertSessionHasErrors('items');
        $this->assertSame($before - 1, DB::table('variants')->where('id', $this->variantId)->value('stock'));
        $this->assertDatabaseHas('stock_movements', ['order_id' => $order->id, 'reason' => 'waste', 'affected_quantity' => 1, 'stock_before' => $before - 1, 'stock_after' => $before - 1]);
        $this->assertDatabaseHas('order_inventory_dispositions', ['order_id' => $order->id, 'unmade' => 1, 'sellable' => 1, 'waste' => 1]);
    }

    public function test_bank_unpaid_is_blocked_until_verified_or_explicit_owner_enabled_exception(): void
    {
        $order = $this->createOrder('bank', delivery: true);
        $url = '/quan-tri/don-hang/'.$order->id;
        $this->staff();
        $this->change($order, 'confirmed')->assertSessionHasNoErrors();
        $this->change($order, 'preparing')->assertSessionHasErrors('value');
        $exception = ['kind' => 'prepay_exception', 'value' => 'granted', 'note' => 'Quản lý chấp thuận ngoại lệ đơn thử'];
        $this->patch($url, $exception)->assertForbidden();
        $this->staff('admin')->patch($url, $exception)->assertSessionHasErrors('value');
        $settings = array_replace(Store::settings(), ['allow_payment_override' => true]);
        DB::table('store_settings')->update(['data' => json_encode($settings)]);
        $this->patch($url, $exception)->assertSessionHasNoErrors();
        $this->staff();
        foreach (['preparing', 'shipping'] as $status) {
            $this->change($order, $status)->assertSessionHasNoErrors();
        }
        $this->change($order, 'completed')->assertSessionHasErrors('value');
        $this->staff('doisoat')->patch($url, ['kind' => 'payment', 'value' => 'paid'])->assertSessionHasNoErrors();
        $this->change($order, 'completed')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'kind' => 'prepay_exception', 'value' => 'granted']);
    }

    public function test_paid_cancel_requires_full_method_specific_refund_evidence_and_hides_it_from_staff(): void
    {
        $order = $this->createOrder('bank');
        $url = '/quan-tri/don-hang/'.$order->id;
        $this->staff('doisoat')->patch($url, ['kind' => 'payment', 'value' => 'paid'])->assertSessionHasNoErrors();
        $this->change($order, 'cancelled', ['note' => 'Khách xin hủy trước làm'])->assertSessionHasNoErrors();
        $this->get('/quan-tri/viec-can-xu-ly?filter=refund')->assertInertia(fn (Assert $page) => $page->where('refundCount', 1));
        $fields = ['kind' => 'payment', 'value' => 'refunded', 'refund_method' => 'bank', 'refund_amount' => $order->total,
            'refund_reference' => 'GD-HOAN-20261123', 'refund_verified' => true, 'note' => 'Đã đối chiếu chứng từ thử'];
        $this->staff()->patch($url, $fields)->assertForbidden();
        $this->staff('doisoat')->patch($url, [...$fields, 'refund_method' => 'cash'])->assertSessionHasErrors('refund_reference');
        $this->patch($url, [...$fields, 'refund_amount' => $order->total - 1])->assertSessionHasErrors('refund_reference');
        $this->patch($url, $fields)->assertSessionHasNoErrors();
        $this->patch($url, $fields)->assertSessionHasErrors('value');
        $this->get('/quan-tri/viec-can-xu-ly?filter=refund')->assertInertia(fn (Assert $page) => $page->where('refundCount', 0));
        $this->staff()->get($url)->assertInertia(fn (Assert $page) => $page->missing('order.refund_evidence'));
        $this->get('/tra-cuu/'.$order->tracking_token)->assertInertia(fn (Assert $page) => $page->where('order.payment', 'refunded')->missing('order.refund_evidence'));
    }

    public function test_prepay_snapshot_cannot_be_weakened_and_tightening_policy_blocks_dispatch(): void
    {
        $strict = $this->createOrder('bank');
        $settings = array_replace(Store::settings(), ['bank_prepay_required' => false]);
        DB::table('store_settings')->update(['data' => json_encode($settings)]);
        $this->staff();
        $this->change($strict, 'confirmed')->assertSessionHasNoErrors();
        $this->change($strict, 'preparing')->assertSessionHasErrors('value');
        $invoice = $this->createOrder('bank', delivery: true);
        foreach (['confirmed', 'preparing'] as $status) {
            $this->change($invoice, $status)->assertSessionHasNoErrors();
        }
        $settings['bank_prepay_required'] = true;
        DB::table('store_settings')->update(['data' => json_encode($settings)]);
        $this->change($invoice, 'shipping')->assertSessionHasErrors('value');
        $this->assertDatabaseHas('orders', ['id' => $invoice->id, 'status' => 'preparing']);
        $this->assertSame(0, DB::table('order_events')->where('order_id', $invoice->id)->where('value', 'shipping')->count());
    }

    public function test_cod_cash_refund_needs_a_verified_cash_voucher(): void
    {
        $order = $this->createOrder();
        $url = '/quan-tri/don-hang/'.$order->id;
        $this->staff();
        foreach (['confirmed', 'preparing', 'ready'] as $status) {
            $this->change($order, $status)->assertSessionHasNoErrors();
        }
        $this->patch($url, ['kind' => 'payment', 'value' => 'paid'])->assertSessionHasNoErrors();
        $this->staff('admin');
        $this->change($order, 'cancelled', ['note' => 'Khách chưa nhận, quản lý duyệt hủy'])->assertSessionHasNoErrors();
        $fields = ['kind' => 'payment', 'value' => 'refunded', 'refund_method' => 'cash', 'refund_reference' => 'PHIEU-CHI-THU-01',
            'refund_amount' => $order->total, 'refund_verified' => false, 'note' => 'Đã hoàn tiền mặt và kiểm tra phiếu chi'];
        $this->patch($url, $fields)->assertSessionHasErrors('refund_reference');
        $this->patch($url, [...$fields, 'refund_verified' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('order_notifications', ['order_id' => $order->id, 'type' => 'payment_refunded']);
    }

    public function test_delivery_failure_and_uncollected_have_resolution_and_issued_stock_cannot_be_unmade(): void
    {
        $order = $this->createOrder(delivery: true);
        $url = '/quan-tri/don-hang/'.$order->id;
        $this->staff();
        foreach (['confirmed', 'preparing', 'shipping'] as $status) {
            $this->change($order, $status)->assertSessionHasNoErrors();
        }
        $this->change($order, 'delivery_failed')->assertSessionHasErrors('note');
        $this->change($order, 'delivery_failed', ['note' => 'Chưa liên hệ được khách'])->assertSessionHasNoErrors();
        $this->get('/quan-tri/viec-can-xu-ly?filter=exception')->assertInertia(fn (Assert $page) => $page->where('exceptionCount', 1));
        $this->staff('admin')->patch($url.'/doi-gio', ['previous_slot' => substr($order->scheduled_at, 0, 16), 'slot' => '2026-11-26 14:00', 'note' => 'Đã thống nhất giờ giao lại'])->assertSessionHasNoErrors();
        $this->staff();
        $this->change($order, 'shipping', ['note' => 'Khách xác nhận giao lại'])->assertSessionHasNoErrors();
        $this->staff('admin');
        $this->change($order, 'cancelled', ['note' => 'Khách không nhận, kiểm tra bánh trả về'])->assertSessionHasNoErrors();
        $item = DB::table('order_items')->where('order_id', $order->id)->first();
        $this->post($url.'/ton-huy', ['items' => [['item_id' => $item->id, 'unmade' => 1, 'sellable' => 0, 'waste' => 0]], 'note' => 'Sai phân loại'])->assertSessionHasErrors('items');
        $this->post($url.'/ton-huy', ['items' => [['item_id' => $item->id, 'unmade' => 0, 'sellable' => 0, 'waste' => 1]], 'note' => 'Bánh trả về không thể bán'])->assertSessionHasNoErrors();

        $pickup = $this->createOrder();
        $this->staff();
        foreach (['confirmed', 'preparing', 'ready'] as $status) {
            $this->change($pickup, $status)->assertSessionHasNoErrors();
        }
        $this->change($pickup, 'uncollected', ['note' => 'Khách chưa tới nhận'])->assertSessionHasNoErrors();
        $this->patch('/quan-tri/don-hang/'.$pickup->id, ['kind' => 'payment', 'value' => 'paid'])->assertSessionHasNoErrors();
        $this->change($pickup, 'completed', ['note' => 'Khách đã tới nhận và trả tiền'])->assertSessionHasNoErrors();
    }
}
