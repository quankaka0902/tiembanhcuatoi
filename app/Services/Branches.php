<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Request-scoped selection; a posted order or branch identifier never grants access. */
class Branches
{
    private ?int $selected = null;

    private bool $schemaReady = false;

    public static function enabled(): bool
    {
        $context = app(self::class);
        if (! $context->schemaReady && ! Schema::hasTable('branch_migration_state')) {
            return false;
        }
        $context->schemaReady = true;

        return DB::table('branch_migration_state')->where('id', 1)->value('mode') === 'branch';
    }

    public static function id(): int
    {
        return self::enabled() ? (app(self::class)->selected ?? 1) : 1;
    }

    public static function use(int $id): void
    {
        abort_unless($id > 0 && (! self::enabled() ? $id === 1 : DB::table('branches')->where('id', $id)->exists()), 404);
        app(self::class)->selected = $id;
    }

    public static function run(int $id, Closure $callback): mixed
    {
        $before = app(self::class)->selected;
        self::use($id);
        try {
            return $callback();
        } finally {
            app(self::class)->selected = $before;
        }
    }

    public static function find(?int $id = null): ?object
    {
        return DB::table('branches')->find($id ?? self::id());
    }

    public static function allowedIds(User $user): array
    {
        $user = self::freshUser($user);
        if (! $user?->active || ! $user->isStaff()) {
            return [];
        }
        if (! self::enabled()) {
            return [1];
        }

        return ($user->role === 'admin'
            ? DB::table('branches')->orderBy('id')->pluck('id')
            : DB::table('branch_memberships')->where('user_id', $user->id)->where('active', true)->orderBy('branch_id')->pluck('branch_id'))
            ->map(fn ($id) => (int) $id)->all();
    }

    public static function role(User $user, ?int $branchId = null): ?string
    {
        $user = self::freshUser($user);
        if (! $user?->active || ! $user->isStaff()) {
            return null;
        }
        if ($user->role === 'admin') {
            return 'chain_admin';
        }
        if (! self::enabled()) {
            return 'staff';
        }

        $query = DB::table('branch_memberships')->where('branch_id', $branchId ?? self::id())
            ->where('user_id', $user->id)->where('active', true);

        return (DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query)->value('role');
    }

    public static function can(User $user, string $action, ?int $branchId = null): bool
    {
        $user = self::freshUser($user);
        $role = $user ? self::role($user, $branchId) : null;
        if ($role === 'chain_admin') {
            return true;
        }
        if (! $role) {
            return false;
        }
        if (in_array($action, ['chain', 'staff', 'catalog', 'shared_catalog', 'manage_branches'], true)) {
            return false;
        }
        if ($action === 'reconcile') {
            if ($role === 'kitchen') {
                return false;
            }
            if (! self::enabled()) {
                return (bool) $user->can_reconcile;
            }
            $query = DB::table('branch_memberships')->where('branch_id', $branchId ?? self::id())
                ->where('user_id', $user->id)->where('active', true);

            return (bool) (DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query)->value('can_reconcile');
        }
        if (in_array($action, ['configure', 'branch_settings', 'inventory', 'branch_catalog'], true)) {
            return $role === 'manager';
        }
        if ($action === 'production') {
            return in_array($role, ['manager', 'staff', 'kitchen'], true);
        }
        if (in_array($action, ['reports', 'export', 'view_orders'], true)) {
            return in_array($role, ['manager', 'staff', 'accountant'], true);
        }

        return $action === 'orders' && in_array($role, ['manager', 'staff'], true);
    }

    public static function isManager(User $user, ?int $branchId = null): bool
    {
        return self::can($user, 'configure', $branchId);
    }

    public static function authorize(User $user, string $action, ?int $branchId = null): void
    {
        // Permission revocation takes these locks in the same order as a mutation.
        if (DB::transactionLevel() > 0) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            if (self::enabled()) {
                DB::table('branch_memberships')->where('user_id', $user->id)->where('branch_id', $branchId ?? self::id())->lockForUpdate()->first();
            }
        }
        abort_unless(self::can($user, $action, $branchId), 403);
    }

    public static function scope(Builder $query, ?User $user = null, string $column = 'branch_id'): Builder
    {
        if ($user) {
            abort_unless(in_array(self::id(), self::allowedIds($user), true), 403);
        }

        return $query->where(function (Builder $query) use ($column) {
            $query->where($column, self::id());
            if (self::id() === 1) {
                $query->orWhereNull($column);
            }
        });
    }

    public static function snapshot(?int $id = null): array
    {
        $id ??= self::id();
        $settings = Store::settings(branchId: $id);
        $query = DB::table('branches')->where('id', $id);
        $branch = (DB::transactionLevel() > 0 ? $query->sharedLock() : $query)->first();

        return ['id' => $id, 'code' => $branch?->code ?? 'MAIN', 'name' => $branch?->name ?? 'Cơ sở hiện tại',
            'brand' => $settings['name'], 'address' => $settings['address'], 'phone' => $settings['phone'],
            'timezone' => $settings['timezone'], 'provenance' => 'at_purchase'];
    }

    public static function lockSlots(array $slots, ?int $branchId = null): void
    {
        if (! self::enabled()) {
            return;
        }
        $branchId ??= self::id();
        $slots = array_values(array_unique($slots));
        sort($slots);
        foreach ($slots as $slot) {
            DB::table('branch_slot_locks')->insertOrIgnore(['branch_id' => $branchId, 'scheduled_at' => $slot, 'booked' => 0]);
            DB::table('branch_slot_locks')->where('branch_id', $branchId)->where('scheduled_at', $slot)->lockForUpdate()->first();
        }
    }

    public static function bookSlot(string $slot, int $delta, ?int $branchId = null): void
    {
        if (! self::enabled()) {
            return;
        }
        $query = DB::table('branch_slot_locks')->where('branch_id', $branchId ?? self::id())->where('scheduled_at', $slot);
        $changed = $delta < 0 ? $query->where('booked', '>=', -$delta)->decrement('booked', -$delta) : $query->increment('booked', $delta);
        if ($changed !== 1) {
            throw new \RuntimeException('Sai số khung giờ cơ sở; cần đối chiếu trước khi tiếp tục.');
        }
    }

    public static function audit(string $action, array $data = [], ?int $actorId = null, ?int $branchId = null): void
    {
        DB::table('branch_audits')->insert(['actor_id' => $actorId, 'branch_id' => $branchId,
            'action' => $action, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private static function freshUser(User $user): ?User
    {
        $query = User::query()->whereKey($user->id);

        return (DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query)->first();
    }
}
