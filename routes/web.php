<?php

use App\Http\Controllers\Accounts;
use App\Http\Controllers\AdminCatalog;
use App\Http\Controllers\AdminConfig;
use App\Http\Controllers\AdminOrders;
use App\Http\Controllers\Storefront;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::controller(Storefront::class)->group(function () {
    Route::get('/', 'home');
    Route::get('/thuc-don', 'catalog');
    Route::get('/banh/{slug}', 'product');
    Route::get('/gio-hang', 'cart');
    Route::post('/gio-hang', 'saveLine')->block();
    Route::patch('/gio-hang/{line}', 'saveLine')->whereUuid('line')->block();
    Route::delete('/gio-hang/{line}', 'removeLine')->whereUuid('line')->block();
    Route::get('/dat-banh', 'checkout')->block();
    Route::post('/dat-banh/bao-gia', 'quote')->middleware('throttle:60,1');
    Route::post('/dat-banh', 'place')->middleware('throttle:20,1')->block(30, 10);
    Route::get('/tra-cuu', 'tracking');
    Route::post('/tra-cuu', 'track')->middleware('throttle:tracking');
    Route::get('/tra-cuu/{token}', 'tracked')->where('token', '[a-f0-9]{64}')->middleware('throttle:tracking')->name('tracking.show');
    Route::middleware(['auth', 'can:active-account'])->group(function () {
        Route::get('/don-cua-toi', 'history');
        Route::get('/don-cua-toi/{code}', 'ownOrder');
    });
});

Route::get('/anh/{file}', function (string $file) {
    $disk = Storage::disk('public');
    abort_unless($disk->exists('bakery/'.$file), 404);

    return $disk->response('bakery/'.$file, headers: ['Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
})->where('file', '[A-Za-z0-9]{40}\.(?:jpg|jpeg|png|webp)');

Route::middleware('guest')->group(function () {
    Route::get('/dang-nhap', fn (Accounts $accounts) => $accounts->form('login'))->name('login');
    Route::get('/dang-ky', fn (Accounts $accounts) => $accounts->form('register'));
    Route::get('/quen-mat-khau', fn (Accounts $accounts) => $accounts->form('forgot'));
    Route::get('/dat-lai-mat-khau/{token}', fn (Accounts $accounts, string $token) => $accounts->form('reset', $token))->name('password.reset');
    Route::post('/dang-nhap', [Accounts::class, 'login'])->middleware('throttle:login');
    Route::post('/dang-ky', [Accounts::class, 'register'])->middleware('throttle:8,1');
    Route::post('/quen-mat-khau', [Accounts::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('/dat-lai-mat-khau', [Accounts::class, 'reset'])->middleware('throttle:10,1');
});
Route::post('/dang-xuat', [Accounts::class, 'logout'])->middleware('auth');

Route::prefix('quan-tri')->middleware(['auth', 'can:staff'])->group(function () {
    Route::controller(AdminOrders::class)->group(function () {
        Route::get('/', 'dashboard');
        Route::get('/don-hang', 'index');
        Route::get('/don-hang/xuat', 'export');
        Route::get('/don-hang/{order}', 'show')->whereNumber('order');
        Route::patch('/don-hang/{order}', 'change')->whereNumber('order');
    });

    Route::middleware('can:admin')->group(function () {
        Route::controller(AdminCatalog::class)->group(function () {
            Route::get('/san-pham', 'index');
            Route::get('/san-pham/them', 'edit');
            Route::get('/san-pham/{product}', 'edit')->whereNumber('product');
            Route::post('/san-pham/{product?}', 'save')->whereNumber('product');
            Route::post('/danh-muc/{category?}', 'category')->whereNumber('category');
        });
        Route::controller(AdminConfig::class)->group(function () {
            Route::get('/cai-dat', 'settings');
            Route::post('/cai-dat', 'saveSettings');
            Route::get('/ma-giam/{coupon?}', 'coupons')->whereNumber('coupon');
            Route::post('/ma-giam/{coupon?}', 'saveCoupon')->whereNumber('coupon');
            Route::get('/nhan-su/{user?}', 'users')->whereNumber('user');
            Route::post('/nhan-su/{user?}', 'saveUser')->whereNumber('user');
        });
    });
});
