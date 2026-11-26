<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BranchMigration;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class BranchMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bakery.demo_seed' => true]);
        $this->seed();
        $settings = json_decode(DB::table('store_settings')->where('id', 1)->value('data'), true, flags: JSON_THROW_ON_ERROR);
        DB::table('store_settings')->where('id', 1)->update(['data' => json_encode(array_replace($settings, ['accepting_orders' => false]), JSON_THROW_ON_ERROR)]);
        // Explicitly model rows created before the expansion migration.
        DB::table('orders')->update(['branch_id' => null, 'branch_snapshot' => null, 'sales_context' => null]);
        DB::table('stock_movements')->update(['branch_id' => null]);
    }

    public function test_expansion_starts_legacy_and_keeps_default_branch_paused(): void
    {
        $this->assertSame('legacy', BranchMigration::mode());
        $this->assertFalse(BranchMigration::isActive());
        $branch = DB::table('branches')->find(1);
        $this->assertSame('Asia/Ho_Chi_Minh', $branch->timezone);
        $this->assertFalse(json_decode($branch->settings, true)['accepting_orders']);
        $this->assertDatabaseCount('branch_variants', 0);
        $this->assertDatabaseCount('branch_memberships', 0);
        $this->assertSame(7, DB::table('orders')->whereNull('branch_id')->count());
    }

    public function test_backfill_preserves_available_stock_tokens_amounts_items_and_coupon_usage(): void
    {
        $orders = DB::table('orders')->orderBy('id')->get(['id', 'tracking_token', 'request_key', 'total', 'subtotal', 'discount', 'delivery_fee', 'address', 'stock_restored_at'])->toJson();
        $items = DB::table('order_items')->orderBy('id')->get()->toJson();
        $stock = DB::table('variants')->orderBy('id')->pluck('stock', 'id')->all();
        $coupons = DB::table('coupons')->orderBy('id')->pluck('used', 'id')->all();
        $staff = User::where('role', 'staff')->firstOrFail();
        DB::table('sessions')->insert(['id' => 'old-staff-session', 'user_id' => $staff->id, 'payload' => '', 'last_activity' => time()]);
        $variantId = DB::table('variants')->orderBy('id')->value('id');
        foreach ([null, DB::table('orders')->orderBy('id')->value('id')] as $orderId) {
            DB::table('stock_movements')->insert([
                'variant_id' => $variantId, 'order_id' => $orderId, 'reason' => 'manual',
                'stock_before' => 2, 'stock_after' => 1, 'created_at' => now(),
            ]);
        }

        $report = app(BranchMigration::class)->backfill(2);

        $this->assertTrue($report['passed']);
        $this->assertSame($report['baseline'], $report['current']);
        $this->assertSame($stock, DB::table('variants')->orderBy('id')->pluck('stock', 'id')->all());
        $this->assertSame($stock, DB::table('branch_variants')->where('branch_id', 1)->orderBy('variant_id')->pluck('stock', 'variant_id')->all());
        $this->assertSame($coupons, DB::table('coupons')->orderBy('id')->pluck('used', 'id')->all());
        $this->assertSame($orders, DB::table('orders')->orderBy('id')->get(['id', 'tracking_token', 'request_key', 'total', 'subtotal', 'discount', 'delivery_fee', 'address', 'stock_restored_at'])->toJson());
        $this->assertSame($items, DB::table('order_items')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('orders')->whereNull('branch_id')->count());
        $this->assertSame(2, DB::table('stock_movements')->where('branch_id', 1)->count());
        $snapshot = json_decode(DB::table('orders')->orderBy('id')->value('branch_snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($snapshot['historical_details_known']);
        $this->assertNull($snapshot['address']);
        $this->assertNull($snapshot['name']);
        $this->assertSame('legacy_location_id_1', $snapshot['provenance']);
        $this->assertSame(User::where('role', 'staff')->count(), DB::table('branch_memberships')->where('branch_id', 1)->count());
        $this->assertDatabaseMissing('branch_memberships', ['user_id' => User::where('role', 'admin')->value('id')]);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-staff-session']);
        $this->assertSame(1, (int) $staff->fresh()->authorization_version);
        $this->assertSame(6, (int) DB::table('branch_slot_locks')->sum('booked'));
        $chain = json_decode(DB::table('chain_settings')->where('id', 1)->value('data'), true);
        $branch = json_decode(DB::table('branches')->where('id', 1)->value('settings'), true);
        $this->assertSame([], array_diff_key($chain, array_flip(Store::BRAND_KEYS)));
        $this->assertSame([], array_intersect_key($branch, array_flip(Store::BRAND_KEYS)));
        $this->assertArrayNotHasKey('bank_account', $chain);
        $this->assertArrayHasKey('bank_account', $branch);
        $this->assertDatabaseHas('branch_audits', ['action' => 'migration.backfill', 'branch_id' => 1]);
        $this->assertSame('legacy', BranchMigration::mode());
    }

    public function test_repeated_backfill_is_idempotent_and_does_not_overwrite_stock_or_snapshots(): void
    {
        $migration = app(BranchMigration::class);
        $migration->backfill(1);
        $orderId = DB::table('orders')->orderBy('id')->value('id');
        $snapshot = json_encode(['id' => 1, 'name' => 'Bản chụp thử đã lưu', 'address' => 'Địa chỉ lịch sử thử'], JSON_THROW_ON_ERROR);
        DB::table('orders')->where('id', $orderId)->update(['branch_snapshot' => $snapshot]);
        $first = DB::table('branch_migration_state')->value('baseline');
        $membershipIds = DB::table('branch_memberships')->pluck('id')->all();
        $this->assertTrue($migration->backfill(3)['passed']);
        $this->assertSame($first, DB::table('branch_migration_state')->value('baseline'));
        $this->assertSame(json_decode($snapshot, true), json_decode(DB::table('orders')->where('id', $orderId)->value('branch_snapshot'), true));
        $this->assertSame($membershipIds, DB::table('branch_memberships')->pluck('id')->all());
        $this->assertSame(1, (int) User::where('role', 'staff')->first()->authorization_version);

        $variantId = DB::table('variants')->orderBy('id')->value('id');
        DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $variantId)->increment('stock');
        $before = DB::table('branch_variants')->where('variant_id', $variantId)->value('stock');
        $this->assertFalse($migration->backfill()['passed']);
        $this->assertSame($before, DB::table('branch_variants')->where('variant_id', $variantId)->value('stock'));
        $this->assertNull(DB::table('branch_migration_state')->value('verified_at'));
    }

    public function test_activate_requires_explicit_verify_and_keeps_every_branch_paused(): void
    {
        $migration = app(BranchMigration::class);
        $migration->backfill();
        $this->assertRefused(fn () => $migration->activate());
        $this->assertTrue($migration->verify()['passed']);
        $this->assertTrue($migration->activate()['passed']);
        $this->assertTrue(BranchMigration::isActive());
        $this->assertFalse(json_decode(DB::table('branches')->where('id', 1)->value('settings'), true)['accepting_orders']);
        $this->assertRefused(fn () => $migration->backfill());
        $this->assertDatabaseHas('branch_audits', ['action' => 'migration.activate']);
    }

    public function test_drift_after_verification_blocks_activation_without_recapturing_baseline(): void
    {
        $migration = app(BranchMigration::class);
        $migration->backfill();
        $migration->verify();
        $baseline = DB::table('branch_migration_state')->value('baseline');
        DB::table('coupons')->orderBy('id')->limit(1)->increment('used');
        $this->assertRefused(fn () => $migration->activate());
        $this->assertRefused(fn () => $migration->backfill());
        $this->assertFalse($migration->verify()['passed']);
        $this->assertSame($baseline, DB::table('branch_migration_state')->value('baseline'));
        $this->assertSame('legacy', BranchMigration::mode());
    }

    public function test_capacity_counter_mismatch_is_detected_and_only_frozen_backfill_can_rebuild(): void
    {
        $migration = app(BranchMigration::class);
        $migration->backfill();
        DB::table('branch_slot_locks')->orderBy('id')->limit(1)->increment('booked');
        $this->assertFalse($migration->verify()['passed']);
        $this->assertRefused(fn () => $migration->activate());
        $this->assertTrue($migration->backfill()['passed']);
        $this->assertSame(6, (int) DB::table('branch_slot_locks')->sum('booked'));
    }

    public function test_open_intake_and_unknown_legacy_location_are_rejected_before_any_backfill(): void
    {
        $migration = app(BranchMigration::class);
        $settings = json_decode(DB::table('store_settings')->value('data'), true);
        DB::table('store_settings')->update(['data' => json_encode(array_replace($settings, ['accepting_orders' => true]))]);
        $this->assertRefused(fn () => $migration->backfill());
        DB::table('store_settings')->update(['data' => json_encode($settings)]);
        DB::table('orders')->orderBy('id')->limit(1)->update(['location_id' => 9]);
        $this->assertRefused(fn () => $migration->backfill());
        $this->assertDatabaseCount('branch_variants', 0);
        $this->assertDatabaseCount('branch_memberships', 0);
        $this->assertNull(DB::table('branch_migration_state')->value('baseline'));
    }

    public function test_rollback_read_preserves_expanded_data_and_compatible_default_stock_changes(): void
    {
        $migration = $this->activated();
        $variantId = DB::table('variants')->orderBy('id')->value('id');
        DB::table('variants')->where('id', $variantId)->increment('stock');
        DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $variantId)->increment('stock');
        $orders = DB::table('orders')->orderBy('id')->get()->toJson();
        $this->assertTrue($migration->rollbackRead()['passed']);
        $this->assertSame('legacy', BranchMigration::mode());
        $this->assertSame($orders, DB::table('orders')->orderBy('id')->get()->toJson());
        $this->assertDatabaseHas('branch_audits', ['action' => 'migration.rollback_read']);
        $this->assertSame(7, DB::table('orders')->whereNotNull('branch_snapshot')->count());
    }

    public function test_rollback_refuses_other_branch_orders_stock_coupons_and_memberships(): void
    {
        $migration = $this->activated();
        $branchId = $this->otherBranch();
        $order = (array) DB::table('orders')->orderBy('id')->first();
        unset($order['id']);
        $order['code'] = 'NB-OTHER-TEST';
        $order['tracking_token'] = Str::random(64);
        $order['request_key'] = Str::random(64);
        $order['branch_id'] = $branchId;
        $order['location_id'] = $branchId;
        $orderId = DB::table('orders')->insertGetId($order);
        $this->assertRefused(fn () => $migration->rollbackRead());
        DB::table('orders')->where('id', $orderId)->delete();

        DB::table('branch_variants')->insert(['branch_id' => $branchId, 'variant_id' => DB::table('variants')->value('id'), 'stock' => 1]);
        $this->assertRefused(fn () => $migration->rollbackRead());
        DB::table('branch_variants')->where('branch_id', $branchId)->delete();

        $couponId = DB::table('coupons')->value('id');
        DB::table('coupons')->where('id', $couponId)->update(['branch_id' => $branchId]);
        $this->assertRefused(fn () => $migration->rollbackRead());
        DB::table('coupons')->where('id', $couponId)->update(['branch_id' => null]);

        DB::table('branch_memberships')->insert(['branch_id' => $branchId, 'user_id' => User::where('role', 'staff')->value('id')]);
        $this->assertRefused(fn () => $migration->rollbackRead());
        $this->assertSame('branch', BranchMigration::mode());
    }

    public function test_commands_require_one_step_and_never_activate_as_side_effect_of_backfill(): void
    {
        $this->artisan('bakery:branches')->assertFailed();
        $this->artisan('bakery:branches', ['--backfill' => true, '--activate' => true])->assertFailed();
        $this->artisan('bakery:branches', ['--backfill' => true, '--batch' => 0])->assertFailed();
        $this->artisan('bakery:branches', ['--backfill' => true, '--batch' => 2])->assertSuccessful();
        $this->assertSame('legacy', BranchMigration::mode());
        $this->artisan('bakery:branches', ['--verify' => true])->assertSuccessful();
        $this->artisan('bakery:branches', ['--activate' => true])->assertSuccessful();
        $this->artisan('bakery:branches', ['--rollback-read' => true])->assertSuccessful();
    }

    public function test_rollback_refuses_default_visibility_and_configuration_divergence(): void
    {
        $migration = $this->activated();
        $variantId = DB::table('variants')->orderBy('id')->value('id');
        $active = DB::table('variants')->where('id', $variantId)->value('active');
        DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $variantId)->update(['active' => ! $active]);
        $this->assertRefused(fn () => $migration->rollbackRead());
        DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $variantId)->update(['active' => $active]);
        $settings = json_decode(DB::table('branches')->where('id', 1)->value('settings'), true);
        DB::table('branches')->where('id', 1)->update(['settings' => json_encode(array_replace($settings, ['address' => 'Địa chỉ thử mới']))]);
        $this->assertRefused(fn () => $migration->rollbackRead());
        $this->assertSame('branch', BranchMigration::mode());
    }

    public function test_verify_refuses_configuration_in_the_wrong_scope(): void
    {
        $migration = app(BranchMigration::class);
        $this->assertTrue($migration->backfill()['passed']);
        $chain = json_decode(DB::table('chain_settings')->where('id', 1)->value('data'), true);
        DB::table('chain_settings')->where('id', 1)->update(['data' => json_encode($chain + ['bank_account' => 'TEST-NOT-REAL'])]);
        $this->assertFalse($migration->verify()['passed']);
        $this->assertRefused(fn () => $migration->activate());
        $this->assertSame('legacy', BranchMigration::mode());
    }

    public function test_native_json_object_key_reordering_does_not_change_baseline_or_configuration(): void
    {
        $migration = app(BranchMigration::class);
        $this->assertTrue($migration->backfill()['passed']);
        $baseline = json_decode(DB::table('branch_migration_state')->value('baseline'), true);
        foreach ($baseline as &$section) {
            $section = array_reverse($section, true);
        }
        unset($section);
        DB::table('branch_migration_state')->update(['baseline' => json_encode(array_reverse($baseline, true))]);
        foreach (['branches' => 'settings', 'chain_settings' => 'data'] as $table => $column) {
            $settings = json_decode(DB::table($table)->where('id', 1)->value($column), true);
            DB::table($table)->where('id', 1)->update([$column => json_encode(array_reverse($settings, true))]);
        }
        $this->assertTrue($migration->backfill()['passed']);
        $this->assertTrue($migration->verify()['passed']);
        $this->assertTrue($migration->activate()['passed']);
        $this->assertTrue($migration->rollbackRead()['passed']);
    }

    private function activated(): BranchMigration
    {
        $migration = app(BranchMigration::class);
        $this->assertTrue($migration->backfill()['passed']);
        $this->assertTrue($migration->verify()['passed']);
        $this->assertTrue($migration->activate()['passed']);

        return $migration;
    }

    private function otherBranch(): int
    {
        return DB::table('branches')->insertGetId([
            'code' => 'TEST-OTHER', 'slug' => 'co-so-thu-khac', 'name' => '[THỬ] Cơ sở khác',
            'settings' => json_encode(['accepting_orders' => false]), 'active' => true,
        ]);
    }

    private function assertRefused(callable $action): void
    {
        try {
            $action();
        } catch (RuntimeException $failure) {
            $this->assertNotSame('', $failure->getMessage());

            return;
        }
        $this->fail('Expected the migration safety guard to refuse this action.');
    }
}
