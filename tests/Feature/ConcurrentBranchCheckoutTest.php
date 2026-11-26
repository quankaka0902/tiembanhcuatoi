<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\BranchFixture;
use Tests\TestCase;

class ConcurrentBranchCheckoutTest extends TestCase
{
    use BranchFixture, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Dedicated MySQL schema required for branch row-lock contention.');
        }
        $this->prepareBranches();
    }

    public function test_two_branches_book_independently_with_their_own_price_stock_and_time(): void
    {
        $this->assertSame(['created', 'created'], $this->race([1, 2]));
        $this->assertSame(2, DB::table('orders')->where('email', 'branch-race@example.test')->count());
        $this->assertSame(3, (int) DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->value('stock'));
    }

    public function test_same_branch_cannot_sell_the_last_sku_twice(): void
    {
        DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->update(['stock' => 1]);
        $this->assertSame(['cart', 'created'], $this->race([2, 2], differentSlots: true));
        $this->assertSame(0, (int) DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->value('stock'));
        $this->assertSame(1, DB::table('stock_movements')->where('branch_id', 2)->where('reason', 'order')->count());
    }

    public function test_same_branch_cannot_oversubscribe_the_last_slot(): void
    {
        $this->assertSame(['created', 'slot'], $this->race([2, 2]));
        $slot = now()->addDays(4)->toDateString().' 10:00:00';
        $this->assertDatabaseHas('branch_slot_locks', ['branch_id' => 2, 'scheduled_at' => $slot, 'booked' => 1]);
    }

    public function test_chain_coupon_limit_is_atomic_between_branches(): void
    {
        DB::table('coupons')->where('code', 'NHAMINH10')->update(['usage_limit' => 1, 'used' => 0]);
        $this->assertSame(['coupon', 'created'], $this->race([1, 2], coupon: 'NHAMINH10'));
        $this->assertDatabaseHas('coupons', ['code' => 'NHAMINH10', 'used' => 1]);
        $this->assertSame(1, DB::table('orders')->where('email', 'branch-race@example.test')->count());
    }

    public function test_same_request_replay_does_not_duplicate_reservation_or_email(): void
    {
        $this->assertSame(['created', 'replayed'], $this->race([2, 2], sameKey: true));
        $order = DB::table('orders')->where('email', 'branch-race@example.test')->first();
        $this->assertSame(1, DB::table('orders')->where('email', 'branch-race@example.test')->count());
        $this->assertSame(1, DB::table('order_notifications')->where('order_id', $order->id)->count());
        $this->assertSame(1, DB::table('stock_movements')->where('order_id', $order->id)->count());
    }

    public function test_lock_held_on_a_sku_does_not_block_a_b_booking(): void
    {
        [$holder, $holdInput] = $this->worker(1, (string) Str::uuid(), '', 'hold');
        $holder->start();
        $this->assertTrue($holder->waitUntil(fn ($type, $buffer) => str_contains($buffer, 'ready')), $holder->getErrorOutput());
        [$buyer, $input] = $this->worker(2, (string) Str::uuid(), '');
        try {
            $buyer->start();
            $this->assertTrue($buyer->waitUntil(fn ($type, $buffer) => str_contains($buffer, 'ready')), $buyer->getErrorOutput());
            $input->write("go\n");
            $input->close();
            $this->assertSame(0, $buyer->wait(), $buyer->getErrorOutput());
            $this->assertSame('created', trim(substr($buyer->getOutput(), strlen("ready\n"))));
            $this->assertTrue($holder->isRunning(), 'A lock must still be held when B commits.');
        } finally {
            $holdInput->write("release\n");
            $holdInput->close();
            $holder->wait();
        }
    }

    private function race(array $branches, string $coupon = '', bool $sameKey = false, bool $differentSlots = false): array
    {
        $key = (string) Str::uuid();
        foreach ($branches as $i => $branch) {
            [$processes[$i], $inputs[$i]] = $this->worker($branch, $sameKey ? $key : (string) Str::uuid(), $coupon,
                differentSlot: $differentSlots && $i === 1);
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

    private function worker(int $branch, string $key, string $coupon, string $mode = '', bool $differentSlot = false): array
    {
        $slot = now()->addDays(4)->toDateString().($branch === 1 ? ' 09:00' : ($differentSlot ? ' 15:00' : ' 10:00'));
        $input = new InputStream;
        $process = new Process([PHP_BINARY, 'tests/Support/branch_checkout_worker.php', (string) $branch, $key, $coupon, $slot, $mode, (string) $this->branchVariantId], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::getDatabaseName(),
            'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => config('database.connections.mysql.port'),
            'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'DB_URL' => '',
        ], $input, timeout: 12);

        return [$process, $input];
    }
}
