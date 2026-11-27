<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class BranchRecommendations
{
    private LocationAdapter $locations;

    public function __construct(?LocationAdapter $locations = null)
    {
        $this->locations = $locations ?? new ManualLocationAdapter;
    }

    public function evaluate(array $cart, array $context): array
    {
        $context = array_replace(['fulfillment' => 'pickup', 'method' => 'cod', 'goal' => 'earliest', 'coupon' => ''], $context);
        $fields = Validator::make($context, [
            'fulfillment' => ['required', Rule::in(['pickup', 'delivery'])], 'method' => ['required', Rule::in(['cod', 'bank'])],
            'goal' => ['required', Rule::in(['earliest', 'fee', 'distance', 'travel'])],
            'coupon' => 'nullable|string|max:32', 'area_code' => 'nullable|string|max:80',
            'desired_slot' => 'nullable|date_format:Y-m-d H:i',
            'normalized_address' => 'nullable|array',
        ])->validate();
        $cart = Validator::make(['cart' => $cart], [
            'cart' => 'array|max:30', 'cart.*.variant_id' => 'required|integer|min:1', 'cart.*.quantity' => 'required|integer|min:1|max:30',
            'cart.*.message' => 'nullable|string|max:60', 'cart.*.note' => 'nullable|string|max:250',
            'cart.*.accessories' => 'nullable|array|max:20', 'cart.*.accessories.*' => 'integer|min:1',
        ])->validate()['cart'];
        $cart = array_map(fn ($line) => array_replace(['message' => null, 'note' => null, 'accessories' => []], $line), $cart);
        $normalizedInput = $fields['normalized_address'] ?? null;
        $pin = DeliveryAddress::pin($context['pin'] ?? ($normalizedInput['pin'] ?? null));
        if ($fields['fulfillment'] === 'delivery') {
            $address = DeliveryAddress::normalize(array_replace($normalizedInput ?? $context, [
                'area_code' => $fields['area_code'] ?? ($normalizedInput['area_code'] ?? null), 'pin' => $pin,
            ]), $this->configuredRegions(), requireLine: false);
            $fields['area_code'] = $address['area_code'];
        }
        $candidates = [];
        $unavailable = [];
        foreach ($this->activeBranches() as $branch) {
            $evaluated = Branches::run((int) $branch->id, fn () => $this->evaluateBranch($branch, $cart, $fields, $pin));
            if ($evaluated['available']) {
                unset($evaluated['available']);
                $candidates[] = $evaluated;
            } else {
                unset($evaluated['available']);
                $unavailable[] = $evaluated;
            }
        }
        $applied = $fields['goal'];
        $fallback = null;
        if (in_array($applied, ['distance', 'travel'], true)) {
            $metric = $applied === 'distance' ? 'distance_meters' : 'travel_minutes';
            if (! array_filter($candidates, fn ($candidate) => $candidate[$metric] !== null)) {
                $applied = 'earliest';
                $fallback = 'Chưa có ước tính vị trí đáng tin cậy. Đang xếp theo giờ nhận sớm nhất của cả giỏ.';
            }
        }
        usort($candidates, function ($left, $right) use ($applied) {
            $primary = match ($applied) {
                'fee' => $left['delivery_fee'] <=> $right['delivery_fee'],
                'distance' => ($left['distance_meters'] ?? PHP_INT_MAX) <=> ($right['distance_meters'] ?? PHP_INT_MAX),
                'travel' => ($left['travel_minutes'] ?? PHP_INT_MAX) <=> ($right['travel_minutes'] ?? PHP_INT_MAX),
                default => strcmp($left['earliest_slot'], $right['earliest_slot']),
            };

            return $primary ?: strcmp($left['earliest_slot'], $right['earliest_slot'])
                ?: ($left['delivery_fee'] <=> $right['delivery_fee']) ?: ($left['branch_id'] <=> $right['branch_id']);
        });

        return ['candidates' => $candidates, 'unavailable' => $unavailable,
            'ranking' => ['requested_goal' => $fields['goal'], 'applied_goal' => $applied, 'fallback_reason' => $fallback],
            'estimates_only' => true, 'current_branch_id' => Branches::id()];
    }

    public function activeBranches()
    {
        return DB::table('branches')->where('active', true)->when(! Branches::enabled(), fn ($query) => $query->where('id', 1))->orderBy('id')->get();
    }

    public function configuredRegions(): array
    {
        $regions = [];
        foreach ($this->activeBranches() as $branch) {
            $settings = Branches::run((int) $branch->id, fn () => Store::settings());
            foreach ($settings['regions'] as $region) {
                $code = DeliveryAddress::areaCode((string) ($region['area_code'] ?? $region['key'] ?? ''));
                if ($code !== '') {
                    $regions[$code] = $region + ['area_code' => $code];
                }
            }
        }

        return array_values($regions);
    }

    public function areas(): array
    {
        return array_map(fn ($region) => ['code' => DeliveryAddress::areaCode((string) ($region['area_code'] ?? $region['key'])), 'name' => $region['name']], $this->configuredRegions());
    }

    public static function coordinates(array $settings): ?array
    {
        $lat = $settings['latitude'] ?? null;
        $lng = $settings['longitude'] ?? null;
        if (! ($settings['coordinates_approved'] ?? false) || ! is_numeric($lat) || ! is_numeric($lng)
            || (float) $lat < -90 || (float) $lat > 90 || (float) $lng < -180 || (float) $lng > 180) {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    private function evaluateBranch(object $branch, array $cart, array $context, ?array $pin): array
    {
        $identity = ['branch_id' => (int) $branch->id, 'name' => $branch->name, 'slug' => $branch->slug];
        $settings = Store::settings();
        $fail = fn ($code, $reason) => $identity + ['available' => false, 'reason_code' => $code, 'reason' => $reason];
        if (! $settings['accepting_orders']) {
            return $fail('paused', Store::pauseMessage($settings));
        }
        if (! $cart) {
            return $fail('cart_empty', 'Chọn bánh và số lượng để kiểm tra giờ nhận cho cả giỏ.');
        }
        if (! in_array($context['fulfillment'], $settings['fulfillments'] ?? ['pickup', 'delivery'], true)) {
            return $fail('fulfillment', 'Cơ sở không hỗ trợ hình thức nhận đã chọn.');
        }
        if (! in_array($context['method'], $settings['payment_methods'] ?? ['cod', 'bank'], true)
            || ($context['method'] === 'bank' && ! Store::bank($settings))) {
            return $fail('method', 'Cơ sở chưa hỗ trợ phương thức thanh toán đã chọn.');
        }
        $region = $context['fulfillment'] === 'delivery' ? DeliveryAddress::regionForArea($settings['regions'], $context['area_code']) : null;
        if ($context['fulfillment'] === 'delivery' && ! $region) {
            return $fail('area', 'Cơ sở chưa giao tới khu vực đã chọn.');
        }
        $checkout = app(Checkout::class);
        try {
            $lines = $checkout->cart($cart);
            $quote = $checkout->quote($lines, ['branch_id' => (int) $branch->id, 'fulfillment' => $context['fulfillment'],
                'method' => $context['method'], 'region' => $region['key'] ?? null, 'coupon' => $context['coupon'] ?? ''], $settings);
            $slots = $checkout->availableSlots($settings, max(array_column($lines, 'prep_minutes')));
        } catch (ValidationException $failure) {
            $field = array_key_first($failure->errors());

            return $fail($field === 'cart' ? 'stock_or_unavailable' : $field, $failure->errors()[$field][0]);
        }
        if (! $slots) {
            return $fail('slots', 'Chưa có khung đủ thời gian chuẩn bị và công suất cho cả giỏ.');
        }
        if (! empty($context['desired_slot']) && ! in_array($context['desired_slot'], $slots, true)) {
            return $fail('desired_slot', 'Khung yêu cầu không còn phù hợp tại cơ sở này.');
        }
        $metric = ['distance_meters' => null, 'travel_minutes' => null, 'provider' => 'manual', 'reason' => $pin ? 'branch_coordinates_unverified' : 'pin_required'];
        $origin = self::coordinates($settings);
        if ($origin && $pin) {
            try {
                $estimate = $this->locations->estimate($origin, $pin, ['branch_id' => (int) $branch->id]);
                $metric['provider'] = $estimate['provider'] ?? 'manual';
                $metric['reason'] = $estimate['reason'] ?? null;
                if ($estimate['available'] ?? false) {
                    foreach (['distance_meters', 'travel_minutes'] as $key) {
                        $metric[$key] = isset($estimate[$key]) && is_numeric($estimate[$key]) && is_finite((float) $estimate[$key]) && $estimate[$key] >= 0 ? (int) $estimate[$key] : null;
                    }
                }
            } catch (Throwable) {
                $metric['reason'] = 'provider_unavailable';
            }
        }

        return $identity + ['available' => true, ...array_intersect_key($quote, array_flip(['subtotal', 'discount', 'delivery_fee', 'total'])),
            'earliest_slot' => $slots[0], 'selected_slot' => $context['desired_slot'] ?? $slots[0],
            'prep_minutes' => max(array_column($lines, 'prep_minutes')),
            'distance_meters' => $metric['distance_meters'], 'travel_minutes' => $metric['travel_minutes'],
            'estimate_provider' => $metric['provider'], 'estimate_reason' => $metric['reason'], 'estimated' => true];
    }
}
