<?php

namespace Tests\Feature;

use App\Services\Branches;
use App\Services\BranchRecommendations;
use App\Services\FakeLocationAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BranchFixture;
use Tests\TestCase;

class BranchRecommendationsTest extends TestCase
{
    use BranchFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 27)->setTime(8, 0));
        $this->prepareBranches();
        foreach ([1 => 30000, 2 => 20000] as $branch => $fee) {
            $this->settings($branch, ['regions' => [['key' => 'local-'.$branch, 'area_code' => 'vung-chung', 'name' => '[THỬ] Vùng chung', 'fee' => $fee]], 'free_from' => null]);
        }
    }

    public function test_ranking_uses_full_cart_prices_fees_and_current_prep_without_writing_or_changing_context(): void
    {
        $before = $this->counts();
        $service = new BranchRecommendations;
        $earliest = $service->evaluate($this->branchCart(), $this->context());
        $fee = $service->evaluate($this->branchCart(), $this->context(['goal' => 'fee']));
        $this->assertSame([1, 2], array_column($earliest['candidates'], 'branch_id'));
        $this->assertSame([2, 1], array_column($fee['candidates'], 'branch_id'));
        $this->assertSame(120, $earliest['candidates'][0]['prep_minutes']);
        $this->assertSame(240, $earliest['candidates'][1]['prep_minutes']);
        $this->assertSame(30000, $earliest['candidates'][0]['delivery_fee']);
        $this->assertSame(20000, $earliest['candidates'][1]['delivery_fee']);
        $this->assertSame(1, Branches::id());
        $this->assertSame($before, $this->counts());
        $this->assertStringNotContainsString('ĐỊA CHỈ RIÊNG', json_encode($earliest, JSON_UNESCAPED_UNICODE));
    }

    public function test_whole_cart_and_duplicate_sku_quantities_are_required(): void
    {
        $service = new BranchRecommendations;
        $cart = [$this->branchCart()[0], array_replace($this->branchCart()[0], ['quantity' => 20])];
        $result = $service->evaluate($cart, $this->context());
        $this->assertSame([], $result['candidates']);
        $this->assertSame(['stock_or_unavailable', 'stock_or_unavailable'], array_column($result['unavailable'], 'reason_code'));
        $second = (int) DB::table('variants')->where('id', '!=', $this->branchVariantId)->orderBy('id')->value('id');
        DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $second)->update(['stock' => 0]);
        $cart = [...$this->branchCart(), ['variant_id' => $second, 'quantity' => 1]];
        $this->assertSame([2], array_column($service->evaluate($cart, $this->context())['candidates'], 'branch_id'));
    }

    public function test_pause_method_bank_setup_area_and_coupon_scope_each_remove_ineligible_branch(): void
    {
        $service = new BranchRecommendations;
        $this->settings(1, ['accepting_orders' => false]);
        $result = $service->evaluate($this->branchCart(), $this->context());
        $this->assertSame('paused', $result['unavailable'][0]['reason_code']);
        $this->settings(1, ['accepting_orders' => true]);
        $this->settings(2, ['bank_account' => '', 'bank_name' => '', 'bank_holder' => '']);
        $result = $service->evaluate($this->branchCart(), $this->context(['method' => 'bank']));
        $this->assertSame([1], array_column($result['candidates'], 'branch_id'));
        $this->assertSame('method', $result['unavailable'][0]['reason_code']);
        $this->settings(2, ['regions' => [['key' => 'other', 'area_code' => 'other', 'name' => '[THỬ] Khác', 'fee' => 1]]]);
        $this->assertSame('area', $service->evaluate($this->branchCart(), $this->context())['unavailable'][0]['reason_code']);
        $this->settings(2, ['regions' => [['key' => 'b', 'area_code' => 'vung-chung', 'name' => '[THỬ] Chung', 'fee' => 1]]]);
        DB::table('coupons')->where('code', 'NHAMINH10')->update(['branch_id' => 1]);
        $this->assertSame('coupon', $service->evaluate($this->branchCart(), $this->context(['coupon' => 'NHAMINH10']))['unavailable'][0]['reason_code']);
    }

    public function test_capacity_date_exceptions_and_requested_slot_are_checked_without_reserving(): void
    {
        $service = new BranchRecommendations;
        $today = now()->toDateString();
        $this->settings(1, ['calendar_exceptions' => [['date' => $today, 'closed' => false, 'slots' => ['11:30'], 'slot_limits' => ['11:30' => 1]]]]);
        DB::table('branch_slot_locks')->insert(['branch_id' => 1, 'scheduled_at' => $today.' 11:30:00', 'booked' => 1]);
        $result = $service->evaluate($this->branchCart(), $this->context());
        $this->assertSame(2, $result['candidates'][0]['branch_id']);
        $result = $service->evaluate($this->branchCart(), $this->context(['desired_slot' => $today.' 15:00']));
        $this->assertSame([2], array_column($result['candidates'], 'branch_id'));
        $this->assertSame('desired_slot', $result['unavailable'][0]['reason_code']);
        $this->assertSame(1, (int) DB::table('branch_slot_locks')->where('branch_id', 1)->where('scheduled_at', $today.' 11:30:00')->value('booked'));
    }

    public function test_unknown_distances_stay_null_and_explicit_fake_estimates_can_rank_or_fail(): void
    {
        $manual = (new BranchRecommendations)->evaluate($this->branchCart(), $this->context(['goal' => 'distance']));
        $this->assertSame('earliest', $manual['ranking']['applied_goal']);
        $this->assertNotNull($manual['ranking']['fallback_reason']);
        foreach ($manual['candidates'] as $candidate) {
            $this->assertNull($candidate['distance_meters']);
            $this->assertNull($candidate['travel_minutes']);
        }
        foreach ([1, 2] as $branch) {
            $this->settings($branch, ['latitude' => 10.0, 'longitude' => 106.0, 'coordinates_approved' => true]);
        }
        $adapter = new FakeLocationAdapter([1 => ['distance_meters' => 3000, 'travel_minutes' => 5], 2 => ['distance_meters' => 1000, 'travel_minutes' => 15]]);
        $service = new BranchRecommendations($adapter);
        $context = $this->context(['goal' => 'distance', 'pin' => ['lat' => 10.1, 'lng' => 106.1, 'confirmed' => true]]);
        $this->assertSame([2, 1], array_column($service->evaluate($this->branchCart(), $context)['candidates'], 'branch_id'));
        $this->assertSame([1, 2], array_column($service->evaluate($this->branchCart(), array_replace($context, ['goal' => 'travel']))['candidates'], 'branch_id'));
        $this->assertSame('fake', $service->evaluate($this->branchCart(), $context)['candidates'][0]['estimate_provider']);
        $failed = (new BranchRecommendations(new FakeLocationAdapter([1 => ['failure' => 'timeout'], 2 => ['failure' => 'permission_denied']])))
            ->evaluate($this->branchCart(), $context);
        $this->assertSame('earliest', $failed['ranking']['applied_goal']);
        $this->assertSame(['timeout', 'permission_denied'], array_column($failed['candidates'], 'estimate_reason'));
        $this->assertSame([null, null], array_column($failed['candidates'], 'distance_meters'));
    }

    public function test_empty_cart_has_no_promised_time_and_inactive_branches_are_hidden(): void
    {
        $service = new BranchRecommendations;
        $result = $service->evaluate([], ['fulfillment' => 'pickup']);
        $this->assertSame([], $result['candidates']);
        $this->assertSame(['cart_empty', 'cart_empty'], array_column($result['unavailable'], 'reason_code'));
        DB::table('branches')->where('id', 2)->update(['active' => false]);
        $result = $service->evaluate($this->branchCart(), $this->context());
        $this->assertSame([1], array_column($result['candidates'], 'branch_id'));
        $this->assertSame([], $result['unavailable']);
    }

    public function test_fake_adapter_cannot_be_created_for_production(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->expectException(LogicException::class);
            new FakeLocationAdapter;
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    private function settings(int $branch, array $updates): void
    {
        $settings = json_decode(DB::table('branches')->where('id', $branch)->value('settings'), true);
        DB::table('branches')->where('id', $branch)->update(['settings' => json_encode(array_replace($settings, $updates), JSON_THROW_ON_ERROR)]);
    }

    private function context(array $updates = []): array
    {
        return array_replace(['fulfillment' => 'delivery', 'method' => 'cod', 'area_code' => 'vung-chung', 'goal' => 'earliest',
            'address_line' => '[THỬ] ĐỊA CHỈ RIÊNG', 'delivery_note' => '[THỬ] Ghi chú riêng'], $updates);
    }

    private function counts(): array
    {
        return array_map(fn ($table) => DB::table($table)->count(), ['orders', 'order_items', 'stock_movements', 'branch_slot_locks', 'customer_delivery_addresses']);
    }
}
