<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Checkout
{
    public function cart(array $cart): array
    {
        $variants = DB::table('variants')->join('products', 'products.id', '=', 'variants.product_id')
            ->whereIn('variants.id', array_column($cart, 'variant_id'))
            ->select('variants.*', 'products.name as product_name', 'products.active as product_active',
                'products.slug', 'products.images', 'products.prep_minutes', 'products.customizable', 'products.message_fee')
            ->get()->keyBy('id');

        $choices = DB::table('variants')->whereIn('product_id', $variants->pluck('product_id'))
            ->where('active', true)->orderBy('id')->get(['id', 'name', 'price', 'stock', 'product_id'])->groupBy('product_id');
        $requested = collect($cart)->groupBy('variant_id')->map(fn ($lines) => $lines->sum('quantity'));

        return collect($cart)->map(function ($line, $key) use ($variants, $choices, $requested) {
            $variant = $variants->get($line['variant_id']);
            $available = $variant && $variant->active && $variant->product_active
                && $variant->stock >= $requested[$line['variant_id']];
            $customizable = (bool) ($variant?->customizable ?? false);
            $message = $customizable ? $line['message'] : null;
            $fee = $message !== null && $message !== '' ? (int) $variant->message_fee : 0;

            return [
                'key' => $key, 'variant_id' => $line['variant_id'], 'quantity' => $line['quantity'],
                'product_name' => $variant?->product_name ?? 'Bánh đã ngừng bán',
                'variant_name' => $variant?->name ?? '',
                'unit_price' => (int) ($variant?->price ?? 0), 'option_fee' => $fee,
                'subtotal' => ((int) ($variant?->price ?? 0) + $fee) * $line['quantity'],
                'message' => $message, 'note' => $line['note'], 'available' => (bool) $available,
                'customizable' => $customizable, 'message_fee' => (int) ($variant?->message_fee ?? 0),
                'prep_minutes' => (int) ($variant?->prep_minutes ?? 0), 'slug' => $variant?->slug,
                'image' => $variant ? (json_decode($variant->images, true)[0] ?? null) : null,
                'choices' => $variant ? $choices->get($variant->product_id, collect())->values() : [],
            ];
        })->values()->all();
    }

    public function slots(array $settings, int $prepMinutes): array
    {
        $now = now();
        $minimum = $now->copy()->addMinutes($prepMinutes);
        $slots = $settings['slots'];
        sort($slots);
        $result = [];

        for ($days = 0; $days < 14; $days++) {
            $day = $now->copy()->startOfDay()->addDays($days);
            if (! in_array($day->dayOfWeekIso, $settings['weekdays'], true) || in_array($day->toDateString(), $settings['closed_dates'], true)) {
                continue;
            }

            foreach ($slots as $time) {
                $date = $day->copy()->setTimeFromTimeString($time);
                if ($date->greaterThanOrEqualTo($minimum)) {
                    $result[] = $date->format('Y-m-d H:i');
                }
            }
        }

        return $result;
    }

    public function quote(array $lines, array $fields, array $settings, bool $lock = false): array
    {
        if (! $lines || collect($lines)->contains('available', false)) {
            $this->invalid('cart', 'Giỏ hàng trống hoặc có bánh không còn đủ tồn. Vui lòng cập nhật giỏ hàng.');
        }

        $subtotal = array_sum(array_column($lines, 'subtotal'));
        $discount = 0;
        $coupon = null;
        $code = Str::upper(trim($fields['coupon'] ?? ''));

        if ($code !== '') {
            $query = DB::table('coupons')->where('code', $code);
            $coupon = ($lock ? $query->lockForUpdate() : $query)->first();
            $today = now()->toDateString();

            if (! $coupon || ! $coupon->active || ($coupon->starts_on && $today < $coupon->starts_on)
                || ($coupon->ends_on && $today > $coupon->ends_on) || ($coupon->usage_limit !== null && $coupon->used >= $coupon->usage_limit)
                || $subtotal < $coupon->minimum) {
                $this->invalid('coupon', 'Mã giảm giá không hợp lệ, đã hết lượt hoặc chưa đủ giá trị đơn tối thiểu.');
            }

            $discount = min($subtotal, $coupon->kind === 'percent' ? intdiv($subtotal * $coupon->amount, 100) : $coupon->amount);
        }

        $region = null;
        $fee = 0;
        if ($fields['fulfillment'] === 'delivery') {
            $region = collect($settings['regions'])->firstWhere('key', $fields['region'] ?? null);
            if (! $region) {
                $this->invalid('region', 'Vui lòng chọn một khu vực giao hàng được hỗ trợ.');
            }

            $fee = $settings['free_from'] !== null && $subtotal - $discount >= $settings['free_from'] ? 0 : (int) $region['fee'];
        }

        return [
            'subtotal' => $subtotal, 'discount' => $discount, 'delivery_fee' => $fee, 'total' => $subtotal - $discount + $fee,
            'coupon_id' => $coupon?->id, 'coupon_code' => $coupon?->code, 'region_label' => $region['name'] ?? null,
        ];
    }

    public function place(array $cart, array $fields, ?int $userId): array
    {
        return DB::transaction(function () use ($cart, $fields, $userId) {
            // Serialize changes to the shop's inventory, coupon use and schedule.
            // Catalog/configuration edits and cancellations take the same lock first.
            $settings = Store::settings(lock: true);
            $requestKey = hash('sha256', $fields['key']);
            $existing = DB::table('orders')->where('request_key', $requestKey)->first();
            if ($existing) {
                return [$existing, false];
            }

            $lines = $this->cart($cart);
            $quote = $this->quote($lines, $fields, $settings, lock: true);

            if (! in_array($fields['slot'], $this->slots($settings, max(array_column($lines, 'prep_minutes'))), true)) {
                $this->invalid('slot', 'Giờ nhận không còn phù hợp với lịch mở cửa hoặc thời gian làm bánh.');
            }

            $bank = $fields['method'] === 'bank' ? Store::bank($settings) : null;
            if ($fields['method'] === 'bank' && ! $bank) {
                $this->invalid('method', 'Cửa hàng chưa mở thanh toán chuyển khoản.');
            }

            $code = 'NB-'.now()->format('ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
            $id = DB::table('orders')->insertGetId([
                ...$quote,
                'code' => $code, 'request_key' => $requestKey, 'tracking_token' => bin2hex(random_bytes(32)),
                'user_id' => $userId, 'name' => $fields['name'], 'email' => $fields['email'], 'phone' => $fields['phone'],
                'fulfillment' => $fields['fulfillment'], 'address' => $fields['fulfillment'] === 'delivery' ? $fields['address'] : null,
                'scheduled_at' => $fields['slot'].':00', 'method' => $fields['method'],
                'bank_snapshot' => $bank ? json_encode($bank, JSON_THROW_ON_ERROR) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($lines as $line) {
                $reserved = DB::table('variants')->where('id', $line['variant_id'])->where('stock', '>=', $line['quantity'])
                    ->decrement('stock', $line['quantity']);
                if (! $reserved) {
                    $this->invalid('cart', 'Bánh đã hết hoặc không còn đủ số lượng.');
                }

                DB::table('order_items')->insert([
                    ...array_intersect_key($line, array_flip(['variant_id', 'product_name', 'variant_name', 'unit_price', 'option_fee', 'quantity', 'message', 'note'])),
                    'order_id' => $id,
                ]);
            }
            if ($quote['coupon_id']) {
                DB::table('coupons')->where('id', $quote['coupon_id'])->increment('used');
            }
            Orders::event($id, null, 'status', 'pending');

            return [DB::table('orders')->find($id), true];
        }, 5);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
