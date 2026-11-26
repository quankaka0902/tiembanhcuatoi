<?php

namespace Tests\Feature;

use App\Jobs\SendOrderEmail;
use App\Models\User;
use App\Services\Branches;
use App\Services\LaunchReadiness;
use App\Services\OrderNotices;
use App\Services\OrderRequests;
use App\Services\Orders;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class BranchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private object $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 26)->setTime(8, 0));
        config(['bakery.demo_seed' => true, 'inertia.testing.ensure_pages_exist' => false]);
        $this->seed();
        $settings = Store::settings();
        DB::table('chain_settings')->where('id', 1)->update(['data' => json_encode($settings)]);
        DB::table('branches')->where('id', 1)->update(['settings' => json_encode($settings)]);
        DB::table('branches')->insert(['id' => 2, 'code' => 'TEST-B', 'slug' => 'co-so-thu-b', 'name' => '[THỬ] Cơ sở B',
            'timezone' => 'Asia/Ho_Chi_Minh', 'active' => true, 'settings' => json_encode($settings), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->update(['branch_id' => 1]);
        DB::table('orders')->where('code', 'NB-DEMO002')->update(['branch_id' => 2]);
        DB::table('orders')->whereIn('code', ['NB-DEMO001', 'NB-DEMO002'])->update([
            'scheduled_at' => '2026-11-28 11:00:00', 'prep_minutes_snapshot' => 60,
        ]);
        DB::table('branch_variants')->delete();
        foreach (DB::table('variants')->get() as $variant) {
            foreach ([1, 2] as $branch) {
                DB::table('branch_variants')->insert(['branch_id' => $branch, 'variant_id' => $variant->id,
                    'stock' => $variant->stock, 'active' => $variant->active, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('branch_migration_state')->where('id', 1)->update(['mode' => 'branch']);
        User::whereIn('role', ['admin', 'staff'])->each(fn (User $user) => $user->forceFill([
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
        ])->save());
        Branches::use(1);
        $this->a = DB::table('orders')->where('code', 'NB-DEMO001')->first();
        $this->b = DB::table('orders')->where('code', 'NB-DEMO002')->first();
    }

    private function member(string $role = 'staff', bool $reconcile = false): User
    {
        $user = User::where('email', 'nhanvien@tiembanh.test')->firstOrFail();
        DB::table('branch_memberships')->where('user_id', $user->id)->delete();
        DB::table('branch_memberships')->insert(['branch_id' => 1, 'user_id' => $user->id, 'role' => $role,
            'active' => true, 'can_reconcile' => $reconcile, 'created_at' => now(), 'updated_at' => now()]);

        return $user->fresh();
    }

    private function staff(User $user, int $branch = 1): self
    {
        $this->actingAs($user)->withSession(['admin_branch_id' => $branch, 'staff_mfa_verified' => $user->staffProof()]);

        return $this;
    }

    public function test_staff_cannot_read_mutate_export_or_schedule_another_branch(): void
    {
        $user = $this->member();
        $this->staff($user)->get('/quan-tri/don-hang')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orders.data', fn ($rows) => collect($rows)->contains('code', $this->a->code) && ! collect($rows)->contains('code', $this->b->code)));
        $this->get('/quan-tri/don-hang/'.$this->b->id)->assertNotFound();
        $this->patch('/quan-tri/don-hang/'.$this->b->id, ['kind' => 'status', 'value' => 'preparing'])->assertNotFound();
        $this->patch('/quan-tri/don-hang/'.$this->b->id.'/doi-gio', [
            'previous_slot' => substr($this->b->scheduled_at, 0, 16), 'slot' => '2026-11-29 11:00', 'note' => 'Thử chéo cơ sở',
        ])->assertNotFound();
        $csv = $this->get('/quan-tri/don-hang/xuat')->assertOk()->streamedContent();
        $this->assertStringContainsString($this->a->code, $csv);
        $this->assertStringNotContainsString($this->b->code, $csv);
        $this->assertStringContainsString('1 · DEFAULT', $csv);
        $this->assertStringNotContainsString('TEST-B', $csv);
        $this->get('/quan-tri/lich-lam-banh?date=2026-11-28')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orderCount', 1)->where('schedule', fn ($slots) => ! str_contains(json_encode($slots), $this->b->code)));
        $this->get('/quan-tri/doi-soat')->assertForbidden();
        $this->get('/quan-tri/bao-cao?scope=chain')->assertForbidden();
        $this->get('/quan-tri/don-hang/xuat?scope=chain')->assertForbidden();
        $this->post('/quan-tri/chon-co-so', ['branch_id' => 2])->assertForbidden();
        $this->withSession(['admin_branch_id' => 2])->get('/quan-tri/don-hang')->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $this->b->id, 'status' => $this->b->status]);
    }

    public function test_kitchen_has_production_view_without_customer_payment_or_report_access(): void
    {
        DB::table('orders')->where('id', $this->a->id)->update(['status' => 'confirmed', 'method' => 'cod']);
        $user = $this->member('kitchen', true);
        $this->staff($user)->get('/quan-tri/lich-lam-banh?date=2026-11-28')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('productionOnly', true)->where('orderCount', 1)
            ->where('schedule', function ($slots) {
                $encoded = json_encode($slots);

                return ! str_contains($encoded, $this->a->email) && ! str_contains($encoded, '"phone"')
                    && ! str_contains($encoded, '"address"') && ! str_contains($encoded, '"payment"')
                    && ! str_contains($encoded, '"total"') && ! str_contains($encoded, '"note"');
            }));
        foreach (['/quan-tri', '/quan-tri/don-hang', '/quan-tri/don-hang/'.$this->a->id,
            '/quan-tri/don-hang/xuat', '/quan-tri/bao-cao', '/quan-tri/doi-soat'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->patch('/quan-tri/don-hang/'.$this->a->id, ['kind' => 'status', 'value' => 'preparing', 'expected_status' => 'confirmed'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['id' => $this->a->id, 'status' => 'preparing']);
        $this->patch('/quan-tri/don-hang/'.$this->a->id, ['kind' => 'status', 'value' => 'cancelled', 'note' => 'Không có quyền'])->assertForbidden();
        $this->patch('/quan-tri/don-hang/'.$this->a->id, ['kind' => 'payment', 'value' => 'paid'])->assertForbidden();
    }

    public function test_accountant_reconciles_selected_branch_without_order_processing_or_contact_data(): void
    {
        DB::table('orders')->whereIn('id', [$this->a->id, $this->b->id])->update(['method' => 'bank', 'payment' => 'unpaid']);
        $user = $this->member('accountant', true);
        $this->staff($user)->get('/quan-tri/doi-soat')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orders.data', fn ($rows) => ! collect($rows)->contains('code', $this->b->code)));
        $this->get('/quan-tri/don-hang/'.$this->a->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('order.email')->missing('order.phone')->missing('order.address')->where('next', [])->where('changeRequests', []));
        $this->patch('/quan-tri/don-hang/'.$this->a->id, ['kind' => 'status', 'value' => 'confirmed'])->assertForbidden();
        $this->patch('/quan-tri/don-hang/'.$this->a->id, ['kind' => 'payment', 'value' => 'paid'])->assertSessionHasNoErrors();
        $this->patch('/quan-tri/don-hang/'.$this->b->id, ['kind' => 'payment', 'value' => 'paid'])->assertNotFound();
        $this->assertDatabaseHas('orders', ['id' => $this->b->id, 'payment' => 'unpaid']);
    }

    public function test_membership_and_reconciliation_revocation_invalidate_an_open_staff_session(): void
    {
        foreach ([['role' => 'kitchen'], ['active' => false], ['can_reconcile' => false]] as $change) {
            $user = $this->member('staff', true);
            $this->staff($user);
            DB::table('branch_memberships')->where('user_id', $user->id)->update($change);
            $this->patch('/quan-tri/don-hang/'.$this->a->id, ['kind' => 'status', 'value' => 'confirmed'])->assertRedirect('/dang-nhap');
            $this->assertGuest();
            $this->assertDatabaseHas('orders', ['id' => $this->a->id, 'status' => $this->a->status]);
        }
    }

    public function test_domain_mutation_rechecks_membership_for_a_stale_actor_object(): void
    {
        $user = $this->member();
        DB::table('branch_memberships')->where('user_id', $user->id)->update(['active' => false]);
        try {
            Orders::change($this->a->id, $user, ['kind' => 'status', 'value' => 'confirmed']);
            $this->fail('Revoked membership must not mutate an order.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('orders', ['id' => $this->a->id, 'status' => $this->a->status]);
    }

    public function test_chain_aggregate_is_explicit_and_admin_only(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $this->staff($admin)->get('/quan-tri')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('total', DB::table('orders')->where('branch_id', 1)->count())->where('scope', 'branch'));
        $this->get('/quan-tri?scope=chain')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('total', DB::table('orders')->count())->where('scope', 'chain'));
        $csv = $this->get('/quan-tri/don-hang/xuat?scope=chain')->assertOk()->streamedContent();
        $this->assertStringContainsString($this->b->code, $csv);
        $this->assertStringContainsString('Cơ sở', $csv);
        $this->assertStringContainsString('2 · TEST-B', $csv);
        $this->post('/quan-tri/chon-co-so', ['branch_id' => 2, 'order_id' => $this->a->id])->assertNotFound();
        $this->post('/quan-tri/chon-co-so', ['branch_id' => 2, 'order_id' => $this->b->id])->assertRedirect('/quan-tri/don-hang/'.$this->b->id);
        $this->get('/quan-tri/don-hang/'.$this->b->id)->assertOk();
    }

    public function test_guest_action_and_worker_derive_branch_from_the_order_and_restore_context(): void
    {
        DB::table('orders')->where('id', $this->b->id)->update(['branch_snapshot' => json_encode([
            'id' => 2, 'name' => '[THỬ] Cơ sở B lúc đặt', 'address' => '[THỬ] Địa chỉ B lúc đặt', 'phone' => '0900123456',
        ])]);
        OrderRequests::requestLink($this->b->tracking_token, $this->b->email);
        $notice = DB::table('order_notifications')->where('order_id', $this->b->id)->where('type', 'action_link')->first();
        $this->assertNotNull($notice);
        $body = Crypt::decryptString($notice->encrypted_body);
        $this->assertStringContainsString('[THỬ] Cơ sở B lúc đặt', $body);
        $this->assertStringContainsString('[THỬ] Địa chỉ B lúc đặt', $body);
        preg_match('~/thao-tac-don/([a-f0-9]{64})~', $body, $match);
        $this->assertSame(1, Branches::id());
        OrderRequests::submitGuest($match[1], ['request_key' => (string) Str::uuid(), 'kind' => 'cancel',
            'previous_slot' => substr($this->b->scheduled_at, 0, 16), 'reason' => 'Ca thử cơ sở B']);
        $requestId = DB::table('order_change_requests')->where('order_id', $this->b->id)->value('id');
        $this->assertNotNull($requestId);
        $this->assertSame(1, Branches::id());
        $this->staff($this->member())->patch('/quan-tri/yeu-cau-don/'.$requestId, ['decision' => 'rejected', 'note' => 'Thử chéo cơ sở'])->assertNotFound();
        (new SendOrderEmail($this->b->id, null, $notice->id))->handle();
        $this->assertSame(1, Branches::id());
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertNotNull(DB::table('order_notifications')->where('id', $notice->id)->value('sent_at'));
        $before = DB::table('order_notifications')->count();
        (new SendOrderEmail($this->b->id, 'confirmed'))->handle();
        $this->assertSame($before + 1, DB::table('order_notifications')->count());
        $this->assertSame(1, Branches::id());
    }

    public function test_readiness_checks_each_active_branch_and_uses_its_inventory_and_verification(): void
    {
        User::all()->each(fn (User $user) => $user->forceFill(['email' => 'qa-'.$user->id.'@example.test'])->save());
        $settings = array_replace(Store::settings(), ['phone' => '0900123456', 'address' => '[THỬ] Địa chỉ A',
            'business_verified' => true, 'bank_name' => '', 'bank_account' => '', 'bank_holder' => '', 'bank_demo' => false]);
        $brand = array_replace($settings, ['order_policy' => '[THỬ] Chính sách được duyệt trong test', 'privacy_notice' => '[THỬ] Thông báo dữ liệu']);
        DB::table('chain_settings')->where('id', 1)->update(['data' => json_encode($brand)]);
        DB::table('branches')->where('id', 1)->update(['settings' => json_encode($settings)]);
        DB::table('branches')->where('id', 2)->update(['settings' => json_encode(array_replace($settings,
            ['address' => '', 'business_verified' => false]))]);
        DB::table('branch_variants')->where('branch_id', 2)->update(['stock' => 0]);
        $this->assertSame([], LaunchReadiness::branchDataIssues(1));
        $issues = LaunchReadiness::branchDataIssues();
        $this->assertNotEmpty($issues);
        $this->assertTrue(collect($issues)->every(fn ($issue) => str_starts_with($issue, '[Cơ sở #2 TEST-B]')));
        $this->assertStringContainsString('Xác minh thông tin cơ sở', implode(' ', $issues));
        $this->assertStringContainsString('còn tồn', implode(' ', $issues));
        $this->assertSame(1, Branches::id());
    }

    public function test_owner_request_follows_its_branch_without_granting_access_to_other_customers(): void
    {
        $customer = User::where('role', 'customer')->firstOrFail();
        DB::table('orders')->where('id', $this->b->id)->update(['user_id' => $customer->id]);
        $url = '/don-cua-toi/'.$this->b->code.'/yeu-cau';
        $this->actingAs(User::factory()->create())->withSession(['branch_id' => 1])->get($url)->assertNotFound();
        $this->actingAs($customer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('order.code', $this->b->code)->where('changeRequest.canRequest', true));
        $this->post($url, ['request_key' => (string) Str::uuid(), 'kind' => 'cancel',
            'previous_slot' => substr($this->b->scheduled_at, 0, 16), 'reason' => 'Ca thử tài khoản sở hữu đơn B'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('order_change_requests', ['order_id' => $this->b->id, 'user_id' => $customer->id, 'status' => 'pending']);
    }

    public function test_email_job_cannot_send_a_notification_attached_to_another_order(): void
    {
        $notice = OrderNotices::record($this->a->id, 'tracking_link', dedupeKey: 'test:job-order-mismatch');
        (new SendOrderEmail($this->b->id, null, $notice))->handle();
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertNull(DB::table('order_notifications')->where('id', $notice)->value('sent_at'));
        $this->assertSame(1, Branches::id());
    }

    public function test_public_branch_fallback_uses_active_branch_and_all_inactive_stays_paused(): void
    {
        DB::table('branches')->where('id', 1)->update(['active' => false]);
        $this->withSession(['branch_id' => 1])->get('/thuc-don')->assertOk()->assertSessionHas('branch_id', 2)->assertInertia(fn (Assert $page) => $page
            ->where('shop.branch.id', 2));
        DB::table('branches')->where('id', 2)->update(['active' => false]);
        $this->get('/thuc-don')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('shop.branch.id', 1)->where('shop.accepting_orders', false)->where('shop.branches', []));
        $this->staff($this->member())->get('/quan-tri/don-hang')->assertOk();
    }
}
