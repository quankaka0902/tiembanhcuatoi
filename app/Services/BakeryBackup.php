<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class BakeryBackup
{
    private const FILES = ['database.sql', 'images.zip'];

    private const MANIFEST = 'manifest.json';

    public function create(): string
    {
        $root = $this->privateDirectory(config('operations.backup.root'));
        $copyRoot = config('operations.backup.copy_root') ? $this->privateDirectory(config('operations.backup.copy_root')) : null;
        if ($copyRoot && ($this->inside($root, $copyRoot) || $this->inside($copyRoot, $root))) {
            throw new RuntimeException('Chọn hai thư mục sao lưu độc lập.');
        }
        $id = now()->format('Ymd-His').'-'.bin2hex(random_bytes(4));
        $startedAt = now()->timestamp;
        $backup = $this->publish($root, $id, function (string $directory) use ($startedAt) {
            $this->dumpDatabase($directory.'/database.sql');
            $this->checkMediaReferences();
            $this->archiveMedia($directory.'/images.zip');
            $metadata = [];
            foreach (self::FILES as $file) {
                $path = $directory.'/'.$file;
                $metadata[$file] = ['size' => filesize($path), 'sha256' => hash_file('sha256', $path)];
            }
            if (file_put_contents($directory.'/'.self::MANIFEST, json_encode([
                'version' => 1,
                'started_at' => $startedAt,
                'completed_at' => now()->timestamp,
                'files' => $metadata,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false) {
                throw new RuntimeException('Không ghi được manifest của bản sao.');
            }
        });

        if ($copyRoot) {
            $this->publish($copyRoot, $id, function (string $directory) use ($backup) {
                foreach ([...self::FILES, self::MANIFEST] as $file) {
                    if (! copy($backup.'/'.$file, $directory.'/'.$file)) {
                        throw new RuntimeException('Không sao chép được bản sao lưu sang nơi lưu thứ hai.');
                    }
                }
            });
        }

        return $id;
    }

    public function verified(string $directory): bool
    {
        $manifest = $this->manifest($directory);
        if (! $manifest) {
            return false;
        }
        foreach (self::FILES as $file) {
            if (hash_file('sha256', $directory.'/'.$file) !== $manifest['files'][$file]['sha256']) {
                return false;
            }
        }

        return true;
    }

    public function hasRecentCompleteBackup(int $cutoff): bool
    {
        $root = config('operations.backup.copy_root') ?: config('operations.backup.root');
        if (! $root || ! is_dir($root)) {
            return false;
        }
        foreach (array_reverse(glob($root.'/*', GLOB_ONLYDIR) ?: []) as $directory) {
            if (is_link($directory) || ! preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/D', basename($directory))) {
                continue;
            }
            $manifest = $this->manifest($directory);
            if ($manifest && $manifest['completed_at'] >= $cutoff && $manifest['completed_at'] <= now()->timestamp) {
                return true;
            }
        }

        return false;
    }

    private function dumpDatabase(string $path): void
    {
        $connection = DB::connection();
        $schema = $connection->getDatabaseName();
        $defaults = config('operations.backup.mysql_defaults');
        if ($connection->getDriverName() !== 'mysql' || ! preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_-]*$/D', $schema)
            || ! $defaults || ! ($defaults = realpath($defaults)) || ! is_file($defaults)) {
            throw new RuntimeException('Cấu hình MySQL và tệp tùy chọn riêng của tài khoản sao lưu.');
        }
        $socket = $connection->getConfig('unix_socket');
        $host = $connection->getConfig('host');
        if (! $socket && (! is_string($host) || $host === '')) {
            throw new RuntimeException('Chọn host MySQL của cơ sở dữ liệu nguồn.');
        }
        $network = $socket
            ? ['--protocol=SOCKET', '--socket='.$socket]
            : ['--protocol=TCP', '--host='.$host, '--port='.$connection->getConfig('port')];
        $process = new Process([
            config('operations.backup.mysqldump'), '--defaults-file='.$defaults, '--no-login-paths',
            ...$network, '--single-transaction', '--quick', '--no-tablespaces', '--set-gtid-purged=OFF',
            '--skip-add-drop-table', '--hex-blob', '--result-file='.$path, $schema,
        ], timeout: 900);
        // Avoid including options or raw mysqldump output in application logs.
        if ($process->run() !== 0 || ! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('Sao lưu MySQL thất bại. Kiểm tra quyền đọc và cấu hình mysqldump.');
        }
    }

    private function archiveMedia(string $path): void
    {
        $root = realpath(config('filesystems.disks.public.root'));
        if (! $root || ! is_dir($root) || ! is_readable($root)) {
            throw new RuntimeException('Không đọc được thư mục gốc của ảnh.');
        }
        $media = $root.DIRECTORY_SEPARATOR.'bakery';
        $files = [];
        if (file_exists($media) || is_link($media)) {
            if (is_link($media) || ! is_dir($media) || ($files = scandir($media)) === false) {
                throw new RuntimeException('Không đọc được thư mục ảnh bakery.');
            }
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Không tạo được tệp ảnh sao lưu.');
        }
        $added = $zip->addEmptyDir('bakery');
        foreach ($files as $file) {
            if (! preg_match('/^[a-zA-Z0-9]{40}\.(jpg|jpeg|png|webp)$/D', $file)) {
                continue;
            }
            $source = $media.DIRECTORY_SEPARATOR.$file;
            $added = $added && ! is_link($source) && is_file($source) && $zip->addFile($source, 'bakery/'.$file);
        }
        if (! $zip->close() || ! $added || $zip->open($path, ZipArchive::CHECKCONS | ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Tệp ảnh sao lưu chưa hoàn chỉnh.');
        }
        $zip->close();
    }

    private function checkMediaReferences(): void
    {
        $publicDisk = config('filesystems.disks.public.root');
        $products = DB::table('products')->select('images')->orderBy('id')->cursor()
            ->map(fn ($product) => json_decode($product->images, true, flags: JSON_THROW_ON_ERROR));
        $sets = LazyCollection::make([[Store::settings()['logo']]])->concat($products);
        foreach ($sets as $images) {
            foreach ($images as $url) {
                if (is_string($url) && str_starts_with($url, '/anh/')) {
                    $file = substr($url, 5);
                    if (! preg_match('/^[a-zA-Z0-9]{40}\.(jpg|jpeg|png|webp)$/D', $file)
                        || ! is_file($publicDisk.'/bakery/'.$file)) {
                        throw new RuntimeException('Một ảnh tải lên đang được cửa hàng sử dụng bị thiếu.');
                    }
                }
            }
        }
    }

    private function publish(string $root, string $id, callable $write): string
    {
        $temporary = $root.'/.partial-'.$id;
        $final = $root.'/'.$id;
        if (! mkdir($temporary, 0700)) {
            throw new RuntimeException('Không tạo được thư mục tạm cho bản sao lưu.');
        }
        try {
            $write($temporary);
            if (! $this->verified($temporary) || ! rename($temporary, $final)) {
                throw new RuntimeException('Bản sao lưu không khớp mã băm hoặc không thể công bố.');
            }
        } finally {
            if (is_dir($temporary)) {
                foreach ([...self::FILES, self::MANIFEST] as $file) {
                    $path = $temporary.'/'.$file;
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
                rmdir($temporary);
            }
        }

        return $final;
    }

    private function manifest(string $directory): ?array
    {
        $path = $directory.'/'.self::MANIFEST;
        if (! is_file($path) || is_link($path) || filesize($path) > 16384) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ($data['version'] ?? null) !== 1
            || ! is_int($data['completed_at'] ?? null) || $data['completed_at'] <= 0
            || ! is_array($data['files'] ?? null)) {
            return null;
        }
        if (array_key_exists('started_at', $data) && (! is_int($data['started_at'])
            || $data['started_at'] <= 0 || $data['started_at'] > $data['completed_at'])) {
            return null;
        }
        foreach (self::FILES as $file) {
            $metadata = $data['files'][$file] ?? [];
            $path = $directory.'/'.$file;
            if (! is_array($metadata) || ! is_int($metadata['size'] ?? null) || $metadata['size'] < 0
                || ! is_string($metadata['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $metadata['sha256'])
                || ! is_file($path) || is_link($path) || filesize($path) !== $metadata['size']) {
                return null;
            }
        }

        return $data;
    }

    private function privateDirectory(?string $path): string
    {
        $directory = $path ? realpath($path) : false;
        $public = realpath(public_path());
        $media = realpath(config('filesystems.disks.public.root'));
        if (! $directory || ! is_dir($directory) || ! is_writable($directory)
            || ($public && $this->inside($directory, $public)) || ($media && $this->inside($directory, $media))) {
            throw new RuntimeException('Chọn thư mục sao lưu riêng, có quyền ghi và nằm ngoài thư mục phục vụ web.');
        }

        return $directory;
    }

    private function inside(string $path, string $root): bool
    {
        return str_starts_with(strtolower($path.DIRECTORY_SEPARATOR), strtolower(rtrim($root, '\\/').DIRECTORY_SEPARATOR));
    }
}
