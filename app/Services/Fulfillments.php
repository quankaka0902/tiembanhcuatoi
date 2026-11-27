<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Fulfillments
{
    public const STATUSES = ['pending' => 'Chờ điều phối', 'assigned' => 'Đã phân công', 'ready' => 'Sẵn sàng bàn giao',
        'in_transit' => 'Đang giao', 'delivered' => 'Đã giao bánh', 'failed' => 'Giao chưa thành công',
        'cancelled' => 'Đã hủy công việc', 'collected' => 'Khách đã nhận bánh'];

    private ?array $managed = null;

    public static function managing(int $orderId, int $actorId, string $value): bool
    {
        return app(self::class)->managed === [$orderId, $actorId, $value];
    }

    private static function withManaged(object $order, User $actor, string $value, string $reason): void
    {
        $context = app(self::class);
        $before = $context->managed;
        $context->managed = [(int) $order->id, (int) $actor->id, $value];
        try {
            Orders::change($order->id, $actor, ['kind' => 'status', 'value' => $value, 'note' => $reason,
                'expected_status' => $order->status]);
        } finally {
            $context->managed = $before;
        }
    }

    public static function query(): Builder
    {
        return Branches::scope(DB::table('fulfillment_jobs'), column: 'fulfillment_jobs.branch_id');
    }

    /** Called only inside checkout's transaction. Existing orders require explicit staff review. */
    public static function createForOrder(object $order): void
    {
        if (! Branches::enabled()) {
            return;
        }
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Tạo công việc cùng transaction của đơn.');
        }
        DB::table('fulfillment_jobs')->insertOrIgnore([
            'order_id' => $order->id, 'branch_id' => $order->branch_id ?? 1, 'kind' => $order->fulfillment,
            'status' => 'pending', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function ensure(int $orderId, User $actor, array $fields): object
    {
        $fields = Validator::make($fields, ['request_key' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($orderId, $actor, $fields) {
            Branches::authorize($actor, 'dispatch');
            Store::settings(lock: true, shared: true);
            [$command, $replay] = self::command($actor, 'ensure', ['order_id' => $orderId, ...$fields]);
            if ($replay) {
                return self::query()->find($command->result_id);
            }
            $order = Orders::query()->lockForUpdate()->find($orderId);
            abort_unless($order, 404);
            $job = self::query()->where('order_id', $orderId)->lockForUpdate()->first();
            if (! $job) {
                self::createForOrder($order);
                $state = match ($order->status) {
                    'cancelled' => 'cancelled', 'completed' => $order->fulfillment === 'pickup' ? 'collected' : 'delivered',
                    'shipping' => 'in_transit', 'delivery_failed' => 'failed',
                    'ready', 'uncollected' => $order->fulfillment === 'pickup' ? 'ready' : 'pending', default => 'pending',
                };
                DB::table('fulfillment_jobs')->where('order_id', $orderId)->update(['status' => $state, 'legacy_inferred' => true]);
                $job = self::query()->where('order_id', $orderId)->lockForUpdate()->first();
                self::event($job, $actor->id, 'legacy_review', null, 'Nhân viên tiếp nhận trạng thái hiện tại; không suy ra bằng chứng hoặc thời gian giao cũ.');
            }
            self::finishCommand($command, $job->id);

            return $job;
        }, 5);
    }

    public static function assignTrip(User $actor, array $fields): object
    {
        $fields = Validator::make($fields, ['order_ids' => 'required|array|min:1|max:30', 'order_ids.*' => 'required|integer|min:1|distinct',
            'courier_id' => 'required|integer|min:1', 'request_key' => 'required|uuid'])->validate();
        sort($fields['order_ids']);

        return DB::transaction(function () use ($actor, $fields) {
            self::lockPeople([$actor->id, $fields['courier_id']]);
            Branches::authorize($actor, 'dispatch');
            $courier = User::find($fields['courier_id']);
            abort_unless($courier && Branches::role($courier) === 'courier' && Branches::can($courier, 'driver'), 403);
            Store::settings(lock: true, shared: true);
            [$command, $replay] = self::command($actor, 'assign', $fields);
            if ($replay) {
                return DB::table('delivery_trips')->where('branch_id', Branches::id())->find($command->result_id);
            }
            $orders = Orders::query()->whereIn('orders.id', $fields['order_ids'])->orderBy('orders.id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($orders->count() === count($fields['order_ids']), 404);
            $jobs = self::query()->whereIn('order_id', $fields['order_ids'])->orderBy('order_id')->lockForUpdate()->get();
            if ($jobs->count() !== $orders->count()) {
                self::invalid('order_ids', 'Tiếp nhận công việc cho từng đơn cũ trước khi phân chuyến.');
            }
            foreach ($jobs as $job) {
                $order = $orders[$job->order_id];
                if ($job->kind !== 'delivery' || $job->trip_id || $job->courier_id || in_array($order->status, ['cancelled', 'completed'], true)
                    || (! in_array($job->status, ['pending', 'ready'], true) && ! ($job->legacy_inferred && $job->status === 'in_transit'))) {
                    self::invalid('order_ids', 'Chỉ ghép các công việc giao của cùng cơ sở, chưa được phân chuyến.');
                }
                if ($job->status === 'in_transit') {
                    self::assertMoney($order);
                }
            }
            $adapter = match (config('bakery.dispatch_adapter', 'manual')) {
                'manual' => new ManualDispatchAdapter, 'fake' => new FakeDispatchAdapter,
                default => throw ValidationException::withMessages(['adapter' => 'Chỉ có điều phối nội bộ hoặc bộ giả lập local/test.']),
            };
            $manifest = $adapter->manifest($fields['order_ids']);
            $tripId = DB::table('delivery_trips')->insertGetId(['branch_id' => Branches::id(), 'courier_id' => $courier->id,
                'order_ids' => json_encode($fields['order_ids'], JSON_THROW_ON_ERROR),
                'order_codes' => json_encode($orders->pluck('code')->values()->all(), JSON_THROW_ON_ERROR),
                'code' => 'CG-'.now()->format('ymd').'-'.Str::upper(Str::random(8)), 'adapter' => $manifest['adapter'],
                'adapter_reference' => $manifest['reference'], 'created_at' => now(), 'updated_at' => now()]);
            foreach ($jobs as $job) {
                self::update($job, $actor, 'assigned', ['trip_id' => $tripId, 'courier_id' => $courier->id,
                    'status' => in_array($job->status, ['ready', 'in_transit'], true) ? $job->status : 'assigned']);
            }
            self::finishCommand($command, $tripId);

            return DB::table('delivery_trips')->find($tripId);
        }, 5);
    }

    public static function change(int $jobId, User $actor, array $fields): object
    {
        $fields = Validator::make($fields, self::rules())->validate();
        $hint = self::query()->when(! Branches::can($actor, 'dispatch'), fn ($query) => $query->where('courier_id', $actor->id))->find($jobId);
        abort_unless($hint, 404);

        return DB::transaction(function () use ($jobId, $actor, $fields, $hint) {
            self::lockPeople([$actor->id, $hint->courier_id]);
            $staff = Branches::can($actor, 'dispatch');
            Branches::authorize($actor, $staff ? 'dispatch' : 'driver');
            Store::settings(lock: true, shared: true);
            [$command, $replay] = self::command($actor, 'job:'.$jobId, $fields);
            if ($replay) {
                $job = self::query()->find($command->result_id);
                abort_unless($staff || (int) $job?->courier_id === (int) $actor->id, 404);

                return $job;
            }
            $order = Orders::query()->lockForUpdate()->find($hint->order_id);
            abort_unless($order, 404);
            $job = self::query()->lockForUpdate()->find($jobId);
            abort_unless($job && ($staff || (int) $job->courier_id === (int) $actor->id), 404);
            if ((int) $job->version !== (int) $fields['expected_version']) {
                self::invalid('expected_version', 'Công việc vừa thay đổi. Tải lại trước khi xử lý.');
            }
            if (! in_array($fields['action'], array_column(self::actions($job, $order, $actor), 'key'), true)) {
                self::invalid('action', 'Thao tác không còn phù hợp hoặc tài khoản chưa được cấp quyền.');
            }
            $action = $fields['action'];
            $reason = in_array($action, ['failed', 'cancel', 'retry'], true) ? self::reason($fields) : null;
            if (in_array($action, ['delivered', 'collect'], true)) {
                self::evidence($fields);
            }
            if ($action === 'ready') {
                self::update($job, $actor, 'ready', ['status' => 'ready', 'ready_at' => now()]);
                if ($job->kind === 'pickup' && $order->status === 'preparing') {
                    Orders::change($order->id, $actor, ['kind' => 'status', 'value' => 'ready', 'expected_status' => $order->status]);
                }
            } elseif ($action === 'start') {
                $courier = User::find($job->courier_id);
                abort_unless($courier && Branches::role($courier) === 'courier', 403);
                self::assertMoney($order);
                if (self::contact($order)['address_status'] !== 'available') {
                    self::invalid('action', 'Nhân viên cần kiểm tra địa chỉ nhận trước khi xuất giao.');
                }
                self::update($job, $actor, 'start', ['status' => 'in_transit', 'started_at' => now(), 'attempt_count' => $job->attempt_count + 1]);
                if ($order->status !== 'shipping') {
                    self::withManaged($order, $actor, 'shipping', 'Xuất giao theo công việc #'.$jobId.'.');
                }
            } elseif (in_array($action, ['delivered', 'failed', 'cancel', 'collect'], true)) {
                if ($action === 'collect') {
                    self::assertMoney($order);
                }
                $state = ['collect' => 'collected', 'cancel' => 'cancelled'][$action] ?? $action;
                if ($job->status === 'in_transit' || $action === 'collect') {
                    self::attempt($job, $actor, $state, $fields, $reason);
                }
                self::update($job, $actor, $action, ['status' => $state, $state.'_at' => now(),
                    ...($job->status === 'in_transit' || $action === 'collect' ? ['attempt_count' => max(1, $job->attempt_count)] : [])], $reason);
                if (in_array($action, ['failed', 'cancel'], true) && $job->status === 'in_transit' && $order->status === 'shipping') {
                    self::withManaged($order, $actor, 'delivery_failed', $reason);
                }
            } else {
                self::update($job, $actor, 'retry', ['status' => 'pending', 'trip_id' => null, 'courier_id' => null,
                    'ready_at' => null, 'started_at' => null], $reason);
            }
            self::finishCommand($command, $jobId);

            return self::query()->find($jobId);
        }, 5);
    }

    public static function rules(): array
    {
        return ['action' => 'required|in:ready,start,delivered,failed,cancel,retry,collect', 'expected_version' => 'required|integer|min:1',
            'request_key' => 'required|uuid', 'reason' => 'nullable|string|max:250', 'reference' => 'nullable|string|max:120', 'verified' => 'nullable|boolean'];
    }

    public static function indexData(User $actor, array $filters): array
    {
        $manage = Branches::can($actor, 'dispatch');
        Branches::authorize($actor, $manage ? 'dispatch' : 'driver');
        $jobs = self::query()->when(! $manage, fn ($query) => $query->where('courier_id', $actor->id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->orderByDesc('id')->paginate(15)->withQueryString()->through(function ($job) use ($actor) {
                $order = Orders::query()->find($job->order_id);

                return self::project($job, $order, $actor);
            });
        $trips = DB::table('delivery_trips')->where('branch_id', Branches::id())
            ->when(! $manage, fn ($query) => $query->where('courier_id', $actor->id))->orderByDesc('id')->limit(25)->get()
            ->map(function ($trip) {
                $jobs = self::query()->where('trip_id', $trip->id)->get();
                $active = $jobs->whereNotIn('status', ['delivered', 'failed', 'cancelled']);
                $state = $jobs->isEmpty() || $active->isEmpty() ? 'completed' : ($jobs->contains('status', 'in_transit') ? 'in_transit' : 'assigned');

                return ['id' => $trip->id, 'code' => $trip->code, 'version' => $trip->version, 'status' => $state,
                    'courier_name' => DB::table('users')->where('id', $trip->courier_id)->value('name'),
                    'order_codes' => json_decode($trip->order_codes, true, flags: JSON_THROW_ON_ERROR), 'eta_label' => 'Chưa có ước tính'];
            });

        return ['jobs' => $jobs, 'trips' => $trips, 'courierOnly' => ! $manage, 'canManage' => $manage,
            'statuses' => self::STATUSES, 'filters' => $filters,
            'drivers' => $manage ? DB::table('branch_memberships')->join('users', 'users.id', '=', 'branch_memberships.user_id')
                ->where('branch_memberships.branch_id', Branches::id())->where('branch_memberships.role', 'courier')
                ->where('branch_memberships.active', true)->where('users.active', true)->where('users.role', 'staff')
                ->orderBy('users.name')->get(['users.id', 'users.name']) : [],
            'eligibleOrders' => $manage ? Orders::query()->whereNotIn('status', ['completed', 'cancelled'])
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('fulfillment_jobs')->whereColumn('fulfillment_jobs.order_id', 'orders.id'))
                ->orderBy('scheduled_at')->limit(50)->get(['id', 'code', 'fulfillment', 'status', 'scheduled_at']) : []];
    }

    public static function publicData(object $order): ?array
    {
        $job = self::job($order);
        if (! $job) {
            return null;
        }

        return ['kind' => $job->kind, 'status' => $job->status, 'label' => self::STATUSES[$job->status],
            'eta_label' => 'Chưa có ước tính', 'ready_at' => $job->ready_at, 'delivered_at' => $job->delivered_at,
            'collected_at' => $job->collected_at, 'legacy_inferred' => (bool) $job->legacy_inferred,
            'events' => DB::table('fulfillment_events')->where('job_id', $job->id)->orderBy('id')->get(['action', 'status', 'created_at'])];
    }

    public static function detail(object $order, User $actor): ?array
    {
        abort_unless((int) ($order->branch_id ?? 1) === Branches::id(), 404);
        $job = self::job($order);
        if (! $job) {
            return null;
        }
        if (! Branches::can($actor, 'dispatch') && ! Branches::can($actor, 'driver')) {
            return self::publicData($order);
        }
        abort_unless(Branches::can($actor, 'dispatch') || (int) $job->courier_id === (int) $actor->id, 404);
        $data = self::project($job, $order, $actor);
        if (Branches::can($actor, 'dispatch')) {
            $data['history'] = DB::table('fulfillment_events')->where('job_id', $job->id)->orderBy('id')->get(['action', 'previous_status', 'status', 'version', 'reason', 'created_at']);
            $data['attempts'] = DB::table('fulfillment_attempts')->where('job_id', $job->id)->orderBy('sequence')->get()
                ->map(fn ($row) => ['sequence' => $row->sequence, 'outcome' => $row->outcome, 'started_at' => $row->started_at,
                    'finished_at' => $row->finished_at, 'reason' => $row->reason,
                    'evidence' => $row->evidence ? json_decode(Crypt::decryptString($row->evidence), true, flags: JSON_THROW_ON_ERROR) : null]);
        }

        return $data;
    }

    private static function project(object $job, object $order, User $actor): array
    {
        $manage = Branches::can($actor, 'dispatch');
        $active = ! in_array($job->status, ['delivered', 'failed', 'cancelled', 'collected'], true);
        $contact = ['name' => $order->name];
        if ($manage || $active) {
            $contact = self::contact($order);
        }

        return ['id' => $job->id, 'order_id' => $order->id, 'order_code' => $order->code, 'kind' => $job->kind,
            'status' => $job->status, 'version' => $job->version, 'scheduled_at' => $order->scheduled_at,
            'trip_id' => $job->trip_id, 'courier_name' => $manage && $job->courier_id ? DB::table('users')->where('id', $job->courier_id)->value('name') : null,
            'contact' => $contact, 'cod_amount' => ($manage || $active) && $order->method === 'cod' && $order->payment === 'unpaid' ? $order->total : 0,
            'actions' => self::actions($job, $order, $actor), 'attempt_count' => $job->attempt_count,
            'can_assign' => $manage && $job->kind === 'delivery' && ! $job->trip_id && ! $job->courier_id
                && (in_array($job->status, ['pending', 'ready'], true) || ($job->legacy_inferred && $job->status === 'in_transit')),
            'eta_label' => 'Chưa có ước tính'];
    }

    private static function contact(object $order): array
    {
        $address = null;
        $note = null;
        $status = 'available';
        if ($order->fulfillment === 'delivery') {
            if ($order->delivery_snapshot ?? null) {
                try {
                    $snapshot = DeliveryAddress::open($order->delivery_snapshot);
                    $address = $snapshot['address_line'] ?? null;
                    $note = $snapshot['delivery_note'] ?? null;
                } catch (DecryptException|\JsonException $exception) {
                    $status = 'needs_review';
                }
            } else {
                $address = $order->address;
            }
            if (! filled($address)) {
                $status = 'needs_review';
            }
        }

        return ['name' => $order->name, 'phone' => $order->phone, 'address' => $address,
            'delivery_note' => $note, 'address_status' => $status];
    }

    private static function actions(object $job, object $order, User $actor): array
    {
        $manage = Branches::can($actor, 'dispatch');
        $driver = Branches::can($actor, 'driver') && (int) $job->courier_id === (int) $actor->id;
        if ((! $manage && ! $driver) || in_array($order->status, ['cancelled', 'completed'], true)) {
            return [];
        }
        $keys = [];
        if ($manage && in_array($job->status, ['pending', 'assigned'], true)
            && in_array($order->status, $job->kind === 'pickup' ? ['preparing', 'ready', 'uncollected'] : ['preparing', 'delivery_failed'], true)) {
            $keys[] = 'ready';
        }
        if ($job->kind === 'delivery' && $job->status === 'ready' && $job->trip_id && $job->courier_id) {
            $keys[] = 'start';
        }
        if ($job->kind === 'delivery' && $job->status === 'in_transit') {
            array_push($keys, 'delivered', 'failed');
        }
        if ($manage && ! in_array($job->status, ['delivered', 'collected', 'cancelled'], true)) {
            $keys[] = 'cancel';
        }
        if ($manage && in_array($job->status, ['failed', 'cancelled'], true)) {
            $keys[] = 'retry';
        }
        if ($manage && $job->kind === 'pickup' && $job->status === 'ready' && in_array($order->status, ['ready', 'uncollected'], true)) {
            $keys[] = 'collect';
        }
        $labels = ['ready' => 'Sẵn sàng bàn giao', 'start' => 'Xuất giao', 'delivered' => 'Đã giao bánh', 'failed' => 'Giao chưa thành công',
            'cancel' => 'Hủy công việc', 'retry' => 'Tạo lại lượt xử lý', 'collect' => 'Xác nhận khách đã nhận'];

        return array_map(fn ($key) => ['key' => $key, 'label' => $labels[$key],
            'requires_reason' => in_array($key, ['failed', 'cancel', 'retry'], true),
            'requires_reference' => in_array($key, ['delivered', 'collect'], true),
            'requires_verified' => in_array($key, ['delivered', 'collect'], true)], $keys);
    }

    public static function assertTransition(object $order, string $kind, string $value): void
    {
        if ($kind !== 'status') {
            return;
        }
        $job = self::job($order, lock: DB::transactionLevel() > 0);
        if (! $job) {
            return;
        }
        $allowed = match ($value) {
            'shipping' => $job->kind === 'delivery' && $job->status === 'in_transit',
            'delivery_failed' => in_array($job->status, ['failed', 'cancelled'], true),
            'completed' => $job->status === ($job->kind === 'pickup' ? 'collected' : 'delivered'), default => true,
        };
        if (! $allowed) {
            self::invalid('value', 'Hoàn tất bàn giao hoặc giao nhận qua màn hình điều phối trước khi đổi trạng thái đơn.');
        }
    }

    public static function onOrderChanged(object $order, string $kind, string $value, int $actorId, ?int $eventId = null): void
    {
        if ($kind !== 'status') {
            return;
        }
        $job = self::job($order, lock: true);
        if (! $job) {
            return;
        }
        $actor = User::find($actorId);
        if ($value === 'ready' && $job->kind === 'pickup' && $job->status === 'pending') {
            self::update($job, $actor, 'ready', ['status' => 'ready', 'ready_at' => now()], orderEventId: $eventId);
        } elseif ($value === 'cancelled' && ! in_array($job->status, ['delivered', 'collected', 'cancelled'], true)) {
            if ($job->status === 'in_transit') {
                self::attempt($job, $actor, 'cancelled', [], 'Đơn đã hủy theo quy trình đơn hàng.');
            }
            self::update($job, $actor, 'cancel', ['status' => 'cancelled', 'cancelled_at' => now()],
                'Đơn đã hủy theo quy trình đơn hàng.', $eventId, notify: false);
        }
    }

    public static function assertTransferable(object $order): void
    {
        $job = self::job($order, lock: true);
        if ($job && ($job->courier_id || $job->trip_id || $job->attempt_count > 0 || ! in_array($job->status, ['pending', 'cancelled'], true))) {
            self::invalid('order', 'Đơn đã điều phối hoặc đã có lượt giao/nhận; không chuyển cơ sở.');
        }
    }

    public static function transferToBranch(object $order, int $targetBranchId, string $newSlot): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Chuyển công việc cùng transaction chuyển đơn.');
        }
        $job = DB::table('fulfillment_jobs')->where('order_id', $order->id)->lockForUpdate()->first();
        if ($job) {
            if ($job->courier_id || $job->trip_id || $job->attempt_count > 0 || ! in_array($job->status, ['pending', 'cancelled'], true)) {
                self::invalid('order', 'Công việc đã điều phối; không chuyển cơ sở.');
            }
            DB::table('fulfillment_jobs')->where('id', $job->id)->update(['branch_id' => $targetBranchId, 'version' => $job->version + 1, 'updated_at' => now()]);
            self::event((object) array_replace((array) $job, ['version' => $job->version + 1]), null, 'branch_transfer', $job->status,
                'Chuyển cơ sở theo quyết định chuyển đơn đã được duyệt.');
        }
    }

    private static function job(object $order, bool $lock = false): ?object
    {
        $query = DB::table('fulfillment_jobs')->where('order_id', $order->id)->where('branch_id', $order->branch_id ?? 1);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private static function update(object $job, User $actor, string $action, array $changes, ?string $reason = null, ?int $orderEventId = null, bool $notify = true): void
    {
        $previous = $job->status;
        $changes += ['version' => $job->version + 1, 'updated_at' => now()];
        DB::table('fulfillment_jobs')->where('id', $job->id)->update($changes);
        $next = (object) array_replace((array) $job, $changes);
        $eventId = self::event($next, $actor->id, $action, $previous, $reason);
        $type = match ($action) {
            'assigned' => 'dispatch_assigned', 'start' => 'dispatch_started', 'delivered' => 'dispatch_delivered',
            'failed' => 'dispatch_failed', 'cancel' => 'dispatch_cancelled', 'collect' => 'pickup_collected',
            'ready' => $job->kind === 'pickup' ? 'pickup_ready' : null, default => null,
        };
        if ($type && $notify) {
            $orderEventId ??= Orders::event($job->order_id, $actor->id, 'fulfillment', $action, $reason, $previous);
            OrderNotices::record($job->order_id, $type, [], $orderEventId, 'fulfillment:'.$eventId);
        }
    }

    private static function event(object $job, ?int $actorId, string $action, ?string $previous, ?string $reason = null): int
    {
        return DB::table('fulfillment_events')->insertGetId(['job_id' => $job->id, 'actor_id' => $actorId, 'action' => $action,
            'trip_id' => $job->trip_id, 'courier_id' => $job->courier_id,
            'previous_status' => $previous, 'status' => $job->status, 'version' => $job->version, 'reason' => $reason, 'created_at' => now()]);
    }

    private static function attempt(object $job, User $actor, string $outcome, array $fields, ?string $reason): void
    {
        $evidence = in_array($outcome, ['delivered', 'collected'], true) ? Crypt::encryptString(json_encode([
            'reference' => trim($fields['reference']), 'verified' => true, 'actor_id' => $actor->id, 'at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR)) : null;
        DB::table('fulfillment_attempts')->insert(['job_id' => $job->id, 'sequence' => max(1, $job->attempt_count),
            'trip_id' => $job->trip_id, 'courier_id' => $job->courier_id, 'actor_id' => $actor->id, 'outcome' => $outcome,
            'started_at' => $job->started_at, 'finished_at' => now(), 'evidence' => $evidence, 'reason' => $reason]);
    }

    private static function assertMoney(object $order): void
    {
        if (Orders::prepayRequired($order) && $order->payment !== 'paid' && ! $order->prepayment_waived_at) {
            self::invalid('action', 'Đơn yêu cầu trả trước phải được đối soát trước khi xuất giao hoặc bàn giao bánh.');
        }
    }

    private static function evidence(array $fields): void
    {
        if (! ($fields['verified'] ?? false) || mb_strlen(trim($fields['reference'] ?? '')) < 3) {
            self::invalid('reference', 'Nhập mã/xác nhận bàn giao và xác nhận đã đối chiếu người nhận (tối thiểu 3 ký tự).');
        }
    }

    private static function reason(array $fields): string
    {
        $reason = trim($fields['reason'] ?? '');
        if (mb_strlen($reason) < 3) {
            self::invalid('reason', 'Cần lý do từ 3 đến 250 ký tự.');
        }

        return $reason;
    }

    private static function lockPeople(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids);
        DB::table('users')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
        DB::table('branch_memberships')->whereIn('user_id', $ids)->where('branch_id', Branches::id())->orderBy('user_id')->lockForUpdate()->get(['id']);
    }

    private static function command(User $actor, string $kind, array $fields): array
    {
        $key = hash('sha256', $fields['request_key']);
        unset($fields['request_key']);
        $hash = hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
        DB::table('dispatch_commands')->insertOrIgnore(['request_key' => $key, 'branch_id' => Branches::id(),
            'actor_id' => $actor->id, 'kind' => $kind, 'payload_hash' => $hash, 'created_at' => now()]);
        $row = DB::table('dispatch_commands')->where('request_key', $key)->lockForUpdate()->first();
        if ((int) $row->branch_id !== Branches::id() || (int) $row->actor_id !== (int) $actor->id || $row->kind !== $kind || ! hash_equals($row->payload_hash, $hash)) {
            self::invalid('request_key', 'Mã thao tác đã được dùng cho nội dung khác. Tải lại trước khi thử lại.');
        }

        return [$row, $row->result_id !== null];
    }

    private static function finishCommand(object $command, int $result): void
    {
        DB::table('dispatch_commands')->where('id', $command->id)->update(['result_id' => $result]);
    }

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
