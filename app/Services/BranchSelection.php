<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchSelection
{
    public static function preview(array $cart, array $fields, ?int $cartBranchId = null): array
    {
        $cartBranchId ??= Branches::id();
        $data = self::calculate($cart, $fields, $cartBranchId);
        $expires = now()->addMinutes(10)->timestamp;

        return $data + ['quote_token' => Crypt::encryptString(json_encode(['purpose' => 'cart-branch', 'from' => Branches::id(),
            'target' => (int) $fields['target_branch_id'], 'context' => $fields, 'cart' => self::digest($cart), 'cart_branch' => $cartBranchId,
            'quote' => self::digest($data), 'expires' => $expires], JSON_THROW_ON_ERROR)), 'expires_at' => date(DATE_ATOM, $expires)];
    }

    public static function confirm(array $cart, array $fields, ?int $cartBranchId = null): array
    {
        try {
            $token = json_decode(Crypt::decryptString($fields['quote_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            self::invalid('quote_token', 'Báo giá không hợp lệ. Hãy kiểm tra lại cơ sở.');
        }
        if (($token['purpose'] ?? '') !== 'cart-branch' || ($token['expires'] ?? 0) < now()->timestamp
            || ($token['from'] ?? null) !== Branches::id() || ($token['target'] ?? null) !== (int) $fields['branch_id']
            || ($token['cart_branch'] ?? null) !== ($cartBranchId ?? Branches::id())
            || ! hash_equals($token['cart'] ?? '', self::digest($cart))) {
            self::invalid('quote_token', 'Giỏ hoặc cơ sở đã đổi, hoặc báo giá hết hạn. Giỏ cũ vẫn được giữ.');
        }

        return DB::transaction(function () use ($cart, $fields, $token) {
            $ids = array_unique([Branches::id(), $token['cart_branch'], (int) $fields['branch_id']]);
            sort($ids);
            DB::table('branches')->whereIn('id', $ids)->orderBy('id')->sharedLock()->get();
            DB::table('chain_settings')->where('id', 1)->sharedLock()->first();
            $fresh = self::calculate($cart, $token['context'], $token['cart_branch']);
            if (! hash_equals($token['quote'], self::digest($fresh))) {
                self::invalid('quote_token', 'Giá, phí, tồn hoặc giờ đã đổi. Kiểm tra lại và xác nhận báo giá mới.');
            }
            if ($fields['mode'] === 'keep' && ! $fresh['can_keep_cart']) {
                self::invalid('mode', 'Cơ sở chưa nhận đủ giỏ. Giữ cơ sở cũ hoặc xác nhận bỏ giỏ.');
            }
            $context = $token['context'];
            $draft = ['fulfillment' => $context['fulfillment'], 'method' => $context['method'], 'slot' => $fresh['new']['slot'] ?? ''];
            if ($fields['mode'] === 'keep' && $context['fulfillment'] === 'delivery' && filled($context['address_line'] ?? null)) {
                $draft += Branches::run((int) $fields['branch_id'], fn () => DeliveryAddress::normalize($context, Store::settings()['regions']));
            }

            return ['quote' => $fresh, 'draft' => $draft];
        }, 5);
    }

    private static function calculate(array $cart, array $fields, int $origin): array
    {
        abort_unless(Branches::enabled(), 404);
        $target = DB::table('branches')->where('id', $fields['target_branch_id'])->where('active', true)->first();
        abort_unless($target, 404);
        $source = Branches::find($origin);
        abort_unless($source, 404);
        $before = self::atBranch($origin, $cart, $fields);
        $after = self::atBranch((int) $target->id, $cart, $fields);

        return ['from' => ['id' => (int) $source->id, 'name' => $source->name], 'to' => ['id' => (int) $target->id, 'name' => $target->name],
            'old' => $before['quote'], 'new' => $after['quote'], 'can_keep_cart' => $after['valid'], 'blocked_reason' => $after['reason'],
            'changes' => ['Tổng tiền: '.($before['quote']['total'] ?? 'chưa xác định').' → '.($after['quote']['total'] ?? 'chưa xác định').' VND',
                'Phí giao: '.($before['quote']['delivery_fee'] ?? 'chưa xác định').' → '.($after['quote']['delivery_fee'] ?? 'chưa xác định').' VND',
                'Giờ dự kiến: '.($before['quote']['slot'] ?? 'chưa xác định').' → '.($after['quote']['slot'] ?? 'chưa xác định'),
                'Chưa giữ tồn hoặc khung giờ. Máy chủ kiểm tra lại khi đặt.'],
        ];
    }

    private static function atBranch(int $id, array $cart, array $fields): array
    {
        return Branches::run($id, function () use ($cart, $fields, $id) {
            $checkout = app(Checkout::class);
            $settings = Store::settings();
            $lines = $checkout->cart($cart);
            $quote = ['lines' => collect($lines)->map(fn ($line) => array_intersect_key($line, array_flip(['key', 'product_name', 'variant_name', 'quantity', 'unit_price', 'option_fee', 'accessory_fee', 'subtotal', 'available'])))->all()];
            try {
                $region = ($fields['fulfillment'] ?? '') === 'delivery' ? DeliveryAddress::regionForArea($settings['regions'], $fields['area_code'] ?? '') : null;
                if (($fields['fulfillment'] ?? '') === 'delivery') {
                    DeliveryAddress::normalize($fields, $settings['regions'], requireLine: false);
                }
                if (($fields['method'] ?? '') === 'bank' && ! Store::bank($settings)) {
                    self::invalid('method', 'Cơ sở chưa mở chuyển khoản.');
                }
                if (! $cart) {
                    return ['valid' => true, 'quote' => $quote + ['total' => 0, 'delivery_fee' => 0, 'slot' => null], 'reason' => null];
                }
                $quote += $checkout->quote($lines, ['branch_id' => $id, ...$fields, 'region' => $region['key'] ?? null], $settings);
                $slots = $checkout->availableSlots($settings, max(array_column($lines, 'prep_minutes')));
                $slot = $fields['slot'] ?? $fields['desired_slot'] ?? null;
                if ($slot && ! in_array($slot, $slots, true)) {
                    self::invalid('slot', 'Giờ đã chọn không còn phù hợp tại cơ sở này.');
                }
                if (! $slots) {
                    self::invalid('slot', 'Chưa có khung giờ còn chỗ cho cả giỏ.');
                }

                return ['valid' => true, 'quote' => $quote + ['slot' => $slot ?: $slots[0]], 'reason' => null];
            } catch (ValidationException $failure) {
                return ['valid' => false, 'quote' => $quote, 'reason' => collect($failure->errors())->flatten()->implode(' ')];
            }
        });
    }

    private static function digest(array $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), config('app.key'));
    }

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
