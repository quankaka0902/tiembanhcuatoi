<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store
{
    public const BRAND_KEYS = ['name', 'logo', 'logo_approved', 'about_text', 'faq_items', 'public_content_approved', 'order_policy', 'privacy_notice'];

    public static function locationId(): int
    {
        return Branches::id();
    }

    public static function settings(bool $lock = false, ?int $branchId = null, bool $shared = false): array
    {
        $defaults = array_replace(config('bakery.defaults'), ['accepting_orders' => ! LaunchReadiness::required(),
            'timezone' => 'Asia/Ho_Chi_Minh', 'fulfillments' => ['pickup', 'delivery'],
            'payment_methods' => ['cod', 'bank'], 'calendar_exceptions' => []]);
        if (! Branches::enabled()) {
            $query = DB::table('store_settings')->where('id', 1);
            $data = ($lock ? $query->lockForUpdate() : $query)->value('data');

            return array_replace($defaults, $data ? json_decode($data, true, flags: JSON_THROW_ON_ERROR) : [], ['branch_id' => 1]);
        }
        $branchId ??= Branches::id();
        // A transaction may already have a repeatable-read snapshot from branch resolution.
        // Sales decisions must read current configuration after acquiring its resource locks.
        if (! $lock && DB::transactionLevel() > 0) {
            $lock = true;
            $shared = true;
        }
        $query = DB::table('branches')->where('id', $branchId);
        if ($lock) {
            $shared ? $query->sharedLock() : $query->lockForUpdate();
        }
        $branch = $query->first();
        abort_unless($branch, 404);
        $brandQuery = DB::table('chain_settings')->where('id', 1);
        if ($lock) {
            $shared ? $brandQuery->sharedLock() : $brandQuery->lockForUpdate();
        }
        $brand = $brandQuery->value('data');
        $settings = json_decode($branch->settings, true, flags: JSON_THROW_ON_ERROR);

        return array_replace($defaults, $brand ? array_intersect_key(json_decode($brand, true, flags: JSON_THROW_ON_ERROR), array_flip(self::BRAND_KEYS)) : [],
            array_diff_key($settings, array_flip(self::BRAND_KEYS)),
            ['branch_id' => $branchId, 'timezone' => $branch->timezone,
                'accepting_orders' => (bool) $branch->active && (bool) ($settings['accepting_orders'] ?? false)]);
    }

    /** Call after taking the settings lock; legacy columns remain for compatibility. */
    public static function saveSettings(array $settings, ?int $branchId = null): void
    {
        $branchId ??= self::locationId();
        unset($settings['branch_id'], $settings['timezone']);
        $brand = array_intersect_key($settings, array_flip(self::BRAND_KEYS));
        $local = array_diff_key($settings, array_flip(self::BRAND_KEYS));
        if (! Branches::enabled()) {
            DB::table('store_settings')->where('id', 1)->update(['data' => json_encode($settings, JSON_THROW_ON_ERROR)]);
        }
        if (Schema::hasTable('chain_settings')) {
            $current = array_intersect_key(json_decode(DB::table('chain_settings')->where('id', 1)->lockForUpdate()->value('data') ?? '{}', true, flags: JSON_THROW_ON_ERROR), array_flip(self::BRAND_KEYS));
            $actor = request()->user();
            $chainWriter = ! $actor || Branches::can($actor, 'chain');
            if (Branches::enabled() && ! $chainWriter) {
                abort_unless(array_replace($current, $brand) === $current, 403);
            }
            if (! Branches::enabled() || $chainWriter) {
                DB::table('chain_settings')->updateOrInsert(['id' => 1], ['data' => json_encode(array_replace($current, $brand), JSON_THROW_ON_ERROR)]);
            }
            DB::table('branches')->where('id', $branchId)->update(['settings' => json_encode($local, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }
        if (Branches::enabled() && $branchId === 1) {
            DB::table('store_settings')->where('id', 1)->update(['data' => json_encode($settings, JSON_THROW_ON_ERROR)]);
        }
    }

    public static function publicSettings(): array
    {
        $settings = self::settings();
        $public = array_intersect_key($settings, array_flip(['name', 'logo', 'phone', 'email', 'address', 'hours', 'accepting_orders']));
        $branch = Branches::find();

        return $public + ['pause_message' => self::pauseMessage($settings), 'logo_asset' => app(ResponsiveImages::class)->asset($settings['logo']),
            'branch_mode' => Branches::enabled(),
            'branch' => $branch ? array_intersect_key((array) $branch, array_flip(['id', 'code', 'slug', 'name'])) : ['id' => 1, 'name' => 'Cơ sở hiện tại'],
            'branches' => Branches::enabled() ? DB::table('branches')->where('active', true)->orderBy('id')->get(['id', 'code', 'slug', 'name'])->all() : []];
    }

    public static function pauseMessage(array $settings): string
    {
        return $settings['pause_message'] ?: 'Tiệm đang tạm ngừng nhận đơn mới. Vui lòng quay lại sau.';
    }

    public static function exception(array $settings, CarbonInterface $date): ?array
    {
        return collect($settings['calendar_exceptions'] ?? [])->firstWhere('date', $date->toDateString());
    }

    public static function openOn(array $settings, CarbonInterface $date): bool
    {
        $exception = self::exception($settings, $date);
        if ($exception !== null) {
            return ! ($exception['closed'] ?? false);
        }

        return in_array($date->dayOfWeekIso, $settings['weekdays'], true)
            && ! in_array($date->toDateString(), $settings['closed_dates'], true);
    }

    public static function bank(array $settings): ?array
    {
        if (! $settings['bank_name'] || ! $settings['bank_account'] || ! $settings['bank_holder']) {
            return null;
        }

        return ['name' => $settings['bank_name'], 'account' => $settings['bank_account'], 'holder' => $settings['bank_holder'], 'demo' => $settings['bank_demo']];
    }
}
