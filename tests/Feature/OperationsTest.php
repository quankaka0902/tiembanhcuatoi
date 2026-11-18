<?php

namespace Tests\Feature;

use App\Jobs\ProbeOrderMailWorker;
use App\Services\BakeryBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        config([
            'app.url' => 'https://bakery.example.test',
            'cache.stores.bakery-operations' => ['driver' => 'array'],
            'operations.monitor.alert_url' => 'https://alerts.example.test',
            'operations.monitor.heartbeat_url' => null,
            'operations.backup.root' => Storage::fake('ops-backups')->path(''),
            'operations.backup.copy_root' => null,
            'operations.backup.max_age_hours' => 1,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://bakery.example.test/up' => Http::response('up'),
            'https://alerts.example.test' => Http::response('', 202),
        ]);
    }

    public function test_monitor_detects_stopped_worker_suppresses_duplicate_alert_and_signals_recovery(): void
    {
        $this->backupFixture();
        $this->artisan('bakery:monitor')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        Http::assertSentCount(1);
        $this->travel(6)->minutes();
        $this->artisan('bakery:monitor')->assertFailed();
        $this->artisan('bakery:monitor')->assertFailed();
        $this->assertDatabaseCount('jobs', 1);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request->url() === 'https://alerts.example.test'
            && $request['state'] === 'incident' && $request['checks'] === ['worker', 'mail_backlog']
            && array_keys($request->data()) === ['service', 'state', 'checks', 'at']);

        $this->artisan('queue:work', ['connection' => 'order-mail', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(now()->timestamp, Cache::store('bakery-operations')->get(ProbeOrderMailWorker::SEEN));
        $this->artisan('bakery:monitor')->assertSuccessful();
        Http::assertSent(fn ($request) => $request->url() === 'https://alerts.example.test'
            && $request['state'] === 'recovered' && $request['checks'] === []);
    }

    public function test_monitor_reports_database_and_stale_copy_when_primary_backup_is_recent(): void
    {
        $this->backupFixture();
        config(['operations.backup.copy_root' => Storage::fake('ops-second-copy')->path('')]);
        $this->backupFixture('ops-second-copy', now()->subHours(2)->timestamp);
        $default = config('database.default');
        config([
            'database.default' => 'ops-failure',
            'database.connections.ops-failure' => ['driver' => 'missing'],
        ]);
        try {
            $this->artisan('bakery:monitor')->assertFailed();
        } finally {
            config(['database.default' => $default]);
        }
        Http::assertSent(fn ($request) => $request->url() === 'https://alerts.example.test'
            && $request['checks'] === ['database', 'backup']);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_verify_rejects_changed_bytes_and_partial_backups_do_not_count(): void
    {
        $directory = $this->backupFixture();
        $backup = app(BakeryBackup::class);
        $this->artisan('bakery:backup', ['--verify' => $directory])->assertSuccessful();
        file_put_contents($directory.'/database.sql', 'bad');
        $this->artisan('bakery:backup', ['--verify' => $directory])->assertFailed();

        config(['operations.backup.root' => Storage::fake('ops-partial')->path('')]);
        $this->backupFixture('ops-partial', name: '.partial-20261116-120000-acde1234');
        $this->assertFalse($backup->hasRecentCompleteBackup(now()->subHour()->timestamp));
    }

    public function test_scheduler_heartbeat_is_sent_even_while_an_incident_is_reported(): void
    {
        config([
            'operations.monitor.heartbeat_url' => 'https://heartbeat.example.test/ping',
            'operations.monitor.heartbeat_token' => 'test-token',
        ]);
        Http::fake(['https://heartbeat.example.test/ping' => Http::response('', 204)]);

        $this->artisan('bakery:monitor')->assertFailed();

        Http::assertSent(fn ($request) => $request->url() === 'https://alerts.example.test' && $request['checks'] === ['backup']);
        Http::assertSent(fn ($request) => $request->url() === 'https://heartbeat.example.test/ping'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->data() === ['service' => 'bakery', 'at' => now()->toIso8601String()]);
    }

    private function backupFixture(string $disk = 'ops-backups', ?int $finishedAt = null, string $name = '20261116-120000-acde1234'): string
    {
        $storage = Storage::disk($disk);
        $files = ['database.sql' => 'sql', 'images.zip' => 'zip'];
        $manifest = ['version' => 1, 'completed_at' => $finishedAt ?? now()->timestamp, 'files' => []];
        foreach ($files as $file => $contents) {
            $storage->put($name.'/'.$file, $contents);
            $manifest['files'][$file] = ['size' => strlen($contents), 'sha256' => hash('sha256', $contents)];
        }
        $storage->put($name.'/manifest.json', json_encode($manifest));

        return $storage->path($name);
    }
}
