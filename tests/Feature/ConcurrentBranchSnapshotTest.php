<?php

namespace Tests\Feature;

use App\Services\Branches;
use App\Services\Checkout;
use App\Services\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\BranchFixture;
use Tests\TestCase;

class ConcurrentBranchSnapshotTest extends TestCase
{
    use BranchFixture, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('A committed second connection and MySQL REPEATABLE READ are required.');
        }
        $this->prepareBranches();
    }

    public static function priceChanges(): array
    {
        return [['branch_price'], ['shared_variant'], ['accessory']];
    }

    #[DataProvider('priceChanges')]
    public function test_final_quote_uses_current_prices_even_after_an_earlier_repeatable_read_snapshot(string $change): void
    {
        $accessory = null;
        if ($change === 'accessory') {
            $accessory = DB::table('product_accessories')->insertGetId([
                'product_id' => DB::table('variants')->where('id', $this->branchVariantId)->value('product_id'),
                'name' => '[THỬ] Phụ kiện snapshot', 'price' => 25000, 'active' => true, 'approved' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $before = DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->branchVariantId)->value('stock');
        $orders = DB::table('orders')->count();
        [$worker, $input] = $this->snapshotWorker('checkout', 1, $accessory);
        try {
            DB::transaction(function () use ($change, $accessory) {
                Store::settings(lock: true);
                if ($change === 'branch_price') {
                    $price = DB::table('variants')->where('id', $this->branchVariantId)->value('price');
                    DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->branchVariantId)->update(['price' => $price + 5000]);
                } elseif ($change === 'shared_variant') {
                    DB::table('variants')->where('id', $this->branchVariantId)->increment('price', 5000);
                } else {
                    DB::table('product_accessories')->where('id', $accessory)->increment('price', 7000);
                }
            });
            $this->assertSame('quote_token', $this->finish($worker, $input));
        } finally {
            $worker->stop(1);
        }
        $this->assertSame($orders, DB::table('orders')->count());
        $this->assertSame($before, DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->branchVariantId)->value('stock'));
        $this->assertDatabaseCount('order_notifications', 0);
        $this->assertDatabaseCount('checkout_request_locks', 0);
    }

    public function test_intake_update_at_another_branch_does_not_restore_stale_brand_settings(): void
    {
        [$worker, $input] = $this->snapshotWorker('intake', 2);
        $newName = '[THỬ] Thương hiệu mới sau snapshot';
        $newPolicy = '[THỬ] Chính sách mới sau snapshot';
        try {
            DB::transaction(function () use ($newName, $newPolicy) {
                $settings = Store::settings(lock: true);
                Store::saveSettings(array_replace($settings, ['name' => $newName, 'order_policy' => $newPolicy]));
            });
            $this->assertSame('saved', $this->finish($worker, $input));
        } finally {
            $worker->stop(1);
        }
        $brand = json_decode(DB::table('chain_settings')->where('id', 1)->value('data'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($newName, $brand['name']);
        $this->assertSame($newPolicy, $brand['order_policy']);
        $this->assertFalse(Branches::run(2, fn () => Store::settings()['accepting_orders']));
        $this->assertTrue(Store::settings()['accepting_orders']);
    }

    public function test_legacy_catalog_http_update_does_not_overwrite_a_reservation_committed_after_its_snapshot(): void
    {
        $settings = Store::settings();
        DB::table('branch_migration_state')->where('id', 1)->update(['mode' => 'legacy']);
        DB::table('store_settings')->where('id', 1)->update(['data' => json_encode($settings)]);
        $before = DB::table('variants')->where('id', $this->branchVariantId)->value('stock');
        $orders = DB::table('orders')->count();
        $fields = $this->branchFields();
        [$worker, $input] = $this->snapshotWorker('legacy_catalog', 1);
        try {
            app(Checkout::class)->place($this->branchCart(), $fields, null);
            $this->assertMatchesRegularExpression('/^variants\.[0-9]+\.stock$/', $this->finish($worker, $input));
        } finally {
            $worker->stop(1);
        }
        $this->assertSame($orders + 1, DB::table('orders')->count());
        $this->assertSame($before - 1, DB::table('variants')->where('id', $this->branchVariantId)->value('stock'));
        $this->assertSame($before - 1, DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->branchVariantId)->value('stock'));
        $this->assertDatabaseCount('order_notifications', 1);
    }

    private function snapshotWorker(string $mode, int $branch, ?int $accessory = null): array
    {
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, 'tests/Support/branch_snapshot_worker.php', $mode, (string) $branch,
            (string) $this->branchVariantId, (string) ($accessory ?? ''), now()->addDays(4)->toDateString().' 09:00'], base_path(), [
                'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::getDatabaseName(),
                'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => config('database.connections.mysql.port'),
                'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
                'DB_URL' => '', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
            ], $input, timeout: 20);
        $worker->start();
        $this->assertTrue($worker->waitUntil(fn ($type, $buffer) => str_contains($buffer, "ready\n")), $worker->getErrorOutput());

        return [$worker, $input];
    }

    private function finish(Process $worker, InputStream $input): string
    {
        $input->write("go\n");
        $input->close();
        $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());

        return trim(substr($worker->getOutput(), strlen("ready\n")));
    }
}
