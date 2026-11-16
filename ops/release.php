<?php

declare(strict_types=1);

function releasePath(string $root, string $id): string
{
    if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,79}$/D', $id)) {
        throw new RuntimeException('Mã phiên bản không hợp lệ.');
    }
    $base = $root.DIRECTORY_SEPARATOR.'releases';
    $candidate = realpath($base.DIRECTORY_SEPARATOR.$id);
    if (! $candidate || dirname($candidate) !== $base) {
        throw new RuntimeException('Phiên bản phải nằm trực tiếp trong thư mục releases.');
    }
    foreach (['artisan', 'vendor/autoload.php', 'public/index.php', 'public/build/manifest.json'] as $file) {
        if (! is_file($candidate.DIRECTORY_SEPARATOR.$file)) {
            throw new RuntimeException('Gói phiên bản chưa có đầy đủ mã, dependency và frontend đã build.');
        }
    }

    return $candidate;
}

function checkRelease(string $path, bool $staging): void
{
    $args = [PHP_BINARY, 'artisan', 'bakery:preflight', '--no-interaction'];
    if ($staging) {
        $args[] = '--staging';
    }
    $process = proc_open($args, [0 => STDIN, 1 => STDERR, 2 => STDERR], $pipes, $path);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Preflight của phiên bản thất bại. Phiên bản hiện hành đã được giữ lại.');
    }
}

function writeReleaseState(string $root, array $state): void
{
    $temporary = $root.DIRECTORY_SEPARATOR.'release-'.bin2hex(random_bytes(8)).'.tmp';
    try {
        if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR)) === false
            || ! rename($temporary, $root.DIRECTORY_SEPARATOR.'release.json')) {
            throw new RuntimeException('Không cập nhật được phiên bản hiện hành.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function runRelease(array $args): int
{
    $action = $args[1] ?? '';
    $root = realpath($args[2] ?? '');
    $candidate = $action === 'activate' ? ($args[3] ?? '') : null;
    $remaining = array_slice($args, $action === 'activate' ? 4 : 3);
    $staging = $remaining === ['--staging'];
    if (! $root || ! in_array($action, ['current', 'activate', 'rollback'], true) || ($remaining && (! $staging || $action === 'current'))) {
        throw new RuntimeException('Dùng release.php current ROOT, activate ROOT ID [--staging] hoặc rollback ROOT [--staging].');
    }
    $lock = fopen($root.DIRECTORY_SEPARATOR.'release.lock', 'c');
    if (! $lock || ! flock($lock, LOCK_EX)) {
        throw new RuntimeException('Không khóa được trạng thái phát hành.');
    }
    try {
        $file = $root.DIRECTORY_SEPARATOR.'release.json';
        $state = is_file($file) ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : ['current' => null, 'previous' => null];
        $next = match ($action) {
            'current' => $state['current'],
            'rollback' => $state['previous'],
            default => $candidate,
        };
        if (! $next) {
            throw new RuntimeException('Chưa có phiên bản phù hợp trong trạng thái phát hành.');
        }
        $path = releasePath($root, $next);
        if ($action !== 'current' && $next !== $state['current']) {
            checkRelease($path, $staging);
            writeReleaseState($root, ['current' => $next, 'previous' => $state['current']]);
        }
        echo $path.PHP_EOL;

        return 0;
    } finally {
        fclose($lock);
    }
}

try {
    exit(runRelease($argv));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
