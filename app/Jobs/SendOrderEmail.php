<?php

namespace App\Jobs;

use App\Services\Orders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendOrderEmail implements ShouldQueue
{
    use Queueable;

    public const STATUS_UPDATES = ['confirmed', 'ready', 'shipping'];

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $orderId,
        public ?string $status = null,
    ) {
        $this->onConnection('order-mail');
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $order = DB::table('orders')->find($this->orderId, ['code', 'email', 'status', 'tracking_token']);
        if (! $order || ($this->status !== null && $order->status === 'cancelled')) {
            return;
        }

        $subject = $this->status === null
            ? 'Liên kết tra cứu đơn '.$order->code
            : Orders::STATUSES[$this->status].' · Đơn '.$order->code;
        $body = $this->status === null
            ? "Đây là liên kết tra cứu cho đơn {$order->code} mà bạn đã yêu cầu."
            : "Đơn {$order->code} đã được cập nhật sang trạng thái ".Orders::STATUSES[$this->status].'.';

        Mail::raw($body."\n\nXem tình trạng đơn tại ".Orders::link($order),
            fn ($message) => $message->to($order->email)->subject($subject));
    }
}
