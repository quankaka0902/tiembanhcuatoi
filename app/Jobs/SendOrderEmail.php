<?php

namespace App\Jobs;

use App\Services\OrderNotices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class SendOrderEmail implements ShouldQueue
{
    use Queueable;

    public const STATUS_UPDATES = ['confirmed', 'ready', 'shipping'];

    public const RECEIPT = 'pending';

    public const CONNECTION = 'order-mail';

    public int $tries = 3;

    public int $timeout = 60;

    public string $deliveryKey;

    public function __construct(
        public int $orderId,
        public ?string $status = null,
        public ?int $noticeId = null,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->deliveryKey = (string) Str::uuid();
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $noticeId = $this->noticeId ?? OrderNotices::legacy($this->orderId, $this->status,
            $this->deliveryKey ?? ('old-job:'.($this->job?->uuid() ?? $this->job?->getJobId() ?? $this->orderId.':'.$this->status)));
        if ($noticeId !== null) {
            OrderNotices::deliver($noticeId);
        }
    }
}
