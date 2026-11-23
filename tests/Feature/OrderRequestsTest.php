<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OrderRequests;
use App\Services\Orders;
use App\Services\Store;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(8, 0));
        config(['bakery.demo_seed' => true, 'inertia.testing.ensure_pages_exist' => false]);
        $this->seed();
        User::whereIn('role', ['admin', 'staff'])->each(fn (User $user) => $user->forceFill([
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
        ])->save());
        DB::table('orders')->where('code', 'NB-DEMO001')->update([
            'scheduled_at' => '2026-11-25 11:00:00', 'prep_minutes_snapshot' => 60,
        ]);
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->withSession(['staff_mfa_verified' => $user instanceof User ? $user->staffProof() : null]);

        return parent::actingAs($user, $guard);
    }

    public function test_owner_request_is_idempotent_and_stays_available_while_intake_is_paused(): void
    {
        $order = $this->order();
        $fields = $this->fields($order);
        $url = '/don-cua-toi/'.$order->code.'/yeu-cau';
        $settings = array_replace(Store::settings(), ['accepting_orders' => false]);
        DB::table('store_settings')->update(['data' => json_encode($settings)]);
        $stock = DB::table('variants')->where('id', 1)->value('stock');
        $this->post($url, $fields)->assertRedirect('/dang-nhap');
        $this->actingAs(User::factory()->create())->post($url, $fields)->assertNotFound();
        $this->actingAs($this->customer())->post($url, $fields)->assertSessionHasNoErrors();
        $this->post($url, $fields)->assertSessionHasNoErrors();
        $this->post($url, array_replace($fields, ['reason' => 'Nội dung khác']))->assertSessionHasErrors('request_key');
        $this->assertDatabaseCount('order_change_requests', 1);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending', 'scheduled_at' => $order->scheduled_at]);
        $this->assertSame($stock, DB::table('variants')->where('id', 1)->value('stock'));
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('kind', 'request')->count());
        $this->assertSame(1, DB::table('order_notifications')->where('order_id', $order->id)->where('type', 'request_created')->count());
        $this->get('/don-cua-toi/'.$order->code)->assertInertia(fn (Assert $page) => $page
            ->where('changeRequests.0.reason', $fields['reason'])->where('changeRequest.canRequest', false));
        $this->post('/dang-xuat');
        $this->get('/tra-cuu/'.$order->tracking_token)->assertInertia(fn (Assert $page) => $page
            ->where('changeRequests.0.status', 'pending')->missing('changeRequests.0.reason')->where('changeRequest.canRequest', false));
    }

    public function test_tracking_is_view_only_and_guest_must_use_separate_short_lived_link(): void
    {
        $order = $this->order();
        $fields = $this->fields($order);
        $tracking = '/tra-cuu/'.$order->tracking_token;
        $endpoint = $tracking.'/lien-ket-thao-tac';
        $this->post($tracking, $fields)->assertStatus(405);
        $this->post($endpoint, ['email' => 'wrong@example.test'])->assertRedirect();
        $this->assertDatabaseCount('order_action_links', 0);
        $this->post($endpoint, ['email' => strtoupper($order->email)])->assertRedirect();
        $notice = DB::table('order_notifications')->where('order_id', $order->id)->where('type', 'action_link')->first();
        $this->assertSame($order->email, $notice->recipient);
        $token = $this->tokenFromNotice($notice);
        $this->assertDatabaseHas('order_action_links', ['token_hash' => hash('sha256', $token), 'used_at' => null]);
        $this->assertStringNotContainsString($token, json_encode(DB::table('order_action_links')->first()));
        $this->assertStringNotContainsString($token, DB::table('jobs')->latest('id')->value('payload'));
        $this->get('/thao-tac-don/'.$token)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('OrderAction')->where('expired', false)->where('changeRequest.canRequest', true));
        $this->assertDatabaseHas('order_action_links', ['token_hash' => hash('sha256', $token), 'used_at' => null]);
        $this->post('/thao-tac-don/'.$token, $fields)->assertRedirect(Orders::link($order));
        $this->post('/thao-tac-don/'.$token, $fields)->assertRedirect(Orders::link($order));
        $this->post('/thao-tac-don/'.$token, array_replace($fields, ['request_key' => (string) Str::uuid()]))->assertSessionHasErrors('request_key');
        $this->assertDatabaseCount('order_change_requests', 1);
        $this->get('/thao-tac-don/'.$token)->assertStatus(410)->assertInertia(fn (Assert $page) => $page->where('expired', true)->where('order', null));
        $this->get($tracking)->assertOk();
    }

    public function test_guest_link_expiry_and_reissue_do_not_change_the_order(): void
    {
        $order = $this->order();
        OrderRequests::requestLink($order->tracking_token, $order->email);
        $notice = DB::table('order_notifications')->where('type', 'action_link')->latest('id')->first();
        $token = $this->tokenFromNotice($notice);
        OrderRequests::requestLink($order->tracking_token, $order->email);
        $this->assertDatabaseCount('order_action_links', 1);
        $this->travel(15)->minutes();
        $this->get('/thao-tac-don/'.$token)->assertStatus(410);
        $this->post('/thao-tac-don/'.$token, $this->fields($order))->assertStatus(410)
            ->assertInertia(fn (Assert $page) => $page->component('OrderAction')->where('expired', true)->where('order', null));
        $this->assertDatabaseCount('order_change_requests', 0);
        OrderRequests::requestLink($order->tracking_token, $order->email);
        $this->assertDatabaseCount('order_action_links', 2);
        $new = DB::table('order_notifications')->where('type', 'action_link')->latest('id')->first();
        $this->assertNotSame($token, $this->tokenFromNotice($new));
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending', 'scheduled_at' => $order->scheduled_at]);
    }

    public function test_staff_decision_moves_slot_once_and_conflicts_leave_the_old_time(): void
    {
        $order = $this->order();
        $target = '2026-11-25 16:00';
        $change = OrderRequests::submitOwner($order->id, $this->customer(), $this->fields($order, [
            'kind' => 'reschedule', 'requested_slot' => $target,
        ]));
        $settings = array_replace(Store::settings(), ['slot_limits' => ['16:00' => 1]]);
        DB::table('store_settings')->update(['data' => json_encode($settings)]);
        $blocker = DB::table('orders')->where('code', 'NB-DEMO002')->first();
        DB::table('orders')->where('id', $blocker->id)->update(['scheduled_at' => $target.':00']);
        $decision = ['decision' => 'approved', 'note' => 'Đã thống nhất giờ mới với khách.'];
        $staff = User::where('email', 'nhanvien@tiembanh.test')->first();
        try {
            OrderRequests::resolve($change->id, $staff, $decision);
            $this->fail('A full slot must not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slot', $exception->errors());
        }
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'scheduled_at' => $order->scheduled_at]);
        $this->assertDatabaseHas('order_change_requests', ['id' => $change->id, 'status' => 'pending']);
        DB::table('orders')->where('id', $blocker->id)->update(['scheduled_at' => $blocker->scheduled_at]);
        $result = OrderRequests::resolve($change->id, $staff, $decision);
        $this->assertSame('approved', $result->status);
        OrderRequests::resolve($change->id, $staff, $decision);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'scheduled_at' => $target.':00']);
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('kind', 'schedule')->count());
        $this->assertSame(1, DB::table('order_notifications')->where('order_id', $order->id)->where('type', 'request_approved')->count());
    }

    public function test_stale_request_and_late_request_cannot_be_approved_even_by_admin(): void
    {
        $order = $this->order();
        $change = OrderRequests::submitOwner($order->id, $this->customer(), $this->fields($order));
        $admin = User::where('email', 'admin@tiembanh.test')->first();
        DB::table('orders')->where('id', $order->id)->update(['scheduled_at' => '2026-11-25 14:00:00']);
        $decision = ['decision' => 'approved', 'note' => 'Duyệt yêu cầu.'];
        $this->actingAs($admin)->patch('/quan-tri/yeu-cau-don/'.$change->id, $decision)->assertSessionHasErrors('decision');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending', 'scheduled_at' => '2026-11-25 14:00:00']);
        $this->assertDatabaseHas('order_change_requests', ['id' => $change->id, 'status' => 'pending']);
        $this->patch('/quan-tri/yeu-cau-don/'.$change->id, ['decision' => 'rejected', 'note' => 'Giờ cũ đã thay đổi, vui lòng liên hệ tiệm.'])->assertRedirect();
        $this->assertDatabaseHas('order_change_requests', ['id' => $change->id, 'status' => 'rejected', 'open_order_id' => null]);
        $order = $this->order();
        $change = OrderRequests::submitOwner($order->id, $this->customer(), $this->fields($order));
        $this->travelTo('2026-11-25 13:00:00');
        $this->patch('/quan-tri/yeu-cau-don/'.$change->id, $decision)->assertSessionHasErrors('decision');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
    }

    public function test_non_staff_cannot_process_requests_and_preparation_closes_customer_requests(): void
    {
        $order = $this->order();
        $change = OrderRequests::submitOwner($order->id, $this->customer(), $this->fields($order));
        $this->actingAs($this->customer())->patch('/quan-tri/yeu-cau-don/'.$change->id, [
            'decision' => 'approved', 'note' => 'Tự duyệt.',
        ])->assertForbidden();
        DB::table('orders')->where('id', $order->id)->update(['status' => 'preparing']);
        $this->post('/don-cua-toi/'.$order->code.'/yeu-cau', $this->fields($order))->assertSessionHasErrors('previous_slot');
        $this->assertDatabaseHas('order_change_requests', ['id' => $change->id, 'status' => 'pending']);
        $this->assertSame(0, DB::table('order_events')->where('order_id', $order->id)->where('value', 'cancelled')->count());
    }

    public function test_unknown_legacy_lead_time_requires_review_and_current_menu_cannot_change_snapshot_cutoff(): void
    {
        $order = $this->order();
        DB::table('products')->update(['prep_minutes' => 10080]);
        $this->travelTo('2026-11-25 09:59:59');
        $this->assertTrue(Orders::canCustomerRequest($order));
        DB::table('orders')->where('id', $order->id)->update(['prep_minutes_snapshot' => null]);
        $legacy = $this->order();
        $this->assertFalse(Orders::canCustomerRequest($legacy));
        $this->assertFalse(OrderRequests::customerData($legacy, $this->customer())['changeRequest']['canRequest']);
        $this->actingAs($this->customer())->post('/don-cua-toi/'.$legacy->code.'/yeu-cau', $this->fields($legacy))->assertSessionHasErrors('previous_slot');
    }

    private function order(): object
    {
        return DB::table('orders')->where('code', 'NB-DEMO001')->first();
    }

    private function customer(): User
    {
        return User::where('email', 'khach@tiembanh.test')->first();
    }

    private function fields(object $order, array $overrides = []): array
    {
        return array_replace([
            'request_key' => (string) Str::uuid(), 'kind' => 'cancel', 'previous_slot' => substr($order->scheduled_at, 0, 16),
            'requested_slot' => null, 'reason' => 'Khách không thể nhận bánh như dự kiến.',
        ], $overrides);
    }

    private function tokenFromNotice(object $notice): string
    {
        preg_match('~/thao-tac-don/([a-f0-9]{64})~', Crypt::decryptString($notice->encrypted_body), $matches);
        $this->assertArrayHasKey(1, $matches);

        return $matches[1];
    }
}
