<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LaunchReadiness
{
    public static function required(): bool
    {
        return app()->environment(['production', 'staging']) || config('bakery.rehearsal');
    }

    public static function loopback(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    public static function configurationIssues(): array
    {
        $issues = [];
        $privateStaging = PrivateStaging::enabled();
        $rehearsal = (bool) config('bakery.rehearsal');
        $url = (string) config('app.url');
        $loopback = self::loopback((string) parse_url($url, PHP_URL_HOST));
        if (config('app.debug') || config('bakery.demo_seed')
            || ($rehearsal ? ! app()->environment(['local', 'testing']) : ! app()->environment(['production', 'staging']))) {
            $issues[] = 'Chọn đúng môi trường và tắt debug, dữ liệu mẫu.';
        }
        if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || ($rehearsal !== $loopback) || ! config('session.secure') || ! config('session.encrypt')) {
            $issues[] = 'Cấu hình APP_URL HTTPS, SESSION_SECURE_COOKIE=true và SESSION_ENCRYPT=true.';
        }
        if (! config('session.http_only') || ! in_array(config('session.same_site'), ['lax', 'strict'], true)) {
            $issues[] = 'Cấu hình cookie phiên HttpOnly và SameSite=lax hoặc strict.';
        }
        if ($privateStaging) {
            if (! PrivateStaging::accessConfigured() || config('session.domain')) {
                $issues[] = 'Dùng địa chỉ HTTPS riêng, mật khẩu truy cập staging và cookie giới hạn ở tên miền này.';
            }
            if (! PrivateStaging::isolatedDatabase()) {
                $issues[] = 'Staging cần MySQL riêng với tên schema kết thúc bằng _test.';
            }
            if (! PrivateStaging::smtpConfigured() || ! PrivateStaging::approvedRecipients()) {
                $issues[] = 'Cấu hình SMTP qua TLS và danh sách hộp thư thử đã duyệt cho staging.';
            }
        }
        $sender = (string) config('mail.from.address');
        $smtpHost = (string) config('mail.mailers.smtp.host');
        $smtpUrl = config('mail.mailers.smtp.url');
        if ($smtpUrl) {
            $smtpHost = (string) parse_url($smtpUrl, PHP_URL_HOST);
        }
        if (config('mail.default') !== 'smtp' || ! filter_var($sender, FILTER_VALIDATE_EMAIL) || trim($smtpHost) === ''
            || ($rehearsal && (! self::loopback($smtpHost) || $smtpUrl))
            || (! $rehearsal && preg_match('/(?:\.(?:test|example|invalid)|@example\.(?:com|net|org))$/i', $sender))) {
            $issues[] = $rehearsal ? 'SMTP nghiệm thu phải gửi vào hộp thư bắt tại loopback.' : 'Cấu hình SMTP và địa chỉ gửi thư của cửa hàng.';
        }

        return $issues;
    }

    public static function dataIssues(array $settings): array
    {
        $issues = [];
        $branchId = (int) ($settings['branch_id'] ?? Branches::id());
        if (! ($settings['phone'] || $settings['email']) || ! $settings['address'] || ! $settings['order_policy'] || ! $settings['privacy_notice']) {
            $issues[] = 'Điền liên hệ, địa chỉ, chính sách đặt bánh và thông báo xử lý dữ liệu.';
        }
        $menu = DB::table('products')->join('variants', 'variants.product_id', '=', 'products.id')
            ->where('products.active', true)->where('variants.active', true);
        if (Branches::enabled()) {
            $menu->join('branch_variants', 'branch_variants.variant_id', '=', 'variants.id')
                ->where('branch_variants.branch_id', $branchId)->where('branch_variants.active', true)->where('branch_variants.stock', '>', 0);
            $prepMinutes = $menu->min(DB::raw('COALESCE(branch_variants.prep_minutes, products.prep_minutes)'));
            if (! ($settings['business_verified'] ?? false)) {
                $issues[] = 'Xác minh thông tin cơ sở thực tế trước khi mở nhận đơn.';
            }
        } else {
            $prepMinutes = $menu->where('variants.stock', '>', 0)->min('products.prep_minutes');
        }
        if ($prepMinutes === null) {
            $issues[] = 'Cần ít nhất một phiên bản bánh đang bán, còn tồn.';
        }
        if (! app(Checkout::class)->slots($settings, (int) $prepMinutes)) {
            $issues[] = 'Cần ít nhất một giờ nhận trong 14 ngày tới.';
        }
        foreach ($settings['regions'] as $region) {
            if (empty($region['key']) || empty($region['name']) || ! isset($region['fee'])
                || filter_var($region['fee'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000000]]) === false) {
                $issues[] = 'Kiểm tra tên khu vực và phí giao hàng.';
                break;
            }
        }
        $bankStarted = $settings['bank_name'] || $settings['bank_account'] || $settings['bank_holder'];
        if ($settings['bank_demo'] || ($bankStarted && (! Store::bank($settings) || preg_match('/^0+$/', trim($settings['bank_account']))))
            || DB::table('users')->whereIn('email', ['admin@tiembanh.test', 'nhanvien@tiembanh.test', 'doisoat@tiembanh.test', 'khach@tiembanh.test'])->exists()) {
            $issues[] = 'Gỡ tài khoản mẫu và kiểm tra thông tin ngân hàng trước khi mở nhận đơn.';
        }
        $staff = DB::table('users')->whereIn('role', ['staff', 'admin'])->where('active', true);
        if (Branches::enabled()) {
            $staff->where(fn ($query) => $query->where('role', 'admin')->orWhereExists(fn ($membership) => $membership
                ->selectRaw('1')->from('branch_memberships')->whereColumn('branch_memberships.user_id', 'users.id')
                ->where('branch_id', $branchId)->where('branch_memberships.active', true)));
        }
        if (! (clone $staff)->where('role', 'admin')->whereNotNull('two_factor_secret')->exists()
            || $staff->whereNull('two_factor_secret')->exists()) {
            $issues[] = 'Cấp khóa xác thực hai bước cho tài khoản nội bộ đang hoạt động.';
        }

        return $issues;
    }

    /** Default CLI readiness is an explicit aggregate across every active location. */
    public static function branchDataIssues(?int $branchId = null): array
    {
        if (! Branches::enabled()) {
            return self::dataIssues(Store::settings());
        }
        $branches = DB::table('branches')->when($branchId !== null,
            fn ($query) => $query->where('id', $branchId), fn ($query) => $query->where('active', true))
            ->orderBy('id')->get(['id', 'code', 'active']);
        if ($branches->isEmpty()) {
            return ['Không có cơ sở hợp lệ để kiểm tra mở nhận đơn.'];
        }
        $issues = [];
        foreach ($branches as $branch) {
            $local = Branches::run((int) $branch->id, fn () => self::dataIssues(Store::settings()));
            if (! $branch->active) {
                array_unshift($local, 'Cơ sở đang ngừng hoạt động.');
            }
            foreach ($local as $issue) {
                $issues[] = '[Cơ sở #'.$branch->id.' '.$branch->code.'] '.$issue;
            }
        }

        return $issues;
    }
}
