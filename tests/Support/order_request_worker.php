<?php

use App\Models\User;
use App\Services\Checkout;
use App\Services\OrderRequests;
use App\Services\Orders;
use App\Services\Store;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::getDriverName() !== 'mysql' || ! str_ends_with(DB::getDatabaseName(), '_test') || config('database.connections.mysql.url')) {
    exit(2);
}
$mode = $argv[1];
$order = Orders::query()->find((int) $argv[2]);
if (! $order) {
    exit(2);
}
$checkout = app(Checkout::class);
$slot = $argv[5];
$fields = ['request_key' => $argv[4], 'kind' => 'cancel', 'previous_slot' => substr($order->scheduled_at, 0, 16), 'reason' => 'Khách gửi lại cùng yêu cầu.'];
$cart = [['variant_id' => 1, 'quantity' => 1, 'message' => null, 'note' => null]];
if ($mode === 'checkout') {
    $fields = [
        'key' => $argv[4], 'fulfillment' => 'pickup', 'method' => 'cod', 'slot' => $slot,
        'name' => 'Khách tranh khung thử', 'email' => 'concurrent@tiembanh.test', 'phone' => '0900000000',
    ];
    $lines = $checkout->cart($cart);
    $fields['quote_token'] = $checkout->quoteToken($checkout->quote($lines, $fields, Store::settings()), $lines, $fields['key']);
}
echo "ready\n";
if (trim((string) fgets(STDIN)) !== 'go') {
    exit(1);
}
try {
    if ($mode === 'request') {
        $result = OrderRequests::submitOwner($order->id, User::findOrFail($order->user_id), $fields);
        echo 'request:'.$result->id;
    } elseif ($mode === 'approve') {
        $actor = User::where('role', 'staff')->firstOrFail();
        OrderRequests::resolve((int) $argv[3], $actor, ['decision' => 'approved', 'note' => 'Chấp thuận giờ khách yêu cầu.']);
        echo 'approved';
    } elseif ($mode === 'checkout') {
        [, $created] = $checkout->place($cart, $fields, null);
        echo $created ? 'created' : 'replayed';
    } else {
        exit(2);
    }
} catch (ValidationException $failure) {
    echo array_key_first($failure->errors());
}
