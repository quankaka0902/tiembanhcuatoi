<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OrderRequests;
use App\Services\Orders;
use App\Services\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentOrderRequestsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Use the dedicated MySQL test schema to verify request and slot row locks.');
        }
        config(['bakery.demo_seed' => true]);
        $this->seed();
        DB::table('orders')->where('code', 'NB-DEMO001')->update([
            'scheduled_at' => now()->addDays(3)->toDateString().' 11:00:00', 'prep_minutes_snapshot' => 60,
        ]);
    }

    public function test_simultaneous_identical_customer_requests_create_one_request_event_and_email(): void
    {
        $order = DB::table('orders')->where('code', 'NB-DEMO001')->first();
        $beforeStock = DB::table('variants')->where('id', 1)->value('stock');
        $result = $this->race($order->id, ['request', 'request'], key: (string) Str::uuid());
        $this->assertSame($result[0], $result[1]);
        $this->assertMatchesRegularExpression('/^request:[0-9]+$/', $result[0]);
        $this->assertDatabaseCount('order_change_requests', 1);
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('kind', 'request')->count());
        $this->assertSame(1, DB::table('order_notifications')->where('order_id', $order->id)->where('type', 'request_created')->count());
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending', 'scheduled_at' => $order->scheduled_at]);
        $this->assertSame($beforeStock, DB::table('variants')->where('id', 1)->value('stock'));
    }

    public function test_approving_a_customer_reschedule_and_checkout_cannot_take_the_same_last_slot(): void
    {
        $order = DB::table('orders')->where('code', 'NB-DEMO001')->first();
        $slot = now()->addDays(2)->toDateString().' 09:00';
        $settings = array_replace(Store::settings(), ['slot_limits' => ['09:00' => 1]]);
        DB::table('store_settings')->where('id', 1)->update(['data' => json_encode($settings)]);
        $customer = User::findOrFail($order->user_id);
        $change = OrderRequests::submitOwner($order->id, $customer, [
            'request_key' => (string) Str::uuid(), 'kind' => 'reschedule', 'previous_slot' => substr($order->scheduled_at, 0, 16),
            'requested_slot' => $slot, 'reason' => 'Kiểm thử khách đổi giờ tranh khung cuối.',
        ]);
        $result = $this->race($order->id, ['approve', 'checkout'], $change->id, slot: $slot);
        $this->assertContains($result, [['approved', 'slot'], ['created', 'slot']]);
        $this->assertSame(1, Orders::scheduled()->where('scheduled_at', $slot.':00')->count());
        $changed = DB::table('orders')->find($order->id);
        if (in_array('approved', $result, true)) {
            $this->assertSame($slot.':00', $changed->scheduled_at);
            $this->assertDatabaseHas('order_change_requests', ['id' => $change->id, 'status' => 'approved']);
            $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('kind', 'schedule')->count());
            $this->assertSame(1, DB::table('order_notifications')->where('order_id', $order->id)->where('type', 'request_approved')->count());
        } else {
            $this->assertSame($order->scheduled_at, $changed->scheduled_at);
            $this->assertDatabaseHas('order_change_requests', ['id' => $change->id, 'status' => 'pending']);
            $this->assertSame(0, DB::table('order_events')->where('order_id', $order->id)->where('kind', 'schedule')->count());
            $this->assertSame(0, DB::table('order_notifications')->where('order_id', $order->id)->where('type', 'request_approved')->count());
        }
    }

    private function race(int $orderId, array $modes, ?int $changeId = null, ?string $key = null, string $slot = ''): array
    {
        $processes = $inputs = [];
        foreach ($modes as $i => $mode) {
            $inputs[$i] = new InputStream;
            $processes[$i] = new Process([
                PHP_BINARY, 'tests/Support/order_request_worker.php', $mode, (string) $orderId,
                $changeId === null ? '' : (string) $changeId, $key ?? (string) Str::uuid(), $slot,
            ], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::getDatabaseName(),
                'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => config('database.connections.mysql.port'),
                'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
                'DB_URL' => '',
            ], $inputs[$i], timeout: 20);
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
