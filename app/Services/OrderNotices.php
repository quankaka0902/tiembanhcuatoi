<?php

namespace App\Services;

use App\Jobs\SendOrderEmail;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OrderNotices
{
    /**
     * Store an immutable event notification and queue it in the order's transaction.
     * Callers supply the persisted event ID or an operation's stable deduplication key.
     */
    public static function record(int $orderId, string $type, array $payload = [], ?int $eventId = null, ?string $dedupeKey = null, bool $queue = true): int
    {
        return DB::transaction(function () use ($orderId, $type, $payload, $eventId, $dedupeKey, $queue) {
            $order = Orders::query()->find($orderId);
            if (! $order) {
                throw new RuntimeException('Không tìm thấy đơn để tạo thông báo.');
            }
            if ($eventId !== null && ! DB::table('order_events')->where('id', $eventId)->where('order_id', $orderId)->exists()) {
                throw new RuntimeException('Sự kiện thông báo không thuộc đơn hàng.');
            }
            [$subject, $body] = self::content($order, $type, $payload);
            $key = hash('sha256', $orderId.'|'.$type.'|'.($dedupeKey ?? ($eventId !== null ? 'event:'.$eventId : (string) Str::uuid())));
            $created = DB::table('order_notifications')->insertOrIgnore([
                'order_id' => $orderId, 'event_id' => $eventId, 'event_key' => $key, 'type' => $type,
                'recipient' => $order->email, 'subject' => $subject,
                'encrypted_body' => Crypt::encryptString($body),
                'message_id' => 'bakery-'.Str::uuid().'@order-notices.invalid',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $notice = DB::table('order_notifications')->where('event_key', $key)->lockForUpdate()->first();
            if (! $notice) {
                throw new RuntimeException('Không lưu được thông báo đơn hàng.');
            }
            if ($created && $queue) {
                // order-mail uses the same DB connection, so a rollback removes both rows.
                Bus::dispatch(new SendOrderEmail($orderId, null, $notice->id));
            }

            return $notice->id;
        }, 5);
    }

    public static function actionLink(int $orderId, string $token, CarbonInterface|string $expiresAt): int
    {
        return self::record($orderId, 'action_link', [
            'token' => $token, 'expires_at' => (string) $expiresAt,
        ], dedupeKey: 'action:'.hash('sha256', $token));
    }

    /** Keep in-flight jobs from earlier releases and intentional tracking resends working. */
    public static function legacy(int $orderId, ?string $status, string $deliveryKey): ?int
    {
        $order = Orders::query()->find($orderId);
        if (! $order || ($order->status === 'cancelled' && in_array($status, SendOrderEmail::STATUS_UPDATES, true))) {
            return null;
        }

        return self::record($orderId, $status ?? 'tracking_link', dedupeKey: 'legacy:'.$deliveryKey, queue: false);
    }

    /** Notification-row locking never takes the shop/order lock or changes the order. */
    public static function deliver(int $noticeId): void
    {
        try {
            // Do not automatically rerun an SMTP call after a database deadlock.
            DB::transaction(function () use ($noticeId) {
                $notice = DB::table('order_notifications')->lockForUpdate()->find($noticeId);
                if (! $notice || $notice->sent_at !== null) {
                    return;
                }
                $sent = Mail::raw(Crypt::decryptString($notice->encrypted_body), function (Message $message) use ($notice) {
                    $message->to($notice->recipient)->subject($notice->subject);
                    $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $notice->message_id);
                });
                if ($sent === null) {
                    throw new RuntimeException('Kênh thư chưa chấp nhận thông báo.');
                }
                DB::table('order_notifications')->where('id', $noticeId)->update([
                    'sent_at' => now(), 'last_error' => null, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            // SMTP exceptions can contain recipients, credentials or private URLs.
            $error = class_basename($exception);
            DB::table('order_notifications')->where('id', $noticeId)->whereNull('sent_at')->update([
                'last_error' => $error, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
            ]);
            throw new RuntimeException('Chưa gửi được thông báo đơn hàng ('.$error.').');
        }
    }

    private static function content(object $order, string $type, array $payload): array
    {
        $time = fn (?string $value) => $value ? CarbonImmutable::parse($value)->format('H:i d/m/Y') : 'Chưa chọn';
        $requestKind = ($payload['kind'] ?? '') === 'cancel' ? 'hủy đơn' : 'đổi giờ';
        $schedule = $time($order->scheduled_at);
        [$subject, $body] = match ($type) {
            'tracking_link' => ['Liên kết tra cứu đơn '.$order->code, "Đây là liên kết tra cứu cho đơn {$order->code} mà bạn đã yêu cầu."],
            'pending' => [
                'Đã nhận đơn '.$order->code,
                "Tiệm đã nhận đơn {$order->code} và sẽ xác nhận sau.\nTổng tiền: ".number_format($order->total, 0, ',', '.')." ₫\nGiờ giao hoặc nhận: ".$schedule,
            ],
            'schedule', 'rescheduled' => [
                'Đã đổi giờ · Đơn '.$order->code,
                'Tiệm đã đổi giờ giao hoặc nhận từ '.$time($payload['old_slot'] ?? $payload['previous_slot'] ?? null)
                    .' sang '.$time($payload['new_slot'] ?? $payload['requested_slot'] ?? $order->scheduled_at).'.',
            ],
            'payment_paid', 'paid' => ['Đã ghi nhận thanh toán · Đơn '.$order->code, 'Tiệm đã ghi nhận thanh toán '.number_format($order->total, 0, ',', '.').' ₫ cho đơn '.$order->code.'.'],
            'payment_refunded', 'refunded' => ['Đã ghi nhận hoàn tiền · Đơn '.$order->code, 'Tiệm đã ghi nhận hoàn tiền '.number_format($order->total, 0, ',', '.').' ₫ cho đơn '.$order->code.'. Nếu chưa nhận được tiền, vui lòng liên hệ tiệm để đối chiếu.'],
            'request_created' => ['Đã nhận yêu cầu '.$requestKind.' · Đơn '.$order->code, 'Tiệm đã nhận yêu cầu '.$requestKind.'. Yêu cầu đang chờ xử lý. Giờ nhận và trạng thái đơn chỉ đổi sau khi tiệm chấp thuận.'],
            'request_approved' => ['Đã chấp thuận yêu cầu '.$requestKind.' · Đơn '.$order->code, 'Tiệm đã chấp thuận yêu cầu '.$requestKind.'. '.(($payload['kind'] ?? '') === 'cancel' ? 'Đơn đã được hủy.' : 'Giờ giao hoặc nhận mới: '.$schedule.'.')],
            'request_rejected' => ['Chưa chấp thuận yêu cầu '.$requestKind.' · Đơn '.$order->code, 'Tiệm chưa chấp thuận yêu cầu '.$requestKind.'. Giờ nhận và trạng thái đơn được giữ nguyên. Vui lòng xem yêu cầu trong trang đơn hoặc liên hệ tiệm.'],
            'action_link' => [
                'Xác minh yêu cầu đổi hoặc hủy · Đơn '.$order->code,
                "Bạn đã yêu cầu liên kết để gửi yêu cầu đổi giờ hoặc hủy đơn {$order->code}.\nLiên kết thao tác dùng một lần, hết hạn lúc ".$time($payload['expires_at'] ?? null).'.'
                    ."\n\n".rtrim(config('app.url'), '/').'/thao-tac-don/'.$payload['token']
                    ."\n\nNếu bạn không yêu cầu, hãy bỏ qua thư này. Liên kết tra cứu thông thường chỉ cho phép xem đơn.",
            ],
            'payment_required' => ['Đơn cần thanh toán · '.$order->code, 'Vui lòng chuyển đúng số tiền và nội dung trước hạn để tiệm xử lý đơn.'],
            'transfer_approval' => ['Cần xác nhận chuyển cơ sở · '.$order->code,
                self::transferText($payload)."\nChưa chuyển kho hoặc khung giờ. Chỉ thực hiện sau khi bạn đồng ý và tiệm kiểm tra lại tài nguyên."
                ."\nLiên kết xác nhận riêng hết hạn lúc ".$time($payload['expires_at'] ?? null).":\n"
                .rtrim(config('app.url'), '/').'/xac-nhan-chuyen-don/'.$payload['token']],
            'transfer_approved' => ['Đã nhận xác nhận chuyển cơ sở · '.$order->code, 'Bạn đã đồng ý báo giá chuyển cơ sở. Tiệm còn cần kiểm tra và thực hiện; đơn tại cơ sở cũ vẫn được giữ cho đến khi chuyển thành công.'],
            'transfer_rejected', 'transfer_cancelled' => ['Đã đóng đề nghị chuyển cơ sở · '.$order->code, 'Đề nghị chuyển cơ sở đã đóng. Chưa thay đổi đơn, kho và khung giờ của bạn.'],
            'transfer_completed' => ['Đã chuyển cơ sở · '.$order->code, self::transferText($payload)."\nĐơn đã chuyển thành công. Giữ nguyên mã đơn và liên kết tra cứu. Vui lòng kiểm tra tổng tiền mới trước khi thanh toán."],
            'dispatch_assigned' => ['Đã phân công giao bánh · '.$order->code, 'Tiệm đã phân công chuyến giao nội bộ. Chưa có ước tính thời gian đến được xác minh.'],
            'dispatch_started' => ['Bánh đang được giao · '.$order->code, 'Bánh đã được bàn giao cho người giao. Vui lòng sẵn sàng nhận và liên hệ tiệm khi cần hỗ trợ.'],
            'dispatch_delivered' => ['Đã ghi nhận giao bánh · '.$order->code, 'Tiệm đã ghi nhận bàn giao bánh. Việc thu tiền và hoàn tất đơn được đối chiếu riêng. Nếu chưa nhận được bánh, hãy liên hệ tiệm.'],
            'dispatch_failed' => ['Cần thống nhất giao lại · '.$order->code, 'Chuyến giao chưa thành công. Vui lòng liên hệ tiệm để thống nhất lịch giao lại hoặc phương án xử lý.'],
            'dispatch_cancelled' => ['Đã dừng công việc giao · '.$order->code, 'Tiệm đã dừng công việc giao hiện tại để xử lý lại. Đây chưa phải xác nhận hủy đơn hoặc hoàn tiền. Vui lòng liên hệ tiệm.'],
            'pickup_ready' => ['Bánh sẵn sàng nhận · '.$order->code, 'Bánh đã sẵn sàng tại cơ sở xử lý đơn. Giờ nhận: '.$schedule.'.'],
            'pickup_collected' => ['Đã ghi nhận nhận bánh · '.$order->code, 'Tiệm đã ghi nhận khách nhận bánh tại tiệm. Việc thanh toán được đối chiếu riêng. Nếu có sai sót, hãy liên hệ tiệm.'],
            default => self::statusContent($order, $type),
        };
        if (in_array($type, ['pending', 'payment_required'], true) && $order->method === 'bank' && $order->payment === 'unpaid') {
            $bank = $order->bank_snapshot ? json_decode($order->bank_snapshot, true, flags: JSON_THROW_ON_ERROR) : null;
            $body .= "\n\nChuyển khoản thủ công: ".number_format($order->total, 0, ',', '.').' ₫. Nội dung: '.$order->code.'.';
            if ($order->bank_due_at) {
                $body .= "\nHạn chuyển khoản: ".$time($order->bank_due_at).'.';
            }
            if ($bank) {
                $body .= "\n".($bank['name'] ?? '').' · '.($bank['account'] ?? '').' · '.($bank['holder'] ?? '');
                if ($bank['demo'] ?? false) {
                    $body .= "\nThông tin chuyển khoản là dữ liệu mẫu, không chuyển tiền thật.";
                }
            }
        }
        if ($type === 'cancelled' && $order->payment === 'paid') {
            $body .= "\nĐơn đã thanh toán đang chờ tiệm xử lý hoàn tiền. Đây chưa phải xác nhận đã hoàn tiền.";
        }
        if (in_array($type, ['request_approved', 'request_rejected'], true) && filled($payload['response_message'] ?? null)) {
            $body .= "\n\nPhản hồi của tiệm: ".$payload['response_message'];
        }
        $location = isset($order->branch_snapshot) && $order->branch_snapshot
            ? json_decode($order->branch_snapshot, true, flags: JSON_THROW_ON_ERROR) : null;
        if ($location && filled($location['name'] ?? null)) {
            $body .= "\n\nCơ sở xử lý đơn: ".$location['name'];
            foreach (['address' => 'Địa chỉ', 'phone' => 'Điện thoại'] as $field => $label) {
                if (filled($location[$field] ?? null)) {
                    $body .= "\n".$label.': '.$location[$field];
                }
            }
        } elseif (Branches::enabled()) {
            $body .= "\n\nCơ sở xử lý đơn: #".($order->branch_id ?? 1).'. Thông tin tại lúc đặt chưa có bản chụp đầy đủ.';
        }

        return [$subject, $body."\n\nThông báo ghi nhận lúc ".now()->format('H:i d/m/Y').'.'
            ."\nXem tình trạng hiện tại của đơn tại ".Orders::link($order)];
    }

    private static function statusContent(object $order, string $status): array
    {
        $label = Orders::STATUSES[$status] ?? match ($status) {
            'delivery_failed' => 'Giao hàng chưa thành công', 'uncollected' => 'Khách chưa nhận bánh',
            default => throw new RuntimeException('Loại thông báo đơn hàng không hợp lệ.'),
        };
        $body = "Đơn {$order->code} đã được cập nhật sang trạng thái ".$label.'.';
        if (in_array($status, ['ready', 'shipping'], true)) {
            $body .= "\nGiờ giao hoặc nhận: ".CarbonImmutable::parse($order->scheduled_at)->format('H:i d/m/Y').'.';
        }
        if (in_array($status, ['delivery_failed', 'uncollected'], true)) {
            $body .= "\nVui lòng liên hệ tiệm để thống nhất cách nhận hoặc xử lý đơn. Đơn chưa được ghi nhận hoàn tất.";
        }

        return [$label.' · Đơn '.$order->code, $body];
    }

    private static function transferText(array $payload): string
    {
        $money = fn ($value) => number_format((int) $value, 0, ',', '.').' ₫';

        return 'Cơ sở: '.($payload['from']['name'] ?? '').' → '.($payload['to']['name'] ?? '')
            ."\nTổng tiền cũ: ".$money($payload['old']['total'] ?? 0).'; mới: '.$money($payload['new']['total'] ?? 0)
            ."\nGiờ mới: ".($payload['new']['slot'] ?? '').'.';
    }
}
