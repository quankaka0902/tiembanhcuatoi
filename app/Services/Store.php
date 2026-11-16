<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class Store
{
    public static function settings(bool $lock = false): array
    {
        $query = DB::table('store_settings')->where('id', 1);
        $data = ($lock ? $query->lockForUpdate() : $query)->value('data');

        return array_replace(
            config('bakery.defaults'),
            ['accepting_orders' => ! LaunchReadiness::required()],
            $data ? json_decode($data, true, flags: JSON_THROW_ON_ERROR) : [],
        );
    }

    public static function publicSettings(): array
    {
        $settings = self::settings();

        return array_intersect_key($settings, array_flip([
            'name', 'logo', 'phone', 'email', 'address', 'hours', 'accepting_orders',
        ])) + ['pause_message' => self::pauseMessage($settings)];
    }

    public static function pauseMessage(array $settings): string
    {
        return $settings['pause_message'] ?: 'Tiệm đang tạm ngừng nhận đơn mới. Vui lòng quay lại sau.';
    }

    public static function openOn(array $settings, CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeekIso, $settings['weekdays'], true)
            && ! in_array($date->toDateString(), $settings['closed_dates'], true);
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
