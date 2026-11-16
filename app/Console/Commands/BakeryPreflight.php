<?php

namespace App\Console\Commands;

use App\Jobs\SendOrderEmail;
use App\Services\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BakeryPreflight extends Command
{
    protected $signature = 'bakery:preflight {--staging : Kiểm tra môi trường local nghiệm thu}';

    protected $description = 'Kiểm tra cấu hình và dữ liệu cần thiết trước khi mở nhận đơn';

    public function handle(): int
    {
        $staging = $this->option('staging');
        $url = (string) config('app.url');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        $failures = [];

        if ($staging) {
            $database = (string) DB::connection('mysql')->getDatabaseName();
            if (! app()->environment(['local', 'testing']) || ! $loopback || ! str_ends_with($database, '_test')) {
                $this->error('Nghiệm thu cần APP_ENV=local/testing, URL loopback và schema MySQL có hậu tố _test.');

                return self::FAILURE;
            }
            if (! in_array(config('mail.default'), ['log', 'array'], true)) {
                $failures[] = 'Dùng mailer log hoặc array cho nghiệm thu local.';
            }
        } else {
            if (! app()->environment('production') || config('app.debug') || config('bakery.demo_seed')) {
                $failures[] = 'Đặt APP_ENV=production, APP_DEBUG=false và BAKERY_DEMO_SEED=false.';
            }
            if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
                || $loopback || ! config('session.secure') || ! config('session.encrypt')) {
                $failures[] = 'Cấu hình APP_URL HTTPS, SESSION_SECURE_COOKIE=true và SESSION_ENCRYPT=true.';
            }
            $sender = (string) config('mail.from.address');
            if (config('mail.default') !== 'smtp' || ! filter_var($sender, FILTER_VALIDATE_EMAIL)
                || preg_match('/(?:\.(?:test|example|invalid)|@example\.(?:com|net|org))$/i', $sender)) {
                $failures[] = 'Cấu hình SMTP và địa chỉ gửi thư của cửa hàng.';
            }
            if (is_file(public_path('hot'))) {
                $failures[] = 'Gỡ chế độ Vite dev trước khi mở bán.';
            }
        }

        try {
            app('encrypter');
        } catch (Throwable) {
            $failures[] = 'Cần APP_KEY hợp lệ cho môi trường này.';
        }

        if (! is_file(public_path('build/manifest.json'))) {
            $failures[] = 'Build frontend bằng npm run build.';
        }
        $publicRoot = config('filesystems.disks.public.root');
        if (! is_dir($publicRoot) || ! is_writable($publicRoot)) {
            $failures[] = 'Cấp quyền ghi cho thư mục ảnh sản phẩm.';
        }

        if (config('database.default') !== 'mysql') {
            $failures[] = 'Chọn kết nối MySQL riêng cho môi trường này.';
        } else {
            array_push($failures, ...$this->databaseChecks($staging));
        }

        foreach ($failures as $failure) {
            $this->error($failure);
        }
        if ($failures) {
            return self::FAILURE;
        }

        $this->info('Cấu hình và dữ liệu qua kiểm tra. Dùng đơn thử để xác minh worker và luồng gửi thư.');

        return self::SUCCESS;
    }

    private function databaseChecks(bool $staging): array
    {
        try {
            $migrator = app('migrator');
            $migrator->setConnection(config('database.default'));
            if (! $migrator->repositoryExists()
                || array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan())) {
                return ['Chạy đủ migration trên cơ sở dữ liệu của môi trường này.'];
            }
            $queue = SendOrderEmail::CONNECTION;
            $failedDb = config('queue.failed.database');
            if (! Schema::hasTable(config("queue.connections.{$queue}.table"))
                || config('queue.failed.driver') !== 'database-uuids'
                || ! Schema::connection($failedDb)->hasTable(config('queue.failed.table'))) {
                return ['Hàng đợi email cần bảng jobs và nhật ký failed_jobs qua database-uuids.'];
            }

            $errors = [];
            if (! DB::table('users')->where('role', 'admin')->where('active', true)->exists()) {
                $errors[] = 'Tạo tài khoản quản trị đang hoạt động bằng bakery:admin.';
            }
            if (! $staging) {
                $settings = Store::settings();
                if ($settings['bank_demo'] || DB::table('users')->whereIn('email', [
                    'admin@tiembanh.test', 'nhanvien@tiembanh.test', 'doisoat@tiembanh.test', 'khach@tiembanh.test',
                ])->exists()) {
                    $errors[] = 'Gỡ tài khoản mẫu và dữ liệu chuyển khoản mẫu trước khi mở bán.';
                }
                if (! ($settings['phone'] || $settings['email']) || ! $settings['address'] || ! $settings['order_policy']) {
                    $errors[] = 'Điền liên hệ, địa chỉ và chính sách đặt bánh trong quản trị.';
                }
            }

            return $errors;
        } catch (Throwable) {
            return ['Kiểm tra kết nối MySQL và quyền đọc schema của ứng dụng.'];
        }
    }
}
