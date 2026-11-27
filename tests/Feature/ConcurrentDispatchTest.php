<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Fulfillments;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\BranchFixture;
use Tests\TestCase;

class ConcurrentDispatchTest extends TestCase
{
    use BranchFixture, DatabaseMigrations;

    private int $jobId;

    private User $courier;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run the dispatch race with the dedicated MySQL test schema.');
        }
        $this->prepareBranches();
        $order = DB::table('orders')->where('code', 'NB-DEMO001')->first();
        DB::table('orders')->where('id', $order->id)->update(['fulfillment' => 'delivery', 'status' => 'preparing',
            'method' => 'cod', 'payment' => 'unpaid', 'address' => '[THỬ] Điểm nhận kiểm thử đồng thời']);
        $this->courier = User::factory()->create();
        $this->courier->forceFill(['role' => 'staff', 'active' => true])->save();
        DB::table('branch_memberships')->insert(['branch_id' => 1, 'user_id' => $this->courier->id, 'role' => 'courier',
            'active' => true, 'can_reconcile' => false, 'created_at' => now(), 'updated_at' => now()]);
        $staff = User::where('email', 'nhanvien@tiembanh.test')->firstOrFail();
        DB::transaction(fn () => Fulfillments::createForOrder(DB::table('orders')->find($order->id)));
        $this->jobId = (int) DB::table('fulfillment_jobs')->where('order_id', $order->id)->value('id');
        Fulfillments::assignTrip($staff, ['order_ids' => [$order->id], 'courier_id' => $this->courier->id, 'request_key' => (string) Str::uuid()]);
        foreach ([['ready', $staff], ['start', $this->courier]] as [$action, $actor]) {
            Fulfillments::change($this->jobId, $actor, ['action' => $action,
                'expected_version' => DB::table('fulfillment_jobs')->where('id', $this->jobId)->value('version'), 'request_key' => (string) Str::uuid()]);
        }
    }

    public function test_simultaneous_identical_delivery_confirmation_has_one_attempt_event_and_notice(): void
    {
        $this->assertSame(['delivered', 'delivered'], $this->race(true));
        $this->assertOneConfirmation();
    }

    public function test_simultaneous_different_keys_with_one_version_reject_the_stale_confirmation(): void
    {
        $this->assertSame(['delivered', 'expected_version'], $this->race(false));
        $this->assertOneConfirmation();
    }

    private function assertOneConfirmation(): void
    {
        $orderId = DB::table('fulfillment_jobs')->where('id', $this->jobId)->value('order_id');
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('job_id', $this->jobId)->count());
        $this->assertSame(1, DB::table('fulfillment_events')->where('job_id', $this->jobId)->where('action', 'delivered')->count());
        $this->assertSame(1, DB::table('order_notifications')->where('order_id', $orderId)->where('type', 'dispatch_delivered')->count());
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'shipping', 'payment' => 'unpaid']);
        $this->assertDatabaseHas('fulfillment_jobs', ['id' => $this->jobId, 'status' => 'delivered', 'attempt_count' => 1]);
    }

    private function race(bool $sameKey): array
    {
        $key = (string) Str::uuid();
        $version = DB::table('fulfillment_jobs')->where('id', $this->jobId)->value('version');
        $processes = $inputs = [];
        try {
            foreach ([0, 1] as $index) {
                $inputs[$index] = new InputStream;
                $processes[$index] = new Process([PHP_BINARY, 'tests/Support/dispatch_worker.php', (string) $this->jobId,
                    (string) $this->courier->id, $sameKey ? $key : (string) Str::uuid(), (string) $version], base_path(), [
                        'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::getDatabaseName(),
                        'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => config('database.connections.mysql.port'),
                        'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
                        'DB_URL' => '', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
                    ], $inputs[$index], timeout: 20);
                $processes[$index]->start();
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $buffer) => str_contains($buffer, "ready\n")), $process->getErrorOutput());
            }
            foreach ($inputs as $input) {
                $input->write("go\n");
                $input->close();
            }
            $result = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $result[] = trim(substr($process->getOutput(), strlen("ready\n")));
            }
            sort($result);

            return $result;
        } finally {
            foreach ($processes as $process) {
                $process->stop(1);
            }
        }
    }
}
