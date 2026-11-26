<?php

use App\Services\Branches;
use App\Services\Checkout;
use App\Services\Inventory;
use App\Services\Store;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$variantId = filter_var($argv[6] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (! $variantId || ! $app->environment('testing') || DB::getDriverName() !== 'mysql' || ! str_ends_with(DB::getDatabaseName(), '_test') || DB::connection()->getConfig('url')) {
    fwrite(STDERR, "Dedicated MySQL test schema and variant argument required.\n");
    exit(2);
}
Branches::use((int) $argv[1]);
if (($argv[5] ?? '') === 'hold') {
    DB::beginTransaction();
    Inventory::lock([$variantId]);
    echo "ready\n";
    fflush(STDOUT);
    fgets(STDIN);
    DB::rollBack();
    exit;
}
$checkout = $app->make(Checkout::class);
$cart = [['variant_id' => $variantId, 'quantity' => 1, 'message' => null, 'note' => null]];
$fields = ['key' => $argv[2], 'branch_id' => (int) $argv[1], 'coupon' => $argv[3], 'fulfillment' => 'pickup',
    'method' => 'cod', 'region' => null, 'address' => null, 'slot' => $argv[4],
    'name' => '[THỬ] Đồng thời nhiều cơ sở', 'email' => 'branch-race@example.test', 'phone' => '0900000000'];
$lines = $checkout->cart($cart);
$fields['quote_token'] = $checkout->quoteToken($checkout->quote($lines, $fields, Store::settings()), $lines, $fields['key'], $fields);
echo "ready\n";
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'go') {
    exit(1);
}
try {
    [, $created] = $checkout->place($cart, $fields, null);
    echo $created ? 'created' : 'replayed';
} catch (ValidationException $failure) {
    echo array_key_first($failure->errors());
}
