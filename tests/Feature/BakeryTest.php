<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Checkout;
use App\Services\Store;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BakeryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(8, 0));
        config(['bakery.demo_seed' => true, 'inertia.testing.ensure_pages_exist' => false]);
        $this->seed();
    }

    public function test_home_catalog_search_prices_pagination_and_sold_out(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Home')->has('featured', 4));
        $this->get('/thuc-don')->assertInertia(fn (Assert $page) => $page->component('Catalog')->where('products.total', 9)->has('products.data', 8));
        $this->get('/thuc-don?page=2')->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
        $this->get('/thuc-don?category=banh-kem&max=200000&sort=price_asc')
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.slug', 'banh-kem-dau-mini'));
        $this->get('/thuc-don?q=Raspberry')->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
        $this->get('/banh/banh-kem-dau-mini')->assertInertia(fn (Assert $page) => $page->where('product.variants.0.stock', 0));
    }

    public function test_guest_checkout_recalculates_price_discounts_delivery_and_snapshots(): void
    {
        DB::table('orders')->delete();
        $variant = $this->variant();
        $fields = $this->cartAndFields($variant, '0', 2);
        $fields = array_replace($fields, [
            'fulfillment' => 'delivery', 'region' => 'quan-1', 'address' => 'Địa chỉ thử nghiệm',
            'coupon' => 'nhaminh10', 'total' => 1, 'subtotal' => 1, 'delivery_fee' => 999999,
            'discount' => 750000, 'unit_price' => 1, 'user_id' => 1, 'payment' => 'paid',
        ]);
        $response = $this->post('/dat-banh', $fields);
        $order = DB::table('orders')->first();
        $response->assertRedirect(route('tracking.show', $order->tracking_token));
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'user_id' => null, 'subtotal' => 750000, 'discount' => 75000,
            'delivery_fee' => 0, 'total' => 675000, 'payment' => 'unpaid',
        ]);
        $this->assertDatabaseHas('variants', ['id' => $variant->id, 'stock' => $variant->stock - 2]);
        DB::table('variants')->where('id', $variant->id)->update(['price' => 10, 'name' => 'Đã đổi']);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'variant_name' => $variant->name,
            'unit_price' => 360000, 'option_fee' => 15000, 'message' => '0', 'quantity' => 2,
        ]);
        $this->get('/tra-cuu/'.$order->tracking_token)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/tra-cuu/'.str_repeat('a', 64))->assertNotFound();
    }

    public function test_repeat_checkout_keeps_one_order_and_reserves_stock_once(): void
    {
        $variant = $this->variant();
        $fields = $this->cartAndFields($variant);
        $count = DB::table('orders')->count();

        $first = $this->post('/dat-banh', $fields);
        $this->post('/dat-banh', $fields)->assertRedirect($first->headers->get('Location'));
        $this->assertDatabaseCount('orders', $count + 1);
        $this->assertDatabaseHas('variants', ['id' => $variant->id, 'stock' => $variant->stock - 1]);

        $this->post('/dat-banh', array_replace($fields, ['key' => (string) Str::uuid()]))->assertSessionHasErrors('cart');
    }

    public function test_stock_and_schedule_are_revalidated_at_checkout(): void
    {
        $variant = $this->variant();
        $fields = $this->cartAndFields($variant);
        $count = DB::table('orders')->count();
        $this->post('/dat-banh', array_replace($fields, ['slot' => '2026-11-15 09:00']))->assertSessionHasErrors('slot');
        $settings = Store::settings();
        $settings['closed_dates'] = ['2026-11-15'];
        DB::table('store_settings')->update(['data' => json_encode($settings)]);
        $this->post('/dat-banh', $fields)->assertSessionHasErrors('slot');

        DB::table('variants')->where('id', $variant->id)->update(['stock' => 0]);
        $this->post('/dat-banh', $fields)->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', $count);
    }

    public function test_cancel_restores_stock_and_coupon_exactly_once(): void
    {
        DB::table('coupons')->where('code', 'NHAMINH10')->update(['usage_limit' => 1]);
        $variant = $this->variant();
        $fields = $this->cartAndFields($variant) + ['coupon' => 'NHAMINH10'];
        $this->post('/dat-banh', $fields)->assertRedirect();
        $order = DB::table('orders')->latest('id')->first();
        $this->assertDatabaseHas('coupons', ['code' => 'NHAMINH10', 'used' => 1]);

        $this->post('/dat-banh', $this->cartAndFields($variant) + ['coupon' => 'NHAMINH10'])->assertSessionHasErrors('coupon');
        $this->assertDatabaseHas('variants', ['id' => $variant->id, 'stock' => $variant->stock - 1]);

        $this->actingAs($this->account('nhanvien'));
        $route = '/quan-tri/don-hang/'.$order->id;
        $cancel = ['kind' => 'status', 'value' => 'cancelled'];
        $this->patch($route, $cancel)->assertSessionHasNoErrors();
        $this->patch($route, $cancel)->assertSessionHasErrors('value');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('variants', ['id' => $variant->id, 'stock' => $variant->stock]);
        $this->assertDatabaseHas('coupons', ['code' => 'NHAMINH10', 'used' => 0]);
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('value', 'cancelled')->count());
    }

    public function test_bank_snapshot_authorization_and_paid_fulfillment(): void
    {
        $fields = array_replace($this->cartAndFields($this->variant()), ['method' => 'bank', 'fulfillment' => 'delivery', 'region' => 'quan-1', 'address' => '24 Địa chỉ mẫu']);
        $receipt = $this->post('/dat-banh', $fields);
        $order = DB::table('orders')->latest('id')->first();
        $receipt->assertRedirect(route('tracking.show', $order->tracking_token));
        $this->get('/tra-cuu/'.$order->tracking_token)->assertInertia(fn (Assert $page) => $page
            ->where('order.bank_snapshot.demo', true)->where('order.total', 385000)->where('order.payment', 'unpaid'));
        $url = '/quan-tri/don-hang/'.$order->id;
        $pay = ['kind' => 'payment', 'value' => 'paid'];
        $this->actingAs($this->account('khach'))->patch($url, $pay)->assertForbidden();
        $this->actingAs($this->account('nhanvien'))->patch($url, $pay)->assertForbidden();
        $this->post('/quan-tri/cai-dat', [])->assertForbidden();
        $this->actingAs($this->account('doisoat'))->patch($url, $pay)->assertSessionHasNoErrors();
        $this->patch($url, $pay)->assertSessionHasErrors('value');
        foreach (['confirmed', 'preparing', 'shipping', 'completed'] as $status) {
            $this->patch($url, ['kind' => 'status', 'value' => $status])->assertSessionHasNoErrors();
        }
        $this->get('/tra-cuu/'.$order->tracking_token)->assertInertia(fn (Assert $page) => $page->where('order.status', 'completed')->where('order.payment', 'paid'));
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'actor_id' => $this->account('doisoat')->id, 'kind' => 'payment', 'value' => 'paid']);
    }

    public function test_owner_only_history_and_admin_export_filter(): void
    {
        $order = DB::table('orders')->where('code', 'NB-DEMO006')->first();
        $this->get('/don-cua-toi/'.$order->code)->assertRedirect('/dang-nhap');
        $this->actingAs(User::factory()->create())->get('/don-cua-toi/'.$order->code)->assertNotFound();
        $this->get('/don-cua-toi')->assertInertia(fn (Assert $page) => $page->where('orders.total', 0));
        $this->actingAs($this->account('khach'))->get('/don-cua-toi/'.$order->code)->assertOk();
        $this->actingAs($this->account('admin'));
        $this->get('/quan-tri?from=2026-11-15&to=2026-11-15')->assertInertia(fn (Assert $page) => $page
            ->where('total', 1)->where('working', 1)->where('revenue', 0));
        $export = $this->get('/quan-tri/don-hang/xuat?status=completed')->assertDownload('don-banh-2026-11-15.csv');
        $this->assertStringContainsString('NB-DEMO006', $export->streamedContent());
        $this->assertStringNotContainsString('NB-DEMO005', $export->streamedContent());
    }

    public function test_registration_never_assigns_staff_and_password_reset_uses_local_notification(): void
    {
        Notification::fake();
        $fields = ['email' => 'new@tiembanh.test', 'name' => 'Khách Mới', 'password' => 'new-password-safe', 'password_confirmation' => 'new-password-safe'];
        $this->post('/dang-ky', $fields + ['role' => 'admin', 'can_reconcile' => true])->assertRedirect('/');
        $user = User::where('email', $fields['email'])->firstOrFail();
        $this->assertSame('customer', $user->role);
        $this->post('/dang-xuat')->assertRedirect('/');
        $this->post('/quen-mat-khau', ['email' => $user->email])->assertSessionHas('notice');
        Notification::assertSentTo($user, ResetPassword::class, function ($mail) use ($user) {
            $this->get('/dat-lai-mat-khau/'.$mail->token.'?email='.$user->email)->assertOk();
            $this->post('/dat-lai-mat-khau', [
                'email' => $user->email, 'token' => $mail->token,
                'password' => 'changed-password-2026', 'password_confirmation' => 'changed-password-2026',
            ])->assertRedirect('/dang-nhap');

            return true;
        });
        $this->post('/dang-nhap', ['email' => $user->email, 'password' => 'changed-password-2026'])->assertRedirect('/');
    }

    public function test_admin_upload_validates_type_size_and_serves_image(): void
    {
        Storage::fake('public');
        $this->actingAs($this->account('admin'));
        $fields = [
            'name' => 'Bánh QA', 'category_id' => DB::table('categories')->where('slug', 'banh-kem')->value('id'),
            'description' => 'Bánh mới', 'prep_minutes' => 60, 'message_fee' => 0,
            'active' => true, 'featured' => false, 'customizable' => false,
            'variants' => [['name' => 'Bánh nhỏ', 'price' => 50000, 'stock' => 5, 'active' => true]],
        ];
        $this->post('/quan-tri/san-pham', $fields + ['uploads' => [UploadedFile::fake()->create('bad.svg', 5, 'image/svg+xml')]])->assertSessionHasErrors('uploads.0');
        $this->post('/quan-tri/san-pham', $fields + ['uploads' => [UploadedFile::fake()->image('large.jpg')->size(5000)]])->assertSessionHasErrors('uploads.0');
        $this->post('/quan-tri/san-pham', $fields + ['uploads' => [UploadedFile::fake()->image('valid.jpg')]])->assertSessionHasNoErrors();
        $uploaded = DB::table('products')->where('name', 'Bánh QA')->value('images');
        $this->get(json_decode($uploaded, true)[0])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_admin_can_save_shop_config_and_disable_online_bank(): void
    {
        Storage::fake('public');
        $fields = Store::settings();
        $fields['name'] = 'Tiệm Bánh QA';
        $fields['logo'] = UploadedFile::fake()->image('logo.png');
        $fields['remove_logo'] = false;
        $fields['slots'] = ['10:00', '17:00'];
        $fields['closed_dates'] = ['2026-11-16'];
        $fields['bank_name'] = $fields['bank_account'] = $fields['bank_holder'] = null;
        $fields['bank_demo'] = false;

        $this->actingAs($this->account('admin'))->post('/quan-tri/cai-dat', $fields)->assertSessionHasNoErrors();
        $stored = Store::settings();
        $this->assertSame(['10:00', '17:00'], $stored['slots']);
        $this->assertSame(['2026-11-16'], $stored['closed_dates']);
        $this->assertNull(Store::bank($stored));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('shop.name', 'Tiệm Bánh QA')->where('shop.logo', $stored['logo']));
        $this->get($stored['logo'])->assertOk();
        $fields['bank_name'] = 'Chỉ tên ngân hàng';
        $this->post('/quan-tri/cai-dat', $fields)->assertSessionHasErrors(['bank_holder', 'bank_account']);
    }

    private function variant(): object
    {
        $productId = DB::table('products')->where('slug', 'banh-kem-dau')->value('id');

        return DB::table('variants')->where('product_id', $productId)->orderBy('id')->first();
    }

    private function account(string $prefix): User
    {
        return User::where('email', $prefix.'@tiembanh.test')->firstOrFail();
    }

    private function cartAndFields(object $variant, ?string $message = null, int $quantity = 1): array
    {
        $this->post('/gio-hang', compact('message', 'quantity') + ['variant_id' => $variant->id])
            ->assertSessionHasNoErrors();
        $this->get('/dat-banh')->assertOk();
        $lines = app(Checkout::class)->cart(session('cart'));
        $slot = app(Checkout::class)->slots(Store::settings(), max(array_column($lines, 'prep_minutes')))[0];

        return [
            'key' => session('checkout_key'), 'name' => 'Khách Đặt Bánh', 'email' => 'test@example.test', 'phone' => '0900123456',
            'fulfillment' => 'pickup', 'slot' => $slot, 'method' => 'cod',
        ];
    }
}
