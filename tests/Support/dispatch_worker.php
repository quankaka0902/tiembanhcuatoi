<?php

use App\Models\User;
use App\Services\Branches;
use App\Services\Fulfillments;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$jobId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$actorId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (! $jobId || ! $actorId || ! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || ! str_ends_with(DB::getDatabaseName(), '_test') || DB::connection()->getConfig('url')) {
    fwrite(STDERR, "Dedicated MySQL test schema and valid arguments required.\n");
    exit(2);
}
Branches::use(1);
$actor = User::findOrFail($actorId);
echo "ready\n";
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'go') {
    exit(1);
}
try {
    $job = Fulfillments::change($jobId, $actor, ['action' => 'delivered', 'expected_version' => (int) $argv[4],
        'request_key' => $argv[3], 'reference' => 'CONCURRENT-LOCAL-123', 'verified' => true]);
    echo $job->status;
} catch (ValidationException $failure) {
    echo array_key_first($failure->errors());
} catch (Throwable $failure) {
    fwrite(STDERR, class_basename($failure).' at line '.$failure->getLine()."\n");
    exit(2);
}
