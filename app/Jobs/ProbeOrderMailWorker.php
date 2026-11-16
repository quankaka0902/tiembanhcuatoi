<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ProbeOrderMailWorker implements ShouldQueue
{
    use Queueable;

    public const PENDING = 'mail-probe-pending';

    public const SEEN = 'mail-probe-seen';

    public const FIRST = 'mail-probe-first';

    public const LOCK = 'mail-probe-lock';

    public function __construct(public string $id)
    {
        $this->onConnection(SendOrderEmail::CONNECTION);
    }

    public function handle(): void
    {
        $cache = Cache::store('bakery-operations');
        $cache->lock(self::LOCK, 10)->block(5, function () use ($cache) {
            if ($cache->get(self::PENDING) === $this->id) {
                $cache->forever(self::SEEN, now()->timestamp);
                $cache->forget(self::PENDING);
            }
        });
    }
}
