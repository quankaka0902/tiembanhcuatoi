<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderRequests
{
    public const LINK_MINUTES = 15;

    public const STATUSES = ['pending' => 'Chờ tiệm xử lý', 'approved' => 'Đã chấp thuận', 'rejected' => 'Đã từ chối'];

    public static function customerData(object $order, ?User $actor = null, bool $guestVerified = false): array
    {
        $owner = self::isOwner($order, $actor);
        $verified = $owner || $guestVerified;
        $eligible = Orders::canCustomerRequest($order);
        $history = self::history($order->id, verified: $verified);
        $pending = $history->firstWhere('status', 'pending');
        $cutoff = $order->prep_minutes_snapshot === null ? null
            : CarbonImmutable::parse($order->scheduled_at)->subMinutes((int) $order->prep_minutes_snapshot)->toDateTimeString();

        return [
            'changeRequest' => [
                'canRequest' => $eligible && $verified && ! $pending,
                'eligible' => $eligible,
                'cutoffAt' => $cutoff,
                'previousSlot' => substr($order->scheduled_at, 0, 16),
                'requestKey' => (string) Str::uuid(),
                'submitUrl' => $owner ? '/don-cua-toi/'.$order->code.'/yeu-cau' : null,
                'actionLinkUrl' => $eligible && ! $verified ? '/tra-cuu/'.$order->tracking_token.'/lien-ket-thao-tac' : null,
                'slots' => $eligible && $verified ? app(Checkout::class)->availableSlots(Store::settings(), Orders::prepMinutes($order->id)) : [],
            ],
            'changeRequests' => $history,
        ];
    }

    public static function history(int $orderId, bool $internal = false, bool $verified = false): Collection
    {
        return DB::table('order_change_requests as changes')
            ->leftJoin('users as requester', 'requester.id', '=', 'changes.user_id')
            ->leftJoin('users as handler', 'handler.id', '=', 'changes.handled_by')
            ->where('changes.order_id', $orderId)->whereIn('changes.order_id', Orders::query()->select('orders.id'))->orderBy('changes.id')
            ->get(['changes.*', 'requester.name as requester_name', 'handler.name as handler_name'])
            ->map(function (object $row) use ($internal, $verified) {
                $data = array_intersect_key((array) $row, array_flip([
                    'id', 'kind', 'status', 'previous_slot', 'requested_slot', 'created_at', 'handled_at',
                ]));
                $data['actor'] = $row->status === 'pending' ? 'Khách hàng' : 'Nhân viên tiệm';
                if ($internal || $verified) {
                    $data['reason'] = $row->reason;
                    $data['response_message'] = $row->response_message;
                    $data['requester_name'] = $internal ? ($row->requester_name ?? 'Khách vãng lai') : 'Khách hàng';
                    $data['handler_name'] = $row->handled_at ? ($internal ? ($row->handler_name ?? 'Nhân viên tiệm') : 'Nhân viên tiệm') : null;
                }

                return $data;
            });
    }

    public static function requestLink(string $trackingToken, string $email): void
    {
        DB::transaction(function () use ($trackingToken, $email) {
            Store::settings(lock: true);
            $order = Orders::query()->where('tracking_token', $trackingToken)->lockForUpdate()->first();
            if (! $order || ! hash_equals(Str::lower(trim($order->email)), Str::lower(trim($email))) || ! Orders::canCustomerRequest($order)) {
                return;
            }
            // Resends are neutral and bounded per order, in addition to the route's IP throttle.
            if (DB::table('order_action_links')->where('order_id', $order->id)->where('created_at', '>', now()->subMinute())->exists()) {
                return;
            }
            $token = bin2hex(random_bytes(32));
            $expiresAt = now()->addMinutes(self::LINK_MINUTES);
            DB::table('order_action_links')->where('order_id', $order->id)->whereNull('used_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            DB::table('order_action_links')->insert([
                'order_id' => $order->id, 'token_hash' => hash('sha256', $token), 'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);
            OrderNotices::actionLink($order->id, $token, $expiresAt);
        }, 5);
    }

    public static function guestAccess(string $token): array
    {
        $link = self::findLink($token);
        self::assertLive($link);
        abort_if($link->used_at !== null, 410, 'Liên kết đã được dùng. Vui lòng yêu cầu liên kết mới từ trang tra cứu.');
        $order = Orders::query()->find($link->order_id);
        abort_unless($order, 410);

        return [$order, $link];
    }

    public static function submitOwner(int $orderId, User $actor, array $fields): object
    {
        $fields = Validator::make($fields, self::rules())->validate();

        return DB::transaction(function () use ($orderId, $actor, $fields) {
            Store::settings(lock: true);
            $order = Orders::query()->lockForUpdate()->find($orderId);
            abort_unless($order && self::isOwner($order, $actor), 404);

            return self::submit($order, $actor->id, $fields);
        }, 5);
    }

    public static function submitGuest(string $token, array $fields): object
    {
        $fields = Validator::make($fields, self::rules())->validate();
        $candidate = self::findLink($token);

        return DB::transaction(function () use ($candidate, $token, $fields) {
            Store::settings(lock: true);
            $order = Orders::query()->lockForUpdate()->find($candidate->order_id);
            $link = DB::table('order_action_links')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            self::assertLive($link);
            abort_unless($order && $order->id === $link->order_id, 410);
            if ($link->used_at !== null) {
                $existing = DB::table('order_change_requests')->find($link->order_change_request_id);
                if ($existing && $existing->request_key === $fields['request_key'] && hash_equals($existing->payload_hash, self::fingerprint($fields))) {
                    return $existing;
                }
                throw ValidationException::withMessages(['request_key' => 'Liên kết đã được dùng cho một yêu cầu khác.']);
            }
            $change = self::submit($order, null, $fields);
            DB::table('order_action_links')->where('id', $link->id)->update([
                'used_at' => now(), 'order_change_request_id' => $change->id,
            ]);

            return $change;
        }, 5);
    }

    public static function resolve(int $requestId, User $actor, array $fields): object
    {
        $fields = Validator::make($fields, ['decision' => 'required|in:approved,rejected', 'note' => 'required|string|max:200'])->validate();
        $candidate = DB::table('order_change_requests')->find($requestId);
        abort_unless($candidate, 404);

        return DB::transaction(function () use ($candidate, $requestId, $actor, $fields) {
            Store::settings(lock: true);
            $actor = $actor->fresh();
            abort_unless($actor?->active && $actor->isStaff(), 403);
            $order = Orders::query()->lockForUpdate()->find($candidate->order_id);
            $change = DB::table('order_change_requests')->lockForUpdate()->find($requestId);
            abort_unless($order && $change, 404);
            if ($change->status !== 'pending') {
                if ($change->status === $fields['decision'] && $change->response_message === $fields['note']) {
                    return $change;
                }
                throw ValidationException::withMessages(['decision' => 'Yêu cầu đã được người khác xử lý. Vui lòng tải lại trang.']);
            }
            if ($fields['decision'] === 'approved') {
                if (! Orders::canCustomerRequest($order) || $change->previous_status !== $order->status || $change->previous_slot !== $order->scheduled_at) {
                    throw ValidationException::withMessages(['decision' => 'Đơn đã đổi giờ, đổi trạng thái hoặc quá mốc nhận yêu cầu. Tải lại và trao đổi với khách trước khi xử lý.']);
                }
                if ($change->kind === 'reschedule') {
                    Orders::reschedule($order->id, $actor, [
                        'previous_slot' => substr($change->previous_slot, 0, 16),
                        'slot' => substr($change->requested_slot, 0, 16), 'note' => $fields['note'],
                    ], app(Checkout::class));
                } else {
                    Orders::change($order->id, $actor, ['kind' => 'status', 'value' => 'cancelled', 'note' => $fields['note']]);
                }
            }
            DB::table('order_change_requests')->where('id', $requestId)->update([
                'status' => $fields['decision'], 'response_message' => $fields['note'],
                'handled_by' => $actor->id, 'handled_at' => now(), 'open_order_id' => null, 'updated_at' => now(),
            ]);
            $eventId = Orders::event($order->id, $actor->id, 'request', $fields['decision'], 'Yêu cầu #'.$requestId.'. '.$fields['note']);
            OrderNotices::record($order->id, 'request_'.$fields['decision'], [
                'kind' => $change->kind, 'request_id' => $requestId,
                'previous_slot' => $change->previous_slot, 'requested_slot' => $change->requested_slot,
                'response_message' => $fields['note'],
            ], $eventId, 'request:'.$requestId.':'.$fields['decision']);

            return DB::table('order_change_requests')->find($requestId);
        }, 5);
    }

    private static function submit(object $order, ?int $userId, array $fields): object
    {
        $existing = DB::table('order_change_requests')->where('order_id', $order->id)->where('request_key', $fields['request_key'])->first();
        if ($existing) {
            if (! hash_equals($existing->payload_hash, self::fingerprint($fields))) {
                throw ValidationException::withMessages(['request_key' => 'Khóa yêu cầu đã được dùng với nội dung khác. Vui lòng tải lại trang.']);
            }

            return $existing;
        }
        if (! Orders::canCustomerRequest($order) || $order->scheduled_at !== $fields['previous_slot'].':00') {
            throw ValidationException::withMessages(['previous_slot' => 'Đơn đã thay đổi hoặc đã quá mốc yêu cầu đổi/hủy. Liên hệ tiệm để được hỗ trợ.']);
        }
        if (DB::table('order_change_requests')->where('open_order_id', $order->id)->exists()) {
            throw ValidationException::withMessages(['kind' => 'Tiệm đang xử lý yêu cầu trước của đơn này.']);
        }
        $slot = $fields['kind'] === 'reschedule' ? $fields['requested_slot'] : null;
        if ($slot !== null) {
            if ($slot === $fields['previous_slot']) {
                throw ValidationException::withMessages(['requested_slot' => 'Hãy chọn giờ mới khác giờ đang giữ.']);
            }
            app(Checkout::class)->assertSlot($slot, Store::settings(), Orders::prepMinutes($order->id));
        }
        $id = DB::table('order_change_requests')->insertGetId([
            'order_id' => $order->id, 'user_id' => $userId, 'request_key' => $fields['request_key'],
            'payload_hash' => self::fingerprint($fields), 'kind' => $fields['kind'], 'status' => 'pending',
            'previous_status' => $order->status, 'previous_slot' => $order->scheduled_at,
            'requested_slot' => $slot ? $slot.':00' : null, 'reason' => $fields['reason'],
            'open_order_id' => $order->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $eventId = Orders::event($order->id, $userId, 'request', $fields['kind'], 'Yêu cầu #'.$id.'. '.$fields['reason']);
        OrderNotices::record($order->id, 'request_created', [
            'kind' => $fields['kind'], 'request_id' => $id, 'previous_slot' => $order->scheduled_at,
            'requested_slot' => $slot ? $slot.':00' : null,
        ], $eventId, 'request:'.$id.':pending');

        return DB::table('order_change_requests')->find($id);
    }

    private static function fingerprint(array $fields): string
    {
        return hash('sha256', json_encode([
            $fields['kind'], $fields['previous_slot'], $fields['kind'] === 'reschedule' ? $fields['requested_slot'] : null, $fields['reason'],
        ], JSON_THROW_ON_ERROR));
    }

    public static function rules(): array
    {
        return [
            'request_key' => 'required|uuid', 'kind' => 'required|in:reschedule,cancel',
            'previous_slot' => 'required|date_format:Y-m-d H:i',
            'requested_slot' => 'nullable|required_if:kind,reschedule|date_format:Y-m-d H:i',
            'reason' => 'required|string|max:200',
        ];
    }

    private static function isOwner(object $order, ?User $actor): bool
    {
        $actor = $actor?->fresh();

        return $actor?->active && $order->user_id !== null && $actor->id === (int) $order->user_id;
    }

    private static function findLink(string $token): object
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 410);
        $link = DB::table('order_action_links')->where('token_hash', hash('sha256', $token))->first();
        abort_unless($link, 410, 'Liên kết không còn hiệu lực.');

        return $link;
    }

    private static function assertLive(?object $link): void
    {
        abort_if(! $link || $link->revoked_at !== null || CarbonImmutable::parse($link->expires_at)->lessThanOrEqualTo(now()), 410,
            'Liên kết đã hết hạn. Vui lòng yêu cầu liên kết mới từ trang tra cứu.');
    }
}
