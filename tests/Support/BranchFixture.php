<?php

namespace Tests\Support;

use App\Services\Branches;
use App\Services\BranchMigration;
use App\Services\Checkout;
use App\Services\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait BranchFixture
{
    protected int $branchVariantId;

    protected function prepareBranches(): void
    {
        config(['bakery.demo_seed' => true, 'inertia.testing.ensure_pages_exist' => false]);
        $this->seed();
        $this->branchVariantId = (int) DB::table('variants')->orderBy('id')->value('id');
        $settings = array_replace(Store::settings(), ['accepting_orders' => false]);
        DB::table('store_settings')->where('id', 1)->update(['data' => json_encode($settings)]);
        $migration = app(BranchMigration::class);
        $migration->backfill();
        $this->assertTrue($migration->verify()['passed']);
        $migration->activate();
        $settings['accepting_orders'] = true; // Synthetic fixture, never a readiness approval.
        $settings['slot_limits'] = ['09:00' => 1];
        DB::table('branches')->where('id', 1)->update(['settings' => json_encode($settings)]);
        $settingsB = array_replace($settings, ['slots' => ['10:00', '15:00'], 'slot_limits' => ['10:00' => 1],
            'address' => '[THỬ] Điểm nhận B', 'regions' => [['key' => 'vung-b', 'name' => '[THỬ] Vùng B', 'fee' => 55000]], 'free_from' => null]);
        DB::table('branches')->insert(['id' => 2, 'code' => 'TEST-B', 'slug' => 'thu-b', 'name' => '[THỬ] Cơ sở B',
            'timezone' => 'Asia/Ho_Chi_Minh', 'active' => true, 'settings' => json_encode($settingsB), 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('variants')->get() as $variant) {
            DB::table('branch_variants')->insert(['branch_id' => 2, 'variant_id' => $variant->id,
                'stock' => 4, 'price' => $variant->price + 100000, 'prep_minutes' => 240,
                'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Branches::use(1);
    }

    protected function branchCart(): array
    {
        return [['variant_id' => $this->branchVariantId, 'quantity' => 1, 'message' => null, 'note' => null]];
    }

    protected function branchFields(int $branch = 1, array $overrides = []): array
    {
        return Branches::run($branch, function () use ($branch, $overrides) {
            $checkout = app(Checkout::class);
            $fields = array_replace(['key' => (string) Str::uuid(), 'branch_id' => $branch, 'coupon' => '',
                'fulfillment' => 'pickup', 'method' => 'cod', 'region' => null, 'address' => null,
                'slot' => now()->addDays(4)->toDateString().($branch === 1 ? ' 09:00' : ' 10:00'),
                'name' => '[THỬ] Khách nhiều cơ sở', 'email' => 'branches@example.test', 'phone' => '0900000000'], $overrides);
            $lines = $checkout->cart($this->branchCart());
            $fields['quote_token'] = $checkout->quoteToken($checkout->quote($lines, $fields, Store::settings()), $lines, $fields['key'], $fields);

            return $fields;
        });
    }
}
