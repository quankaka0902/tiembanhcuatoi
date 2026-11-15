<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Orders
{
    public const STATUSES = [
        'pending' => 'Chờ xác nhận', 'confirmed' => 'Đã xác nhận', 'preparing' => 'Đang chuẩn bị',
        'ready' => 'Sẵn sàng nhận', 'shipping' => 'Đang giao', 'completed' => 'Hoàn tất', 'cancelled' => 'Đã hủy',
    ];

    public const PAYMENTS = ['unpaid' => 'Chưa thanh toán', 'paid' => 'Đã thanh toán', 'refunded' => 'Đã hoàn tiền'];

    public static function next(object $order): array
    {
        return match ($order->status) {
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['preparing', 'cancelled'],
            'preparing' => [$order->fulfillment === 'delivery' ? 'shipping' : 'ready', 'cancelled'],
            'ready', 'shipping' => ['completed', 'cancelled'],
            default => [],
        };
    }

    public static function canReconcile(User $actor): bool
    {
        return $actor->active && ($actor->role === 'admin' || ($actor->role === 'staff' && $actor->can_reconcile));
    }

    public static function change(int $id, User $actor, array $fields): void
    {
        DB::transaction(function () use ($id, $actor, $fields) {
            Store::settings(lock: true);
            $actor = $actor->fresh();
            abort_unless($actor?->active && in_array($actor->role, ['admin', 'staff'], true), 403);
            $order = DB::table('orders')->lockForUpdate()->find($id);
            abort_unless($order, 404);
            $value = $fields['value'];

            if ($fields['kind'] === 'payment') {
                abort_unless(($order->method === 'cod' && $value === 'paid') || self::canReconcile($actor), 403);
                $allowed = ($value === 'paid' && $order->payment === 'unpaid' && $order->status !== 'cancelled'
                    && ($order->method === 'bank' || in_array($order->status, ['ready', 'shipping', 'completed'], true)))
                    || ($value === 'refunded' && $order->payment === 'paid' && $order->status === 'cancelled');
                if (! $allowed) {
                    throw ValidationException::withMessages(['value' => 'Không thể thay đổi thanh toán ở trạng thái hiện tại.']);
                }

                DB::table('orders')->where('id', $id)->update([
                    'payment' => $value, 'updated_at' => now(),
                    ...($value === 'paid' ? ['paid_at' => now()] : []),
                ]);
            } else {
                if (! in_array($value, self::next($order), true) || ($value === 'completed' && $order->payment !== 'paid')) {
                    throw ValidationException::withMessages(['value' => 'Trạng thái không hợp lệ. Hãy xác nhận thanh toán trước khi hoàn tất.']);
                }

                $update = ['status' => $value, 'updated_at' => now()];
                if ($value === 'completed') {
                    $update['completed_at'] = now();
                }
                if ($value === 'cancelled' && ! $order->stock_restored_at) {
                    foreach (DB::table('order_items')->where('order_id', $id)->orderBy('variant_id')->get() as $item) {
                        DB::table('variants')->where('id', $item->variant_id)->increment('stock', $item->quantity);
                    }
                    if ($order->coupon_id) {
                        DB::table('coupons')->where('id', $order->coupon_id)->where('used', '>', 0)->decrement('used');
                    }
                    $update['stock_restored_at'] = now();
                }
                DB::table('orders')->where('id', $id)->update($update);
            }
            self::event($id, $actor->id, $fields['kind'], $value, $fields['note'] ?? null);
        }, 5);
    }

    public static function event(int $orderId, ?int $actorId, string $kind, string $value, ?string $note = null): void
    {
        DB::table('order_events')->insert([
            'order_id' => $orderId, 'actor_id' => $actorId, 'kind' => $kind, 'value' => $value,
            'note' => $note, 'created_at' => now(),
        ]);
    }

    public static function link(object $order): string
    {
        return route('tracking.show', ['token' => $order->tracking_token]);
    }

    public static function detail(object $order, bool $internal = false): array
    {
        $data = (array) $order;
        unset($data['tracking_token'], $data['request_key'], $data['user_id'], $data['stock_restored_at']);
        if (! $internal) {
            $data = array_intersect_key($data, array_flip([
                'code', 'fulfillment', 'address', 'region_label', 'scheduled_at',
                'method', 'payment', 'status', 'subtotal', 'discount', 'delivery_fee', 'total',
            ]));
        }
        $data['bank_snapshot'] = $order->bank_snapshot ? json_decode($order->bank_snapshot, true, flags: JSON_THROW_ON_ERROR) : null;
        $data['items'] = DB::table('order_items')->where('order_id', $order->id)->get();
        $events = DB::table('order_events')->where('order_id', $order->id)->orderBy('order_events.id');
        if ($internal) {
            $events->leftJoin('users', 'users.id', '=', 'order_events.actor_id')->select('order_events.*', 'users.name as actor');
        } else {
            $events->select('kind', 'value', 'created_at');
        }
        $data['events'] = $events->get();

        return $data;
    }
}
