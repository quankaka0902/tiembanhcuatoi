<?php

namespace App\Console\Commands;

use App\Jobs\ProbeOrderMailWorker;
use App\Jobs\SendOrderEmail;
use App\Services\BakeryBackup;
use App\Services\LaunchReadiness;
use Illuminate\Cache\Repository;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BakeryMonitor extends Command
{
    protected $signature = 'bakery:monitor';

    protected $description = 'Kiểm tra web, MySQL, worker và bản sao lưu rồi gửi cảnh báo';

    public function handle(BakeryBackup $backups): int
    {
        $state = Cache::store('bakery-operations');
        $code = $state->lock('run-monitor', 90)->get(function () use ($state, $backups) {
            $issues = [];
            if (! $this->webHealthy()) {
                $issues[] = 'web';
            }
            try {
                DB::select('SELECT 1');
            } catch (Throwable) {
                $issues[] = 'database';
            }
            if (! in_array('database', $issues, true)) {
                try {
                    array_push($issues, ...$this->mailIssues($state));
                } catch (Throwable) {
                    $issues[] = 'worker_check';
                }
            }
            $cutoff = now()->subHours(max(1, config('operations.backup.max_age_hours')))->timestamp;
            if (! $backups->hasRecentCompleteBackup($cutoff)) {
                $issues[] = 'backup';
            }

            $this->line($issues ? 'Cần kiểm tra: '.implode(', ', $issues) : 'Các kiểm tra vận hành đạt.');

            return $this->notify($issues, $state) && ! $issues ? self::SUCCESS : self::FAILURE;
        });

        return $code === false ? self::SUCCESS : $code;
    }

    private function webHealthy(): bool
    {
        try {
            $request = $this->http();
            if ($user = config('operations.monitor.web_user')) {
                $request->withBasicAuth($user, (string) config('operations.monitor.web_password'));
            }
            $url = rtrim((string) config('app.url'), '/').'/up';

            return $this->trustedDestination($url) && $request->get($url)->ok();
        } catch (Throwable) {
            return false;
        }
    }

    private function mailIssues(Repository $state): array
    {
        $issues = [];
        $wait = max(1, config('operations.monitor.worker_wait_minutes')) * 60;
        $time = now()->timestamp;
        $state->lock(ProbeOrderMailWorker::LOCK, 10)->block(5, function () use ($state, $wait, $time) {
            $last = $state->get(ProbeOrderMailWorker::SEEN);
            if (! $state->has(ProbeOrderMailWorker::PENDING) && ($last === null || $last <= $time - 60)) {
                $probe = (string) Str::uuid();
                $state->put(ProbeOrderMailWorker::PENDING, $probe, $wait * 3);
                try {
                    Bus::dispatch(new ProbeOrderMailWorker($probe));
                    $state->add(ProbeOrderMailWorker::FIRST, $time);
                } catch (Throwable $exception) {
                    $state->forget(ProbeOrderMailWorker::PENDING);
                    throw $exception;
                }
            }
        });
        $last = $state->get(ProbeOrderMailWorker::SEEN) ?? $state->get(ProbeOrderMailWorker::FIRST);
        if ($last !== null && $last <= $time - $wait) {
            $issues[] = 'worker';
        }
        $connection = SendOrderEmail::CONNECTION;
        $queue = config("queue.connections.{$connection}.queue");
        if (DB::table(config("queue.connections.{$connection}.table"))->where('queue', $queue)
            ->where('created_at', '<=', $time - $wait)->exists()) {
            $issues[] = 'mail_backlog';
        }
        if (DB::connection(config('queue.failed.database'))->table(config('queue.failed.table'))
            ->where('connection', $connection)->where('queue', $queue)
            ->where('failed_at', '>=', now()->subDay())->exists()) {
            $issues[] = 'mail_failed';
        }

        return $issues;
    }

    private function notify(array $issues, Repository $state): bool
    {
        $previous = $state->get('last-alert');
        if ((! $issues && empty($previous['checks'])) ||
            ($issues === ($previous['checks'] ?? null) && ($previous['at'] ?? 0) > now()->subMinutes(config('operations.monitor.repeat_minutes'))->timestamp)) {
            return true;
        }

        $payload = ['service' => 'bakery', 'state' => $issues ? 'incident' : 'recovered', 'checks' => $issues, 'at' => now()->toIso8601String()];
        $url = (string) config('operations.monitor.alert_url');
        if (! $this->trustedDestination($url)) {
            Log::warning('Chưa cấu hình kênh nhận cảnh báo hợp lệ.', $payload);

            return false;
        }
        try {
            $request = $this->http()->asJson()->acceptJson();
            if ($token = config('operations.monitor.alert_token')) {
                $request->withToken($token);
            }
            if ($request->post($url, $payload)->successful()) {
                $state->forever('last-alert', ['checks' => $issues, 'at' => now()->timestamp]);

                return true;
            }
        } catch (Throwable) {
            // Transport exceptions may include the private webhook URL.
        }
        Log::warning('Chưa gửi được cảnh báo vận hành.', $payload);

        return false;
    }

    private function trustedDestination(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return $host && ! parse_url($url, PHP_URL_USER) && ($scheme === 'https'
            || ($scheme === 'http' && app()->environment(['local', 'testing']) && LaunchReadiness::loopback($host)));
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(3)->timeout(5)->withoutRedirecting()
            ->withOptions(['verify' => config('operations.monitor.ca') ?: true]);
    }
}
