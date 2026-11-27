<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Branches;
use App\Services\Fulfillments;
use App\Services\Orders;
use App\Services\OrderTransfers;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\BranchFixture;
use Tests\TestCase;

class DispatchTest extends TestCase
{
    use BranchFixture, RefreshDatabase;

    private User $staff;

    private User $courier;

    private array $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 27)->setTime(8, 0));
        $this->prepareBranches();
        $this->staff = User::where('email', 'nhanvien@tiembanh.test')->firstOrFail();
        $this->staff->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'])->save();
        $this->courier = $this->driver(1);
        $this->orders = DB::table('orders')->orderBy('id')->limit(3)->pluck('id')->all();
        DB::table('orders')->whereIn('id', $this->orders)->update(['branch_id' => 1, 'fulfillment' => 'delivery', 'status' => 'preparing',
            'method' => 'cod', 'payment' => 'unpaid', 'phone' => '0900123456', 'address' => '[THỬ] Địa chỉ chỉ giao tài xế',
            'email' => 'dispatch-private@example.test', 'prep_minutes_snapshot' => 60]);
        DB::table('orders')->where('id', $this->orders[2])->update(['branch_id' => 2]);
        foreach ($this->orders as $id) {
            $order = DB::table('orders')->find($id);
            Branches::run((int) $order->branch_id, fn () => DB::transaction(fn () => Fulfillments::createForOrder($order)));
        }
    }

    private function driver(int $branch): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'staff', 'active' => true, 'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'])->save();
        DB::table('branch_memberships')->insert(['branch_id' => $branch, 'user_id' => $user->id, 'role' => 'courier',
            'active' => true, 'can_reconcile' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    private function job(int $index = 0): object
    {
        return DB::table('fulfillment_jobs')->where('order_id', $this->orders[$index])->first();
    }

    private function act(string $action, ?User $actor = null, array $extra = [], int $index = 0): object
    {
        $job = $this->job($index);

        return Fulfillments::change($job->id, $actor ?? $this->staff, array_replace([
            'action' => $action, 'expected_version' => $job->version, 'request_key' => (string) Str::uuid(),
        ], $extra));
    }

    private function assign(array $indices = [0], ?string $key = null): object
    {
        return Fulfillments::assignTrip($this->staff, ['order_ids' => array_map(fn ($index) => $this->orders[$index], $indices),
            'courier_id' => $this->courier->id, 'request_key' => $key ?? (string) Str::uuid()]);
    }

    private function invalid(callable $call, string $field): void
    {
        try {
            $call();
            $this->fail('Expected validation failure: '.$field);
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey($field, $failure->errors());
        }
    }

    private function denied(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('Expected access denial.');
        } catch (HttpExceptionInterface $failure) {
            $this->assertSame($status, $failure->getStatusCode());
        }
    }

    public function test_trip_is_branch_scoped_and_identical_assignment_is_idempotent(): void
    {
        $key = (string) Str::uuid();
        $trip = $this->assign([0, 1], $key);
        $same = $this->assign([0, 1], $key);
        $this->assertSame($trip->id, $same->id);
        $this->assertDatabaseCount('delivery_trips', 1);
        $this->assertDatabaseCount('fulfillment_events', 2);
        $this->assertSame($this->courier->id, $this->job()->courier_id);
        $this->assertSame($this->courier->id, $this->job(1)->courier_id);
        $this->assertSame(2, DB::table('order_notifications')->where('type', 'dispatch_assigned')->count());
        $this->denied(fn () => $this->assign([2]), 404);
        $this->assertDatabaseCount('delivery_trips', 1);
        $this->assertNull($this->job(2)->trip_id);
    }

    public function test_delivery_confirmation_is_physical_evidence_and_does_not_mark_cod_paid(): void
    {
        $stock = DB::table('branch_variants')->where('branch_id', 1)->pluck('stock', 'variant_id')->all();
        $this->assign();
        $this->act('ready');
        $this->act('start', $this->courier);
        $this->assertDatabaseHas('orders', ['id' => $this->orders[0], 'status' => 'shipping', 'payment' => 'unpaid']);
        $this->invalid(fn () => $this->act('delivered', $this->courier, ['reference' => 'NHAN-123', 'verified' => false]), 'reference');
        $job = $this->act('delivered', $this->courier, ['reference' => 'NHAN-123', 'verified' => true]);
        $this->assertSame('delivered', $job->status);
        $this->assertDatabaseHas('orders', ['id' => $this->orders[0], 'status' => 'shipping', 'payment' => 'unpaid']);
        $attempt = DB::table('fulfillment_attempts')->where('job_id', $job->id)->sole();
        $this->assertStringNotContainsString('NHAN-123', $attempt->evidence);
        $this->assertSame('NHAN-123', json_decode(Crypt::decryptString($attempt->evidence), true)['reference']);
        Orders::change($this->orders[0], $this->staff, ['kind' => 'payment', 'value' => 'paid']);
        Orders::change($this->orders[0], $this->staff, ['kind' => 'status', 'value' => 'completed']);
        $this->assertSame($stock, DB::table('branch_variants')->where('branch_id', 1)->pluck('stock', 'variant_id')->all());
    }

    public function test_unpaid_prepay_bank_order_cannot_start_and_courier_cannot_reconcile(): void
    {
        DB::table('orders')->where('id', $this->orders[0])->update(['method' => 'bank', 'bank_prepay_required' => true, 'prepayment_waived_at' => null]);
        $this->assign();
        $this->act('ready');
        $this->invalid(fn () => $this->act('start', $this->courier), 'action');
        $this->assertSame('ready', $this->job()->status);
        $this->assertSame(0, $this->job()->attempt_count);
        $this->assertFalse(Branches::can($this->courier, 'reconcile'));
        $this->denied(fn () => Orders::change($this->orders[0], $this->courier, ['kind' => 'payment', 'value' => 'paid']), 403);
        Orders::change($this->orders[0], User::where('role', 'admin')->first(), ['kind' => 'payment', 'value' => 'paid']);
        $this->assertSame('in_transit', $this->act('start', $this->courier)->status);
    }

    public function test_cancelling_dispatch_and_retrying_never_cancels_order_or_restores_stock(): void
    {
        $stock = DB::table('branch_variants')->pluck('stock', 'id')->all();
        $this->assign();
        $this->act('ready');
        $this->act('start', $this->courier);
        $this->act('cancel', extra: ['reason' => 'Ca thử hủy chuyến khi đang giao']);
        $this->assertDatabaseHas('orders', ['id' => $this->orders[0], 'status' => 'delivery_failed', 'stock_restored_at' => null]);
        $this->assertSame('cancelled', $this->job()->status);
        $this->act('retry', extra: ['reason' => 'Đã thống nhất giao lại với khách']);
        $this->assertNull($this->job()->trip_id);
        $this->assertNull($this->job()->courier_id);
        $this->assign();
        $this->act('ready');
        $this->act('start', $this->courier);
        $this->act('delivered', $this->courier, ['reference' => 'LAN-HAI-123', 'verified' => true]);
        $this->assertSame([1, 2], DB::table('fulfillment_attempts')->where('job_id', $this->job()->id)->orderBy('sequence')->pluck('sequence')->all());
        $this->assertSame(['cancelled', 'delivered'], DB::table('fulfillment_attempts')->where('job_id', $this->job()->id)->orderBy('sequence')->pluck('outcome')->all());
        $this->assertSame($stock, DB::table('branch_variants')->pluck('stock', 'id')->all());
        $this->assertDatabaseCount('delivery_trips', 2);
        $this->assertSame([$this->orders[0]], json_decode(DB::table('delivery_trips')->orderBy('id')->first()->order_ids, true));
    }

    public function test_stale_version_and_repeated_completion_do_not_create_duplicate_attempts(): void
    {
        $this->assign();
        $this->act('ready');
        $this->invalid(fn () => $this->act('start', $this->courier, ['expected_version' => 1]), 'expected_version');
        $this->act('start', $this->courier);
        $fields = ['action' => 'delivered', 'expected_version' => $this->job()->version, 'request_key' => (string) Str::uuid(), 'reference' => 'PHIEU-123', 'verified' => true];
        Fulfillments::change($this->job()->id, $this->courier, $fields);
        Fulfillments::change($this->job()->id, $this->courier, $fields);
        $this->assertDatabaseCount('fulfillment_attempts', 1);
        $this->assertSame(1, DB::table('order_notifications')->where('type', 'dispatch_delivered')->count());
        $this->invalid(fn () => Fulfillments::change($this->job()->id, $this->courier, array_replace($fields, ['reference' => 'KHAC-123'])), 'request_key');
    }

    public function test_driver_has_only_assigned_minimum_data_and_terminal_contacts_are_redacted(): void
    {
        $this->assign();
        $data = Fulfillments::indexData($this->courier, []);
        $rows = $data['jobs']->items();
        $this->assertCount(1, $rows);
        $this->assertSame('0900123456', $rows[0]['contact']['phone']);
        $encoded = json_encode($data);
        foreach (['dispatch-private@example.test', 'bank_snapshot', 'coupon_code', 'accessories_snapshot', 'order_items', 'Chúc mừng'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->denied(fn () => $this->act('ready', $this->courier, index: 1), 404);
        $this->denied(fn () => $this->act('ready', $this->courier, index: 2), 404);
        $this->act('cancel', extra: ['reason' => 'Ca thử đóng công việc']);
        $row = Fulfillments::indexData($this->courier, [])['jobs']->items()[0];
        $this->assertArrayNotHasKey('phone', $row['contact']);
        $this->assertArrayNotHasKey('address', $row['contact']);
        $this->assertSame(0, $row['cod_amount']);
        $this->assertSame([], $row['actions']);
    }

    public function test_pickup_requires_verified_handover_before_order_completion(): void
    {
        DB::table('orders')->where('id', $this->orders[0])->update(['fulfillment' => 'pickup']);
        DB::table('fulfillment_jobs')->where('order_id', $this->orders[0])->update(['kind' => 'pickup']);
        $this->act('ready');
        $this->assertDatabaseHas('orders', ['id' => $this->orders[0], 'status' => 'ready']);
        $this->invalid(fn () => Orders::change($this->orders[0], $this->staff, ['kind' => 'status', 'value' => 'completed']), 'value');
        $this->invalid(fn () => $this->act('collect', extra: ['verified' => true]), 'reference');
        $this->act('collect', extra: ['reference' => 'NHAN-TAI-TIEM-123', 'verified' => true]);
        $this->assertDatabaseHas('orders', ['id' => $this->orders[0], 'status' => 'ready', 'payment' => 'unpaid']);
        Orders::change($this->orders[0], $this->staff, ['kind' => 'payment', 'value' => 'paid']);
        Orders::change($this->orders[0], $this->staff, ['kind' => 'status', 'value' => 'completed']);
        $this->assertSame('collected', $this->job()->status);
    }

    public function test_order_shortcuts_and_revoked_driver_membership_are_rejected(): void
    {
        $this->invalid(fn () => Orders::change($this->orders[0], $this->staff, ['kind' => 'status', 'value' => 'shipping']), 'value');
        $this->assign();
        $this->act('ready');
        DB::table('branch_memberships')->where('user_id', $this->courier->id)->update(['active' => false]);
        $this->denied(fn () => $this->act('start', $this->courier), 403);
        $this->denied(fn () => $this->act('start'), 403);
        $this->assertSame('ready', $this->job()->status);
    }

    public function test_transfer_rejects_assigned_job_and_public_tracking_has_no_delivery_private_data(): void
    {
        $order = DB::table('orders')->find($this->orders[0]);
        DB::transaction(fn () => Fulfillments::assertTransferable($order));
        $this->assign();
        $this->invalid(fn () => DB::transaction(fn () => Fulfillments::assertTransferable($order)), 'order');
        $data = json_encode(Fulfillments::publicData($order));
        foreach (['0900123456', 'Địa chỉ chỉ giao', 'dispatch-private', 'actor_id', 'courier_id', 'reference'] as $private) {
            $this->assertStringNotContainsString($private, $data);
        }
    }

    public function test_driver_login_lands_on_dispatch_and_http_routes_keep_the_minimum_scope(): void
    {
        $this->travelTo(now()->setTimestamp(59));
        $this->courier->forceFill(['password' => 'DriverLocalTest2026!'])->save();
        $this->post('/dang-nhap', ['email' => $this->courier->email, 'password' => 'DriverLocalTest2026!'])->assertRedirect('/xac-thuc-hai-buoc');
        $this->post('/xac-thuc-hai-buoc', ['code' => '287082'])->assertRedirect('/quan-tri/giao-nhan');
        $this->assign();
        $this->get('/quan-tri/giao-nhan')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('courierOnly', true)->where('jobs.total', 1)->where('eligibleOrders', [])->where('drivers', [])
            ->missing('jobs.data.0.email')->missing('jobs.data.0.bank_snapshot')->missing('jobs.data.0.items'));
        $this->get('/quan-tri/bao-cao')->assertForbidden();
        $this->get('/quan-tri/don-hang/'.$this->orders[0])->assertForbidden();
        $this->patch('/quan-tri/giao-nhan/cong-viec/'.$this->job(2)->id, ['action' => 'cancel', 'request_key' => (string) Str::uuid(),
            'expected_version' => 1, 'reason' => 'Không được chạm công việc cơ sở B'])->assertNotFound();
        $this->post('/quan-tri/chon-co-so', ['branch_id' => 1])->assertRedirect('/quan-tri/giao-nhan');
    }

    public function test_fake_adapter_is_local_only_and_never_approves_a_production_trip(): void
    {
        config(['bakery.dispatch_adapter' => 'fake']);
        $trip = $this->assign();
        $this->assertSame('fake', $trip->adapter);
        $this->assertStringStartsWith('SANDBOX-', $trip->adapter_reference);
        $this->app['env'] = 'production';
        try {
            $this->invalid(fn () => $this->assign([1]), 'adapter');
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertDatabaseCount('delivery_trips', 1);
        $this->assertNull($this->job(1)->trip_id);
    }

    public function test_legacy_review_is_explicit_and_does_not_invent_attempts_or_historical_timestamps(): void
    {
        DB::table('fulfillment_jobs')->where('id', $this->job()->id)->delete();
        DB::table('orders')->where('id', $this->orders[0])->update(['status' => 'shipping']);
        $key = (string) Str::uuid();
        $job = Fulfillments::ensure($this->orders[0], $this->staff, ['request_key' => $key]);
        $this->assertSame($job->id, Fulfillments::ensure($this->orders[0], $this->staff, ['request_key' => $key])->id);
        $this->assertTrue((bool) $job->legacy_inferred);
        $this->assertSame('in_transit', $job->status);
        $this->assertNull($job->started_at);
        $this->assertDatabaseCount('fulfillment_attempts', 0);
        $this->assign();
        $this->act('delivered', $this->courier, ['reference' => 'XAC-NHAN-MOI', 'verified' => true]);
        $attempt = DB::table('fulfillment_attempts')->sole();
        $this->assertNull($attempt->started_at);
        $this->assertNotNull($attempt->finished_at);
        $this->assertSame(1, $this->job()->attempt_count);
    }

    public function test_transferring_unpaid_bank_order_keeps_the_stricter_prepay_rule_and_clears_the_source_exception(): void
    {
        foreach ([1, 2] as $branchId) {
            $settings = Branches::run($branchId, fn () => Store::settings());
            $settings = array_replace($settings, ['allow_branch_transfers' => true, 'transfer_policy_version' => OrderTransfers::POLICY,
                'transfer_policy_approval_reference' => '[THỬ] Chính sách chuyển đơn trong test', 'bank_prepay_required' => $branchId === 1,
                'allow_payment_override' => $branchId === 1]);
            DB::table('branches')->where('id', $branchId)->update(['settings' => json_encode($settings)]);
        }
        $order = DB::table('orders')->find($this->orders[0]);
        DB::table('orders')->where('id', $order->id)->update(['fulfillment' => 'pickup', 'status' => 'pending', 'method' => 'bank',
            'bank_prepay_required' => true, 'bank_snapshot' => json_encode(Store::bank(Store::settings())),
            'production_started_at' => null, 'dispatched_at' => null, 'prepayment_waived_at' => null,
            'coupon_id' => null, 'coupon_code' => null, 'discount' => 0, 'delivery_fee' => 0, 'total' => $order->subtotal]);
        DB::table('fulfillment_jobs')->where('order_id', $order->id)->update(['kind' => 'pickup']);
        $admin = User::where('role', 'admin')->firstOrFail();
        Orders::change($order->id, $admin, ['kind' => 'prepay_exception', 'value' => 'granted', 'note' => 'Ngoại lệ được cơ sở nguồn cho phép']);
        $this->assertNotNull(DB::table('orders')->where('id', $order->id)->value('prepayment_waived_at'));
        $fields = ['target_branch_id' => 2, 'slot' => now()->addDays(4)->toDateString().' 15:00'];
        $quote = OrderTransfers::preview($order->id, $admin, $fields);
        $proposal = OrderTransfers::propose($order->id, $admin, $fields + ['quote_token' => $quote['quote_token'],
            'request_key' => (string) Str::uuid(), 'note' => 'Ca thử phạm vi ngoại lệ thanh toán']);
        OrderTransfers::approve(['proposal_id' => $proposal->id, 'decision' => 'approved', 'accept_quote' => true,
            'request_key' => (string) Str::uuid()], owner: User::findOrFail($order->user_id), code: $order->code);
        $moved = OrderTransfers::execute($proposal->id, $admin, ['request_key' => (string) Str::uuid(),
            'note' => 'Đã đối chiếu chưa thu tiền', 'bank_checked' => true, 'bank_reference' => 'LOCAL-UNPAID-123']);
        $this->assertSame(2, $moved->branch_id);
        $this->assertTrue((bool) $moved->bank_prepay_required);
        $this->assertNull($moved->prepayment_waived_at);
        Branches::run(2, function () use ($moved, $admin) {
            Orders::change($moved->id, $admin, ['kind' => 'status', 'value' => 'confirmed']);
            $this->invalid(fn () => Orders::change($moved->id, $admin, ['kind' => 'status', 'value' => 'preparing']), 'value');
        });
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'branch_id' => 2, 'status' => 'confirmed', 'payment' => 'unpaid']);
    }

    public function test_managed_milestones_do_not_send_duplicate_or_contradictory_customer_notices(): void
    {
        $this->assign();
        $this->act('ready');
        $this->act('start', $this->courier);
        $this->act('failed', $this->courier, ['reason' => 'Khách xin hẹn lại giờ nhận']);
        foreach (['dispatch_started', 'dispatch_failed'] as $type) {
            $this->assertSame(1, DB::table('order_notifications')->where('order_id', $this->orders[0])->where('type', $type)->count());
        }
        $this->assertSame(0, DB::table('order_notifications')->where('order_id', $this->orders[0])->whereIn('type', ['shipping', 'delivery_failed'])->count());
        Orders::change($this->orders[1], User::where('role', 'admin')->firstOrFail(), [
            'kind' => 'status', 'value' => 'cancelled', 'note' => 'Hủy đơn theo chính sách, không phải hủy chuyến riêng',
        ]);
        $this->assertSame('cancelled', $this->job(1)->status);
        $this->assertSame(1, DB::table('order_notifications')->where('order_id', $this->orders[1])->where('type', 'cancelled')->count());
        $this->assertSame(0, DB::table('order_notifications')->where('order_id', $this->orders[1])->where('type', 'dispatch_cancelled')->count());
        $this->assertSame(1, DB::table('fulfillment_events')->where('job_id', $this->job(1)->id)->where('action', 'cancel')->count());
    }

    public function test_pickup_ready_emits_one_ready_notice_for_a_controlled_order(): void
    {
        DB::table('orders')->where('id', $this->orders[0])->update(['fulfillment' => 'pickup']);
        DB::table('fulfillment_jobs')->where('order_id', $this->orders[0])->update(['kind' => 'pickup']);
        $this->act('ready');
        $types = DB::table('order_notifications')->where('order_id', $this->orders[0])->pluck('type')->all();
        $this->assertSame(['pickup_ready'], $types);
    }
}
