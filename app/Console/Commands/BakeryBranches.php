<?php

namespace App\Console\Commands;

use App\Services\BranchMigration;
use Illuminate\Console\Command;
use RuntimeException;

class BakeryBranches extends Command
{
    protected $signature = 'bakery:branches
        {--backfill : Bổ sung dữ liệu cơ sở mặc định, không thay đổi tồn khả dụng}
        {--verify : Đối chiếu baseline, số lượng, tiền, tồn và lượt mã}
        {--activate : Bật chế độ đọc theo cơ sở sau đối chiếu, vẫn tạm ngừng nhận đơn}
        {--rollback-read : Quay về chế độ đọc cũ nếu dữ liệu còn tương thích}
        {--batch=200 : Số dòng mỗi batch, từ 1 đến 1000}';

    protected $description = 'Chuyển đổi cơ sở theo bước có đối chiếu; không mở bán hoặc xóa dữ liệu cũ';

    public function handle(BranchMigration $migration): int
    {
        $selected = array_filter(['backfill', 'verify', 'activate', 'rollback-read'], fn ($flag) => $this->option($flag));
        if (count($selected) !== 1) {
            $this->error('Chọn đúng một bước --backfill, --verify, --activate hoặc --rollback-read.');

            return self::FAILURE;
        }
        $action = array_values($selected)[0];
        try {
            $report = match ($action) {
                'backfill' => $migration->backfill(filter_var($this->option('batch'), FILTER_VALIDATE_INT) ?: 0),
                'verify' => $migration->verify(),
                'activate' => $migration->activate(),
                'rollback-read' => $migration->rollbackRead(),
            };
        } catch (RuntimeException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
