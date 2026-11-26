<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
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
    public static function move(int $variantId, int $delta, string $reason, ?int $actorId = null, ?int $orderId = null, ?string $note = null, ?int $branchId = null): bool
    {
        $branchId = self::branchId($branchId, $orderId);
        $query = self::stockQuery($variantId, $branchId);
        $variant = (clone $query)->lockForUpdate()->first(['stock']);
        if (! $variant || $variant->stock + $delta < 0) {
            return false;
        }

        $after = $variant->stock + $delta;
        $query->update(['stock' => $after]);
        // The original column remains a rollback mirror for the original location only.
        if (Branches::enabled() && $branchId === 1) {
            DB::table('variants')->where('id', $variantId)->update(['stock' => $after]);
        } elseif (! Branches::enabled()) {
            DB::table('branch_variants')->where('branch_id', 1)->where('variant_id', $variantId)->update(['stock' => $after]);
        }
        self::record($variantId, $variant->stock, $after, $reason, $actorId, $orderId, $note, abs($delta), $branchId);

        return true;
    }

    public static function waste(int $variantId, int $quantity, int $actorId, int $orderId, string $note, ?int $branchId = null): void
    {
        $branchId = self::branchId($branchId, $orderId);
        $stock = self::stockQuery($variantId, $branchId)->lockForUpdate()->value('stock');
        self::record($variantId, $stock, $stock, 'waste', $actorId, $orderId, $note, $quantity, $branchId);
    }

    public static function record(int $variantId, int $before, int $after, string $reason, ?int $actorId = null, ?int $orderId = null, ?string $note = null, ?int $affectedQuantity = null, ?int $branchId = null): void
    {
        if ($before === $after && ! ($reason === 'waste' && $affectedQuantity > 0)) {
            return;
        }

        DB::table('stock_movements')->insert([
            'variant_id' => $variantId, 'order_id' => $orderId, 'actor_id' => $actorId, 'branch_id' => self::branchId($branchId, $orderId),
            'reason' => $reason, 'stock_before' => $before, 'stock_after' => $after, 'note' => $note,
            'affected_quantity' => $affectedQuantity ?? abs($after - $before),
            'created_at' => now(),
        ]);
    }

    public static function lock(array $variantIds, ?int $branchId = null): void
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        sort($variantIds);
        foreach ($variantIds as $id) {
            self::stockQuery($id, $branchId ?? Branches::id())->lockForUpdate()->first();
        }
    }

    private static function stockQuery(int $variantId, int $branchId): Builder
    {
        return Branches::enabled()
            ? DB::table('branch_variants')->where('branch_id', $branchId)->where('variant_id', $variantId)
            : DB::table('variants')->where('id', $variantId);
    }

    private static function branchId(?int $branchId, ?int $orderId): int
    {
        if ($orderId) {
            $orderBranch = (int) (DB::table('orders')->where('id', $orderId)->value('branch_id') ?? 1);
            if ($branchId !== null && $branchId !== $orderBranch) {
                throw new \LogicException('Kho phải thuộc cơ sở ghi trên đơn.');
            }

            return $orderBranch;
        }

        return $branchId ?? Branches::id();
    }
}
