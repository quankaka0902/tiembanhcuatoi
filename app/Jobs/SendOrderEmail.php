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

    public const RECEIPT = 'pending';

    public const CONNECTION = 'order-mail';

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $orderId,
        public ?string $status = null,
    ) {
        $this->onConnection(self::CONNECTION);
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $order = DB::table('orders')->find($this->orderId, ['code', 'email', 'status', 'total', 'tracking_token']);
        if (! $order || ($order->status === 'cancelled' && in_array($this->status, self::STATUS_UPDATES, true))) {
            return;
        }

        [$subject, $body] = match ($this->status) {
            null => ['Liên kết tra cứu đơn '.$order->code, "Đây là liên kết tra cứu cho đơn {$order->code} mà bạn đã yêu cầu."],
            self::RECEIPT => [
                'Đã nhận đơn '.$order->code,
                "Tiệm đã nhận đơn {$order->code} và sẽ xác nhận sau.\nTổng tiền: ".number_format($order->total, 0, ',', '.').' ₫',
            ],
            default => [
                Orders::STATUSES[$this->status].' · Đơn '.$order->code,
                "Đơn {$order->code} đã được cập nhật sang trạng thái ".Orders::STATUSES[$this->status].'.',
            ],
        };

        Mail::raw($body."\n\nXem tình trạng đơn tại ".Orders::link($order),
            fn ($message) => $message->to($order->email)->subject($subject));
    }
}
