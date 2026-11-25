<?php

use App\Http\Controllers\Accounts;
use App\Http\Controllers\AdminCatalog;
use App\Http\Controllers\AdminConfig;
use App\Http\Controllers\AdminOrders;
use App\Http\Controllers\AdminSchedule;
use App\Http\Controllers\OrderChanges;
use App\Http\Controllers\PublicPages;
use App\Http\Controllers\Storefront;
use App\Http\Middleware\VerifyStaffFactor;
use App\Services\ResponsiveImages;
use Illuminate\Support\Facades\Route;

Route::controller(Storefront::class)->group(function () {
    Route::get('/', 'home');
    Route::get('/thuc-don', 'catalog');
    Route::get('/thuc-don/{categorySlug}', 'catalog')->where('categorySlug', '[a-z0-9]+(?:-[a-z0-9]+)*');
    Route::get('/banh/{slug}', 'product');
    Route::get('/gio-hang', 'cart');
    Route::post('/gio-hang', 'saveLine')->block();
    Route::patch('/gio-hang/{line}', 'saveLine')->whereUuid('line')->block();
    Route::delete('/gio-hang/{line}', 'removeLine')->whereUuid('line')->block();
    Route::get('/dat-banh', 'checkout')->block();
    Route::post('/dat-banh/bao-gia', 'quote')->middleware('throttle:checkout-quote');
    Route::post('/dat-banh', 'place')->middleware('throttle:checkout-place')->block(30, 10);
    Route::get('/chinh-sach-dat-banh', 'policy');
    Route::get('/tra-cuu', 'tracking');
    Route::post('/tra-cuu', 'track')->middleware('throttle:tracking');
    Route::post('/tra-cuu/gui-lai', 'resendTracking')->middleware('throttle:tracking-resend');
    Route::get('/tra-cuu/{token}', 'tracked')->where('token', '[a-f0-9]{64}')->middleware('throttle:tracking')->name('tracking.show');
    Route::post('/tra-cuu/{trackingToken}/lien-ket-thao-tac', [OrderChanges::class, 'sendLink'])
        ->where('trackingToken', '[a-f0-9]{64}')->middleware('throttle:order-action-link');
    Route::get('/thao-tac-don/{token}', [OrderChanges::class, 'guestForm'])
        ->where('token', '[a-f0-9]{64}')->middleware('throttle:order-actions');
    Route::post('/thao-tac-don/{token}', [OrderChanges::class, 'guestStore'])
        ->where('token', '[a-f0-9]{64}')->middleware('throttle:order-actions')->block();
    Route::middleware(['auth', 'can:active-account'])->group(function () {
        Route::get('/don-cua-toi', 'history');
        Route::get('/don-cua-toi/{code}', 'ownOrder');
        Route::post('/don-cua-toi/{code}/mua-lai', 'reorder')->block();
        Route::get('/don-cua-toi/{code}/yeu-cau', [OrderChanges::class, 'ownerForm']);
        Route::post('/don-cua-toi/{code}/yeu-cau', [OrderChanges::class, 'ownerStore'])->middleware('throttle:order-actions')->block();
    });
});

Route::controller(PublicPages::class)->group(function () {
    Route::get('/gioi-thieu', 'about');
    Route::get('/lien-he', 'contact');
    Route::get('/cau-hoi-thuong-gap', 'faq');
    Route::get('/cua-hang', 'location');
    Route::get('/robots.txt', 'robots');
    Route::get('/sitemap.xml', 'sitemap');
});

Route::get('/anh/{file}', function (string $file) {
    return app(ResponsiveImages::class)->response($file);
})->where('file', '[A-Za-z0-9]{40}\.(?:jpg|jpeg|png|webp)')->withoutMiddleware('web');

Route::middleware('guest')->group(function () {
    Route::get('/dang-nhap', fn (Accounts $accounts) => $accounts->form('login'))->name('login');
    Route::get('/dang-ky', fn (Accounts $accounts) => $accounts->form('register'));
    Route::get('/quen-mat-khau', fn (Accounts $accounts) => $accounts->form('forgot'));
    Route::get('/dat-lai-mat-khau/{token}', fn (Accounts $accounts, string $token) => $accounts->form('reset', $token))->name('password.reset');
    Route::post('/dang-nhap', [Accounts::class, 'login'])->middleware('throttle:login');
    Route::get('/xac-thuc-hai-buoc', [Accounts::class, 'challenge']);
    Route::post('/xac-thuc-hai-buoc', [Accounts::class, 'verifyChallenge'])->middleware('throttle:staff-factor')->block();
    Route::post('/dang-ky', [Accounts::class, 'register'])->middleware('throttle:8,1');
    Route::post('/quen-mat-khau', [Accounts::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('/dat-lai-mat-khau', [Accounts::class, 'reset'])->middleware('throttle:10,1');
});
Route::post('/dang-xuat', [Accounts::class, 'logout'])->middleware('auth');

Route::prefix('quan-tri')->middleware(['auth', 'can:staff', VerifyStaffFactor::class])->group(function () {
    Route::patch('/yeu-cau-don/{requestId}', [OrderChanges::class, 'resolve'])->whereNumber('requestId');
    Route::get('/lich-lam-banh', AdminSchedule::class);

    Route::controller(AdminOrders::class)->group(function () {
        Route::get('/', 'dashboard');
        Route::get('/don-hang', 'index');
        Route::get('/don-hang/xuat', 'export');
        Route::get('/doi-soat', 'reconciliation');
        Route::get('/viec-can-xu-ly', 'inbox');
        Route::get('/bao-cao', 'reports');
        Route::get('/don-hang/{order}', 'show')->whereNumber('order');
        Route::patch('/don-hang/{order}', 'change')->whereNumber('order');
        Route::patch('/don-hang/{order}/doi-gio', 'reschedule')->whereNumber('order');
        Route::post('/don-hang/{order}/ton-huy', 'disposeInventory')->whereNumber('order');
    });

    Route::middleware('can:admin')->group(function () {
        Route::get('/email-don', [AdminOrders::class, 'mail']);
        Route::controller(AdminCatalog::class)->group(function () {
            Route::get('/ton-kho', 'inventory');
            Route::get('/san-pham', 'index');
            Route::get('/san-pham/them', 'edit');
            Route::get('/san-pham/{product}', 'edit')->whereNumber('product');
            Route::post('/san-pham/{product?}', 'save')->whereNumber('product');
            Route::post('/danh-muc/{category?}', 'category')->whereNumber('category');
        });
        Route::controller(AdminConfig::class)->group(function () {
            Route::get('/cai-dat', 'settings');
            Route::post('/cai-dat', 'saveSettings');
            Route::post('/nhan-don', 'saveIntake');
            Route::get('/ma-giam/{coupon?}', 'coupons')->whereNumber('coupon');
            Route::post('/ma-giam/{coupon?}', 'saveCoupon')->whereNumber('coupon');
            Route::get('/nhan-su/{user?}', 'users')->whereNumber('user');
            Route::post('/nhan-su/{user?}', 'saveUser')->whereNumber('user');
        });
    });
});
