<?php

use App\Http\Controllers\Accounts;
use App\Http\Controllers\AdminBranches;
use App\Http\Controllers\AdminCatalog;
use App\Http\Controllers\AdminConfig;
use App\Http\Controllers\AdminDispatch;
use App\Http\Controllers\AdminOrders;
use App\Http\Controllers\AdminSchedule;
use App\Http\Controllers\BranchSelection;
use App\Http\Controllers\Locations;
use App\Http\Controllers\OrderChanges;
use App\Http\Controllers\OrderTransfers;
use App\Http\Controllers\PublicPages;
use App\Http\Controllers\Storefront;
use App\Http\Middleware\ResolveBranch;
use App\Http\Middleware\VerifyStaffFactor;
use App\Services\ResponsiveImages;
use Illuminate\Support\Facades\Route;

Route::get('/co-so', [Locations::class, 'index']);
Route::post('/co-so/goi-y', [Locations::class, 'recommend'])->middleware('throttle:checkout-quote');
Route::post('/co-so/du-kien', [BranchSelection::class, 'preview'])->middleware('throttle:checkout-quote')->block();
Route::post('/co-so/xac-nhan', [BranchSelection::class, 'confirm'])->middleware('throttle:checkout-place')->block();
Route::get('/co-so/{slug}', [Locations::class, 'show'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
Route::get('/xac-nhan-chuyen-don/{token}', [OrderTransfers::class, 'guestForm'])->where('token', '[a-f0-9]{64}')->middleware('throttle:order-actions');
Route::post('/xac-nhan-chuyen-don/{token}', [OrderTransfers::class, 'guestDecision'])->where('token', '[a-f0-9]{64}')->middleware('throttle:order-actions')->block();
Route::middleware(['auth', 'can:active-account'])->group(function () {
    Route::get('/tai-khoan/dia-chi', [Locations::class, 'addresses']);
    Route::post('/tai-khoan/dia-chi/{address?}', [Locations::class, 'saveAddress'])->whereNumber('address')->middleware('throttle:20,1');
    Route::patch('/tai-khoan/dia-chi/{address}', [Locations::class, 'saveAddress'])->whereNumber('address')->middleware('throttle:20,1');
    Route::delete('/tai-khoan/dia-chi/{address}', [Locations::class, 'deleteAddress'])->whereNumber('address');
    Route::get('/don-cua-toi/{code}/chuyen-co-so', [OrderTransfers::class, 'ownerForm']);
    Route::post('/don-cua-toi/{code}/chuyen-co-so', [OrderTransfers::class, 'ownerDecision'])->middleware('throttle:order-actions')->block();
});

Route::controller(Storefront::class)->group(function () {
    Route::get('/', 'home');
    Route::post('/co-so/chon', 'selectBranch')->block();
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
    Route::get('/giao-nhan', [AdminDispatch::class, 'index']);
    Route::get('/dieu-phoi', fn () => redirect('/quan-tri/giao-nhan'));
    Route::post('/giao-nhan/chuyen', [AdminDispatch::class, 'trip']);
    Route::post('/giao-nhan/don/{order}/tao', [AdminDispatch::class, 'ensure'])->whereNumber('order');
    Route::patch('/giao-nhan/cong-viec/{job}', [AdminDispatch::class, 'change'])->whereNumber('job');
    Route::post('/don-hang/{order}/chuyen-co-so/bao-gia', [OrderTransfers::class, 'preview'])->whereNumber('order');
    Route::post('/don-hang/{order}/chuyen-co-so', [OrderTransfers::class, 'propose'])->whereNumber('order');
    Route::post('/chuyen-co-so/{proposal}/thuc-hien', [OrderTransfers::class, 'execute'])->whereNumber('proposal');
    Route::post('/chuyen-co-so/{proposal}/huy', [OrderTransfers::class, 'cancel'])->whereNumber('proposal');
    Route::post('/chon-co-so', [ResolveBranch::class, 'selectAdmin'])->block();
    Route::get('/thuc-don-co-so', [AdminCatalog::class, 'branchCatalog']);
    Route::patch('/thuc-don-co-so/{variant}', [AdminCatalog::class, 'saveBranchVariant'])->whereNumber('variant');
    Route::get('/ton-kho', [AdminCatalog::class, 'inventory']);
    Route::get('/cai-dat', [AdminConfig::class, 'settings']);
    Route::post('/cai-dat', [AdminConfig::class, 'saveSettings']);
    Route::post('/nhan-don', [AdminConfig::class, 'saveIntake']);
    Route::get('/ma-giam/{coupon?}', [AdminConfig::class, 'coupons'])->whereNumber('coupon');
    Route::post('/ma-giam/{coupon?}', [AdminConfig::class, 'saveCoupon'])->whereNumber('coupon');
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
        Route::get('/co-so', [AdminBranches::class, 'index']);
        Route::post('/co-so/{branch?}', [AdminBranches::class, 'save'])->whereNumber('branch');
        Route::post('/phan-cong-co-so', [AdminBranches::class, 'membership']);
        Route::get('/email-don', [AdminOrders::class, 'mail']);
        Route::controller(AdminCatalog::class)->group(function () {
            Route::get('/san-pham', 'index');
            Route::get('/san-pham/them', 'edit');
            Route::get('/san-pham/{product}', 'edit')->whereNumber('product');
            Route::post('/san-pham/{product?}', 'save')->whereNumber('product');
            Route::post('/danh-muc/{category?}', 'category')->whereNumber('category');
        });
        Route::controller(AdminConfig::class)->group(function () {
            Route::get('/nhan-su/{user?}', 'users')->whereNumber('user');
            Route::post('/nhan-su/{user?}', 'saveUser')->whereNumber('user');
        });
    });
});
