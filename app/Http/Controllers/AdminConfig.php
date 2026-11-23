<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LaunchReadiness;
use App\Services\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminConfig extends Controller
{
    public function settings()
    {
        $settings = Store::settings();
        unset($settings['accepting_orders'], $settings['pause_message']);

        return Inertia::render('AdminSettings', ['settings' => $settings]);
    }

    public function saveIntake(Request $request)
    {
        $fields = $request->validate([
            'accepting_orders' => 'required|boolean', 'pause_message' => 'nullable|string|max:250',
        ]);
        DB::transaction(function () use ($fields) {
            $current = Store::settings(lock: true);
            if ($fields['accepting_orders'] && ! $current['accepting_orders'] && LaunchReadiness::required()) {
                $errors = [
                    ...LaunchReadiness::configurationIssues(),
                    ...LaunchReadiness::dataIssues($current),
                ];
                if ($errors) {
                    throw ValidationException::withMessages(['accepting_orders' => $errors]);
                }
            }
            $settings = array_replace($current, [
                'accepting_orders' => (bool) $fields['accepting_orders'],
                'pause_message' => $fields['pause_message'] ?? null,
            ]);
            DB::table('store_settings')->where('id', 1)->update(['data' => json_encode($settings, JSON_THROW_ON_ERROR)]);
        }, 5);

        return back()->with('notice', $fields['accepting_orders'] ? 'Tiệm đang nhận đơn mới.' : 'Đã tạm ngừng nhận đơn mới.');
    }

    public function saveSettings(Request $request, AdminCatalog $catalog)
    {
        $fields = $request->validate([
            'name' => 'required|string|max:100', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:254',
            'address' => 'nullable|string|max:250', 'hours' => 'required|string|max:100',
            'order_policy' => 'nullable|string|max:4000',
            'privacy_notice' => 'nullable|string|max:4000',
            'logo' => 'nullable|'.AdminCatalog::IMAGE_RULES, 'remove_logo' => 'required|boolean',
            'weekdays' => 'required|array|min:1|max:7', 'weekdays.*' => 'integer|distinct|between:1,7',
            'slots' => 'required|array|min:1|max:48', 'slots.*' => 'required|date_format:H:i|distinct',
            'slot_limits' => 'nullable|array|max:48', 'slot_limits.*' => 'nullable|integer|min:1|max:1000',
            'closed_dates' => 'nullable|array|max:100', 'closed_dates.*' => 'required|date_format:Y-m-d|distinct',
            'regions' => 'nullable|array|max:50', 'regions.*' => 'required|array:key,name,fee',
            'regions.*.key' => 'required|alpha_dash:ascii|max:60|distinct', 'regions.*.name' => 'required|string|max:100',
            'regions.*.fee' => 'required|integer|min:0|max:10000000', 'free_from' => 'nullable|integer|min:0|max:100000000',
            'bank_name' => 'nullable|required_with:bank_holder,bank_account|string|max:100',
            'bank_account' => 'nullable|required_with:bank_name,bank_holder|string|max:40',
            'bank_holder' => 'nullable|required_with:bank_name,bank_account|string|max:100', 'bank_demo' => 'required|boolean',
            'bank_due_minutes' => 'sometimes|required|integer|min:1|max:1440',
            'bank_prepay_required' => 'sometimes|required|boolean',
            'allow_payment_override' => 'sometimes|required|boolean',
            'coupon_return_on_early_cancel' => 'sometimes|required|boolean',
        ]);
        $slotTimes = array_flip($fields['slots']);
        if (array_diff_key($fields['slot_limits'] ?? [], $slotTimes)) {
            throw ValidationException::withMessages(['slot_limits' => 'Chỉ đặt giới hạn cho khung giờ có trong lịch nhận bánh.']);
        }
        DB::transaction(function () use ($fields, $catalog, $slotTimes) {
            $current = Store::settings(lock: true);
            $logo = isset($fields['logo']) ? $catalog->uploadedImage($fields['logo']) : ($fields['remove_logo'] ? null : $current['logo']);
            $limits = array_filter(array_intersect_key($fields['slot_limits'] ?? $current['slot_limits'], $slotTimes));
            unset($fields['remove_logo']);
            $settings = array_replace($current, $fields, [
                'logo' => $logo, 'weekdays' => array_map('intval', $fields['weekdays']),
                'slot_limits' => array_map('intval', $limits),
                'closed_dates' => $fields['closed_dates'] ?? [], 'regions' => $fields['regions'] ?? [],
                'bank_due_minutes' => (int) ($fields['bank_due_minutes'] ?? $current['bank_due_minutes']),
                'bank_demo' => (bool) $fields['bank_demo'],
                'bank_prepay_required' => (bool) ($fields['bank_prepay_required'] ?? $current['bank_prepay_required']),
                'allow_payment_override' => (bool) ($fields['allow_payment_override'] ?? $current['allow_payment_override']),
                'coupon_return_on_early_cancel' => (bool) ($fields['coupon_return_on_early_cancel'] ?? $current['coupon_return_on_early_cancel']),
            ]);
            DB::table('store_settings')->where('id', 1)->update(['data' => json_encode($settings, JSON_THROW_ON_ERROR)]);
        });

        return back()->with('notice', 'Đã lưu cấu hình cửa hàng.');
    }

    public function coupons(?int $coupon = null)
    {
        $row = $coupon ? DB::table('coupons')->find($coupon) : null;
        abort_if($coupon && ! $row, 404);

        return Inertia::render('AdminCoupons', ['coupon' => $row, 'coupons' => DB::table('coupons')->latest('id')->paginate(20)]);
    }

    public function saveCoupon(Request $request, ?int $coupon = null)
    {
        if ($coupon) {
            abort_unless(DB::table('coupons')->find($coupon), 404);
        }
        if (is_string($code = $request->input('code'))) {
            $request->merge(['code' => Str::upper($code)]);
        }
        $fields = $request->validate([
            'code' => ['required', 'regex:/^[A-Z0-9][A-Z0-9_-]{1,31}$/', Rule::unique('coupons')->ignore($coupon)],
            'kind' => 'required|in:percent,fixed', 'amount' => 'required|integer|min:1|max:'.($request->input('kind') === 'percent' ? 100 : 100000000),
            'minimum' => 'required|integer|min:0|max:100000000', 'usage_limit' => 'nullable|integer|min:1|max:1000000',
            'starts_on' => 'nullable|date_format:Y-m-d',
            'ends_on' => 'nullable|date_format:Y-m-d'.($request->filled('starts_on') ? '|after_or_equal:starts_on' : ''),
            'active' => 'required|boolean',
        ]);
        DB::transaction(function () use ($fields, $coupon) {
            Store::settings(lock: true);
            if ($coupon) {
                DB::table('coupons')->where('id', $coupon)->update($fields);
            } else {
                DB::table('coupons')->insert($fields);
            }
        });

        return redirect('/quan-tri/ma-giam')->with('notice', 'Đã lưu mã giảm giá.');
    }

    public function users(?int $user = null)
    {
        $record = $user ? User::whereIn('role', ['admin', 'staff'])->findOrFail($user) : null;

        return Inertia::render('AdminUsers', [
            'selected' => $record ? $record->only('id', 'name', 'email', 'role', 'active', 'can_reconcile') + ['mfa_ready' => (bool) $record->staffProof()] : null,
            'users' => User::whereIn('role', ['admin', 'staff'])->latest('id')->paginate(20, ['id', 'name', 'email', 'role', 'active', 'can_reconcile']),
        ]);
    }

    public function saveUser(Request $request, ?int $user = null)
    {
        $record = $user ? User::whereIn('role', ['admin', 'staff'])->findOrFail($user) : new User;
        if (is_string($email = $request->input('email'))) {
            $request->merge(['email' => Str::lower($email)]);
        }
        $fields = $request->validate([
            'name' => 'required|string|max:120', 'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($user)],
            'password' => ($user ? 'nullable' : 'required').'|string|min:10|max:72',
            'role' => 'required|in:admin,staff', 'active' => 'required|boolean', 'can_reconcile' => 'required|boolean',
        ]);
        if ($user === $request->user()->id && (! $fields['active'] || $fields['role'] !== 'admin')) {
            throw ValidationException::withMessages(['role' => 'Bạn không thể tự bỏ quyền quản trị hoặc khóa tài khoản đang dùng.']);
        }
        if (empty($fields['password'])) {
            unset($fields['password']);
        } else {
            $fields['password'] = Hash::make($fields['password']);
            $fields['remember_token'] = Str::random(60);
        }

        DB::transaction(function () use ($record, $fields, $request, $user) {
            Store::settings(lock: true);
            abort_unless($request->user()->fresh()?->active && $request->user()->fresh()?->role === 'admin', 403);
            $record->forceFill($fields)->save();
            if ($user && (! $fields['active'] || isset($fields['password'])) && config('session.driver') === 'database') {
                DB::table(config('session.table'))->where('user_id', $user)->delete();
            }
        });

        return redirect('/quan-tri/nhan-su')->with('notice', 'Đã lưu tài khoản nội bộ.');
    }
}
