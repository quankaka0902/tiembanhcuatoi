<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use WeakMap;

class ResponsiveImages
{
    public const WIDTHS = [320, 640, 960, 1440];

    private const LEGACY = ['cheesecake.jpg', 'chocolate.jpg', 'cookies.jpg', 'croissant.jpg', 'strawberry.webp', 'tiramisu.jpg'];

    private static ?WeakMap $requestCache = null;

    /** Existing URLs are retained; only local, explicitly supported sources are ever opened. */
    public function sourcePath(string $url): ?string
    {
        if (preg_match('~^/anh/([A-Za-z0-9]{40}\.(?:jpg|jpeg|png|webp))$~D', $url, $match)) {
            $directory = Storage::disk('public')->path('bakery');
            $path = $directory.DIRECTORY_SEPARATOR.$match[1];
        } elseif (str_starts_with($url, '/images/') && in_array(substr($url, 8), self::LEGACY, true)) {
            $directory = public_path('images');
            $path = $directory.DIRECTORY_SEPARATOR.substr($url, 8);
        } else {
            return null;
        }
        if (is_link($directory) || is_link($path) || ! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $resolved = realpath($path);
        $root = realpath($directory);

        return $resolved && $root && dirname($resolved) === $root ? $resolved : null;
    }

    /** A single metadata query per application/request, including catalog collections. */
    private function records(): array
    {
        self::$requestCache ??= new WeakMap;
        $app = app()->bound('request') ? request() : app();
        $key = Storage::disk('public')->path('bakery');
        $cache = self::$requestCache[$app] ?? [];
        if (! array_key_exists($key, $cache)) {
            $cache[$key] = Schema::hasTable('responsive_images')
                ? DB::table('responsive_images')->get()->keyBy('source_url')->all() : [];
            self::$requestCache[$app] = $cache;
        }

        return $cache[$key];
    }

    private function clearCache(): void
    {
        $scope = app()->bound('request') ? request() : app();
        if (self::$requestCache && isset(self::$requestCache[$scope])) {
            unset(self::$requestCache[$scope]);
        }
    }

    public function assets(array $urls): array
    {
        $result = [];
        foreach ($urls as $url) {
            if (is_string($url)) {
                $result[$url] = $this->asset($url);
            }
        }

        return $result;
    }

    public function asset(?string $url): ?array
    {
        if (! $url) {
            return null;
        }
        $fallback = ['src' => $url, 'srcset' => null, 'width' => null, 'height' => null,
            'sources' => [], 'fallback' => $url, 'original_width' => null, 'original_height' => null];
        $path = $this->sourcePath($url);
        if (! $path) {
            return $fallback;
        }
        $record = $this->records()[$url] ?? null;
        $data = $record ? json_decode($record->metadata, true) : null;
        if (! is_array($data) || ! $this->dimensions($data) || ! is_array($data['sources'] ?? null)
            || $record->source_size !== @filesize($path) || $record->source_mtime !== @filemtime($path)) {
            return $fallback;
        }
        $sources = [];
        foreach ($data['sources'] as $source) {
            if (! $this->variantValid($source, false)) {
                continue;
            }
            $sources[] = ['src' => '/anh/'.$source['file'], 'width' => $source['width'], 'height' => $source['height']];
        }
        if (! $sources) {
            return [...$fallback, 'width' => $data['width'], 'height' => $data['height'],
                'original_width' => $data['width'], 'original_height' => $data['height']];
        }
        $chosen = collect($sources)->last(fn ($source) => $source['width'] <= 960) ?? $sources[0];

        return ['src' => $chosen['src'], 'srcset' => implode(', ', array_map(fn ($source) => $source['src'].' '.$source['width'].'w', $sources)),
            'width' => $chosen['width'], 'height' => $chosen['height'], 'sources' => $sources, 'fallback' => $url,
            'original_width' => $data['width'], 'original_height' => $data['height']];
    }

    private function dimensions(array $data): bool
    {
        return is_int($data['width'] ?? null) && is_int($data['height'] ?? null)
            && $data['width'] > 0 && $data['width'] <= 5000 && $data['height'] > 0 && $data['height'] <= 5000;
    }

    private function variantValid(mixed $source, bool $checksum): bool
    {
        if (! is_array($source) || ! $this->dimensions($source)
            || ! is_string($source['file'] ?? null) || ! preg_match('/^[a-f0-9]{40}\.webp$/D', $source['file'])
            || ! is_int($source['bytes'] ?? null) || ! is_string($source['sha256'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $source['sha256'])) {
            return false;
        }
        $path = Storage::disk('public')->path('bakery/'.$source['file']);

        $digest = $checksum && is_file($path) ? @hash_file('sha256', $path) : null;

        return ! is_link($path) && is_file($path) && @filesize($path) === $source['bytes']
            && (! $checksum || (is_string($digest) && hash_equals($source['sha256'], $digest)));
    }

    /** Called by upload/CLI only. Public image and page requests never decode/resize images. */
    public function generate(string $url, bool $rebuild = false): array
    {
        $path = $this->sourcePath($url);
        $info = $path ? @getimagesize($path) : false;
        $bytes = $path ? filesize($path) : 0;
        if (! $info || $bytes > 4 * 1024 * 1024 || $info[0] > 5000 || $info[1] > 5000 || $info[0] * $info[1] > 12000000
            || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['uploads' => 'Ảnh không đọc được hoặc vượt giới hạn 4 MB, 5000 px mỗi chiều và 12 triệu điểm ảnh.']);
        }
        $digest = hash_file('sha256', $path);
        $existing = DB::table('responsive_images')->where('source_url', $url)->first();
        $old = $existing ? json_decode($existing->metadata, true) : [];
        if (! $rebuild && $existing?->source_sha256 === $digest && is_array($old)
            && $this->dimensions($old) && is_array($old['sources'] ?? null) && $old['sources']
            && collect($old['sources'])->every(fn ($source) => $this->variantValid($source, true))) {
            DB::table('responsive_images')->where('id', $existing->id)->update(['source_size' => $bytes, 'source_mtime' => filemtime($path)]);
            $this->clearCache();

            return $this->asset($url);
        }
        $image = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($path), 'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
        };
        if (! $image) {
            throw ValidationException::withMessages(['uploads' => 'Không giải mã được ảnh. Hãy xuất lại ảnh JPEG, PNG hoặc WebP.']);
        }
        try {
            $image = $this->orient($image, $info['mime'] === 'image/jpeg' ? $this->orientation($path) : 1);
            $width = imagesx($image);
            $height = imagesy($image);
            $sources = [];
            foreach (array_unique(array_map(fn ($target) => min($width, $target), self::WIDTHS)) as $targetWidth) {
                $targetHeight = max(1, (int) round($height * $targetWidth / $width));
                $target = imagecreatetruecolor($targetWidth, $targetHeight);
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
                imagecopyresampled($target, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
                ob_start();
                try {
                    if (! imagewebp($target, null, 80)) {
                        throw new RuntimeException('Không mã hóa được ảnh WebP.');
                    }
                    $encoded = ob_get_contents();
                } finally {
                    ob_end_clean();
                    imagedestroy($target);
                }
                $file = substr(hash('sha256', 'bakery-responsive-v1:'.$encoded), 0, 40).'.webp';
                $this->publish($file, $encoded, $path, is_array($old) ? ($old['sources'] ?? []) : []);
                $sources[] = ['file' => $file, 'width' => $targetWidth, 'height' => $targetHeight,
                    'bytes' => strlen($encoded), 'sha256' => hash('sha256', $encoded)];
            }
        } finally {
            imagedestroy($image);
        }
        DB::table('responsive_images')->updateOrInsert(['source_url' => $url], [
            'source_sha256' => $digest, 'source_size' => $bytes, 'source_mtime' => filemtime($path),
            'metadata' => json_encode(['width' => $width, 'height' => $height, 'sources' => $sources], JSON_THROW_ON_ERROR),
            'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
        ]);
        $this->clearCache();

        return $this->asset($url);
    }

    private function publish(string $file, string $bytes, string $original, array $owned): void
    {
        $disk = Storage::disk('public');
        $disk->makeDirectory('bakery');
        $path = $disk->path('bakery/'.$file);
        if (is_link(dirname($path)) || is_link($path) || realpath($path) === $original) {
            throw new RuntimeException('Không ghi ảnh dẫn xuất vào đường dẫn không an toàn.');
        }
        if (is_file($path)) {
            if (hash_file('sha256', $path) === hash('sha256', $bytes)) {
                return;
            }
            if (! collect($owned)->contains(fn ($source) => is_array($source) && ($source['file'] ?? null) === $file)) {
                throw new RuntimeException('Ảnh dẫn xuất trùng tên tệp đã có. Giữ nguyên tệp gốc.');
            }
        }
        $temporary = $disk->path('bakery/.partial-'.Str::uuid());
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || ! rename($temporary, $path)) {
                throw new RuntimeException('Không lưu được ảnh dẫn xuất.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function response(string $file)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}\.(?:jpg|jpeg|png|webp)$/D', $file), 404);
        foreach ($this->records() as $record) {
            $data = json_decode($record->metadata, true);
            foreach (is_array($data) && is_array($data['sources'] ?? null) ? $data['sources'] : [] as $source) {
                if (is_array($source) && ($source['file'] ?? null) === $file) {
                    if ($this->variantValid($source, true)) {
                        return Storage::disk('public')->response('bakery/'.$file, headers: [
                            'Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff',
                        ]);
                    }
                    $path = $this->sourcePath($record->source_url);
                    abort_unless($path, 404);

                    return response()->file($path, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
                }
            }
        }
        $path = $this->sourcePath('/anh/'.$file);
        abort_unless($path, 404);

        return response()->file($path, ['Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
        };
        if ($angle) {
            $rotated = imagerotate($image, $angle, 0);
            if (! $rotated) {
                throw new RuntimeException('Không xoay được ảnh.');
            }
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    /** Read just the JPEG APP1/TIFF orientation tag, without requiring ext-exif. */
    private function orientation(string $path): int
    {
        $header = file_get_contents($path, false, null, 0, 131072);
        for ($position = 2, $length = strlen($header); $position + 4 < $length;) {
            if (ord($header[$position]) !== 255) {
                break;
            }
            $marker = ord($header[$position + 1]);
            if (in_array($marker, [0xDA, 0xD9], true)) {
                break;
            }
            $size = unpack('n', substr($header, $position + 2, 2))[1];
            if ($size < 2 || $position + 2 + $size > $length) {
                break;
            }
            $segment = substr($header, $position + 4, $size - 2);
            if ($marker === 0xE1 && str_starts_with($segment, "Exif\0\0")) {
                $tiff = substr($segment, 6);
                $little = str_starts_with($tiff, "II\x2a\0");
                if (! $little && ! str_starts_with($tiff, "MM\0\x2a")) {
                    return 1;
                }
                $read = function (int $offset, int $bytes) use ($tiff, $little): ?int {
                    return $offset >= 0 && $offset + $bytes <= strlen($tiff)
                        ? unpack($bytes === 2 ? ($little ? 'v' : 'n') : ($little ? 'V' : 'N'), substr($tiff, $offset, $bytes))[1] : null;
                };
                $offset = $read(4, 4);
                $count = $offset !== null ? $read($offset, 2) : null;
                if ($count === null || $count > 256) {
                    return 1;
                }
                for ($index = 0; $index < $count; $index++) {
                    $entry = $offset + 2 + $index * 12;
                    if ($read($entry, 2) === 0x112 && $read($entry + 2, 2) === 3 && $read($entry + 4, 4) === 1) {
                        $value = $read($entry + 8, 2);

                        return $value >= 1 && $value <= 8 ? $value : 1;
                    }
                }
            }
            $position += 2 + $size;
        }

        return 1;
    }
}
