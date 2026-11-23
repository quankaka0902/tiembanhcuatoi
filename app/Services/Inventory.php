<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Inventory
{
    public const REASONS = [
        'initial' => 'Tồn khi tạo phiên bản',
        'manual' => 'Điều chỉnh thủ công',
        'order' => 'Giữ tồn khi đặt bánh',
        'cancel' => 'Hoàn tồn khi hủy',
        'cancel_unmade' => 'Giải phóng phần chưa làm',
        'return_checked' => 'Nhập lại bánh đã kiểm tra',
        'waste' => 'Bánh hỏng hoặc tiêu hao',
    ];

    // Call within the same transaction as the order or catalog update.
    public static function move(int $variantId, int $delta, string $reason, ?int $actorId = null, ?int $orderId = null, ?string $note = null): bool
    {
        $variant = DB::table('variants')->lockForUpdate()->find($variantId, ['stock']);
        if (! $variant || $variant->stock + $delta < 0) {
            return false;
        }

        $after = $variant->stock + $delta;
        DB::table('variants')->where('id', $variantId)->update(['stock' => $after]);
        self::record($variantId, $variant->stock, $after, $reason, $actorId, $orderId, $note, abs($delta));

        return true;
    }

    public static function waste(int $variantId, int $quantity, int $actorId, int $orderId, string $note): void
    {
        $stock = DB::table('variants')->lockForUpdate()->where('id', $variantId)->value('stock');
        self::record($variantId, $stock, $stock, 'waste', $actorId, $orderId, $note, $quantity);
    }

    public static function record(int $variantId, int $before, int $after, string $reason, ?int $actorId = null, ?int $orderId = null, ?string $note = null, ?int $affectedQuantity = null): void
    {
        if ($before === $after && ! ($reason === 'waste' && $affectedQuantity > 0)) {
            return;
        }

        DB::table('stock_movements')->insert([
            'variant_id' => $variantId, 'order_id' => $orderId, 'actor_id' => $actorId,
            'reason' => $reason, 'stock_before' => $before, 'stock_after' => $after, 'note' => $note,
            'affected_quantity' => $affectedQuantity ?? abs($after - $before),
            'created_at' => now(),
        ]);
    }
}
