<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Checkout
{
    public function cart(array $cart): array
    {
        $currentRead = Branches::enabled() && DB::transactionLevel() > 0;
        $variants = $this->cartVariants(array_column($cart, 'variant_id'), currentRead: $currentRead);

        $choiceIds = DB::table('variants')->whereIn('product_id', $variants->pluck('product_id'))->orderBy('id')->pluck('id')->all();
        $choices = $this->cartVariants($choiceIds)->filter(fn ($variant) => $variant->active)
            ->map(fn ($variant) => (object) array_intersect_key((array) $variant, array_flip(['id', 'name', 'price', 'stock', 'product_id'])))
            ->groupBy('product_id');
        $requested = collect($cart)->groupBy('variant_id')->map(fn ($lines) => $lines->sum('quantity'));

        return collect($cart)->map(function ($line, $key) use ($variants, $choices, $requested, $currentRead) {
            $variant = $variants->get($line['variant_id']);
            $accessoryChoices = $variant ? ProductMetadata::accessories($variant->product_id, currentRead: $currentRead) : collect();
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

    private function cartVariants(array $ids, bool $currentRead = false): Collection
    {
        $query = DB::table('variants')->join('products', 'products.id', '=', 'variants.product_id')
            ->whereIn('variants.id', $ids)
            ->select('variants.*', 'products.name as product_name', 'products.active as product_active',
                'products.slug', 'products.images', 'products.prep_minutes', 'products.customizable', 'products.message_fee');
        if (Branches::enabled()) {
            $query->leftJoin('branch_variants as branch_sku', fn ($join) => $join->on('branch_sku.variant_id', '=', 'variants.id')->where('branch_sku.branch_id', Branches::id()))
                ->selectRaw('COALESCE(branch_sku.price, variants.price) AS price, COALESCE(branch_sku.stock, 0) AS stock,
                    CASE WHEN variants.active = 1 AND branch_sku.active = 1 THEN 1 ELSE 0 END AS active,
                    COALESCE(branch_sku.prep_minutes, products.prep_minutes) AS prep_minutes');
        }

        return ($currentRead ? $query->sharedLock() : $query)->get()->keyBy('id');
    }

    public function slots(array $settings, int $prepMinutes): array
    {
        $now = now($settings['timezone'] ?? 'Asia/Ho_Chi_Minh');
        $minimum = $now->copy()->addMinutes($prepMinutes);
        $result = [];

        for ($days = 0; $days < 14; $days++) {
            $day = $now->copy()->startOfDay()->addDays($days);
            if (! Store::openOn($settings, $day)) {
                continue;
            }

            $slots = Store::exception($settings, $day)['slots'] ?? $settings['slots'];
            sort($slots);
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
        $times = array_map(fn ($slot) => $slot.':00', $slots);
        $booked = Branches::enabled()
            ? DB::table('branch_slot_locks')->where('branch_id', Branches::id())->whereIn('scheduled_at', $times)->pluck('booked', 'scheduled_at')
            : Orders::scheduled()->whereIn('scheduled_at', $times)->groupBy('scheduled_at')->selectRaw('scheduled_at, COUNT(*) AS booked')->pluck('booked', 'scheduled_at');

        return array_values(array_filter($slots, function (string $slot) use ($booked, $settings) {
            $limit = $this->slotLimit($slot, $settings);

            return $limit === null || ($booked[$slot.':00'] ?? 0) < $limit;
        }));
    }

    public function quote(array $lines, array $fields, array $settings, bool $lock = false, ?int $reservedCouponId = null): array
    {
        if (! $settings['accepting_orders']) {
            $this->invalid('cart', Store::pauseMessage($settings));
        }
        if (Branches::enabled() && isset($fields['branch_id']) && (int) $fields['branch_id'] !== Branches::id()) {
            $this->invalid('branch_id', 'Cơ sở đã thay đổi. Vui lòng kiểm tra lại giỏ và giờ nhận.');
        }
        if (! in_array($fields['fulfillment'], $settings['fulfillments'] ?? ['pickup', 'delivery'], true)) {
            $this->invalid('fulfillment', 'Cơ sở không hỗ trợ phương thức nhận này.');
        }
        if (! empty($fields['method']) && ! in_array($fields['method'], $settings['payment_methods'] ?? ['cod', 'bank'], true)) {
            $this->invalid('method', 'Cơ sở không hỗ trợ phương thức thanh toán này.');
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
            $coupon = ($lock ? $query->lockForUpdate() : (DB::transactionLevel() > 0 ? $query->sharedLock() : $query))->first();
            $today = now()->toDateString();

            if (! $coupon || ! $coupon->active || ($coupon->starts_on && $today < $coupon->starts_on)
                || ($coupon->ends_on && $today > $coupon->ends_on) || ($coupon->usage_limit !== null && $coupon->used - ((int) $coupon->id === $reservedCouponId ? 1 : 0) >= $coupon->usage_limit)
                || $subtotal < $coupon->minimum) {
                $this->invalid('coupon', 'Mã giảm giá không hợp lệ, đã hết lượt hoặc chưa đủ giá trị đơn tối thiểu.');
            }
            if ($coupon->branch_id !== null && (int) $coupon->branch_id !== Branches::id()) {
                $this->invalid('coupon', 'Mã giảm giá không áp dụng cho cơ sở này.');
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

    public function quoteToken(array $quote, array $lines, string $key, array $context = []): string
    {
        $prices = array_map(fn (array $line) => [
            $line['variant_id'], $line['quantity'], $line['unit_price'], $line['option_fee'],
            $line['accessory_fee'] ?? 0, $line['accessories'] ?? [],
            $line['message'] ?? null, $line['note'] ?? null, $line['prep_minutes'] ?? 0,
        ], $lines);

        $binding = [];
        if (Branches::enabled()) {
            $settings = Store::settings();
            $delivery = $this->delivery($context, $settings, false);
            $binding = [Branches::id(), [$context['fulfillment'] ?? null, $context['region'] ?? null, $context['method'] ?? null, $context['slot'] ?? null,
                $delivery ? DeliveryAddress::fingerprint($delivery) : null],
                array_intersect_key($settings, array_flip(['regions', 'free_from', 'slots', 'slot_limits', 'weekdays', 'closed_dates',
                    'calendar_exceptions', 'fulfillments', 'payment_methods', 'bank_prepay_required', 'bank_due_minutes', 'timezone']))];
        }

        return hash_hmac('sha256', json_encode([$key, $quote, $prices, ...$binding], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function place(array $cart, array $fields, ?int $userId): array
    {
        return DB::transaction(function () use ($cart, $fields, $userId) {
            $requestKey = hash('sha256', $fields['key']);
            if (Branches::enabled()) {
                DB::table('checkout_request_locks')->insertOrIgnore(['request_key' => $requestKey, 'branch_id' => Branches::id(), 'created_at' => now()]);
                $claim = DB::table('checkout_request_locks')->where('request_key', $requestKey)->lockForUpdate()->first();
                if ((int) $claim->branch_id !== Branches::id()) {
                    $this->invalid('key', 'Yêu cầu đã được dùng tại cơ sở khác. Vui lòng tải lại bước đặt bánh.');
                }
            }
            // Shared configuration lock permits independent SKU/slot bookings in this branch.
            $settings = Store::settings(lock: true, shared: true);
            $existing = DB::table('orders')->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($existing) {
                if ((int) ($existing->branch_id ?? 1) !== Branches::id()) {
                    $this->invalid('key', 'Yêu cầu đã thuộc cơ sở khác.');
                }

                return [$existing, false];
            }

            if (Branches::enabled()) {
                if (! empty($fields['coupon'])) {
                    DB::table('coupons')->where('code', Str::upper(trim($fields['coupon'])))->lockForUpdate()->first();
                }
                Branches::lockSlots([$fields['slot'].':00']);
                Inventory::lock(array_column($cart, 'variant_id'));
            }

            $lines = $this->cart($cart);
            $quote = $this->quote($lines, $fields, $settings, lock: true);

            $scheduledAt = $this->assertSlot($fields['slot'], $settings, max(array_column($lines, 'prep_minutes')));

            if (! hash_equals($this->quoteToken($quote, $lines, $fields['key'], $fields), $fields['quote_token'])) {
                $this->invalid('quote_token', 'Giá bánh, giảm giá hoặc phí giao đã thay đổi. Hãy kiểm tra tổng mới và xác nhận lại.');
            }

            $bank = $fields['method'] === 'bank' ? Store::bank($settings) : null;
            if ($fields['method'] === 'bank' && ! $bank) {
                $this->invalid('method', 'Cửa hàng chưa mở thanh toán chuyển khoản.');
            }

            $delivery = $this->delivery($fields, $settings, true);
            $createdAt = now();
            $code = 'NB-'.$createdAt->format('ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
            $id = DB::table('orders')->insertGetId([
                ...$quote,
                'branch_id' => Branches::id(), 'branch_snapshot' => json_encode(Branches::snapshot(), JSON_THROW_ON_ERROR),
                'sales_context' => json_encode(['branch_id' => Branches::id(), 'timezone' => $settings['timezone'],
                    'fulfillment' => $fields['fulfillment'], 'method' => $fields['method'], 'region_key' => $fields['region'] ?? null,
                    'region_label' => $quote['region_label'], 'scheduled_local' => $fields['slot'],
                    'delivery_fee' => $quote['delivery_fee'], 'free_from' => $settings['free_from'],
                    'coupon_scope' => $quote['coupon_id'] ? (DB::table('coupons')->where('id', $quote['coupon_id'])->value('branch_id') === null ? 'chain' : 'branch') : null], JSON_THROW_ON_ERROR),
                'location_id' => Store::locationId(), 'prep_minutes_snapshot' => max(array_column($lines, 'prep_minutes')),
                'bank_prepay_required' => $fields['method'] === 'bank' && $settings['bank_prepay_required'],
                'code' => $code, 'request_key' => $requestKey, 'tracking_token' => bin2hex(random_bytes(32)),
                'user_id' => $userId, 'name' => $fields['name'], 'email' => $fields['email'], 'phone' => $fields['phone'],
                'fulfillment' => $fields['fulfillment'], 'address' => $delivery['address_line'] ?? null,
                'delivery_snapshot' => $delivery ? DeliveryAddress::seal($delivery) : null,
                'scheduled_at' => $scheduledAt, 'method' => $fields['method'],
                'bank_snapshot' => $bank ? json_encode($bank, JSON_THROW_ON_ERROR) : null,
                'bank_due_at' => $bank ? Orders::bankDueAt($createdAt, $scheduledAt, $settings['bank_due_minutes']) : null,
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
            Branches::bookSlot($scheduledAt, 1);

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
            Fulfillments::createForOrder(DB::table('orders')->find($id));
            if ($quote['coupon_id']) {
                DB::table('coupons')->where('id', $quote['coupon_id'])->increment('used');
            }
            $event = Orders::event($id, null, 'status', 'pending');
            OrderNotices::record($id, 'pending', [], $event);

            return [DB::table('orders')->find($id), true];
        }, 5);
    }

    // The caller holds configuration shared + the branch/slot resource lock.
    public function assertSlot(string $slot, array $settings, int $prepMinutes): string
    {
        if (! in_array($slot, $this->slots($settings, $prepMinutes), true)) {
            $this->invalid('slot', 'Giờ nhận không còn phù hợp với lịch mở cửa hoặc thời gian làm bánh.');
        }
        $scheduledAt = $slot.':00';
        $limit = $this->slotLimit($slot, $settings);
        Branches::lockSlots([$scheduledAt]);
        $booked = Branches::enabled()
            ? (int) DB::table('branch_slot_locks')->where('branch_id', Branches::id())->where('scheduled_at', $scheduledAt)->lockForUpdate()->value('booked')
            : Orders::scheduled()->where('scheduled_at', $scheduledAt)->count();
        if ($limit !== null && $booked >= $limit) {
            $this->invalid('slot', 'Khung giờ vừa hết chỗ. Vui lòng chọn giờ khác.');
        }

        return $scheduledAt;
    }

    private function slotLimit(string $slot, array $settings): ?int
    {
        $exception = Store::exception($settings, CarbonImmutable::parse($slot, $settings['timezone'] ?? 'Asia/Ho_Chi_Minh'));
        $limits = $exception['slot_limits'] ?? $settings['slot_limits'];

        return isset($limits[substr($slot, 11)]) ? (int) $limits[substr($slot, 11)] : null;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function delivery(array $fields, array $settings, bool $requireLine = true): ?array
    {
        if (($fields['fulfillment'] ?? null) !== 'delivery') {
            return null;
        }
        $region = collect($settings['regions'])->firstWhere('key', $fields['region'] ?? null);
        if (isset($fields['area_code']) && DeliveryAddress::regionForArea($settings['regions'], $fields['area_code']) !== $region) {
            $this->invalid('area_code', 'Khu vực và báo giá giao không khớp. Chọn lại khu vực.');
        }

        return DeliveryAddress::normalize([
            ...$fields, 'country_code' => $fields['country_code'] ?? 'VN',
            'area_code' => $fields['area_code'] ?? ($region['area_code'] ?? $region['key'] ?? ''),
            'address_line' => $fields['address_line'] ?? $fields['address'] ?? '',
        ], $settings['regions'], requireLine: $requireLine);
    }
}
