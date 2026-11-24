<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Checkout
{
    public function cart(array $cart): array
    {
        $variants = $this->cartVariants(array_column($cart, 'variant_id'));

        $choices = DB::table('variants')->whereIn('product_id', $variants->pluck('product_id'))
            ->where('active', true)->orderBy('id')->get(['id', 'name', 'price', 'stock', 'product_id'])->groupBy('product_id');
        $requested = collect($cart)->groupBy('variant_id')->map(fn ($lines) => $lines->sum('quantity'));

        return collect($cart)->map(function ($line, $key) use ($variants, $choices, $requested) {
            $variant = $variants->get($line['variant_id']);
            $accessoryChoices = $variant ? ProductMetadata::accessories($variant->product_id) : collect();
            $selectedIds = array_values(array_unique(array_map('intval', $line['accessories'] ?? [])));
            $accessories = $accessoryChoices->whereIn('id', $selectedIds)->values();
            $accessoriesAvailable = $accessories->count() === count($selectedIds);
            $accessoryFee = (int) $accessories->sum('price');
            $stockAvailable = $variant && $variant->active && $variant->product_active
                && $variant->stock >= $requested[$line['variant_id']];
            $available = $stockAvailable && $accessoriesAvailable;
            $customizable = (bool) ($variant?->customizable ?? false);
            $message = $customizable ? $line['message'] : null;
            $fee = $message !== null && $message !== '' ? (int) $variant->message_fee : 0;

            return [
                'key' => $key, 'variant_id' => $line['variant_id'], 'quantity' => $line['quantity'],
                'product_name' => $variant?->product_name ?? 'Bánh đã ngừng bán',
                'variant_name' => $variant?->name ?? '',
                'unit_price' => (int) ($variant?->price ?? 0), 'option_fee' => $fee,
                'accessory_fee' => $accessoryFee, 'accessories' => $accessories->all(),
                'accessory_choices' => $accessoryChoices->values()->all(), 'accessories_available' => $accessoriesAvailable,
                'stock_available' => (bool) $stockAvailable,
                'subtotal' => ((int) ($variant?->price ?? 0) + $fee + $accessoryFee) * $line['quantity'],
                'message' => $message, 'note' => $line['note'], 'available' => (bool) $available,
                'customizable' => $customizable, 'message_fee' => (int) ($variant?->message_fee ?? 0),
                'prep_minutes' => (int) ($variant?->prep_minutes ?? 0), 'slug' => $variant?->slug,
                'image' => $variant ? (json_decode($variant->images, true)[0] ?? null) : null,
                'choices' => $variant ? $choices->get($variant->product_id, collect())->values() : [],
            ];
        })->values()->all();
    }

    public function reorder(array $cart, Collection $items): array
    {
        $variants = $this->cartVariants($items->pluck('variant_id')->all());
        $inCart = collect($cart)->groupBy('variant_id')->map(fn ($lines) => $lines->sum('quantity'));
        $added = 0;

        foreach ($items as $item) {
            $variant = $variants->get($item->variant_id);
            if (! $variant?->active || ! $variant->product_active || count($cart) >= 30) {
                continue;
            }

            $alreadyInCart = $inCart->get($item->variant_id, 0);
            $quantity = min($item->quantity, 30, $variant->stock - $alreadyInCart);
            if ($quantity <= 0) {
                continue;
            }

            $cart[(string) Str::uuid()] = [
                'variant_id' => (int) $item->variant_id, 'quantity' => $quantity, 'message' => null, 'note' => null,
            ];
            $inCart->put($item->variant_id, $alreadyInCart + $quantity);
            $added += $quantity;
        }

        return [$cart, $added, $items->sum('quantity') - $added];
    }

    private function cartVariants(array $ids): Collection
    {
        return DB::table('variants')->join('products', 'products.id', '=', 'variants.product_id')
            ->whereIn('variants.id', $ids)
            ->select('variants.*', 'products.name as product_name', 'products.active as product_active',
                'products.slug', 'products.images', 'products.prep_minutes', 'products.customizable', 'products.message_fee')
            ->get()->keyBy('id');
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
            if (! Store::openOn($settings, $day)) {
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

    public function availableSlots(array $settings, int $prepMinutes): array
    {
        $slots = $this->slots($settings, $prepMinutes);
        $limits = $settings['slot_limits'];
        $limitedTimes = [];
        foreach ($slots as $slot) {
            if (isset($limits[substr($slot, 11)])) {
                $limitedTimes[] = $slot.':00';
            }
        }
        if (! $limitedTimes) {
            return $slots;
        }

        $booked = Orders::scheduled()->whereIn('scheduled_at', $limitedTimes)
            ->groupBy('scheduled_at')->selectRaw('scheduled_at, COUNT(*) AS booked')->pluck('booked', 'scheduled_at');

        return array_values(array_filter($slots, function (string $slot) use ($booked, $limits) {
            $limit = $limits[substr($slot, 11)] ?? null;

            return $limit === null || ($booked[$slot.':00'] ?? 0) < $limit;
        }));
    }

    public function quote(array $lines, array $fields, array $settings, bool $lock = false): array
    {
        if (! $settings['accepting_orders']) {
            $this->invalid('cart', Store::pauseMessage($settings));
        }

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

    public function quoteToken(array $quote, array $lines, string $key): string
    {
        $prices = array_map(fn (array $line) => [
            $line['variant_id'], $line['quantity'], $line['unit_price'], $line['option_fee'],
            $line['accessory_fee'] ?? 0, $line['accessories'] ?? [],
        ], $lines);

        return hash_hmac('sha256', json_encode([$key, $quote, $prices], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function place(array $cart, array $fields, ?int $userId): array
    {
        return DB::transaction(function () use ($cart, $fields, $userId) {
            // Serialize changes to the shop's inventory, coupon use and schedule.
            // Catalog/configuration edits and cancellations take the same lock first.
            $settings = Store::settings(lock: true);
            $requestKey = hash('sha256', $fields['key']);
            $existing = Orders::query()->where('request_key', $requestKey)->first();
            if ($existing) {
                return [$existing, false];
            }

            $lines = $this->cart($cart);
            $quote = $this->quote($lines, $fields, $settings, lock: true);

            $scheduledAt = $this->assertSlot($fields['slot'], $settings, max(array_column($lines, 'prep_minutes')));

            if (! hash_equals($this->quoteToken($quote, $lines, $fields['key']), $fields['quote_token'])) {
                $this->invalid('quote_token', 'Giá bánh, giảm giá hoặc phí giao đã thay đổi. Hãy kiểm tra tổng mới và xác nhận lại.');
            }

            $bank = $fields['method'] === 'bank' ? Store::bank($settings) : null;
            if ($fields['method'] === 'bank' && ! $bank) {
                $this->invalid('method', 'Cửa hàng chưa mở thanh toán chuyển khoản.');
            }

            $createdAt = now();
            $code = 'NB-'.$createdAt->format('ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
            $id = DB::table('orders')->insertGetId([
                ...$quote,
                'location_id' => Store::locationId(), 'prep_minutes_snapshot' => max(array_column($lines, 'prep_minutes')),
                'bank_prepay_required' => $fields['method'] === 'bank' && $settings['bank_prepay_required'],
                'code' => $code, 'request_key' => $requestKey, 'tracking_token' => bin2hex(random_bytes(32)),
                'user_id' => $userId, 'name' => $fields['name'], 'email' => $fields['email'], 'phone' => $fields['phone'],
                'fulfillment' => $fields['fulfillment'], 'address' => $fields['fulfillment'] === 'delivery' ? $fields['address'] : null,
                'scheduled_at' => $scheduledAt, 'method' => $fields['method'],
                'bank_snapshot' => $bank ? json_encode($bank, JSON_THROW_ON_ERROR) : null,
                'bank_due_at' => $bank ? Orders::bankDueAt($createdAt, $scheduledAt, $settings['bank_due_minutes']) : null,
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);

            $quantities = collect($lines)->groupBy('variant_id')->map(fn (Collection $items) => $items->sum('quantity'));
            foreach ($quantities->sortKeys() as $variantId => $quantity) {
                if (! Inventory::move($variantId, -$quantity, 'order', orderId: $id)) {
                    $this->invalid('cart', 'Bánh đã hết hoặc không còn đủ số lượng.');
                }
            }

            foreach ($lines as $line) {
                DB::table('order_items')->insert([
                    ...array_intersect_key($line, array_flip(['variant_id', 'product_name', 'variant_name', 'unit_price', 'option_fee', 'quantity', 'message', 'note'])),
                    'order_id' => $id,
                    'accessory_fee' => $line['accessory_fee'],
                    'accessories_snapshot' => json_encode($line['accessories'], JSON_THROW_ON_ERROR),
                ]);
            }
            if ($quote['coupon_id']) {
                DB::table('coupons')->where('id', $quote['coupon_id'])->increment('used');
            }
            $event = Orders::event($id, null, 'status', 'pending');
            OrderNotices::record($id, 'pending', [], $event);

            return [DB::table('orders')->find($id), true];
        }, 5);
    }

    // The caller holds the shop settings lock until the booking or reschedule commits.
    public function assertSlot(string $slot, array $settings, int $prepMinutes): string
    {
        if (! in_array($slot, $this->slots($settings, $prepMinutes), true)) {
            $this->invalid('slot', 'Giờ nhận không còn phù hợp với lịch mở cửa hoặc thời gian làm bánh.');
        }
        $scheduledAt = $slot.':00';
        $limit = $settings['slot_limits'][substr($slot, 11)] ?? null;
        if ($limit !== null && Orders::scheduled()->where('scheduled_at', $scheduledAt)->count() >= $limit) {
            $this->invalid('slot', 'Khung giờ vừa hết chỗ. Vui lòng chọn giờ khác.');
        }

        return $scheduledAt;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
