<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeliveryAddress
{
    /** Area codes are shop-configured service zones, never inferred official divisions. */
    public static function areaCode(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function regionForArea(array $regions, string $areaCode): ?array
    {
        $areaCode = self::areaCode($areaCode);
        foreach ($regions as $region) {
            $configured = self::areaCode((string) ($region['area_code'] ?? $region['key'] ?? ''));
            if ($configured !== '' && $configured === $areaCode) {
                return $region;
            }
        }

        return null;
    }

    public static function normalize(array $input, array $regions, bool $requireLine = true): array
    {
        $data = [];
        foreach (['country_code', 'area_code', 'address_line', 'province_code', 'district_code', 'ward_code', 'delivery_note'] as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = is_string($input[$field]) ? self::text($input[$field]) : $input[$field];
            }
        }
        $data['country_code'] = is_string($data['country_code'] ?? 'VN') ? strtoupper($data['country_code'] ?? 'VN') : $data['country_code'];
        $data['area_code'] = is_string($data['area_code'] ?? null) ? self::areaCode($data['area_code']) : ($data['area_code'] ?? null);
        $validator = Validator::make($data, [
            'country_code' => ['required', Rule::in(['VN'])],
            'area_code' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/D'],
            'address_line' => [$requireLine ? 'required' : 'nullable', 'string', 'max:250'],
            'province_code' => ['nullable', 'string', 'max:30'],
            'district_code' => ['nullable', 'string', 'max:30'],
            'ward_code' => ['nullable', 'string', 'max:30'],
            'delivery_note' => ['nullable', 'string', 'max:500'],
        ]);
        $validator->after(function ($validator) use ($regions, $data) {
            if (is_string($data['area_code'] ?? null) && ! self::regionForArea($regions, $data['area_code'])) {
                $validator->errors()->add('area_code', 'Khu vực chưa được cửa hàng cấu hình phục vụ.');
            }
        });
        $validated = $validator->validate();
        $pin = self::pin($input['pin'] ?? null);

        return [
            'version' => 1, 'country_code' => 'VN', 'area_code' => $validated['area_code'],
            'address_line' => $validated['address_line'] ?? null,
            'province_code' => $validated['province_code'] ?? null, 'district_code' => $validated['district_code'] ?? null,
            'ward_code' => $validated['ward_code'] ?? null, 'delivery_note' => $validated['delivery_note'] ?? null, 'pin' => $pin,
        ];
    }

    public static function seal(array $normalized): string
    {
        if (($normalized['version'] ?? null) !== 1 || ($normalized['country_code'] ?? null) !== 'VN'
            || ! is_string($normalized['area_code'] ?? null) || ! is_string($normalized['address_line'] ?? null)
            || trim($normalized['address_line']) === '') {
            throw ValidationException::withMessages(['address_line' => 'Cần địa chỉ giao đã kiểm tra trước khi lưu.']);
        }

        return Crypt::encryptString(json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function pin(mixed $input): ?array
    {
        if ($input === null) {
            return null;
        }
        if (! is_array($input)) {
            throw ValidationException::withMessages(['pin' => 'Vị trí ghim không hợp lệ.']);
        }
        $values = Validator::make(['pin' => $input], [
            'pin.lat' => 'required|numeric|between:-90,90', 'pin.lng' => 'required|numeric|between:-180,180',
            'pin.confirmed' => ['required', 'accepted'],
        ])->validate()['pin'];

        return ['lat' => (float) $values['lat'], 'lng' => (float) $values['lng'], 'confirmed' => true];
    }

    public static function open(?string $ciphertext): ?array
    {
        if ($ciphertext === null || $ciphertext === '') {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($ciphertext), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DecryptException('Không đọc được bản chụp địa chỉ.');
        }
        if (! is_array($data) || ($data['version'] ?? null) !== 1 || ($data['country_code'] ?? null) !== 'VN') {
            throw new DecryptException('Phiên bản bản chụp địa chỉ không hợp lệ.');
        }

        return $data;
    }

    public static function fingerprint(array $normalized): string
    {
        return hash_hmac('sha256', json_encode(self::canonical($normalized), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (string) config('app.key'));
    }

    public static function text(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw ValidationException::withMessages(['address_line' => 'Văn bản địa chỉ không hợp lệ.']);
        }

        return trim(preg_replace('/[\p{Z}\s\p{Cc}]+/u', ' ', $value));
    }

    private static function canonical(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::canonical($value);
            }
        }
        if (! array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }
}
