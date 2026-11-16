<?php

namespace App\Console\Commands;

use App\Services\BakeryBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class BakeryBackupCommand extends Command
{
    protected $signature = 'bakery:backup {--verify= : Đối chiếu SHA-256 trong một thư mục bản sao}';

    protected $description = 'Sao lưu MySQL và ảnh hoặc đối chiếu bản sao đã tạo';

    public function handle(BakeryBackup $backups): int
    {
        if ($directory = $this->option('verify')) {
            if (! $backups->verified($directory)) {
                $this->error('Bản sao thiếu tệp hoặc không khớp mã băm.');

                return self::FAILURE;
            }
            $this->info('Mã băm của cả hai tệp khớp manifest.');

            return self::SUCCESS;
        }

        $result = Cache::store('bakery-operations')->lock('run-backup', 1800)->get(function () use ($backups) {
            $mode = umask(0077);
            try {
                $id = $backups->create();
                $this->info('Đã kiểm tra và lưu bản sao '.$id);

                return self::SUCCESS;
            } catch (RuntimeException $failure) {
                $this->error($failure::class === RuntimeException::class ? $failure->getMessage() : 'Sao lưu thất bại. Kiểm tra cấu hình và quyền ghi.');

                return self::FAILURE;
            } catch (Throwable) {
                $this->error('Sao lưu thất bại. Kiểm tra cấu hình và quyền ghi.');

                return self::FAILURE;
            } finally {
                umask($mode);
            }
        });

        return $result === false ? self::SUCCESS : $result;
    }
}
