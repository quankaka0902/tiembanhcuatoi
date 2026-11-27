<?php

use App\Models\User;
use App\Services\Branches;
use App\Services\Checkout;
use App\Services\OrderTransfers;
use App\Services\Store;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || ! str_ends_with(DB::getDatabaseName(), '_test') || DB::connection()->getConfig('url')) {
    fwrite(STDERR, "Dedicated MySQL test schema required.\n");
    exit(2);
}
[$script, $mode, $id, $actorId, $variantId, $key, $slot] = $argv;
$actor = User::findOrFail((int) $actorId);
$checkout = app(Checkout::class);
if ($mode === 'checkout') {
    Branches::use(2);
    $cart = [['variant_id' => (int) $variantId, 'quantity' => 1, 'message' => null, 'note' => null]];
    $fields = ['key' => $key, 'branch_id' => 2, 'coupon' => '', 'fulfillment' => 'pickup', 'method' => 'cod',
        'region' => null, 'address' => null, 'slot' => $slot,
        'name' => '[THỬ] Tranh cơ sở đích', 'email' => 'transfer-race@example.test', 'phone' => '0900000000'];
    $lines = $checkout->cart($cart);
    $fields['quote_token'] = $checkout->quoteToken($checkout->quote($lines, $fields, Store::settings()), $lines, $key, $fields);
} else {
    Branches::use(1);
}
echo "ready\n";
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'go') {
    exit(2);
}
try {
    if ($mode === 'checkout') {
        $checkout->place($cart, $fields, null);
        echo 'created';
    } elseif ($mode === 'execute') {
        OrderTransfers::execute((int) $id, $actor, ['request_key' => $key]);
        echo 'transferred';
    } elseif ($mode === 'propose') {
        // The preview was minted by the parent; proposals race the same source order.
        $preview = json_decode(base64_decode($argv[7] ?? '', true), true, flags: JSON_THROW_ON_ERROR);
        OrderTransfers::propose((int) $id, $actor, ['target_branch_id' => 2, 'slot' => $slot, 'note' => '[THỬ] Tranh đề nghị',
            'request_key' => $key, 'quote_token' => $preview['quote_token']]);
        echo 'proposed';
    } else {
        throw new LogicException('Unknown transfer worker mode.');
    }
} catch (ValidationException $failure) {
    echo array_key_first($failure->errors());
} catch (Throwable $failure) {
    fwrite(STDERR, $failure::class);
    exit(3);
}
