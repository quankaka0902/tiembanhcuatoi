<?php

use App\Models\User;
use App\Services\Branches;
use App\Services\Checkout;
use App\Services\Store;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$mode = $argv[1] ?? '';
$branch = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$variant = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (! $branch || ! $variant || ! in_array($mode, ['checkout', 'intake', 'legacy_catalog'], true) || ! $app->environment('testing')
    || DB::getDriverName() !== 'mysql' || ! str_ends_with(DB::getDatabaseName(), '_test') || DB::connection()->getConfig('url')) {
    fwrite(STDERR, "Dedicated MySQL test schema and valid arguments required.\n");
    exit(2);
}
Branches::use($branch);
$checkout = $app->make(Checkout::class);
$cart = [['variant_id' => $variant, 'quantity' => 1, 'message' => null, 'note' => null,
    'accessories' => empty($argv[4]) ? [] : [(int) $argv[4]]]];
$fields = ['key' => (string) Str::uuid(), 'branch_id' => $branch, 'coupon' => '', 'fulfillment' => 'pickup',
    'method' => 'cod', 'region' => null, 'address' => null, 'slot' => $argv[5],
    'name' => '[THỬ] Snapshot đồng thời', 'email' => 'snapshot@example.test', 'phone' => '0900000000'];
if ($mode === 'checkout') {
    $lines = $checkout->cart($cart);
    $fields['quote_token'] = $checkout->quoteToken($checkout->quote($lines, $fields, Store::settings()), $lines, $fields['key'], $fields);
}
if ($mode === 'legacy_catalog') {
    $productId = DB::table('variants')->where('id', $variant)->value('product_id');
    $product = DB::table('products')->find($productId);
    $catalogFields = array_intersect_key((array) $product, array_flip(['name', 'category_id', 'slug', 'description', 'ingredients', 'allergens',
        'prep_minutes', 'message_fee', 'active', 'featured', 'customizable']));
    $catalogFields['variants'] = DB::table('variants')->where('product_id', $productId)->orderBy('id')->get()
        ->map(function ($row) use ($variant) {
            return array_intersect_key((array) $row, array_flip(['id', 'name', 'price', 'active']))
                + ['stock' => $row->stock + ($row->id === $variant ? 5 : 0), 'expected_stock' => $row->stock];
        })->all();
    $admin = User::where('role', 'admin')->firstOrFail();
    $admin->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'])->save();
    Auth::login($admin);
    $session = $app->make('session')->driver();
    $session->put('staff_mfa_verified', $admin->staffProof());
    $session->save();
    $catalogRequest = Request::create('/quan-tri/san-pham/'.$productId, 'POST', $catalogFields,
        [config('session.cookie') => $session->getId()]);
    $catalogRequest->headers->set('Accept', 'application/json');
}

// This nonlocking read deliberately establishes the old MySQL read view before the parent commits its edit.
DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::beginTransaction();
Branches::enabled();
echo "ready\n";
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'go') {
    DB::rollBack();
    exit(1);
}
try {
    if ($mode === 'legacy_catalog') {
        $response = $app->make(HttpKernel::class)->handle($catalogRequest);
        $errors = json_decode($response->getContent(), true)['errors'] ?? [];
        $result = array_key_first($errors) ?? 'http_'.$response->getStatusCode();
    } elseif ($mode === 'intake') {
        $settings = Store::settings(lock: true);
        Store::saveSettings(array_replace($settings, ['accepting_orders' => false]));
        $result = 'saved';
    } else {
        [, $created] = $checkout->place($cart, $fields, null);
        $result = $created ? 'created' : 'replayed';
    }
    DB::commit();
    echo $result;
} catch (ValidationException $failure) {
    DB::rollBack();
    echo array_key_first($failure->errors());
} catch (Throwable $failure) {
    DB::rollBack();
    fwrite(STDERR, class_basename($failure).' at line '.$failure->getLine()."\n");
    exit(2);
}
