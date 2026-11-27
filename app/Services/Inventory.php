<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        'transfer_hold' => 'Giữ tồn tại cơ sở đích',
        'transfer_release' => 'Giải phóng tồn tại cơ sở nguồn',
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

    /** Caller locks both configurations, the order, slots and SKU rows in sorted order. */
    public static function transferReservation(int $orderId, int $from, int $to, array $quantities, int $actorId, string $note): void
    {
        if (! Branches::enabled() || DB::transactionLevel() < 1 || $from === $to) {
            throw new \LogicException('Chuyển giữ tồn cần transaction và hai cơ sở khác nhau.');
        }
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        abort_unless($order && (int) $order->branch_id === $from && $order->payment === 'unpaid'
            && in_array($order->status, ['pending', 'confirmed'], true) && ! Orders::productionStarted($order)
            && ! $order->stock_restored_at, 409);
        ksort($quantities);
        foreach ($quantities as $variantId => $quantity) {
            if ($quantity < 1 || (int) self::stockQuery((int) $variantId, $to)->lockForUpdate()->value('stock') < $quantity) {
                throw ValidationException::withMessages(['transfer' => 'Cơ sở đích không còn đủ bánh; giữ nguyên đơn tại nguồn.']);
            }
        }
        // These two scoped ledger entries deliberately straddle the order's branch update.
        // Ordinary move()/record() cannot override an order's persisted branch.
        foreach ([$to => -1, $from => 1] as $branchId => $direction) {
            foreach ($quantities as $variantId => $quantity) {
                $query = self::stockQuery((int) $variantId, $branchId);
                $before = (int) (clone $query)->lockForUpdate()->value('stock');
                $after = $before + $direction * $quantity;
                $query->update(['stock' => $after]);
                if ($branchId === 1) {
                    DB::table('variants')->where('id', $variantId)->update(['stock' => $after]);
                }
                DB::table('stock_movements')->insert(['variant_id' => $variantId, 'order_id' => $orderId, 'actor_id' => $actorId,
                    'branch_id' => $branchId, 'reason' => $direction < 0 ? 'transfer_hold' : 'transfer_release',
                    'stock_before' => $before, 'stock_after' => $after, 'affected_quantity' => $quantity, 'note' => $note, 'created_at' => now()]);
            }
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
