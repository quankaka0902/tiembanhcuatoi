<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Checkout;
use App\Services\OrderTransfers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BranchFixture;
use Tests\TestCase;

class OrderTransfersTest extends TestCase
{
    use BranchFixture, RefreshDatabase;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 27)->setTime(8, 0));
        $this->prepareBranches();
        $this->admin = User::where('role', 'admin')->firstOrFail();
        $this->owner = User::where('role', 'customer')->firstOrFail();
        foreach ([1, 2] as $branch) {
            $this->settings($branch, ['allow_branch_transfers' => true, 'transfer_policy_version' => OrderTransfers::POLICY,
                'transfer_policy_approval_reference' => '[THỬ] Phê duyệt fixture, không mở bán']);
        }
    }

    public function test_closed_policy_and_source_that_is_paid_or_in_production_cannot_be_transferred(): void
    {
        $order = $this->createOrder();
        $state = $this->sourceState($order->id);
        $this->settings(2, ['allow_branch_transfers' => false]);
        $this->invalid(fn () => OrderTransfers::preview($order->id, $this->admin, $this->fields()), 'transfer');
        $this->settings(2, ['allow_branch_transfers' => true]);
        foreach ([['payment' => 'paid'], ['status' => 'preparing', 'production_started_at' => now()->toDateTimeString()]] as $change) {
            DB::table('orders')->where('id', $order->id)->update($change);
            $this->invalid(fn () => OrderTransfers::preview($order->id, $this->admin, $this->fields()), 'transfer');
            DB::table('orders')->where('id', $order->id)->update(['payment' => 'unpaid', 'status' => 'pending', 'production_started_at' => null]);
        }
        $this->assertSame($state, $this->sourceState($order->id));
        $this->assertDatabaseCount('order_transfers', 0);
    }

    public function test_preview_and_proposal_leave_source_reservations_unchanged_until_customer_approval(): void
    {
        $order = $this->createOrder();
        $before = $this->sourceState($order->id);
        $preview = OrderTransfers::preview($order->id, $this->admin, $this->fields());
        $this->assertSame(1, (int) $preview['from']['id']);
        $this->assertSame(2, (int) $preview['to']['id']);
        $this->assertSame(100000, $preview['delta']);
        $this->assertSame(240, $preview['new']['prep_minutes']);
        $proposal = OrderTransfers::propose($order->id, $this->admin, $this->fields() + ['quote_token' => $preview['quote_token'], 'request_key' => (string) Str::uuid()]);
        $this->assertSame('pending', $proposal->status);
        $this->assertSame($before, $this->sourceState($order->id));
        $this->invalid(fn () => OrderTransfers::execute($proposal->id, $this->admin, ['request_key' => (string) Str::uuid()]), 'transfer');
        $this->assertSame($before, $this->sourceState($order->id));
        $this->assertSame(1, DB::table('order_notifications')->where('order_id', $order->id)->where('type', 'transfer_approval')->count());
    }

    public function test_owner_approval_execute_and_replay_transfer_stock_slot_price_and_identity_atomically(): void
    {
        $order = $this->createOrder();
        $sourceStock = (int) DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->branchVariantId)->value('stock');
        $proposal = $this->proposal($order);
        $approval = ['request_key' => (string) Str::uuid(), 'proposal_id' => $proposal->id, 'decision' => 'approved', 'accept_quote' => true];
        $approved = OrderTransfers::approve($approval, owner: $this->owner, code: $order->code);
        $this->assertSame('approved', $approved->status);
        $this->assertSame($approved->id, OrderTransfers::approve($approval, owner: $this->owner, code: $order->code)->id);
        $command = ['request_key' => (string) Str::uuid()];
        $moved = OrderTransfers::execute($proposal->id, $this->admin, $command);
        $this->assertSame(2, (int) $moved->branch_id);
        $this->assertSame($order->tracking_token, $moved->tracking_token);
        $this->assertSame($order->request_key, $moved->request_key);
        $this->assertSame($order->code, $moved->code);
        $this->assertSame(240, (int) $moved->prep_minutes_snapshot);
        $this->assertSame((int) $order->total + 100000, (int) $moved->total);
        $this->assertSame($sourceStock + 1, (int) DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->branchVariantId)->value('stock'));
        $this->assertDatabaseHas('branch_variants', ['branch_id' => 2, 'variant_id' => $this->branchVariantId, 'stock' => 3]);
        $this->assertDatabaseHas('branch_slot_locks', ['branch_id' => 1, 'scheduled_at' => $order->scheduled_at, 'booked' => 0]);
        $this->assertDatabaseHas('branch_slot_locks', ['branch_id' => 2, 'scheduled_at' => $this->fields()['slot'].':00', 'booked' => 1]);
        $this->assertDatabaseHas('order_transfers', ['id' => $proposal->id, 'status' => 'completed', 'open_order_id' => null]);
        $after = $this->sourceState($order->id);
        $this->assertSame($moved->id, OrderTransfers::execute($proposal->id, $this->admin, $command)->id);
        $this->assertSame($after, $this->sourceState($order->id));
        $this->assertSame(1, DB::table('order_notifications')->where('order_id', $order->id)->where('type', 'transfer_completed')->count());
    }

    public function test_guest_receives_short_lived_approval_link_separate_from_view_token(): void
    {
        $order = $this->createOrder(owner: false);
        $proposal = $this->proposal($order);
        $token = $this->guestToken($order->id);
        $this->assertNotSame($order->tracking_token, $token);
        $this->assertSame(hash('sha256', $token), $proposal->approval_token_hash);
        $fields = ['request_key' => (string) Str::uuid(), 'proposal_id' => $proposal->id, 'decision' => 'approved', 'accept_quote' => true];
        $approved = OrderTransfers::approve($fields, token: $token);
        $this->assertSame('approved', $approved->status);
        $this->assertNull($approved->approved_by);
        $this->assertSame(1, (int) DB::table('orders')->where('id', $order->id)->value('branch_id'));
        $this->assertNull(OrderTransfers::customerSummary($order)['approval_url']);
        try {
            OrderTransfers::access($order->tracking_token);
            $this->fail('View token authorized an action.');
        } catch (HttpException $failure) {
            $this->assertSame(410, $failure->getStatusCode());
        }
    }

    public function test_expired_approval_and_expired_execution_keep_source_held(): void
    {
        $order = $this->createOrder(owner: false);
        $proposal = $this->proposal($order);
        $token = $this->guestToken($order->id);
        $before = $this->sourceState($order->id);
        $this->travel(16)->minutes();
        try {
            OrderTransfers::approve(['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'accept_quote' => true], token: $token);
            $this->fail('Expired action link was accepted.');
        } catch (HttpException $failure) {
            $this->assertSame(410, $failure->getStatusCode());
        }
        $this->assertSame($before, $this->sourceState($order->id));
        DB::table('order_transfers')->where('id', $proposal->id)->update(['status' => 'approved']);
        $this->travel(15)->minutes();
        $this->invalid(fn () => OrderTransfers::execute($proposal->id, $this->admin, ['request_key' => (string) Str::uuid()]), 'transfer');
        $this->assertSame($before, $this->sourceState($order->id));
    }

    public function test_customer_rejection_and_manager_cancellation_only_close_proposal(): void
    {
        $order = $this->createOrder();
        $before = $this->sourceState($order->id);
        $proposal = $this->proposal($order);
        OrderTransfers::approve(['request_key' => (string) Str::uuid(), 'decision' => 'rejected'], owner: $this->owner, code: $order->code);
        $this->assertDatabaseHas('order_transfers', ['id' => $proposal->id, 'status' => 'rejected', 'open_order_id' => null]);
        $this->assertSame($before, $this->sourceState($order->id));
        $second = $this->proposal($order);
        $key = ['request_key' => (string) Str::uuid()];
        OrderTransfers::cancel($second->id, $this->admin, $key);
        OrderTransfers::cancel($second->id, $this->admin, $key);
        $this->assertSame($before, $this->sourceState($order->id));
        $this->assertSame(1, DB::table('order_notifications')->where('order_id', $order->id)->where('type', 'transfer_cancelled')->count());
    }

    public function test_target_stock_or_slot_filling_after_approval_does_not_release_source(): void
    {
        $order = $this->createOrder();
        $proposal = $this->approved($order);
        $before = $this->sourceState($order->id);
        DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->update(['stock' => 0]);
        $this->invalid(fn () => OrderTransfers::execute($proposal->id, $this->admin, ['request_key' => (string) Str::uuid()]), 'cart');
        $this->assertSame($before['order'], $this->sourceState($order->id)['order']);
        $this->assertSame($before['source_stock'], $this->sourceState($order->id)['source_stock']);
        $this->assertSame($before['source_booked'], $this->sourceState($order->id)['source_booked']);
        DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->update(['stock' => 4]);
        $this->settings(2, ['slot_limits' => ['15:00' => 1]]);
        DB::table('branch_slot_locks')->where('branch_id', 2)->where('scheduled_at', $this->fields()['slot'].':00')->update(['booked' => 1]);
        $this->invalid(fn () => OrderTransfers::execute($proposal->id, $this->admin, ['request_key' => (string) Str::uuid()]), 'slot');
        $this->assertSame($before['order'], $this->sourceState($order->id)['order']);
        $this->assertSame($before['source_stock'], $this->sourceState($order->id)['source_stock']);
        $this->assertDatabaseHas('order_transfers', ['id' => $proposal->id, 'status' => 'approved']);
    }

    public function test_price_change_and_source_change_after_approval_require_a_new_quote(): void
    {
        $order = $this->createOrder();
        $proposal = $this->approved($order);
        $before = $this->sourceState($order->id);
        DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->increment('price', 10000);
        $this->invalid(fn () => OrderTransfers::execute($proposal->id, $this->admin, ['request_key' => (string) Str::uuid()]), 'quote_token');
        $this->assertSame($before, $this->sourceState($order->id));
        DB::table('orders')->where('id', $order->id)->update(['status' => 'confirmed']);
        $this->invalid(fn () => OrderTransfers::execute($proposal->id, $this->admin, ['request_key' => (string) Str::uuid()]), 'transfer');
        $this->assertSame(1, (int) DB::table('orders')->where('id', $order->id)->value('branch_id'));
    }

    public function test_staff_manager_without_target_access_and_other_owner_are_denied(): void
    {
        $order = $this->createOrder();
        $staff = User::where('role', 'staff')->firstOrFail();
        foreach (['staff', 'manager'] as $role) {
            DB::table('branch_memberships')->where('user_id', $staff->id)->where('branch_id', 1)->update(['role' => $role]);
            try {
                OrderTransfers::preview($order->id, $staff, $this->fields());
                $this->fail('Actor without both-branch manager access was authorized.');
            } catch (HttpException $failure) {
                $this->assertSame(403, $failure->getStatusCode());
            }
        }
        $this->proposal($order);
        $other = User::factory()->create(['role' => 'customer', 'active' => true]);
        try {
            OrderTransfers::approve(['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'accept_quote' => true], owner: $other, code: $order->code);
            $this->fail('Another owner approved this order.');
        } catch (HttpException $failure) {
            $this->assertSame(404, $failure->getStatusCode());
        }
    }

    public function test_owned_last_coupon_use_is_kept_once_and_branch_owned_coupon_is_released_once(): void
    {
        DB::table('coupons')->where('code', 'NHAMINH10')->update(['usage_limit' => 1, 'used' => 0]);
        $order = $this->createOrder(coupon: 'NHAMINH10');
        $proposal = $this->approved($order);
        $moved = OrderTransfers::execute($proposal->id, $this->admin, ['request_key' => (string) Str::uuid()]);
        $this->assertSame($order->coupon_id, $moved->coupon_id);
        $this->assertSame(1, (int) DB::table('coupons')->where('id', $order->coupon_id)->value('used'));
        DB::table('coupons')->where('code', 'NGOT30000')->update(['branch_id' => 1, 'usage_limit' => 1, 'used' => 0]);
        $orderB = $this->createOrder(coupon: 'NGOT30000');
        $proposalB = $this->approved($orderB);
        $command = ['request_key' => (string) Str::uuid()];
        $movedB = OrderTransfers::execute($proposalB->id, $this->admin, $command);
        OrderTransfers::execute($proposalB->id, $this->admin, $command);
        $this->assertNull($movedB->coupon_id);
        $this->assertNotNull($movedB->coupon_released_at);
        $this->assertSame(0, (int) DB::table('coupons')->where('id', $orderB->coupon_id)->value('used'));
    }

    public function test_unpaid_bank_transfer_requires_unchanged_account_and_explicit_reconciliation_evidence(): void
    {
        $order = $this->createOrder(method: 'bank');
        $proposal = $this->approved($order);
        $this->invalid(fn () => OrderTransfers::execute($proposal->id, $this->admin, ['request_key' => (string) Str::uuid()]), 'bank_reference');
        $moved = OrderTransfers::execute($proposal->id, $this->admin, ['request_key' => (string) Str::uuid(), 'bank_checked' => true, 'bank_reference' => 'TEST-UNPAID-001']);
        $this->assertSame('unpaid', $moved->payment);
        $this->assertLessThanOrEqual($order->bank_due_at, $moved->bank_due_at);
        $second = $this->createOrder(method: 'bank');
        $this->settings(2, ['bank_account' => 'TEST-DIFFERENT-ACCOUNT']);
        $this->invalid(fn () => OrderTransfers::preview($second->id, $this->admin, $this->fields()), 'transfer');
    }

    public function test_proposal_replay_is_single_and_altered_approval_or_command_cannot_reuse_a_key(): void
    {
        $order = $this->createOrder();
        $preview = OrderTransfers::preview($order->id, $this->admin, $this->fields());
        $fields = $this->fields() + ['quote_token' => $preview['quote_token'], 'request_key' => (string) Str::uuid()];
        $proposal = OrderTransfers::propose($order->id, $this->admin, $fields);
        $this->assertSame($proposal->id, OrderTransfers::propose($order->id, $this->admin, $fields)->id);
        $this->assertDatabaseCount('order_transfers', 1);
        foreach ([['note' => '[THỬ] Nội dung khác'], ['target_branch_id' => 1], ['quote_token' => 'invalid-new-token']] as $change) {
            try {
                OrderTransfers::propose($order->id, $this->admin, array_replace($fields, $change));
                $this->fail('Altered proposal payload reused the request key.');
            } catch (HttpException $failure) {
                $this->assertSame(409, $failure->getStatusCode());
            }
        }
        $this->invalid(fn () => OrderTransfers::approve(['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'accept_quote' => false], owner: $this->owner, code: $order->code), 'accept_quote');
        $this->approveOwner($order, $proposal);
        $command = ['request_key' => (string) Str::uuid()];
        OrderTransfers::execute($proposal->id, $this->admin, $command);
        $this->invalid(fn () => OrderTransfers::execute($proposal->id, $this->admin, $command + ['bank_reference' => 'DIFFERENT']), 'request_key');
    }

    private function createOrder(string $method = 'cod', string $coupon = '', bool $owner = true): object
    {
        $fields = $this->branchFields(1, ['method' => $method, 'coupon' => $coupon, 'slot' => now()->addDays(3)->format('Y-m-d').' 14:00']);

        return app(Checkout::class)->place($this->branchCart(), $fields, $owner ? $this->owner->id : null)[0];
    }

    private function fields(): array
    {
        return ['target_branch_id' => 2, 'slot' => now()->addDays(4)->format('Y-m-d').' 15:00', 'note' => '[THỬ] Chuyển cơ sở theo đề nghị khách'];
    }

    private function proposal(object $order): object
    {
        $preview = OrderTransfers::preview($order->id, $this->admin, $this->fields());

        return OrderTransfers::propose($order->id, $this->admin, $this->fields() + ['request_key' => (string) Str::uuid(), 'quote_token' => $preview['quote_token']]);
    }

    private function approved(object $order): object
    {
        $proposal = $this->proposal($order);
        $this->approveOwner($order, $proposal);

        return $proposal;
    }

    private function approveOwner(object $order, object $proposal): void
    {
        OrderTransfers::approve(['request_key' => (string) Str::uuid(), 'proposal_id' => $proposal->id, 'decision' => 'approved', 'accept_quote' => true], owner: $this->owner, code: $order->code);
    }

    private function guestToken(int $orderId): string
    {
        $body = Crypt::decryptString(DB::table('order_notifications')->where('order_id', $orderId)->where('type', 'transfer_approval')->value('encrypted_body'));
        $this->assertSame(1, preg_match('~/xac-nhan-chuyen-don/([a-f0-9]{64})~', $body, $match));

        return $match[1];
    }

    private function sourceState(int $orderId): array
    {
        $order = DB::table('orders')->find($orderId);

        return ['order' => (array) $order,
            'source_stock' => DB::table('branch_variants')->where('branch_id', 1)->orderBy('variant_id')->pluck('stock', 'variant_id')->all(),
            'source_booked' => DB::table('branch_slot_locks')->where('branch_id', 1)->orderBy('scheduled_at')->pluck('booked', 'scheduled_at')->all(),
            'movement_count' => DB::table('stock_movements')->where('order_id', $orderId)->count(),
            'item_hash' => hash('sha256', DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get()->toJson())];
    }

    private function settings(int $branch, array $updates): void
    {
        $settings = json_decode(DB::table('branches')->where('id', $branch)->value('settings'), true);
        DB::table('branches')->where('id', $branch)->update(['settings' => json_encode(array_replace($settings, $updates), JSON_THROW_ON_ERROR)]);
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey($field, $failure->errors());

            return;
        }
        $this->fail('Expected invalid '.$field.' with all source reservations retained.');
    }
}
