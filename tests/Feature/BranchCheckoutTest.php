<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Branches;
use App\Services\Checkout;
use App\Services\Orders;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\BranchFixture;
use Tests\TestCase;

class BranchCheckoutTest extends TestCase
{
    use BranchFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 26)->setTime(8, 0));
        $this->prepareBranches();
    }

    public function test_branch_price_stock_prep_and_pickup_context_are_independent_snapshots(): void
    {
        $checkout = app(Checkout::class);
        $original = DB::table('variants')->find($this->branchVariantId);
        $fieldsA = $this->branchFields();
        [$a] = $checkout->place($this->branchCart(), $fieldsA, null);
        $fieldsB = $this->branchFields(2, ['fulfillment' => 'delivery', 'region' => 'vung-b', 'address' => '[THỬ] Địa chỉ giao']);
        [$b] = Branches::run(2, fn () => $checkout->place($this->branchCart(), $fieldsB, null));
        $this->assertSame(1, (int) $a->branch_id);
        $this->assertSame(2, (int) $b->branch_id);
        $this->assertSame(55000, (int) $b->delivery_fee);
        $this->assertSame(240, (int) $b->prep_minutes_snapshot);
        $this->assertSame($original->price + 100000, (int) DB::table('order_items')->where('order_id', $b->id)->value('unit_price'));
        $this->assertSame($original->stock - 1, (int) DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->branchVariantId)->value('stock'));
        $this->assertSame(3, (int) DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->value('stock'));
        $this->assertSame($original->stock - 1, (int) DB::table('variants')->where('id', $this->branchVariantId)->value('stock'));
        $snapshot = json_decode($b->branch_snapshot, true);
        $this->assertSame('[THỬ] Điểm nhận B', $snapshot['address']);
        $this->assertSame('at_purchase', $snapshot['provenance']);
        $this->assertSame('vung-b', json_decode($b->sales_context, true)['region_key']);
        DB::table('branch_variants')->where('branch_id', 2)->update(['price' => 999999]);
        DB::table('branches')->where('id', 2)->update(['name' => '[THỬ] Đổi tên']);
        $this->assertSame($snapshot, json_decode(DB::table('orders')->find($b->id)->branch_snapshot, true));
        $this->assertSame($b->total, DB::table('orders')->find($b->id)->total);
    }

    public function test_quote_rejects_branch_time_method_region_and_price_changes_without_side_effects(): void
    {
        $checkout = app(Checkout::class);
        $fields = $this->branchFields();
        $before = DB::table('orders')->count();
        foreach ([['branch_id' => 2], ['slot' => now()->addDays(4)->toDateString().' 11:00'], ['method' => 'bank'], ['fulfillment' => 'delivery', 'region' => 'quan-1', 'address' => '[THỬ] Địa chỉ']] as $change) {
            try {
                $checkout->place($this->branchCart(), array_replace($fields, $change), null);
                $this->fail('Stale quote was accepted.');
            } catch (ValidationException $failure) {
                $this->assertContains(array_key_first($failure->errors()), ['branch_id', 'quote_token']);
            }
        }
        DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->branchVariantId)->update(['price' => 123456]);
        try {
            $checkout->place($this->branchCart(), $fields, null);
            $this->fail('Old price accepted.');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey('quote_token', $failure->errors());
        }
        $this->assertSame($before, DB::table('orders')->count());
        $this->assertSame(0, DB::table('checkout_request_locks')->count());
    }

    public function test_request_replay_takes_stock_coupon_and_slot_exactly_once(): void
    {
        $checkout = app(Checkout::class);
        $fields = $this->branchFields(1, ['coupon' => 'NHAMINH10']);
        [$first, $created] = $checkout->place($this->branchCart(), $fields, null);
        [$second, $replayed] = $checkout->place($this->branchCart(), $fields, null);
        $this->assertTrue($created);
        $this->assertFalse($replayed);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, (int) DB::table('coupons')->where('code', 'NHAMINH10')->value('used'));
        $this->assertSame(1, (int) DB::table('branch_slot_locks')->where('branch_id', 1)->where('scheduled_at', $first->scheduled_at)->value('booked'));
        $this->assertSame(1, DB::table('stock_movements')->where('order_id', $first->id)->count());
        $this->assertSame(1, DB::table('order_notifications')->where('order_id', $first->id)->count());
        $cross = $this->branchFields(2, ['key' => $fields['key']]);
        $this->expectException(ValidationException::class);
        Branches::run(2, fn () => $checkout->place($this->branchCart(), $cross, null));
    }

    public function test_branch_coupon_and_enabled_fulfillment_and_payment_are_enforced(): void
    {
        DB::table('coupons')->where('code', 'NHAMINH10')->update(['branch_id' => 1]);
        try {
            $this->branchFields(2, ['coupon' => 'NHAMINH10']);
            $this->fail('Coupon from A used at B.');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey('coupon', $failure->errors());
        }
        $settings = Store::settings();
        $settings['payment_methods'] = ['cod'];
        $settings['fulfillments'] = ['pickup'];
        DB::table('branches')->where('id', 1)->update(['settings' => json_encode($settings)]);
        foreach ([['method' => 'bank'], ['fulfillment' => 'delivery', 'region' => 'quan-1']] as $override) {
            try {
                $this->branchFields(1, $override);
                $this->fail('Unsupported choice accepted.');
            } catch (ValidationException $failure) {
                $this->assertContains(array_key_first($failure->errors()), ['method', 'fulfillment']);
            }
        }
    }

    public function test_cancel_and_reschedule_change_only_the_authoritative_branch_resources(): void
    {
        $checkout = app(Checkout::class);
        $fields = $this->branchFields(2, ['coupon' => 'NHAMINH10']);
        [$order] = Branches::run(2, fn () => $checkout->place($this->branchCart(), $fields, null));
        $admin = User::where('role', 'admin')->firstOrFail();
        $new = now()->addDays(4)->toDateString().' 15:00';
        Branches::run(2, fn () => Orders::reschedule($order->id, $admin, ['previous_slot' => $fields['slot'], 'slot' => $new, 'note' => 'Đổi giờ thử B'], $checkout));
        $this->assertDatabaseHas('branch_slot_locks', ['branch_id' => 2, 'scheduled_at' => $fields['slot'].':00', 'booked' => 0]);
        $this->assertDatabaseHas('branch_slot_locks', ['branch_id' => 2, 'scheduled_at' => $new.':00', 'booked' => 1]);
        Branches::run(2, fn () => Orders::change($order->id, $admin, ['kind' => 'status', 'value' => 'cancelled', 'note' => 'Hủy thử B']));
        $this->assertDatabaseHas('branch_slot_locks', ['branch_id' => 2, 'scheduled_at' => $new.':00', 'booked' => 0]);
        $this->assertDatabaseHas('branch_variants', ['branch_id' => 2, 'variant_id' => $this->branchVariantId, 'stock' => 4]);
        $this->assertDatabaseHas('coupons', ['code' => 'NHAMINH10', 'used' => 0]);
        $this->assertSame(1, DB::table('stock_movements')->where('order_id', $order->id)->where('reason', 'cancel')->where('branch_id', 2)->count());
        try {
            Branches::run(2, fn () => Orders::change($order->id, $admin, ['kind' => 'status', 'value' => 'cancelled', 'note' => 'Hủy lặp']));
            $this->fail('Cancelled order was reprocessed.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('branch_variants', ['branch_id' => 2, 'variant_id' => $this->branchVariantId, 'stock' => 4]);
        }
    }

    public function test_calendar_exception_and_pause_are_branch_specific(): void
    {
        $settings = Store::settings();
        $day = now()->addDays(4)->toDateString();
        $settings['calendar_exceptions'] = [['date' => $day, 'closed' => false, 'slots' => ['12:30'], 'slot_limits' => ['12:30' => 1]]];
        DB::table('branches')->where('id', 1)->update(['settings' => json_encode($settings)]);
        $slots = app(Checkout::class)->availableSlots(Store::settings(), 120);
        $this->assertContains($day.' 12:30', $slots);
        $this->assertNotContains($day.' 09:00', $slots);
        $settings['accepting_orders'] = false;
        DB::table('branches')->where('id', 1)->update(['settings' => json_encode($settings)]);
        $this->assertTrue(Branches::run(2, fn () => Store::settings()['accepting_orders']));
        $this->expectException(ValidationException::class);
        $this->branchFields();
    }
}
