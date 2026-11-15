<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Store
{
    public static function settings(bool $lock = false): array
    {
        $query = DB::table('store_settings')->where('id', 1);
        $data = ($lock ? $query->lockForUpdate() : $query)->value('data');

        return array_replace(config('bakery.defaults'), $data ? json_decode($data, true, flags: JSON_THROW_ON_ERROR) : []);
    }

    public static function publicSettings(): array
    {
        return array_intersect_key(self::settings(), array_flip(['name', 'logo', 'phone', 'email', 'address', 'hours']));
    }

    public static function bank(array $settings): ?array
    {
        if (! $settings['bank_name'] || ! $settings['bank_account'] || ! $settings['bank_holder']) {
            return null;
        }

        return [
            'name' => $settings['bank_name'],
            'account' => $settings['bank_account'],
            'holder' => $settings['bank_holder'],
            'demo' => $settings['bank_demo'],
        ];
    }
}
