<?php

use App\Services\Checkout;
use App\Services\Store;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$checkout = $app->make(Checkout::class);
$slot = $argv[3] ?: $checkout->slots(Store::settings(), 120)[2];

echo "ready\n";
if (trim((string) fgets(STDIN)) !== 'go') {
    exit(1);
}

try {
    [, $created] = $checkout->place([
        ['variant_id' => 1, 'quantity' => 1, 'message' => null, 'note' => null],
    ], [
        'key' => $argv[1], 'coupon' => $argv[2], 'fulfillment' => $argv[4], 'method' => 'cod',
        'region' => 'quan-1', 'address' => 'Địa chỉ giao thử nghiệm',
        'slot' => $slot, 'name' => 'Kiểm thử đồng thời', 'email' => 'concurrent@tiembanh.test', 'phone' => '0900000000',
    ], null);
    echo $created ? 'created' : 'replayed';
} catch (ValidationException $failure) {
    echo array_key_first($failure->errors());
}
