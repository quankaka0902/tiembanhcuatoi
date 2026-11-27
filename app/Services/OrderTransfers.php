<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderTransfers
{
    public const POLICY = 'v1-unpaid-before-production';

    public static function adminData(object $order, User $actor): array
    {
        $source = (int) ($order->branch_id ?? 1);
        $reason = self::blocked($order, Store::settings(branchId: $source));
        $manager = Branches::isManager($actor, $source);
        $targets = $manager && ! $reason ? DB::table('branches')->where('active', true)->where('id', '<>', $source)
            ->whereIn('id', Branches::allowedIds($actor))->orderBy('id')->get(['id', 'name'])
            ->filter(fn ($branch) => Branches::isManager($actor, (int) $branch->id))->values()->all() : [];

        return ['enabled' => $manager && ! $reason, 'blocked_reason' => $reason ?? ($manager ? null : 'Chỉ quản lý có quyền ở cả hai cơ sở được lập đề nghị.'),
            'targets' => $targets,
            'proposals' => $manager ? DB::table('order_transfers')->where('order_id', $order->id)->orderByDesc('id')->get()
                ->map(fn ($proposal) => self::summary($proposal) + ['can_execute' => $proposal->status === 'approved' && now()->lt($proposal->expires_at) && ! $reason,
                    'can_cancel' => in_array($proposal->status, ['pending', 'approved'], true)])->all() : [],
            'preview_url' => '/quan-tri/don-hang/'.$order->id.'/chuyen-co-so/bao-gia',
            'create_url' => '/quan-tri/don-hang/'.$order->id.'/chuyen-co-so'];
    }

    public static function preview(int $orderId, User $actor, array $fields): array
    {
        return DB::transaction(function () use ($orderId, $actor, $fields) {
            [$order, $snapshot] = self::lockedQuote($orderId, $actor, $fields);
            $expires = now()->addMinutes(10)->timestamp;

            return self::previewData($snapshot) + ['quote_token' => Crypt::encryptString(json_encode([
                'purpose' => 'branch-transfer', 'order_id' => $order->id, 'source' => self::sourceFingerprint($order),
                'quote' => self::digest($snapshot), 'expires' => $expires,
            ], JSON_THROW_ON_ERROR)), 'expires_at' => now()->setTimestamp($expires)->toIso8601String()];
        }, 5);
    }

    public static function propose(int $orderId, User $actor, array $fields): object
    {
        return DB::transaction(function () use ($orderId, $actor, $fields) {
            self::authorizeBoth($actor, Branches::id(), (int) $fields['target_branch_id']);
            $requestKey = hash('sha256', $fields['request_key']);
            $requestHash = self::digest(['order_id' => $orderId, 'actor_id' => $actor->id, 'from' => Branches::id(),
                'target' => (int) $fields['target_branch_id'], 'slot' => $fields['slot'], 'area_code' => $fields['area_code'] ?? null,
                'note' => $fields['note'], 'quote_token' => $fields['quote_token']]);
            $existing = DB::table('order_transfers')->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->order_id === $orderId && (int) $existing->proposed_by === $actor->id
                    && hash_equals($existing->request_payload_hash, $requestHash), 409);

                return $existing;
            }
            [$order, $snapshot] = self::lockedQuote($orderId, $actor, $fields);
            try {
                $token = json_decode(Crypt::decryptString($fields['quote_token']), true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                self::invalid('quote_token', 'Báo giá không hợp lệ. Hãy tính lại.');
            }
            if (($token['purpose'] ?? null) !== 'branch-transfer' || ($token['order_id'] ?? null) !== $orderId
                || ($token['expires'] ?? 0) < now()->timestamp || ($token['source'] ?? '') !== self::sourceFingerprint($order)
                || ! hash_equals($token['quote'] ?? '', self::digest($snapshot))) {
                self::invalid('quote_token', 'Đơn, giá, lịch hoặc phí đã đổi. Tính lại báo giá trước khi gửi khách.');
            }
            DB::table('order_transfers')->where('order_id', $orderId)->whereNotNull('open_order_id')
                ->where('expires_at', '<=', now())->update(['status' => 'expired', 'open_order_id' => null, 'closed_at' => now(), 'updated_at' => now()]);
            if (DB::table('order_transfers')->where('open_order_id', $orderId)->exists()) {
                self::invalid('transfer', 'Đơn đang có đề nghị chuyển cơ sở. Xử lý hoặc hủy đề nghị trước.');
            }
            $secret = bin2hex(random_bytes(32));
            $id = DB::table('order_transfers')->insertGetId([
                'order_id' => $orderId, 'open_order_id' => $orderId,
                'from_branch_id' => $snapshot['old']['branch']['id'], 'to_branch_id' => $snapshot['new']['branch']['id'],
                'proposed_by' => $actor->id, 'status' => 'pending', 'request_key' => $requestKey,
                'request_payload_hash' => $requestHash,
                'source_fingerprint' => self::sourceFingerprint($order), 'quote_fingerprint' => self::digest($snapshot),
                'encrypted_snapshot' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)), 'reason' => $fields['note'],
                'approval_token_hash' => hash('sha256', $secret), 'approval_expires_at' => now()->addMinutes(15),
                'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $event = Orders::event($orderId, $actor->id, 'transfer', 'proposed', 'Đề nghị chuyển cơ sở #'.$id);
            OrderNotices::record($orderId, 'transfer_approval', ['token' => $secret, 'expires_at' => now()->addMinutes(15)->toDateTimeString(),
                ...self::previewData($snapshot)], $event, 'transfer:'.$id.':approval');
            Branches::audit('transfer.proposed', ['order_id' => $orderId, 'transfer_id' => $id,
                'to_branch_id' => $snapshot['new']['branch']['id'], 'old_total' => $order->total, 'new_total' => $snapshot['new']['quote']['total']], $actor->id, (int) $order->branch_id);

            return DB::table('order_transfers')->find($id);
        }, 5);
    }

    public static function access(?string $token = null, ?User $owner = null, ?string $code = null): array
    {
        if ($token !== null) {
            $proposal = DB::table('order_transfers')->where('approval_token_hash', hash('sha256', $token))->first();
            abort_unless($proposal && now()->lt($proposal->approval_expires_at), 410);
            $order = DB::table('orders')->find($proposal->order_id);
        } else {
            abort_unless($owner?->fresh()?->active, 403);
            $order = DB::table('orders')->where('user_id', $owner->id)->where('code', $code)->first();
            abort_unless($order, 404);
            $proposal = DB::table('order_transfers')->where('order_id', $order->id)->orderByDesc('id')->first();
        }
        abort_unless($order && $proposal, 404);

        return [$order, $proposal];
    }

    public static function approve(array $fields, ?string $token = null, ?User $owner = null, ?string $code = null): object
    {
        [$candidate, $hint] = self::access($token, $owner, $code);

        return DB::transaction(function () use ($fields, $token, $owner, $candidate, $hint) {
            if ($owner) {
                $fresh = User::whereKey($owner->id)->lockForUpdate()->first();
                abort_unless($fresh?->active && (int) $candidate->user_id === $fresh->id, 403);
            }
            $order = DB::table('orders')->where('id', $candidate->id)->lockForUpdate()->first();
            $proposal = DB::table('order_transfers')->where('id', $hint->id)->lockForUpdate()->first();
            abort_unless($proposal && (int) $proposal->order_id === $order->id, 404);
            if ($token !== null) {
                abort_unless(hash_equals($proposal->approval_token_hash, hash('sha256', $token)) && now()->lt($proposal->approval_expires_at), 410);
            }
            if (isset($fields['proposal_id']) && (int) $fields['proposal_id'] !== $proposal->id) {
                self::invalid('proposal_id', 'Đề nghị đã thay đổi. Vui lòng mở lại.');
            }
            $key = hash('sha256', $fields['request_key'].'|'.$fields['decision']);
            if (in_array($proposal->status, ['approved', 'rejected'], true) && $proposal->approval_request_key === $key) {
                return $proposal;
            }
            if ($proposal->status !== 'pending' || now()->gte($proposal->approval_expires_at) || now()->gte($proposal->expires_at)
                || ! hash_equals($proposal->source_fingerprint, self::sourceFingerprint($order))) {
                self::invalid('proposal_id', 'Đề nghị hết hạn hoặc đơn đã đổi. Tài nguyên nguồn vẫn được giữ.');
            }
            if ($fields['decision'] === 'approved' && ! filter_var($fields['accept_quote'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                self::invalid('accept_quote', 'Cần xác nhận cơ sở, giờ nhận và tổng tiền mới.');
            }
            $approved = $fields['decision'] === 'approved';
            DB::table('order_transfers')->where('id', $proposal->id)->update([
                'status' => $fields['decision'], 'approved_by' => $owner?->id, 'approval_request_key' => $key,
                'approved_at' => $approved ? now() : null, 'open_order_id' => $approved ? $order->id : null,
                'closed_at' => $approved ? null : now(), 'updated_at' => now(),
            ]);
            Branches::run((int) $order->branch_id, function () use ($order, $proposal, $fields, $owner) {
                $event = Orders::event($order->id, $owner?->id, 'transfer', 'customer_'.$fields['decision'], 'Đề nghị #'.$proposal->id);
                OrderNotices::record($order->id, 'transfer_'.$fields['decision'], [], $event);
            });

            return DB::table('order_transfers')->find($proposal->id);
        }, 5);
    }

    public static function execute(int $id, User $actor, array $fields): object
    {
        $hint = self::scopedProposal($id, $actor);

        return DB::transaction(function () use ($id, $hint, $actor, $fields) {
            self::authorizeBoth($actor, (int) $hint->from_branch_id, (int) $hint->to_branch_id);
            self::claim($hint, $actor, $fields, 'execute');
            self::lockConfigurations((int) $hint->from_branch_id, (int) $hint->to_branch_id);
            $order = DB::table('orders')->where('id', $hint->order_id)->lockForUpdate()->first();
            $proposal = DB::table('order_transfers')->where('id', $id)->lockForUpdate()->first();
            if ($proposal->status === 'completed') {
                return $order;
            }
            if ($proposal->status !== 'approved' || now()->gte($proposal->expires_at)
                || (int) $order->branch_id !== (int) $proposal->from_branch_id
                || ! hash_equals($proposal->source_fingerprint, self::sourceFingerprint($order))) {
                self::invalid('transfer', 'Đề nghị chưa được khách duyệt, đã hết hạn hoặc đơn đã thay đổi.');
            }
            $saved = self::payload($proposal);
            $context = ['target_branch_id' => (int) $proposal->to_branch_id, 'slot' => $saved['new']['slot'], 'area_code' => $saved['new']['area_code']];
            self::lockResources($order, (int) $proposal->to_branch_id, $context['slot']);
            $fresh = self::buildQuote($order, $context, $actor);
            if (! hash_equals($proposal->quote_fingerprint, self::digest($fresh))) {
                self::invalid('quote_token', 'Giá, phí hoặc bối cảnh đã đổi sau khi khách duyệt. Lập báo giá mới; chưa chuyển kho hoặc khung nguồn.');
            }
            if ($order->method === 'bank') {
                Branches::authorize($actor, 'reconcile', (int) $proposal->from_branch_id);
                Branches::authorize($actor, 'reconcile', (int) $proposal->to_branch_id);
                if (! filter_var($fields['bank_checked'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    || ! preg_match('/^[A-Za-z0-9_-]{3,80}$/D', $fields['bank_reference'] ?? '')) {
                    self::invalid('bank_reference', 'Người đối soát cần xác nhận chưa thu tiền và ghi mã đối chiếu.');
                }
            }
            $target = (int) $proposal->to_branch_id;
            Branches::run($target, fn () => app(Checkout::class)->assertSlot($fresh['new']['slot'], Store::settings(), $fresh['new']['prep_minutes']));
            // Target resources are obtained before any source reservation is released.
            Inventory::transferReservation($order->id, (int) $proposal->from_branch_id, $target,
                self::quantities($order->id), $actor->id, 'Chuyển cơ sở #'.$id);
            Branches::bookSlot($fresh['new']['slot'].':00', 1, $target);
            Branches::bookSlot($order->scheduled_at, -1, (int) $proposal->from_branch_id);
            $quote = $fresh['new']['quote'];
            if ($order->coupon_id && (int) ($quote['coupon_id'] ?? 0) !== (int) $order->coupon_id && ! $order->coupon_released_at) {
                DB::table('coupons')->where('id', $order->coupon_id)->where('used', '>', 0)->decrement('used');
            }
            DB::table('orders')->where('id', $order->id)->update([
                ...$quote, 'branch_id' => $target, 'location_id' => $target,
                'branch_snapshot' => json_encode($fresh['new']['branch'], JSON_THROW_ON_ERROR),
                'sales_context' => json_encode($fresh['new']['sales_context'], JSON_THROW_ON_ERROR),
                'delivery_snapshot' => $fresh['new']['delivery'] ? DeliveryAddress::seal($fresh['new']['delivery']) : null,
                'scheduled_at' => $fresh['new']['slot'].':00', 'prep_minutes_snapshot' => $fresh['new']['prep_minutes'],
                'bank_snapshot' => $fresh['new']['bank'] ? json_encode($fresh['new']['bank'], JSON_THROW_ON_ERROR) : null,
                'bank_due_at' => $fresh['new']['bank_due_at'], 'bank_prepay_required' => $fresh['new']['bank_prepay_required'],
                'prepayment_waived_at' => null,
                'coupon_released_at' => $quote['coupon_id'] ? null : ($order->coupon_id ? now() : $order->coupon_released_at),
                'status' => 'pending', 'updated_at' => now(),
            ]);
            foreach ($fresh['new']['lines'] as $line) {
                DB::table('order_items')->where('id', $line['item_id'])->where('order_id', $order->id)->update([
                    ...array_intersect_key($line, array_flip(['product_name', 'variant_name', 'unit_price', 'option_fee', 'accessory_fee', 'message', 'note'])),
                    'accessories_snapshot' => json_encode($line['accessories'], JSON_THROW_ON_ERROR),
                ]);
            }
            Fulfillments::transferToBranch($order, $target, $fresh['new']['slot'].':00');
            DB::table('order_transfers')->where('id', $id)->update(['status' => 'completed', 'open_order_id' => null,
                'executed_by' => $actor->id, 'executed_at' => now(), 'closed_at' => now(), 'updated_at' => now()]);
            Branches::run($target, function () use ($order, $proposal, $actor, $id, $fresh) {
                $event = Orders::event($order->id, $actor->id, 'transfer', 'completed', 'Đề nghị #'.$id.'; cơ sở '.$proposal->from_branch_id.' → '.$proposal->to_branch_id);
                OrderNotices::record($order->id, 'transfer_completed', self::previewData($fresh), $event);
            });
            Branches::audit('transfer.completed', ['transfer_id' => $id, 'order_id' => $order->id,
                'from_branch_id' => $proposal->from_branch_id, 'to_branch_id' => $target,
                'old_total' => $order->total, 'new_total' => $quote['total'], 'bank_reference' => $fields['bank_reference'] ?? null], $actor->id, $target);

            return DB::table('orders')->find($order->id);
        }, 5);
    }

    public static function cancel(int $id, User $actor, array $fields): object
    {
        $hint = self::scopedProposal($id, $actor);

        return DB::transaction(function () use ($id, $hint, $actor, $fields) {
            Branches::authorize($actor, 'configure', (int) $hint->from_branch_id);
            self::claim($hint, $actor, $fields, 'cancel');
            $order = DB::table('orders')->where('id', $hint->order_id)->lockForUpdate()->first();
            $proposal = DB::table('order_transfers')->where('id', $id)->lockForUpdate()->first();
            if ($proposal->status === 'cancelled') {
                return $proposal;
            }
            if (! in_array($proposal->status, ['pending', 'approved'], true)) {
                self::invalid('transfer', 'Đề nghị đã đóng.');
            }
            DB::table('order_transfers')->where('id', $id)->update(['status' => 'cancelled', 'open_order_id' => null, 'closed_at' => now(), 'updated_at' => now()]);
            Branches::run((int) $order->branch_id, function () use ($order, $actor, $id) {
                $event = Orders::event($order->id, $actor->id, 'transfer', 'cancelled', 'Đóng đề nghị #'.$id.'; không thay đơn, kho hoặc khung.');
                OrderNotices::record($order->id, 'transfer_cancelled', [], $event);
            });
            Branches::audit('transfer.cancelled', ['order_id' => $order->id, 'transfer_id' => $id], $actor->id, (int) $order->branch_id);

            return DB::table('order_transfers')->find($id);
        }, 5);
    }

    public static function customerSummary(object $order, ?User $owner = null): ?array
    {
        $proposal = DB::table('order_transfers')->where('order_id', $order->id)->orderByDesc('id')->first();

        return $proposal ? self::summary($proposal) + ['approval_url' => $owner?->active && (int) $order->user_id === $owner->id
            ? '/don-cua-toi/'.$order->code.'/chuyen-co-so' : null] : null;
    }

    public static function summary(object $proposal): array
    {
        $data = self::payload($proposal);

        return ['id' => $proposal->id, 'status' => $proposal->status, 'from' => $data['old']['branch'], 'to' => $data['new']['branch'],
            'old_total' => $data['old']['quote']['total'], 'new_total' => $data['new']['quote']['total'],
            'delta' => $data['new']['quote']['total'] - $data['old']['quote']['total'], 'slot' => $data['new']['slot'],
            'old_slot' => $data['old']['slot'], 'expires_at' => $proposal->expires_at,
            'approval_expires_at' => $proposal->approval_expires_at, 'changes' => self::previewData($data)['changes']];
    }

    private static function lockedQuote(int $orderId, User $actor, array $fields): array
    {
        abort_unless(Branches::enabled(), 404);
        $source = Branches::id();
        $target = (int) $fields['target_branch_id'];
        self::authorizeBoth($actor, $source, $target);
        self::lockConfigurations($source, $target);
        $order = Orders::query()->where('id', $orderId)->lockForUpdate()->first();
        abort_unless($order, 404);
        self::lockResources($order, $target, $fields['slot']);

        return [$order, self::buildQuote($order, $fields, $actor)];
    }

    private static function buildQuote(object $order, array $fields, User $actor): array
    {
        $source = (int) $order->branch_id;
        $target = (int) $fields['target_branch_id'];
        if ($target === $source) {
            self::invalid('target_branch_id', 'Chọn cơ sở khác cơ sở nguồn.');
        }
        $sourceSettings = Store::settings(branchId: $source);
        $targetSettings = Store::settings(branchId: $target);
        foreach ([$sourceSettings, $targetSettings] as $settings) {
            if ($reason = self::blocked($order, $settings)) {
                self::invalid('transfer', $reason);
            }
        }
        Fulfillments::assertTransferable($order);
        if (DB::table('order_change_requests')->where('order_id', $order->id)->where('status', 'pending')->exists()) {
            self::invalid('transfer', 'Xử lý yêu cầu đổi/hủy đang chờ trước khi chuyển cơ sở.');
        }
        $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get();
        $cart = [];
        foreach ($items as $item) {
            $cart[(string) $item->id] = ['variant_id' => $item->variant_id, 'quantity' => $item->quantity, 'message' => $item->message,
                'note' => $item->note, 'accessories' => array_column(json_decode($item->accessories_snapshot ?: '[]', true), 'id')];
        }
        $old = ['branch' => json_decode($order->branch_snapshot ?: '{}', true) + ['id' => $source],
            'slot' => substr($order->scheduled_at, 0, 16), 'quote' => array_intersect_key((array) $order,
                array_flip(['subtotal', 'discount', 'delivery_fee', 'total', 'coupon_id', 'coupon_code', 'region_label'])),
            'items' => $items->map(fn ($item) => (array) $item)->all()];
        $delivery = $order->fulfillment === 'delivery' ? DeliveryAddress::open($order->delivery_snapshot) : null;
        $sales = json_decode($order->sales_context ?: '{}', true);
        $sourceRegion = collect($sourceSettings['regions'])->firstWhere('key', $sales['region_key'] ?? '');
        $area = $fields['area_code'] ?? $delivery['area_code'] ?? $sourceRegion['area_code'] ?? $sourceRegion['key'] ?? null;

        return Branches::run($target, function () use ($order, $fields, $targetSettings, $cart, $old, $delivery, $area, $actor, $source) {
            $checkout = app(Checkout::class);
            $region = $order->fulfillment === 'delivery' ? DeliveryAddress::regionForArea($targetSettings['regions'], (string) $area) : null;
            if ($order->fulfillment === 'delivery' && ! $region) {
                self::invalid('area_code', 'Cơ sở đích chưa hỗ trợ vùng giao này. Cần chọn mã vùng đúng địa chỉ của đơn.');
            }
            $context = ['branch_id' => Branches::id(), 'fulfillment' => $order->fulfillment, 'method' => $order->method,
                'region' => $region['key'] ?? null, 'coupon' => $order->coupon_code, 'slot' => $fields['slot']];
            $lines = $checkout->cart($cart);
            try {
                $quote = $checkout->quote($lines, $context, $targetSettings, lock: true, reservedCouponId: $order->coupon_released_at ? null : $order->coupon_id);
            } catch (ValidationException $failure) {
                if (array_key_first($failure->errors()) !== 'coupon') {
                    throw $failure;
                }
                $context['coupon'] = '';
                $quote = $checkout->quote($lines, $context, $targetSettings);
            }
            $prep = max(array_column($lines, 'prep_minutes'));
            $checkout->assertSlot($fields['slot'], $targetSettings, $prep);
            $bank = $order->method === 'bank' ? Store::bank($targetSettings) : null;
            if ($order->method === 'bank') {
                Branches::authorize($actor, 'reconcile', $source);
                Branches::authorize($actor, 'reconcile', Branches::id());
                $before = json_decode($order->bank_snapshot ?: '{}', true);
                if (! $bank || ($before['account'] ?? '') !== $bank['account'] || ($before['name'] ?? '') !== $bank['name']) {
                    self::invalid('transfer', 'Không chuyển đơn ngân hàng sang tài khoản nhận khác. Đối soát và dùng luồng hủy/hoàn hiện có.');
                }
            }
            $newLines = array_map(fn ($line) => ['item_id' => (int) $line['key'], ...array_intersect_key($line, array_flip([
                'variant_id', 'quantity', 'product_name', 'variant_name', 'unit_price', 'option_fee', 'accessory_fee', 'accessories', 'message', 'note', 'prep_minutes',
            ]))], $lines);
            if ($order->fulfillment === 'delivery') {
                $delivery = DeliveryAddress::normalize(array_replace($delivery ?? [], ['country_code' => 'VN', 'area_code' => $area,
                    'address_line' => $delivery['address_line'] ?? $order->address]), $targetSettings['regions']);
            }

            return ['old' => $old, 'new' => ['branch' => Branches::snapshot(), 'slot' => $fields['slot'], 'quote' => $quote,
                'area_code' => $area, 'prep_minutes' => $prep, 'lines' => $newLines, 'delivery' => $delivery, 'bank' => $bank,
                'bank_due_at' => $bank ? min($order->bank_due_at ?: CarbonImmutable::parse($order->created_at)->addMinutes($targetSettings['bank_due_minutes'])->toDateTimeString(), $fields['slot'].':00') : null,
                'bank_prepay_required' => $order->method === 'bank' && ($order->bank_prepay_required || $targetSettings['bank_prepay_required']),
                'sales_context' => ['branch_id' => Branches::id(), 'timezone' => $targetSettings['timezone'], 'fulfillment' => $order->fulfillment,
                    'method' => $order->method, 'region_key' => $region['key'] ?? null, 'area_code' => $area,
                    'scheduled_local' => $fields['slot'], 'delivery_fee' => $quote['delivery_fee'], 'free_from' => $targetSettings['free_from'], 'source' => 'approved_branch_transfer'],
            ]];
        });
    }

    private static function blocked(object $order, array $settings): ?string
    {
        if (! Branches::enabled() || ! ($settings['allow_branch_transfers'] ?? false)
            || ($settings['transfer_policy_version'] ?? '') !== self::POLICY || ! filled($settings['transfer_policy_approval_reference'] ?? null)) {
            return 'Chuyển cơ sở đang đóng; quản lý cần chốt chính sách và cấu hình ở cả nguồn lẫn đích.';
        }
        if (! in_array($order->status, ['pending', 'confirmed'], true) || Orders::productionStarted($order) || $order->dispatched_at) {
            return 'Chỉ chuyển đơn trước khi làm hoặc xuất giao. Bánh đã làm dùng quy trình hủy/kiểm kê hiện có.';
        }
        if ($order->payment !== 'unpaid') {
            return 'Đơn đã thu tiền cần đối soát/hủy/hoàn theo quy trình hiện có; không tự chuyển hoặc bù tiền.';
        }

        return null;
    }

    private static function authorizeBoth(User $actor, int $source, int $target): void
    {
        $ids = array_values(array_unique([$source, $target]));
        sort($ids);
        foreach ($ids as $id) {
            Branches::authorize($actor, 'configure', $id);
        }
    }

    private static function lockConfigurations(int $source, int $target): void
    {
        $ids = array_values(array_unique([$source, $target]));
        sort($ids);
        // All branch rows precede the shared brand row, including for opposite-direction transfers.
        DB::table('branches')->whereIn('id', $ids)->orderBy('id')->sharedLock()->get();
        DB::table('chain_settings')->where('id', 1)->sharedLock()->first();
    }

    private static function lockResources(object $order, int $target, string $slot): void
    {
        if ($order->coupon_id) {
            DB::table('coupons')->where('id', $order->coupon_id)->lockForUpdate()->first();
        }
        $ids = array_values(array_unique([(int) $order->branch_id, $target]));
        sort($ids);
        foreach ($ids as $branch) {
            Branches::lockSlots([$branch === $target ? $slot.':00' : $order->scheduled_at], $branch);
        }
        $variants = array_keys(self::quantities($order->id));
        foreach ($ids as $branch) {
            Inventory::lock($variants, $branch);
        }
    }

    private static function quantities(int $orderId): array
    {
        return DB::table('order_items')->where('order_id', $orderId)->groupBy('variant_id')->orderBy('variant_id')
            ->selectRaw('variant_id, SUM(quantity) AS quantity')->pluck('quantity', 'variant_id')->map(fn ($quantity) => (int) $quantity)->all();
    }

    private static function sourceFingerprint(object $order): string
    {
        $fields = (array) $order;
        $fields['items'] = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        return self::digest($fields);
    }

    private static function digest(array $value): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($item) => is_array($item) ? $canonical($item) : $item, $value);
        };

        return hash_hmac('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR), config('app.key'));
    }

    private static function payload(object $proposal): array
    {
        return json_decode(Crypt::decryptString($proposal->encrypted_snapshot), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function previewData(array $snapshot): array
    {
        $before = $snapshot['old'];
        $after = $snapshot['new'];

        return ['from' => $before['branch'], 'to' => $after['branch'],
            'old' => $before['quote'] + ['slot' => $before['slot']], 'new' => $after['quote'] + ['slot' => $after['slot'], 'prep_minutes' => $after['prep_minutes']],
            'delta' => $after['quote']['total'] - $before['quote']['total'], 'changes' => [
                'Cơ sở: '.($before['branch']['name'] ?? '#'.$before['branch']['id']).' → '.$after['branch']['name'],
                'Giờ nhận: '.$before['slot'].' → '.$after['slot'],
                'Tổng tiền: '.$before['quote']['total'].' → '.$after['quote']['total'].' VND',
                'Phí giao: '.$before['quote']['delivery_fee'].' → '.$after['quote']['delivery_fee'].' VND',
                'Đích chưa giữ tài nguyên đến khi thực hiện thành công; nguồn vẫn được giữ.',
            ]];
    }

    private static function scopedProposal(int $id, User $actor): object
    {
        Branches::authorize($actor, 'configure');
        $proposal = DB::table('order_transfers')->where('id', $id)->where('from_branch_id', Branches::id())->first();
        abort_unless($proposal, 404);

        return $proposal;
    }

    private static function claim(object $proposal, User $actor, array $fields, string $action): void
    {
        $key = hash('sha256', $fields['request_key']);
        $hash = self::digest(['transfer_id' => $proposal->id, 'action' => $action, 'actor' => $actor->id,
            'bank_checked' => $fields['bank_checked'] ?? null, 'bank_reference' => $fields['bank_reference'] ?? null]);
        DB::table('order_transfer_commands')->insertOrIgnore(['request_key' => $key, 'transfer_id' => $proposal->id,
            'actor_id' => $actor->id, 'action' => $action, 'payload_hash' => $hash, 'created_at' => now()]);
        $row = DB::table('order_transfer_commands')->where('request_key', $key)->lockForUpdate()->first();
        if (! hash_equals($row->payload_hash, $hash)) {
            self::invalid('request_key', 'Khóa yêu cầu đã được dùng cho một thao tác khác.');
        }
    }

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
