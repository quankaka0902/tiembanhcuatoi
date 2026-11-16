<?php

namespace Tests\Feature;

use App\Services\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentCheckoutTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        // The child PHP processes need to see committed fixture rows.
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run with the dedicated MySQL test database to exercise row locks.');
        }
        config(['bakery.demo_seed' => true]);
        $this->seed();
    }

    public function test_simultaneous_buyers_cannot_take_the_same_last_variant(): void
    {
        DB::table('variants')->where('id', 1)->update(['stock' => 1]);
        $this->assertSame(['cart', 'created'], $this->race(null));
        $this->assertDatabaseHas('variants', ['id' => 1, 'stock' => 0]);
        $this->assertDatabaseCount('orders', 8);
    }

    public function test_simultaneous_buyers_cannot_take_the_same_last_coupon_use(): void
    {
        DB::table('coupons')->where('code', 'NHAMINH10')->update(['usage_limit' => 1]);
        $this->assertSame(['coupon', 'created'], $this->race('NHAMINH10'));
        $this->assertDatabaseHas('coupons', ['code' => 'NHAMINH10', 'used' => 1]);
        $this->assertDatabaseCount('orders', 8);
    }

    public function test_simultaneous_identical_requests_create_one_order(): void
    {
        $this->assertSame(['created', 'replayed'], $this->race(null, sameKey: true));
        $this->assertDatabaseHas('variants', ['id' => 1, 'stock' => 14]);
        $this->assertDatabaseCount('orders', 8);
    }

    public function test_simultaneous_pickup_and_delivery_cannot_take_the_same_last_slot(): void
    {
        $settings = Store::settings();
        $settings['slot_limits'] = ['09:00' => 1];
        DB::table('store_settings')->update(['data' => json_encode($settings)]);
        $slot = now()->addDays(2)->toDateString().' 09:00';

        $this->assertSame(['created', 'slot'], $this->race(null, slot: $slot, secondFulfillment: 'delivery'));
        $this->assertSame(1, DB::table('orders')->where('scheduled_at', $slot.':00')->count());
        $this->assertDatabaseHas('variants', ['id' => 1, 'stock' => 14]);
    }

    private function race(?string $coupon, bool $sameKey = false, string $slot = '', string $secondFulfillment = 'pickup'): array
    {
        $key = (string) Str::uuid();
        for ($i = 0; $i < 2; $i++) {
            $inputs[$i] = new InputStream;
            $processes[$i] = new Process([
                PHP_BINARY, 'tests/Support/checkout_worker.php', $sameKey ? $key : (string) Str::uuid(), $coupon ?? '',
                $slot, $i === 0 ? 'pickup' : $secondFulfillment,
            ], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::getDatabaseName(),
                'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => config('database.connections.mysql.port'),
                'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
                'DB_URL' => '',
            ], $inputs[$i], timeout: 15);
            $processes[$i]->start();
        }

        foreach ($processes as $process) {
            $this->assertTrue($process->waitUntil(fn ($type, $buffer) => str_contains($buffer, 'ready')), $process->getErrorOutput());
        }
        foreach ($inputs as $input) {
            $input->write("go\n");
            $input->close();
        }

        $results = [];
        foreach ($processes as $process) {
            $this->assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
            $results[] = trim(substr($process->getOutput(), strlen("ready\n")));
        }
        sort($results);

        return $results;
    }
}
