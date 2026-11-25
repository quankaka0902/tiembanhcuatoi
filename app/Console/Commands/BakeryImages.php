<?php

namespace App\Console\Commands;

use App\Services\ResponsiveImages;
use App\Services\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BakeryImages extends Command
{
    protected $signature = 'bakery:images {--rebuild : Tạo lại cả ảnh đã có} {--missing : Chỉ bổ sung hoặc sửa tệp thiếu/hỏng} {--source= : Một URL ảnh local hợp lệ}';

    protected $description = 'Tạo ảnh WebP nhiều kích thước, giữ nguyên ảnh gốc và trạng thái duyệt';

    public function handle(ResponsiveImages $images): int
    {
        $seen = [];
        $processed = $failed = 0;
        $process = function (mixed $url) use ($images, &$seen, &$processed, &$failed) {
            if (! is_string($url) || $url === '' || isset($seen[$url])) {
                return;
            }
            $seen[$url] = true;
            try {
                $images->generate($url, (bool) $this->option('rebuild'));
                $processed++;
            } catch (Throwable) {
                // Do not log arbitrary imported URLs or filesystem details.
                $failed++;
            }
        };
        if ($source = $this->option('source')) {
            $process($source);
        } else {
            DB::table('products')->select('id', 'images')->orderBy('id')->chunkById(100, function ($products) use ($process) {
                foreach ($products as $product) {
                    $urls = json_decode($product->images, true);
                    foreach (is_array($urls) ? $urls : [] as $url) {
                        $process($url);
                    }
                }
            });
            $process(Store::settings()['logo']);
        }
        $this->line("Đã kiểm tra/tạo {$processed} ảnh; {$failed} nguồn không xử lý được. Ảnh gốc và trạng thái duyệt được giữ nguyên.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
