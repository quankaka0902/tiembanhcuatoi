<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductMetadata
{
    public static function hydrate(object $product): object
    {
        $product->occasions = self::stringList($product->occasions ?? null);
        $product->flavors = self::stringList($product->flavors ?? null);
        $product->details_verified = (bool) ($product->details_verified ?? false);
        $product->images_approved = (bool) ($product->images_approved ?? false);

        return $product;
    }

    public static function stringList(mixed $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input) || ! array_is_list($input)) {
            return [];
        }
        $values = [];
        $seen = [];
        foreach ($input as $value) {
            if (! is_string($value) || ($value = trim($value)) === '') {
                continue;
            }
            $key = mb_strtolower($value);
            if (! isset($seen[$key])) {
                $values[] = $value;
                $seen[$key] = true;
            }
        }

        return $values;
    }

    // FormData cannot encode an empty array. Accept JSON array strings as well as arrays.
    public static function listInput(mixed $input): mixed
    {
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && array_is_list($decoded)) {
                return $decoded;
            }
        }

        return $input;
    }

    public static function rules(): array
    {
        return [
            'occasions' => 'sometimes|nullable|array|list|max:20', 'occasions.*' => 'required|string|max:80',
            'flavors' => 'sometimes|nullable|array|list|max:20', 'flavors.*' => 'required|string|max:80',
            'storage_instructions' => 'sometimes|nullable|string|max:1600',
            'details_verified' => 'sometimes|boolean', 'images_approved' => 'sometimes|boolean',
            'variants.*.size_label' => 'sometimes|nullable|string|max:80',
            'variants.*.servings_min' => 'sometimes|nullable|integer|min:1|max:200',
            'variants.*.servings_max' => 'sometimes|nullable|integer|min:1|max:200',
            'variants.*.servings_verified' => 'sometimes|boolean',
            'accessories' => 'sometimes|array|list|max:20',
            'accessories.*' => 'required|array:id,name,price,active,approved',
            'accessories.*.id' => 'nullable|integer|distinct|min:1',
            'accessories.*.name' => 'required|string|max:100',
            'accessories.*.price' => 'required|integer|min:0|max:100000000',
            'accessories.*.active' => 'required|boolean', 'accessories.*.approved' => 'required|boolean',
        ];
    }

    public static function productFields(array $data, ?object $previous, int $imageCount, bool $imagesChanged): array
    {
        $fields = [];
        foreach (['occasions', 'flavors'] as $key) {
            $fields[$key] = json_encode(self::stringList(array_key_exists($key, $data) ? $data[$key] : ($previous?->$key ?? null)), JSON_THROW_ON_ERROR);
        }
        $fields['storage_instructions'] = array_key_exists('storage_instructions', $data) ? $data['storage_instructions'] : ($previous?->storage_instructions ?? null);
        $detailsChanged = false;
        foreach (['ingredients', 'allergens', 'storage_instructions'] as $key) {
            $detailsChanged = $detailsChanged || (array_key_exists($key, $data) && $data[$key] !== ($previous?->$key ?? null));
        }
        $fields['details_verified'] = array_key_exists('details_verified', $data) ? (bool) $data['details_verified'] : (! $detailsChanged && (bool) ($previous?->details_verified ?? false));
        $fields['images_approved'] = array_key_exists('images_approved', $data) ? (bool) $data['images_approved'] : (! $imagesChanged && (bool) ($previous?->images_approved ?? false));
        if ($fields['details_verified']) {
            foreach (['ingredients', 'allergens', 'storage_instructions'] as $key) {
                $value = $key === 'storage_instructions' ? $fields[$key] : (array_key_exists($key, $data) ? $data[$key] : ($previous?->$key ?? null));
                if (! is_string($value) || trim($value) === '') {
                    throw ValidationException::withMessages([$key => 'Nhập nội dung đã đối chiếu với tiệm trước khi xác minh thông tin bánh.']);
                }
            }
        }
        if ($fields['images_approved'] && $imageCount === 0) {
            throw ValidationException::withMessages(['images_approved' => 'Cần ít nhất một ảnh đã được phép sử dụng trước khi duyệt bộ ảnh.']);
        }

        return $fields;
    }

    public static function variantFields(array $data, ?object $previous, int $index): array
    {
        $fields = [];
        foreach (['size_label', 'servings_min', 'servings_max'] as $key) {
            $fields[$key] = array_key_exists($key, $data) ? $data[$key] : ($previous?->$key ?? null);
        }
        $fields['size_label'] = is_string($fields['size_label']) && trim($fields['size_label']) !== '' ? trim($fields['size_label']) : null;
        foreach (['servings_min', 'servings_max'] as $key) {
            $fields[$key] = $fields[$key] === null ? null : (int) $fields[$key];
        }
        $rangeChanged = false;
        foreach (['size_label', 'servings_min', 'servings_max'] as $key) {
            $rangeChanged = $rangeChanged || $fields[$key] !== ($previous?->$key ?? null);
        }
        $fields['servings_verified'] = array_key_exists('servings_verified', $data) ? (bool) $data['servings_verified'] : (! $rangeChanged && (bool) ($previous?->servings_verified ?? false));
        if ($fields['servings_verified'] && ($fields['servings_min'] === null || $fields['servings_max'] === null)) {
            throw ValidationException::withMessages(["variants.{$index}.servings_min" => 'Cần số người ăn tối thiểu và tối đa đã được tiệm xác nhận.']);
        }
        if ($fields['servings_min'] !== null && $fields['servings_max'] !== null && $fields['servings_max'] < $fields['servings_min']) {
            throw ValidationException::withMessages(["variants.{$index}.servings_max" => 'Số người ăn tối đa phải lớn hơn hoặc bằng tối thiểu.']);
        }

        return $fields;
    }

    public static function accessories(int $productId, bool $publicOnly = true): Collection
    {
        $query = DB::table('product_accessories')->where('product_id', $productId)->orderBy('id');

        return $publicOnly ? $query->where('active', true)->where('approved', true)->get(['id', 'name', 'price']) : $query->get();
    }
}
