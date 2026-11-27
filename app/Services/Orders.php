<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class Orders
{
    public const STATUSES = [
        'pending' => 'Chờ xác nhận', 'confirmed' => 'Đã xác nhận', 'preparing' => 'Đang chuẩn bị',
        'ready' => 'Sẵn sàng nhận', 'shipping' => 'Đang giao', 'delivery_failed' => 'Giao chưa thành công',
        'uncollected' => 'Khách chưa nhận', 'completed' => 'Hoàn tất', 'cancelled' => 'Đã hủy',
    ];

    public const PAYMENTS = ['unpaid' => 'Chưa thanh toán', 'paid' => 'Đã thanh toán', 'refunded' => 'Đã hoàn tiền'];

    public static function query(): Builder
    {
        return Branches::enabled() ? Branches::scope(DB::table('orders'), column: 'orders.branch_id')
            : DB::table('orders')->where('orders.location_id', 1);
    }

    public static function scheduled(): Builder
    {
        return self::query()->where('status', '<>', 'cancelled');
    }

    public static function bankDueAt(CarbonInterface $createdAt, string $scheduledAt, int $minutes): string
    {
        return min($createdAt->toImmutable()->addMinutes($minutes)->toDateTimeString(), $scheduledAt);
    }

    public static function next(object $order): array
    {
        return match ($order->status) {
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['preparing', 'cancelled'],
            'preparing' => [$order->fulfillment === 'delivery' ? 'shipping' : 'ready', 'cancelled'],
            'ready' => ['completed', 'uncollected', 'cancelled'],
            'shipping' => ['completed', 'delivery_failed', 'cancelled'],
            'delivery_failed' => ['shipping', 'completed', 'cancelled'],
            'uncollected' => ['ready', 'completed', 'cancelled'],
            default => [],
        };
    }

    public static function canReconcile(User $actor): bool
    {
        return Branches::can($actor, 'reconcile');
    }

    public static function canReschedule(object $order, bool $manager = false): bool
    {
        return in_array($order->status, $manager ? ['pending', 'confirmed', 'delivery_failed', 'uncollected'] : ['pending', 'confirmed'], true);
    }

    public static function prepMinutes(int $orderId): int
    {
        $query = self::query();
        $order = (DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query)->find($orderId);
        abort_unless($order, 404);
        if ($order->prep_minutes_snapshot !== null) {
            return (int) $order->prep_minutes_snapshot;
        }

        // Display aid for legacy orders only. An admin must verify before changing schedule.
        return (int) DB::table('order_items')->join('variants', 'variants.id', '=', 'order_items.variant_id')
            ->join('products', 'products.id', '=', 'variants.product_id')->where('order_id', $orderId)->max('products.prep_minutes');
    }

    public static function canCustomerRequest(object $order): bool
    {
        return self::canReschedule($order) && $order->prep_minutes_snapshot !== null
            && now()->lt(CarbonImmutable::parse($order->scheduled_at)->subMinutes((int) $order->prep_minutes_snapshot));
    }

    public static function productionStarted(object $order): bool
    {
        return $order->production_started_at !== null || in_array($order->status,
            ['preparing', 'ready', 'shipping', 'delivery_failed', 'uncollected', 'completed'], true);
    }

    public static function prepayRequired(object $order, ?array $settings = null): bool
    {
        $settings ??= Store::settings();

        return $order->method === 'bank' && ((bool) $order->bank_prepay_required || $settings['bank_prepay_required']);
    }

    public static function reschedule(int $id, User $actor, array $fields, Checkout $checkout): void
    {
        DB::transaction(function () use ($id, $actor, $fields, $checkout) {
            Branches::authorize($actor, 'orders');
            $settings = Store::settings(lock: true, shared: true);
            $actor = $actor->fresh();
            abort_unless($actor?->active && $actor->isStaff(), 403);
            $order = self::query()->lockForUpdate()->find($id);
            abort_unless($order, 404);
            if (! self::canReschedule($order, Branches::isManager($actor)) || $order->scheduled_at !== $fields['previous_slot'].':00') {
                throw ValidationException::withMessages(['slot' => 'Đơn đã thay đổi hoặc đã vào khâu chuẩn bị. Vui lòng tải lại trang.']);
            }
            if ($fields['slot'] === $fields['previous_slot']) {
                return;
            }
            if ($order->prep_minutes_snapshot === null) {
                throw ValidationException::withMessages(['slot' => 'Quản lý cần xác minh thời gian chuẩn bị của đơn cũ trước khi đổi lịch.']);
            }
            if (! self::canCustomerRequest($order)) {
                abort_unless(Branches::isManager($actor), 403);
                self::requiredNote($fields);
            }
            Branches::lockSlots([$order->scheduled_at, $fields['slot'].':00']);
            $scheduledAt = $checkout->assertSlot($fields['slot'], $settings, (int) $order->prep_minutes_snapshot);
            Branches::bookSlot($order->scheduled_at, -1);
            Branches::bookSlot($scheduledAt, 1);
            $update = ['scheduled_at' => $scheduledAt, 'updated_at' => now()];
            if ($order->method === 'bank' && $order->payment === 'unpaid' && $order->bank_due_at !== null) {
                $update['bank_due_at'] = min($order->bank_due_at, $scheduledAt);
            }
            self::query()->where('id', $id)->update($update);
            $event = self::event($id, $actor->id, 'schedule', $fields['slot'], $fields['note'], $fields['previous_slot']);
            OrderNotices::record($id, 'schedule', ['old_slot' => $fields['previous_slot'], 'new_slot' => $fields['slot']], $event);
        }, 5);
    }

    public static function change(int $id, User $actor, array $fields): void
    {
        DB::transaction(function () use ($id, $actor, $fields) {
            $hint = self::query()->find($id);
            abort_unless($hint, 404);
            $managed = $fields['kind'] === 'status' && in_array($fields['value'], ['shipping', 'delivery_failed'], true)
                && Fulfillments::managing($id, $actor->id, $fields['value']);
            $action = $managed ? (Branches::can($actor, 'dispatch') ? 'dispatch' : 'driver') : ($fields['kind'] === 'payment'
                ? ($hint->method === 'cod' && $fields['value'] === 'paid' && Branches::can($actor, 'orders') ? 'orders' : 'reconcile')
                : (in_array($fields['kind'], ['lead_time', 'prepay_exception'], true) ? 'configure'
                    : (Branches::can($actor, 'orders') ? 'orders' : 'production')));
            Branches::authorize($actor, $action);
            $settings = Store::settings(lock: true, shared: true);
            $actor = $actor->fresh();
            abort_unless($actor?->active && in_array($actor->role, ['admin', 'staff'], true), 403);
            $order = self::query()->lockForUpdate()->find($id);
            abort_unless($order, 404);
            $value = $fields['value'];
            Fulfillments::assertTransition($order, $fields['kind'], $value);
            if (Branches::role($actor) === 'kitchen' && ($fields['kind'] !== 'status' || ! in_array($value, ['preparing', 'ready'], true))) {
                abort(403);
            }
            if (isset($fields['expected_status']) && $fields['expected_status'] !== $order->status) {
                throw ValidationException::withMessages(['value' => 'Đơn đã được người khác cập nhật. Vui lòng tải lại trang.']);
            }

            if (in_array($fields['kind'], ['lead_time', 'prepay_exception'], true)) {
                abort_unless(Branches::isManager($actor), 403);
                $note = self::requiredNote($fields);
                if ($fields['kind'] === 'lead_time') {
                    if ($order->prep_minutes_snapshot !== null || ! self::canReschedule($order)
                        || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10080]]) === false) {
                        throw ValidationException::withMessages(['value' => 'Chỉ xác minh thời gian chuẩn bị cho đơn cũ chưa vào bếp.']);
                    }
                    self::query()->where('id', $id)->update(['prep_minutes_snapshot' => (int) $value, 'updated_at' => now()]);
                } else {
                    if (! $settings['allow_payment_override'] || $value !== 'granted' || $order->method !== 'bank'
                        || $order->payment !== 'unpaid' || $order->prepayment_waived_at || ! self::canReschedule($order)) {
                        throw ValidationException::withMessages(['value' => 'Ngoại lệ chưa được tiệm cho phép hoặc đơn không còn phù hợp.']);
                    }
                    self::query()->where('id', $id)->update(['prepayment_waived_at' => now(), 'updated_at' => now()]);
                }
                self::event($id, $actor->id, $fields['kind'], (string) $value, $note);

                return;
            }

            if ($fields['kind'] === 'payment') {
                abort_unless(($order->method === 'cod' && $value === 'paid') || self::canReconcile($actor), 403);
                $allowed = ($value === 'paid' && $order->payment === 'unpaid' && $order->status !== 'cancelled'
                    && ($order->method === 'bank' || in_array($order->status, ['ready', 'shipping', 'completed', 'delivery_failed', 'uncollected'], true)))
                    || ($value === 'refunded' && $order->payment === 'paid' && $order->status === 'cancelled');
                if (! $allowed) {
                    throw ValidationException::withMessages(['value' => 'Không thể thay đổi thanh toán ở trạng thái hiện tại.']);
                }

                $evidence = null;
                if ($value === 'refunded') {
                    $method = $order->method === 'bank' ? 'bank' : 'cash';
                    $reference = trim((string) ($fields['refund_reference'] ?? ''));
                    if (($fields['refund_method'] ?? null) !== $method || mb_strlen($reference) < ($method === 'bank' ? 6 : 3)
                        || mb_strlen($reference) > 160 || filter_var($fields['refund_amount'] ?? null, FILTER_VALIDATE_INT) !== $order->total
                        || ! filter_var($fields['refund_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        throw ValidationException::withMessages(['refund_reference' => 'Cần đúng phương thức, mã giao dịch/phiếu chi, đủ số tiền và xác nhận đã đối chiếu bằng chứng hoàn tiền.']);
                    }
                    $evidence = json_encode(['method' => $method, 'reference' => $reference, 'amount' => $order->total,
                        'verified_by' => $actor->id, 'verified_at' => now()->toIso8601String(), 'note' => self::requiredNote($fields)], JSON_THROW_ON_ERROR);
                }
                self::query()->where('id', $id)->update([
                    'payment' => $value, 'updated_at' => now(),
                    ...($value === 'paid' ? ['paid_at' => now()] : []),
                    ...($evidence ? ['refund_evidence' => $evidence] : []),
                ]);
            } elseif ($fields['kind'] === 'status') {
                if (! in_array($value, self::next($order), true) || ($value === 'completed' && $order->payment !== 'paid')) {
                    throw ValidationException::withMessages(['value' => 'Trạng thái không hợp lệ. Hãy xác nhận thanh toán trước khi hoàn tất.']);
                }

                if (in_array($value, ['preparing', 'shipping'], true) && self::prepayRequired($order, $settings)
                    && $order->payment !== 'paid' && ! $order->prepayment_waived_at) {
                    throw ValidationException::withMessages(['value' => 'Đơn chuyển khoản cần được người có quyền xác nhận đã thu đủ trước khi vào bếp hoặc giao.']);
                }
                if ($value === 'cancelled' && (self::productionStarted($order) || ! self::canCustomerRequest($order))) {
                    abort_unless(Branches::isManager($actor), 403);
                }
                if ($value === 'cancelled') {
                    self::requiredNote($fields);
                    if (Branches::enabled()) {
                        if ($order->coupon_id) {
                            DB::table('coupons')->where('id', $order->coupon_id)->lockForUpdate()->first();
                        }
                        Branches::lockSlots([$order->scheduled_at]);
                        $skuIds = DB::table('order_items')->where('order_id', $id)->pluck('variant_id')->all();
                        Inventory::lock($skuIds);
                    }
                }
                if (in_array($value, ['delivery_failed', 'uncollected'], true)
                    || in_array($order->status, ['delivery_failed', 'uncollected'], true)) {
                    self::requiredNote($fields);
                }

                $update = ['status' => $value, 'updated_at' => now()];
                if ($value === 'preparing') {
                    $update['production_started_at'] = $order->production_started_at ?: now();
                }
                if ($value === 'shipping') {
                    $update['dispatched_at'] = $order->dispatched_at ?: now();
                }
                if ($value === 'completed') {
                    $update['completed_at'] = now();
                }
                if ($value === 'cancelled' && ! self::productionStarted($order) && ! $order->stock_restored_at) {
                    $quantities = DB::table('order_items')->where('order_id', $id)->groupBy('variant_id')->orderBy('variant_id')
                        ->selectRaw('variant_id, SUM(quantity) AS quantity')->get();
                    foreach ($quantities as $item) {
                        if (! Inventory::move($item->variant_id, (int) $item->quantity, 'cancel', actorId: $actor->id, orderId: $id, note: $fields['note'] ?? null)) {
                            throw new RuntimeException('Cannot restore inventory for variant '.$item->variant_id);
                        }
                    }
                    $update['stock_restored_at'] = now();
                    $update['inventory_processed_at'] = now();
                }
                if ($value === 'cancelled') {
                    Branches::bookSlot($order->scheduled_at, -1);
                    $update['inventory_review_required'] = self::productionStarted($order);
                    if (! self::productionStarted($order) && $settings['coupon_return_on_early_cancel'] && ! $order->coupon_released_at && $order->coupon_id) {
                        DB::table('coupons')->where('id', $order->coupon_id)->where('used', '>', 0)->decrement('used');
                        $update['coupon_released_at'] = now();
                    }
                }
                self::query()->where('id', $id)->update($update);
            } else {
                throw ValidationException::withMessages(['kind' => 'Thao tác không hợp lệ.']);
            }
            $previous = $fields['kind'] === 'payment' ? $order->payment : $order->status;
            $event = self::event($id, $actor->id, $fields['kind'], $value, $fields['note'] ?? null, $previous);
            Fulfillments::onOrderChanged($order, $fields['kind'], $value, $actor->id, $event);
            $dispatchNotice = $managed || ($fields['kind'] === 'status' && $value === 'ready' && $order->fulfillment === 'pickup' && Fulfillments::publicData($order));
            if (! $dispatchNotice) {
                OrderNotices::record($id, $fields['kind'] === 'payment' ? 'payment_'.$value : $value, [], $event);
            }
        }, 5);
    }

    public static function disposeInventory(int $id, User $actor, array $fields): void
    {
        DB::transaction(function () use ($id, $actor, $fields) {
            Branches::authorize($actor, 'inventory');
            Store::settings(lock: true, shared: true);
            $actor = $actor->fresh();
            abort_unless($actor?->active && Branches::isManager($actor), 403);
            $order = self::query()->lockForUpdate()->find($id);
            abort_unless($order, 404);
            if ($order->status !== 'cancelled' || ! $order->inventory_review_required || $order->inventory_processed_at) {
                throw ValidationException::withMessages(['items' => 'Đơn không chờ kiểm kê hoặc đã được xử lý. Không nhập lại tồn lần nữa.']);
            }
            $note = self::requiredNote($fields);
            $dispatched = $order->dispatched_at !== null || DB::table('order_events')->where('order_id', $id)
                ->where('kind', 'status')->where('value', 'shipping')->exists();
            $items = DB::table('order_items')->where('order_id', $id)->orderBy('variant_id')->orderBy('id')->get();
            $input = collect($fields['items'])->keyBy('item_id');
            if ($input->count() !== $items->count() || count($fields['items']) !== $items->count()) {
                throw ValidationException::withMessages(['items' => 'Kiểm kê đủ từng dòng bánh, không lặp hoặc thêm dòng của đơn khác.']);
            }
            foreach ($items as $item) {
                $row = $input->get($item->id);
                if ($dispatched && ($row['unmade'] ?? 0) > 0) {
                    throw ValidationException::withMessages(['items' => 'Đơn đã xuất giao chỉ được phân loại bánh trả về đã kiểm tra hoặc tiêu hao. Không ghi là chưa làm.']);
                }
                if (! $row || array_sum(array_intersect_key($row, array_flip(['unmade', 'sellable', 'waste']))) !== $item->quantity) {
                    throw ValidationException::withMessages(['items' => 'Tổng chưa làm, còn bán được và tiêu hao phải bằng số bánh đã giữ của từng dòng.']);
                }
                foreach (['unmade' => 'cancel_unmade', 'sellable' => 'return_checked'] as $field => $reason) {
                    if ($row[$field] && ! Inventory::move($item->variant_id, $row[$field], $reason, $actor->id, $id, $note)) {
                        throw new RuntimeException('Cannot account for cancelled inventory.');
                    }
                }
                if ($row['waste']) {
                    Inventory::waste($item->variant_id, $row['waste'], $actor->id, $id, $note);
                }
                DB::table('order_inventory_dispositions')->insert([
                    'order_id' => $id, 'order_item_id' => $item->id, 'actor_id' => $actor->id,
                    'unmade' => $row['unmade'], 'sellable' => $row['sellable'], 'waste' => $row['waste'], 'reason' => $note, 'created_at' => now(),
                ]);
            }
            self::query()->where('id', $id)->update(['inventory_processed_at' => now(), 'updated_at' => now()]);
            self::event($id, $actor->id, 'inventory', 'checked', $note);
        }, 5);
    }

    private static function requiredNote(array $fields): string
    {
        $note = trim((string) ($fields['note'] ?? ''));
        if ($note === '' || mb_strlen($note) > 250) {
            throw ValidationException::withMessages(['note' => 'Cần lý do hoặc kết quả đã kiểm tra, tối đa 250 ký tự.']);
        }

        return $note;
    }

    public static function event(int $orderId, ?int $actorId, string $kind, string $value, ?string $note = null, ?string $previousValue = null): int
    {
        return DB::table('order_events')->insertGetId([
            'order_id' => $orderId, 'actor_id' => $actorId, 'kind' => $kind, 'value' => $value,
            'note' => $note, 'previous_value' => $previousValue, 'created_at' => now(),
        ]);
    }

    public static function link(object $order): string
    {
        return rtrim(config('app.url'), '/').route('tracking.show', ['token' => $order->tracking_token], absolute: false);
    }

    public static function detail(object $order, bool $internal = false, bool $owner = false): array
    {
        $data = (array) $order;
        unset($data['tracking_token'], $data['request_key'], $data['user_id'], $data['stock_restored_at'], $data['delivery_snapshot']);
        if (($internal || $owner) && ($order->delivery_snapshot ?? null)) {
            try {
                $data['delivery_address'] = DeliveryAddress::open($order->delivery_snapshot);
            } catch (DecryptException) {
                $data['delivery_address'] = null;
                $data['delivery_address_needs_review'] = true;
            }
        }
        if (! $internal) {
            $data = array_intersect_key($data, array_flip([
                'code', 'fulfillment', 'address', 'region_label', 'scheduled_at',
                'method', 'payment', 'status', 'subtotal', 'discount', 'delivery_fee', 'total', 'bank_due_at',
                'branch_id', 'branch_snapshot',
                ...($owner ? ['delivery_address', 'delivery_address_needs_review'] : []),
            ]));
            if (! $owner) {
                unset($data['address']);
            }
        }
        $data['branch_snapshot'] = $order->branch_snapshot ? json_decode($order->branch_snapshot, true, flags: JSON_THROW_ON_ERROR)
            : ['id' => (int) ($order->branch_id ?? 1), 'provenance' => 'legacy_unknown', 'historical_details_known' => false];
        $branchId = (int) ($order->branch_id ?? 1);
        $branchSettings = Store::settings(branchId: $branchId);
        $branch = Branches::find($branchId);
        $data['branch_contact'] = [
            'id' => $branchId, 'name' => $branch?->name ?? $branchSettings['name'],
            'phone' => $branchSettings['phone'], 'email' => $branchSettings['email'],
        ];
        if ($internal) {
            $data['sales_context'] = $order->sales_context ? json_decode($order->sales_context, true, flags: JSON_THROW_ON_ERROR) : null;
        }
        $showBank = $internal || ($order->status !== 'cancelled' && $order->payment === 'unpaid');
        $data['bank_snapshot'] = $showBank && $order->bank_snapshot ? json_decode($order->bank_snapshot, true, flags: JSON_THROW_ON_ERROR) : null;
        $data['items'] = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')
            ->get($internal || $owner ? ['*'] : ['id', 'product_name', 'variant_name', 'quantity', 'unit_price', 'option_fee', 'accessory_fee', 'accessories_snapshot'])
            ->map(function ($item) {
                $item->accessories_snapshot = $item->accessories_snapshot ? json_decode($item->accessories_snapshot, true, flags: JSON_THROW_ON_ERROR) : [];
                $item->line_total = ((int) $item->unit_price + (int) $item->option_fee + (int) $item->accessory_fee) * $item->quantity;

                return $item;
            });
        $events = DB::table('order_events')->where('order_id', $order->id)->orderBy('order_events.id');
        if ($internal) {
            $events->leftJoin('users', 'users.id', '=', 'order_events.actor_id')->select('order_events.*', 'users.name as actor');
        } else {
            $events->leftJoin('users', 'users.id', '=', 'order_events.actor_id')
                ->select('kind', 'value', 'previous_value', 'order_events.created_at')
                ->selectRaw("CASE WHEN users.role = 'customer' OR (kind = 'request' AND actor_id IS NULL) THEN 'Khách hàng' WHEN actor_id IS NULL THEN 'Hệ thống' ELSE 'Nhân viên tiệm' END AS actor");
        }
        $data['events'] = $events->get();
        if ($internal) {
            $data['inventory_dispositions'] = DB::table('order_inventory_dispositions')->where('order_id', $order->id)
                ->join('users', 'users.id', '=', 'order_inventory_dispositions.actor_id')->select('order_inventory_dispositions.*', 'users.name as actor')->get();
            $data['refund_evidence'] = $order->refund_evidence ? json_decode($order->refund_evidence, true, flags: JSON_THROW_ON_ERROR) : null;
        }

        return $data;
    }
}
