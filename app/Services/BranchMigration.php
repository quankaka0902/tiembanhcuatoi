<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BranchMigration
{
    public static function mode(): string
    {
        return Schema::hasTable('branch_migration_state')
            ? (DB::table('branch_migration_state')->where('id', 1)->value('mode') ?: 'legacy')
            : 'legacy';
    }

    public static function isActive(): bool
    {
        return self::mode() === 'branch';
    }

    public function backfill(int $batch = 200): array
    {
        if ($batch < 1 || $batch > 1000) {
            throw new RuntimeException('Kích thước batch phải từ 1 đến 1000.');
        }

        return DB::transaction(function () use ($batch) {
            [$state, $legacy] = $this->locked();
            $this->requireLegacy($state);
            $this->requirePaused($legacy);
            if (DB::table('orders')->whereNull('branch_id')->where('location_id', '!=', 1)->exists()) {
                throw new RuntimeException('Có đơn location_id khác 1 chưa xác định cơ sở; dừng để đối chiếu thủ công.');
            }
            $before = $this->fingerprint();
            if ($state->baseline !== null && $this->json($this->decode($state->baseline)) !== $this->json($before)) {
                throw new RuntimeException('Dữ liệu đã khác baseline bất biến. Không tự đặt lại baseline; đối chiếu và dùng kế hoạch phục hồi đã duyệt.');
            }
            if ($state->baseline === null) {
                DB::table('branch_migration_state')->where('id', 1)->update(['baseline' => $this->json($before)]);
                DB::table('branches')->where('id', 1)->update([
                    'name' => $legacy['name'] ?? 'Cơ sở hiện tại',
                    'settings' => $this->json(array_replace(array_diff_key($legacy, array_flip(Store::BRAND_KEYS)), ['accepting_orders' => false])), 'updated_at' => now(),
                ]);
                DB::table('chain_settings')->where('id', 1)->update(['data' => $this->json(array_intersect_key($legacy, array_flip(Store::BRAND_KEYS)))]);
            }

            DB::table('variants')->orderBy('id')->chunkById($batch, function ($variants) {
                foreach ($variants as $variant) {
                    DB::table('branch_variants')->insertOrIgnore([
                        'branch_id' => 1, 'variant_id' => $variant->id, 'stock' => $variant->stock,
                        'price' => null, 'prep_minutes' => null, 'active' => $variant->active,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
            DB::table('orders')->where('location_id', 1)->orderBy('id')->chunkById($batch, function ($orders) {
                foreach ($orders as $order) {
                    if ($order->branch_id !== null && (int) $order->branch_id !== 1) {
                        throw new RuntimeException('Đơn cũ đã được gán cơ sở khác; không ghi đè.');
                    }
                    $changes = ['branch_id' => 1];
                    if ($order->branch_snapshot === null) {
                        $changes['branch_snapshot'] = $this->json([
                            'id' => 1, 'provenance' => 'legacy_location_id_1', 'historical_details_known' => false,
                            'name' => null, 'address' => null, 'phone' => null, 'timezone' => null,
                        ]);
                    }
                    if ($order->sales_context === null) {
                        $changes['sales_context'] = $this->json(['source' => 'legacy_backfill', 'branch_id' => 1]);
                    }
                    DB::table('orders')->where('id', $order->id)->update($changes);
                }
            });
            DB::table('stock_movements')->whereNull('branch_id')->orderBy('id')->chunkById($batch, function ($movements) {
                foreach ($movements as $movement) {
                    $branchId = $movement->order_id ? DB::table('orders')->where('id', $movement->order_id)->value('branch_id') : 1;
                    if ((int) $branchId !== 1) {
                        throw new RuntimeException('Lịch sử tồn chưa xác định được cơ sở mặc định.');
                    }
                    DB::table('stock_movements')->where('id', $movement->id)->update(['branch_id' => 1]);
                }
            });
            DB::table('users')->where('role', 'staff')->orderBy('id')->chunkById($batch, function ($users) {
                foreach ($users as $user) {
                    $inserted = DB::table('branch_memberships')->insertOrIgnore([
                        'branch_id' => 1, 'user_id' => $user->id, 'role' => 'staff',
                        'active' => $user->active, 'can_reconcile' => $user->can_reconcile,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if ($inserted) {
                        DB::table('users')->where('id', $user->id)->increment('authorization_version');
                        DB::table('sessions')->where('user_id', $user->id)->delete();
                    }
                }
            });
            // Only the frozen legacy backfill may rebuild capacity counters.
            DB::table('branch_slot_locks')->where('branch_id', 1)->update(['booked' => 0]);
            foreach ($this->slotCounts() as $slot => $booked) {
                DB::table('branch_slot_locks')->updateOrInsert(['branch_id' => 1, 'scheduled_at' => $slot], ['booked' => $booked]);
            }
            $after = $this->fingerprint();
            if ($before !== $after) {
                throw new RuntimeException('Đối chiếu trước/sau không khớp; toàn bộ backfill được quay lui.');
            }
            $report = $this->report($before);
            $report['step'] = 'backfill';
            $this->saveReport($report);
            $this->audit('migration.backfill', ['batch' => $batch, 'passed' => $report['passed'], 'fingerprint' => hash('sha256', $this->json($before))]);

            return $report;
        }, 3);
    }

    public function verify(): array
    {
        return DB::transaction(function () {
            [$state] = $this->locked();
            $baseline = $this->decode($state->baseline);
            $report = $this->report($baseline);
            $report['step'] = 'verify';
            $this->saveReport($report);
            $this->audit('migration.verify', ['passed' => $report['passed'], 'issues' => $report['issues']]);

            return $report;
        }, 3);
    }

    public function activate(): array
    {
        return DB::transaction(function () {
            [$state, $legacy] = $this->locked();
            $this->requireLegacy($state);
            $this->requirePaused($legacy);
            $report = $this->report($this->decode($state->baseline));
            if (! $state->verified_at || ($this->decode($state->verification)['step'] ?? null) !== 'verify' || ! $report['passed']) {
                throw new RuntimeException('Chưa có đối chiếu đạt hoặc dữ liệu đã thay đổi. Chạy --backfill và --verify, không tự bật cơ sở.');
            }
            $this->pauseBranches();
            DB::table('branch_migration_state')->where('id', 1)->update(['mode' => 'branch', 'updated_at' => now()]);
            $this->audit('migration.activate', ['from' => 'legacy', 'to' => 'branch', 'intake_paused' => true]);

            return $report + ['mode' => 'branch'];
        }, 3);
    }

    public function rollbackRead(): array
    {
        return DB::transaction(function () {
            [$state, $legacy] = $this->locked();
            $this->requirePaused($legacy);
            foreach (DB::table('branches')->get(['settings']) as $branch) {
                $this->requirePaused($this->decode($branch->settings));
            }
            $issues = $this->rollbackIssues($legacy);
            if ($issues) {
                throw new RuntimeException('Không thể quay lui chế độ đọc: '.implode(' ', $issues));
            }
            DB::table('branch_migration_state')->where('id', 1)->update([
                'mode' => 'legacy', 'verified_at' => null, 'updated_at' => now(),
            ]);
            $this->audit('migration.rollback_read', ['from' => $state->mode, 'to' => 'legacy', 'intake_paused' => true]);

            return ['passed' => true, 'mode' => 'legacy', 'issues' => []];
        }, 3);
    }

    private function locked(): array
    {
        $legacy = DB::table('store_settings')->where('id', 1)->lockForUpdate()->value('data');
        $state = DB::table('branch_migration_state')->where('id', 1)->lockForUpdate()->first();
        if (! $state) {
            throw new RuntimeException('Chưa có schema cơ sở; chạy migration trên DB đã được sao lưu.');
        }
        DB::table('branches')->orderBy('id')->lockForUpdate()->get(['id']);

        return [$state, $this->decode($legacy)];
    }

    private function requireLegacy(object $state): void
    {
        if ($state->mode !== 'legacy') {
            throw new RuntimeException('Chỉ backfill hoặc activate từ chế độ legacy.');
        }
    }

    private function requirePaused(array $settings): void
    {
        if (($settings['accepting_orders'] ?? true) !== false) {
            throw new RuntimeException('Phải tạm ngừng nhận đơn và đóng băng thao tác ghi trước khi chuyển đổi.');
        }
    }

    private function pauseBranches(): void
    {
        foreach (DB::table('branches')->orderBy('id')->get() as $branch) {
            $settings = array_replace($this->decode($branch->settings), ['accepting_orders' => false]);
            DB::table('branches')->where('id', $branch->id)->update(['settings' => $this->json($settings), 'updated_at' => now()]);
        }
    }

    private function fingerprint(): array
    {
        $orders = DB::table('orders');
        $items = DB::table('order_items');

        return [
            'orders' => [
                'count' => (clone $orders)->count(),
                'subtotal' => (string) (clone $orders)->sum('subtotal'),
                'discount' => (string) (clone $orders)->sum('discount'),
                'delivery_fee' => (string) (clone $orders)->sum('delivery_fee'),
                'total' => (string) (clone $orders)->sum('total'),
                'sha256' => $this->checksum('orders', ['branch_id', 'branch_snapshot', 'sales_context']),
            ],
            'items' => ['count' => (clone $items)->count(), 'quantity' => (string) (clone $items)->sum('quantity'), 'sha256' => $this->checksum('order_items')],
            'stock' => ['count' => DB::table('variants')->count(), 'available' => (string) DB::table('variants')->sum('stock'), 'sha256' => $this->checksum('variants')],
            'coupons' => ['count' => DB::table('coupons')->count(), 'used' => (string) DB::table('coupons')->sum('used'), 'sha256' => $this->checksum('coupons', ['branch_id'])],
            'movements' => ['count' => DB::table('stock_movements')->count(), 'sha256' => $this->checksum('stock_movements', ['branch_id'])],
        ];
    }

    private function checksum(string $table, array $exclude = []): string
    {
        $hash = hash_init('sha256');
        DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($hash, $exclude) {
            foreach ($rows as $row) {
                $data = array_diff_key((array) $row, array_flip($exclude));
                ksort($data);
                hash_update($hash, $this->json($data)."\n");
            }
        });

        return hash_final($hash);
    }

    private function report(array $baseline): array
    {
        $current = $this->fingerprint();
        $issues = [];
        if (! $baseline) {
            $issues[] = 'Chưa có baseline từ backfill.';
        } elseif ($this->json($baseline) !== $this->json($current)) {
            $issues[] = 'Số lượng, tổng tiền, tồn, lượt mã hoặc checksum dữ liệu cũ đã thay đổi.';
        }
        if (DB::table('orders')->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', '!=', 1)
            ->orWhereNull('branch_snapshot')->orWhereNull('sales_context'))->exists()) {
            $issues[] = 'Đơn cũ thiếu gán cơ sở hoặc snapshot.';
        }
        if (DB::table('stock_movements')->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', '!=', 1))->exists()) {
            $issues[] = 'Lịch sử tồn chưa gán cơ sở mặc định.';
        }
        $stockMismatch = DB::table('variants')->leftJoin('branch_variants', function ($join) {
            $join->on('branch_variants.variant_id', '=', 'variants.id')->where('branch_variants.branch_id', 1);
        })->where(fn ($query) => $query->whereNull('branch_variants.id')->orWhereColumn('variants.stock', '!=', 'branch_variants.stock'))->exists();
        if ($stockMismatch) {
            $issues[] = 'Tồn khả dụng cơ sở mặc định khác tồn cũ; không tự tính lại phần giữ.';
        }
        if (DB::table('users')->where('role', 'staff')->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('branch_memberships')->whereColumn('branch_memberships.user_id', 'users.id')->where('branch_id', 1);
        })->exists()) {
            $issues[] = 'Nhân viên cũ thiếu phân công cơ sở mặc định.';
        }
        $expectedSlots = $this->slotCounts();
        $actualSlots = DB::table('branch_slot_locks')->where('branch_id', 1)->where('booked', '>', 0)->orderBy('scheduled_at')->pluck('booked', 'scheduled_at')->map(fn ($value) => (int) $value)->all();
        if ($expectedSlots !== $actualSlots) {
            $issues[] = 'Bộ đếm khung giờ không khớp số đơn chưa hủy.';
        }
        $legacySettings = $this->decode(DB::table('store_settings')->where('id', 1)->value('data'));
        if (! $this->configurationMatches($legacySettings)) {
            $issues[] = 'Cấu hình chuỗi/cơ sở không còn khớp cấu hình cũ hoặc có trường sai phạm vi.';
        }

        return ['passed' => $issues === [], 'issues' => $issues, 'baseline' => $baseline, 'current' => $current, 'checked_at' => now()->toIso8601String()];
    }

    private function rollbackIssues(array $legacy): array
    {
        $issues = [];
        foreach (['orders', 'stock_movements', 'coupons'] as $table) {
            if (DB::table($table)->where('branch_id', '!=', 1)->exists()) {
                $issues[] = 'Đã có dữ liệu '.$table.' thuộc cơ sở khác.';
            }
        }
        if (DB::table('branch_variants')->where('branch_id', '!=', 1)->where(fn ($query) => $query->where('stock', '!=', 0)->orWhereNotNull('price')->orWhereNotNull('prep_minutes'))->exists()) {
            $issues[] = 'Cơ sở khác có tồn hoặc cấu hình phiên bản riêng.';
        }
        if (DB::table('branch_memberships')->where('branch_id', '!=', 1)->exists()) {
            $issues[] = 'Đã có phân công nhân viên cơ sở khác.';
        }
        $mismatch = DB::table('branch_variants')->join('variants', 'variants.id', '=', 'branch_variants.variant_id')->where('branch_id', 1)
            ->where(fn ($query) => $query->whereColumn('branch_variants.stock', '!=', 'variants.stock')
                ->orWhereColumn('branch_variants.active', '!=', 'variants.active')
                ->orWhere(fn ($query) => $query->whereNotNull('branch_variants.price')->whereColumn('branch_variants.price', '!=', 'variants.price'))
                ->orWhereNotNull('branch_variants.prep_minutes'))->exists();
        $missing = DB::table('variants')->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('branch_variants')->whereColumn('variant_id', 'variants.id')->where('branch_id', 1);
        })->exists();
        if ($mismatch || $missing) {
            $issues[] = 'Tồn, trạng thái bán, giá hoặc thời gian chuẩn bị cơ sở mặc định không tương thích dữ liệu cũ.';
        }
        if (! $this->configurationMatches($legacy)) {
            $issues[] = 'Cấu hình cơ sở/chuỗi khác cấu hình cũ; phải đối chiếu trước khi quay lui.';
        }

        return $issues;
    }

    private function slotCounts(): array
    {
        return DB::table('orders')->where('branch_id', 1)->where('status', '!=', 'cancelled')
            ->selectRaw('scheduled_at, COUNT(*) as booked')->groupBy('scheduled_at')->orderBy('scheduled_at')
            ->pluck('booked', 'scheduled_at')->map(fn ($value) => (int) $value)->all();
    }

    private function configurationMatches(array $legacy): bool
    {
        $branch = $this->decode(DB::table('branches')->where('id', 1)->value('settings'));
        $chain = $this->decode(DB::table('chain_settings')->where('id', 1)->value('data'));
        $brandKeys = array_flip(Store::BRAND_KEYS);
        if (array_diff_key($chain, $brandKeys) || array_intersect_key($branch, $brandKeys)) {
            return false;
        }
        $merged = array_replace($chain, $branch);
        ksort($merged);
        ksort($legacy);

        return $this->json($merged) === $this->json($legacy);
    }

    private function saveReport(array $report): void
    {
        DB::table('branch_migration_state')->where('id', 1)->update([
            'verified_at' => $report['passed'] ? now() : null,
            'verification' => $this->json($report), 'updated_at' => now(),
        ]);
    }

    private function audit(string $action, array $data): void
    {
        DB::table('branch_audits')->insert([
            'branch_id' => 1, 'actor_id' => null, 'action' => $action,
            'data' => $this->json($data), 'created_at' => now(),
        ]);
    }

    private function json(array $data): string
    {
        return json_encode($this->canonical($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonical(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonical($value);
            }
        }
        if (! array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }

    private function decode(?string $data): array
    {
        return $data === null ? [] : json_decode($data, true, flags: JSON_THROW_ON_ERROR);
    }
}
