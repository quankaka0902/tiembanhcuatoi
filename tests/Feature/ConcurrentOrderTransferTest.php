<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Checkout;
use App\Services\OrderTransfers;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\BranchFixture;
use Tests\TestCase;

class ConcurrentOrderTransferTest extends TestCase
{
    use BranchFixture, DatabaseMigrations;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Dedicated MySQL schema required for transfer/checkout contention.');
        }
        $this->prepareBranches();
        $this->admin = User::where('role', 'admin')->firstOrFail();
        $this->owner = User::where('role', 'customer')->firstOrFail();
        foreach ([1, 2] as $branch) {
            $settings = json_decode(DB::table('branches')->where('id', $branch)->value('settings'), true);
            $settings = array_replace($settings, ['allow_branch_transfers' => true, 'transfer_policy_version' => OrderTransfers::POLICY,
                'transfer_policy_approval_reference' => '[THỬ] Chính sách test, không mở bán']);
            DB::table('branches')->where('id', $branch)->update(['settings' => json_encode($settings)]);
        }
    }

    public function test_transfer_and_checkout_cannot_both_take_last_target_stock_and_slot(): void
    {
        $order = $this->sourceOrder();
        DB::table('branch_variants')->where('branch_id', 2)->where('variant_id', $this->branchVariantId)->update(['stock' => 1]);
        $proposal = $this->approved($order);
        $sourceStock = (int) DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->branchVariantId)->value('stock');
        $results = $this->race([
            $this->worker('execute', $proposal->id, (string) Str::uuid()),
            $this->worker('checkout', 0, (string) Str::uuid()),
        ]);
        $this->assertSame(1, count(array_intersect($results, ['created', 'transferred'])), json_encode($results));
        $this->assertSame(1, count(array_intersect($results, ['cart', 'slot'])), json_encode($results));
        $this->assertDatabaseHas('branch_variants', ['branch_id' => 2, 'variant_id' => $this->branchVariantId, 'stock' => 0]);
        $this->assertDatabaseHas('branch_slot_locks', ['branch_id' => 2, 'scheduled_at' => $this->targetSlot().':00', 'booked' => 1]);
        $moved = DB::table('orders')->find($order->id);
        if (in_array('transferred', $results, true)) {
            $this->assertSame(2, (int) $moved->branch_id);
            $this->assertSame($sourceStock + 1, (int) DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->branchVariantId)->value('stock'));
            $this->assertDatabaseHas('branch_slot_locks', ['branch_id' => 1, 'scheduled_at' => $order->scheduled_at, 'booked' => 0]);
            $this->assertSame(0, DB::table('orders')->where('email', 'transfer-race@example.test')->count());
            $this->assertSame(1, DB::table('stock_movements')->where('order_id', $order->id)->where('reason', 'transfer_hold')->count());
        } else {
            $this->assertSame(1, (int) $moved->branch_id);
            $this->assertSame($sourceStock, (int) DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $this->branchVariantId)->value('stock'));
            $this->assertDatabaseHas('branch_slot_locks', ['branch_id' => 1, 'scheduled_at' => $order->scheduled_at, 'booked' => 1]);
            $this->assertSame(1, DB::table('orders')->where('email', 'transfer-race@example.test')->count());
            $this->assertSame(0, DB::table('stock_movements')->where('order_id', $order->id)->whereIn('reason', ['transfer_hold', 'transfer_release'])->count());
            $this->assertDatabaseHas('order_transfers', ['id' => $proposal->id, 'status' => 'approved']);
        }
        $this->assertSame($order->tracking_token, $moved->tracking_token);
    }

    public function test_two_execute_replays_move_resources_and_send_completion_once(): void
    {
        $order = $this->sourceOrder();
        $proposal = $this->approved($order);
        $key = (string) Str::uuid();
        $results = $this->race([$this->worker('execute', $proposal->id, $key), $this->worker('execute', $proposal->id, $key)]);
        $this->assertSame(['transferred', 'transferred'], $results);
        $this->assertSame(2, (int) DB::table('orders')->where('id', $order->id)->value('branch_id'));
        $this->assertSame(1, DB::table('stock_movements')->where('order_id', $order->id)->where('reason', 'transfer_hold')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('order_id', $order->id)->where('reason', 'transfer_release')->count());
        $this->assertSame(1, DB::table('order_notifications')->where('order_id', $order->id)->where('type', 'transfer_completed')->count());
        $this->assertDatabaseHas('branch_slot_locks', ['branch_id' => 2, 'scheduled_at' => $this->targetSlot().':00', 'booked' => 1]);
        $this->assertDatabaseCount('order_transfer_commands', 1);
    }

    public function test_two_same_key_proposals_create_one_action_link_and_keep_source_held(): void
    {
        $order = $this->sourceOrder();
        $preview = OrderTransfers::preview($order->id, $this->admin, ['target_branch_id' => 2, 'slot' => $this->targetSlot(), 'note' => '[THỬ] Tranh đề nghị']);
        $key = (string) Str::uuid();
        $results = $this->race([$this->worker('propose', $order->id, $key, $preview), $this->worker('propose', $order->id, $key, $preview)]);
        $this->assertSame(['proposed', 'proposed'], $results);
        $this->assertDatabaseCount('order_transfers', 1);
        $this->assertSame(1, (int) DB::table('orders')->where('id', $order->id)->value('branch_id'));
        $this->assertSame(1, DB::table('order_notifications')->where('order_id', $order->id)->where('type', 'transfer_approval')->count());
        $this->assertSame(0, DB::table('stock_movements')->where('order_id', $order->id)->whereIn('reason', ['transfer_hold', 'transfer_release'])->count());
    }

    private function sourceOrder(): object
    {
        $fields = $this->branchFields(1, ['slot' => now()->addDays(3)->format('Y-m-d').' 14:00']);

        return app(Checkout::class)->place($this->branchCart(), $fields, $this->owner->id)[0];
    }

    private function targetSlot(): string
    {
        return now()->addDays(4)->format('Y-m-d').' 10:00';
    }

    private function approved(object $order): object
    {
        $fields = ['target_branch_id' => 2, 'slot' => $this->targetSlot(), 'note' => '[THỬ] Tranh chuyển đơn'];
        $preview = OrderTransfers::preview($order->id, $this->admin, $fields);
        $proposal = OrderTransfers::propose($order->id, $this->admin, $fields + ['request_key' => (string) Str::uuid(), 'quote_token' => $preview['quote_token']]);
        OrderTransfers::approve(['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'accept_quote' => true], owner: $this->owner, code: $order->code);

        return $proposal;
    }

    private function race(array $workers): array
    {
        foreach ($workers as [$process]) {
            $process->start();
        }
        try {
            foreach ($workers as [$process]) {
                $this->assertTrue($process->waitUntil(fn ($type, $buffer) => str_contains($buffer, 'ready')), $process->getErrorOutput());
            }
            foreach ($workers as [, $input]) {
                $input->write("go\n");
                $input->close();
            }
            $results = [];
            foreach ($workers as [$process]) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
                $results[] = trim(substr($process->getOutput(), strlen("ready\n")));
            }
            sort($results);

            return $results;
        } finally {
            foreach ($workers as [$process]) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    private function worker(string $mode, int $id, string $key, ?array $preview = null): array
    {
        $input = new InputStream;
        $process = new Process([PHP_BINARY, 'tests/Support/order_transfer_worker.php', $mode, (string) $id, (string) $this->admin->id,
            (string) $this->branchVariantId, $key, $this->targetSlot(), $preview ? base64_encode(json_encode($preview, JSON_THROW_ON_ERROR)) : ''], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::getDatabaseName(),
                'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => config('database.connections.mysql.port'),
                'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'DB_URL' => '',
            ], $input, timeout: 20);

        return [$process, $input];
    }
}
