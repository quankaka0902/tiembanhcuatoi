<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Checkout;
use App\Services\Store;
use App\Services\Totp;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class BakeryTest extends TestCase
{
    use RefreshDatabase;

    private const FACTOR_FIXTURE = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(8, 0));
        config(['bakery.demo_seed' => true, 'inertia.testing.ensure_pages_exist' => false]);
        $this->seed();
        User::whereIn('role', ['admin', 'staff'])->each(fn (User $user) => $user->forceFill(['two_factor_secret' => self::FACTOR_FIXTURE])->save());
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->withSession(['staff_mfa_verified' => $user instanceof User ? $user->staffProof() : null]);

        return parent::actingAs($user, $guard);
    }

    public function test_staff_factor_requires_password_and_fresh_unused_code(): void
    {
        $this->travelTo(now()->setTimestamp(59));
        $user = $this->account('admin');
        $this->assertSame(1, Totp::matchingStep(self::FACTOR_FIXTURE, '287082', 59));
        $this->assertNotSame(self::FACTOR_FIXTURE, $user->getRawOriginal('two_factor_secret'));
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $credentials = ['email' => $user->email, 'password' => 'BanhDemo2026!'];
        $this->post('/xac-thuc-hai-buoc', ['code' => '287082'])->assertRedirect('/dang-nhap');
        $this->post('/dang-nhap', $credentials)->assertRedirect('/xac-thuc-hai-buoc');
        $this->assertGuest();
        $this->get('/quan-tri')->assertRedirect('/dang-nhap');
        $this->get('/xac-thuc-hai-buoc')->assertOk();
        $this->post('/xac-thuc-hai-buoc', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->post('/xac-thuc-hai-buoc', ['code' => '287082'])->assertRedirect('/quan-tri');
        $this->get('/quan-tri')->assertOk();
        $this->post('/dang-xuat')->assertRedirect('/');
        $this->post('/dang-nhap', $credentials)->assertRedirect('/xac-thuc-hai-buoc');
        $this->post('/xac-thuc-hai-buoc', ['code' => '287082'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->travel(6)->minutes();
        $this->post('/xac-thuc-hai-buoc', ['code' => '287082'])->assertRedirect('/dang-nhap');
        $this->artisan('bakery:mfa', ['email' => $user->email])->assertSuccessful();
        $this->assertNotSame($user->staffProof(), $user->fresh()->staffProof());
        $this->be($user->fresh())->withSession(['staff_mfa_verified' => $user->staffProof()])->get('/quan-tri')->assertRedirect('/dang-nhap');
    }

    public function test_launch_requires_explicit_open_and_complete_production_data(): void
    {
        config([
            'bakery.rehearsal' => true, 'bakery.demo_seed' => false, 'app.debug' => false,
            'app.url' => 'https://localhost:18847', 'session.secure' => true, 'session.encrypt' => true,
            'mail.default' => 'smtp', 'mail.from.address' => 'qa@example.test', 'mail.mailers.smtp.host' => '127.0.0.1',
        ]);
        $this->assertFalse(Store::settings()['accepting_orders']);
        $this->get('/dat-banh')->assertRedirect('/gio-hang');
        $admin = $this->account('admin');
        $this->actingAs($admin)->post('/quan-tri/nhan-don', ['accepting_orders' => true])->assertSessionHasErrors('accepting_orders');
        User::where('email', 'like', '%@tiembanh.test')->each(fn (User $user) => $user->forceFill(['email' => 'qa-'.$user->email])->save());
        $settings = array_replace(Store::settings(), [
            'bank_name' => '', 'bank_account' => '', 'bank_holder' => '', 'bank_demo' => false,
            'order_policy' => 'Chính sách nghiệm thu.', 'privacy_notice' => 'Dữ liệu dùng để xử lý đơn.',
        ]);
        DB::table('store_settings')->where('id', 1)->update(['data' => json_encode($settings)]);
        $this->assertFalse(Store::settings()['accepting_orders']);
        $this->actingAs($admin->fresh())->post('/quan-tri/nhan-don', ['accepting_orders' => true])->assertSessionHasNoErrors();
        $this->assertTrue(Store::settings()['accepting_orders']);
    }

    public function test_reschedule_checks_capacity_and_refund_remains_in_inbox_until_recorded(): void
    {
        $variant = $this->variant();
        $customer = $this->account('khach');
        $fields = $this->actingAs($customer)->cartAndFields($variant, 'Lời chúc riêng', overrides: [
            'method' => 'bank', 'fulfillment' => 'delivery', 'region' => 'quan-1', 'address' => 'Địa chỉ riêng',
        ]);
        $this->post('/dat-banh', $fields)->assertSessionHasNoErrors();
        $order = DB::table('orders')->latest('id')->first();
        $settings = array_replace(Store::settings(), ['slot_limits' => ['11:00' => 1, '16:00' => 1, '18:00' => 1]]);
        DB::table('store_settings')->where('id', 1)->update(['data' => json_encode($settings)]);
        DB::table('orders')->where('code', 'NB-DEMO002')->update(['scheduled_at' => '2026-11-15 16:00:00']);
        $url = '/quan-tri/don-hang/'.$order->id;
        $change = ['previous_slot' => $fields['slot'], 'slot' => '2026-11-15 16:00', 'note' => 'Khách cần dời giờ'];
        $this->patch($url.'/doi-gio', $change)->assertForbidden();
        $this->actingAs($this->account('nhanvien'))->from('/quan-tri/viec-can-xu-ly')
            ->patch($url.'/doi-gio', $change)->assertRedirect($url)->assertSessionHasErrors('slot');
        $change['slot'] = '2026-11-15 18:00';
        $this->patch($url.'/doi-gio', $change)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->patch($url.'/doi-gio', $change)->assertSessionHasErrors('slot');
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'scheduled_at' => '2026-11-15 18:00:00', 'bank_due_at' => $order->bank_due_at, 'total' => $order->total,
        ]);
        $this->assertDatabaseHas('variants', ['id' => $variant->id, 'stock' => $variant->stock - 1]);
        $this->assertContains($fields['slot'], app(Checkout::class)->availableSlots(Store::settings(), 120));
        $this->get('/tra-cuu/'.$order->tracking_token)->assertInertia(fn (Assert $page) => $page
            ->where('order.scheduled_at', '2026-11-15 18:00:00')->missing('order.address')
            ->missing('order.items.0.message')->missing('order.events.1.note')->where('order.events.1.kind', 'schedule'));
        $this->actingAs($customer)->get('/don-cua-toi/'.$order->code)->assertInertia(fn (Assert $page) => $page
            ->where('order.address', 'Địa chỉ riêng')->where('order.items.0.message', 'Lời chúc riêng'));

        $this->actingAs($this->account('doisoat'))->patch($url, ['kind' => 'payment', 'value' => 'paid'])->assertSessionHasNoErrors();
        $this->patch($url, ['kind' => 'status', 'value' => 'cancelled'])->assertSessionHasNoErrors();
        $inbox = '/quan-tri/viec-can-xu-ly?filter=refund';
        $this->get($inbox)->assertInertia(fn (Assert $page) => $page->where('refundCount', 1)->where('orders.data.0.code', $order->code));
        $this->actingAs($this->account('nhanvien'))->patch($url, ['kind' => 'payment', 'value' => 'refunded'])->assertForbidden();
        $this->actingAs($this->account('doisoat'))->patch($url, ['kind' => 'payment', 'value' => 'refunded'])->assertSessionHasNoErrors();
        $this->get($inbox)->assertInertia(fn (Assert $page) => $page->where('refundCount', 0));
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
        $fields = $this->cartAndFields($variant, '0', 2, [
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
        $this->assertDatabaseCount('jobs', 1);

        $this->post('/dat-banh', array_replace($fields, ['key' => (string) Str::uuid()]))->assertSessionHasErrors('cart');
    }

    public function test_checkout_requires_a_fresh_quote_when_prices_or_fees_change(): void
    {
        $variant = $this->variant();
        $settings = array_replace(Store::settings(), [
            'free_from' => null,
            'regions' => [['key' => 'quan-1', 'name' => 'Khu vực thử', 'fee' => 25000]],
        ]);
        DB::table('store_settings')->update(['data' => json_encode($settings)]);
        $fields = $this->cartAndFields($variant, overrides: [
            'fulfillment' => 'delivery', 'region' => 'quan-1', 'address' => 'Địa chỉ thử',
            'coupon' => 'NHAMINH10',
        ]);
        $count = DB::table('orders')->count();
        $queued = DB::table('jobs')->count();

        DB::table('variants')->where('id', $variant->id)->increment('price', 10000);
        $settings['regions'][0]['fee'] -= 9000;
        DB::table('store_settings')->update(['data' => json_encode($settings)]);
        $this->from('/')->post('/dat-banh', $fields)->assertRedirect('/dat-banh')->assertSessionHasErrors('quote_token');
        $this->post('/dat-banh', array_diff_key($fields, ['quote_token' => true]))->assertSessionHasErrors('quote_token');
        $this->assertDatabaseCount('orders', $count);
        $this->assertDatabaseCount('jobs', $queued);
        $this->assertDatabaseHas('variants', ['id' => $variant->id, 'stock' => $variant->stock]);
        $this->assertDatabaseHas('coupons', ['code' => 'NHAMINH10', 'used' => 0]);
        $this->assertNotEmpty(session('cart'));

        $preview = $this->postJson('/dat-banh/bao-gia', ['fulfillment' => 'delivery', 'region' => 'quan-1', 'coupon' => 'NHAMINH10'])
            ->assertOk()->assertJsonPath('delivery_fee', 16000)->assertJsonPath('total', 349000);
        $this->assertSame([370000], array_values($preview->json('line_totals')));
        $fields['quote_token'] = $preview->json('quote_token');
        $receipt = $this->post('/dat-banh', $fields)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['total' => 349000, 'subtotal' => 370000, 'delivery_fee' => 16000]);

        DB::table('variants')->where('id', $variant->id)->increment('price', 5000);
        $this->post('/dat-banh', $fields)->assertRedirect($receipt->headers->get('Location'));
        $this->assertDatabaseCount('orders', $count + 1);
        $this->assertDatabaseCount('jobs', $queued + 1);
    }

    public function test_checkout_quotes_do_not_consume_the_password_recovery_limit(): void
    {
        Notification::fake();
        $this->cartAndFields($this->variant());
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/dat-banh/bao-gia', ['fulfillment' => 'pickup'])->assertOk();
        }

        $user = $this->account('khach');
        $this->post('/quen-mat-khau', ['email' => $user->email])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_intake_pause_rejects_a_stale_checkout_and_preserves_existing_orders_and_cart(): void
    {
        $variant = $this->variant();
        $first = $this->cartAndFields($variant);
        $accepted = $this->post('/dat-banh', $first)->assertSessionHasNoErrors()->headers->get('Location');
        $count = DB::table('orders')->count();
        $stale = $this->cartAndFields($variant);
        $cart = session('cart');
        $pause = ['accepting_orders' => false, 'pause_message' => 'Tiệm đang bảo trì lò.'];
        $inbox = '/quan-tri/viec-can-xu-ly';
        $this->actingAs($this->account('nhanvien'))->post('/quan-tri/nhan-don', $pause)->assertForbidden();
        $this->actingAs($this->account('admin'))->from($inbox)->post('/quan-tri/nhan-don', $pause)->assertRedirect($inbox);
        $this->get($inbox)->assertInertia(fn (Assert $page) => $page->where('intake.accepting_orders', false));
        $this->actingAs($this->account('khach'))->get('/dat-banh')->assertRedirect('/gio-hang');
        $this->get('/gio-hang')->assertInertia(fn (Assert $page) => $page
            ->where('shop.accepting_orders', false)->where('shop.pause_message', $pause['pause_message'])->has('lines', 1));
        $this->postJson('/dat-banh/bao-gia', ['fulfillment' => 'pickup'])->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->post('/dat-banh', $stale)->assertSessionHasErrors(['cart' => $pause['pause_message']]);
        $this->assertSame($cart, session('cart'));
        $this->assertDatabaseCount('orders', $count);
        $this->assertDatabaseHas('variants', ['id' => $variant->id, 'stock' => $variant->stock - 1]);

        $this->withSession(['cart' => [], 'checkout_key' => $first['key']])->post('/dat-banh', $first)->assertRedirect($accepted);
        $this->assertDatabaseCount('orders', $count);
        $this->assertDatabaseCount('jobs', 1);
        $this->get($accepted)->assertOk();
        $orderId = DB::table('orders')->where('code', 'NB-DEMO001')->value('id');
        $this->actingAs($this->account('nhanvien'))->patch('/quan-tri/don-hang/'.$orderId, ['kind' => 'status', 'value' => 'confirmed'])->assertSessionHasNoErrors();
        $this->actingAs($this->account('admin'))->post('/quan-tri/nhan-don', ['accepting_orders' => true])->assertSessionHasNoErrors();
        $this->withSession(['cart' => $cart, 'checkout_key' => $stale['key']])->post('/dat-banh', $stale)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', $count + 1);
    }

    public function test_receipt_waits_for_worker_and_admin_can_see_delayed_and_failed_mail(): void
    {
        $fields = $this->cartAndFields($this->variant());
        $this->post('/dat-banh', $fields)->assertSessionHasNoErrors();
        $order = DB::table('orders')->latest('id')->first();
        $this->get(route('tracking.show', $order->tracking_token))->assertOk();
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(0, $messages);
        $this->assertDatabaseCount('jobs', 1);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ]);
        $failureId = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $failureId, 'connection' => 'order-mail', 'queue' => 'order-mail',
            'payload' => 'payload test', 'exception' => 'personal data test', 'failed_at' => now(),
        ]);
        $this->travel(5)->minutes();
        $url = '/quan-tri/email-don';
        $this->actingAs($this->account('nhanvien'))->get($url)->assertForbidden();
        $this->actingAs($this->account('admin'))->get($url)->assertInertia(fn (Assert $page) => $page
            ->component('AdminMail')->where('queued', 1)->where('delayed', 1)
            ->where('failures.total', 1)->where('failures.data.0.uuid', $failureId)
            ->missing('failures.data.0.exception')->missing('failures.data.0.payload'));
        $this->runOrderMailWorker();
        $mail = $messages->sole()->getOriginalMessage();
        $this->assertSame($fields['email'], $mail->getTo()[0]->getAddress());
        $this->assertSame('Đã nhận đơn '.$order->code, $mail->getSubject());
        $this->assertStringContainsString(route('tracking.show', $order->tracking_token), $mail->getTextBody());
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('queued', 0)->where('delayed', 0));
    }

    public function test_work_inbox_shows_pending_and_overdue_without_order_contact_data(): void
    {
        $url = '/quan-tri/viec-can-xu-ly';
        $this->get($url)->assertRedirect('/dang-nhap');
        $this->actingAs($this->account('khach'))->get($url)->assertForbidden();
        $staff = $this->account('nhanvien');
        $this->actingAs($staff)->get($url)->assertInertia(fn (Assert $page) => $page
            ->component('AdminInbox')->where('newCount', 1)->where('overdueCount', 0)
            ->where('orders.data.0.code', 'NB-DEMO001')->missing('orders.data.0.email'));
        $this->travel(1)->hour();
        $this->get($url.'?filter=overdue')->assertInertia(fn (Assert $page) => $page
            ->where('overdueCount', 1)->where('orders.total', 1)->where('orders.data.0.code', 'NB-DEMO001'));
        $pending = DB::table('orders')->where('code', 'NB-DEMO001')->value('id');
        $this->patch('/quan-tri/don-hang/'.$pending, ['kind' => 'status', 'value' => 'confirmed'])->assertSessionHasNoErrors();
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('newCount', 0)->where('overdueCount', 1));
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

    public function test_pickup_and_delivery_share_a_slot_limit_and_cancellation_reopens_it(): void
    {
        $settings = Store::settings();
        $settings['slot_limits'] = ['11:00' => 1];
        DB::table('store_settings')->update(['data' => json_encode($settings)]);

        $variant = $this->variant();
        $pickup = $this->cartAndFields($variant);
        $first = $this->post('/dat-banh', $pickup)->assertSessionHasNoErrors();
        $order = DB::table('orders')->latest('id')->first();
        $this->assertSame('2026-11-15 11:00:00', $order->scheduled_at);
        $this->post('/dat-banh', $pickup)->assertRedirect($first->headers->get('Location'));

        $delivery = $this->cartAndFields($variant, overrides: [
            'fulfillment' => 'delivery', 'region' => 'quan-1', 'address' => 'Địa chỉ giao thử nghiệm',
        ]);
        $this->get('/dat-banh')->assertInertia(fn (Assert $page) => $page->where('slots', fn ($slots) => ! $slots->contains($pickup['slot'])
            && $slots->contains('2026-11-15 14:00') && $slots->contains('2026-11-16 11:00')));
        $this->from('/dat-banh')->post('/dat-banh', $delivery)->assertRedirect('/dat-banh')->assertSessionHasErrors('slot');
        $this->assertDatabaseHas('variants', ['id' => $variant->id, 'stock' => $variant->stock - 1]);

        $this->actingAs($this->account('nhanvien'))->patch('/quan-tri/don-hang/'.$order->id, ['kind' => 'status', 'value' => 'cancelled'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->account('khach'))->get('/dat-banh')->assertInertia(fn (Assert $page) => $page
            ->where('slots', fn ($slots) => $slots->contains($pickup['slot'])));
        $this->post('/dat-banh', $delivery)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('orders')->where('scheduled_at', $order->scheduled_at)->where('status', '!=', 'cancelled')->count());
        $this->assertDatabaseHas('orders', ['scheduled_at' => $order->scheduled_at, 'fulfillment' => 'delivery']);
    }

    public function test_staff_schedule_uses_fulfillment_date_and_keeps_order_details(): void
    {
        $variant = $this->variant();
        foreach ([['Chúc mừng sinh nhật', 2], ['Mừng tốt nghiệp', 1]] as [$message, $quantity]) {
            $fields = $this->cartAndFields($variant, $message, $quantity);
            $this->post('/dat-banh', $fields)->assertSessionHasNoErrors();
        }
        $secondOrder = DB::table('orders')->where('request_key', hash('sha256', $fields['key']))->first();
        DB::table('order_items')->where('order_id', $secondOrder->id)->update(['note' => 'Gói hộp riêng']);
        DB::table('orders')->where('code', 'NB-DEMO007')->update(['scheduled_at' => '2026-11-15 11:00:00']);
        DB::table('variants')->where('id', $variant->id)->update(['name' => 'Đã đổi tên']);
        $settings = Store::settings();
        $settings['closed_dates'] = ['2026-11-15'];
        $settings['slot_limits'] = ['11:00' => 2];
        DB::table('store_settings')->update(['data' => json_encode($settings)]);

        $url = '/quan-tri/lich-lam-banh';
        $this->get($url)->assertRedirect('/dang-nhap');
        $this->actingAs($this->account('khach'))->get($url)->assertForbidden();
        $this->actingAs($this->account('nhanvien'))->get($url)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('AdminSchedule')
                ->where('date', '2026-11-15')->where('tomorrow', '2026-11-16')
                ->where('orderCount', 3)->where('cakeCount', 4)->has('schedule', 2)
                ->where('schedule.0.time', '11:00')->where('schedule.0.limit', 2)
                ->has('schedule.0.products', 1)->where('schedule.0.products.0.quantity', 3)
                ->where('schedule.0.products.0.variant_name', $variant->name)
                ->has('schedule.0.orders', 2)->where('schedule.0.orders.0.items.0.message', 'Chúc mừng sinh nhật')
                ->where('schedule.0.orders.1.items.0.note', 'Gói hộp riêng')
                ->missing('schedule.0.orders.0.phone')->where('schedule.1.orders.0.code', 'NB-DEMO002'));
        $this->get($url.'?date=2026-11-16')->assertInertia(fn (Assert $page) => $page
            ->where('date', '2026-11-16')->where('orderCount', 1));
    }

    public function test_cancel_restores_stock_and_coupon_exactly_once(): void
    {
        DB::table('coupons')->where('code', 'NHAMINH10')->update(['usage_limit' => 1]);
        $variant = $this->variant();
        $fields = $this->cartAndFields($variant, overrides: ['coupon' => 'NHAMINH10']);
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
        $fields = $this->cartAndFields($this->variant(), overrides: ['method' => 'bank', 'fulfillment' => 'delivery', 'region' => 'quan-1', 'address' => '24 Địa chỉ mẫu']);
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
        $this->assertDatabaseCount('jobs', 3);
    }

    public function test_bank_deadlines_filter_and_allow_late_reconciliation(): void
    {
        $variant = $this->variant();
        $createBankOrder = function () use ($variant) {
            $fields = array_replace($this->cartAndFields($variant), ['method' => 'bank']);
            $this->post('/dat-banh', $fields)->assertSessionHasNoErrors();

            return DB::table('orders')->latest('id')->first();
        };
        $first = $createBankOrder();
        $this->assertSame('2026-11-15 09:00:00', $first->bank_due_at);
        $this->get('/tra-cuu/'.$first->tracking_token)->assertInertia(fn (Assert $page) => $page->where('order.bank_due_at', $first->bank_due_at));

        $settings = array_replace(Store::settings(), ['bank_due_minutes' => 240]);
        DB::table('store_settings')->where('id', 1)->update(['data' => json_encode($settings, JSON_THROW_ON_ERROR)]);
        $second = $createBankOrder();
        $this->assertSame($second->scheduled_at, $second->bank_due_at);
        $this->assertSame($first->bank_due_at, DB::table('orders')->where('id', $first->id)->value('bank_due_at'));

        DB::table('orders')->where('code', 'NB-DEMO001')->update(['status' => 'cancelled']);
        $this->travel(1)->hour();
        $queue = '/quan-tri/doi-soat';
        $this->get($queue)->assertRedirect('/dang-nhap');
        $this->actingAs($this->account('khach'))->get($queue)->assertForbidden();
        $this->actingAs($this->account('nhanvien'))->get($queue)->assertInertia(fn (Assert $page) => $page
            ->component('AdminReconciliation')->where('orders.total', 2)
            ->where('orders.data.0.code', $first->code)->where('orders.data.0.overdue', true)
            ->where('orders.data.1.code', $second->code)->where('orders.data.1.overdue', false));
        $overdue = $queue.'?filter=overdue';
        $this->get($overdue)->assertInertia(fn (Assert $page) => $page
            ->where('filter', 'overdue')->where('orders.total', 1)->where('orders.data.0.code', $first->code));
        $payment = ['kind' => 'payment', 'value' => 'paid'];
        $order = '/quan-tri/don-hang/'.$first->id;
        $this->patch($order, $payment)->assertForbidden();
        $this->actingAs($this->account('doisoat'))->patch($order, $payment)->assertSessionHasNoErrors();
        $this->get($overdue)->assertInertia(fn (Assert $page) => $page->where('orders.total', 0));
    }

    public function test_status_email_is_queued_transactionally_and_retried_after_mail_failure(): void
    {
        $order = DB::table('orders')->where('code', 'NB-DEMO001')->first();
        $this->actingAs($this->account('nhanvien'));
        $url = '/quan-tri/don-hang/'.$order->id;
        $change = ['kind' => 'status', 'value' => 'confirmed'];
        DB::beginTransaction();
        try {
            $this->patch($url, $change)->assertSessionHasNoErrors();
            $this->assertDatabaseCount('jobs', 1);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseCount('jobs', 0);

        $this->patch($url, $change)->assertSessionHasNoErrors();
        $this->patch($url, $change)->assertSessionHasErrors('value');
        $this->assertDatabaseCount('jobs', 1);
        Exceptions::fake();
        Event::listen(MessageSending::class, fn () => throw new RuntimeException('Lỗi mail mẫu'));
        $this->runOrderMailWorker();
        $this->assertDatabaseHas('jobs', ['queue' => 'order-mail', 'attempts' => 1]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'confirmed']);
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(0, $messages);

        Event::forget(MessageSending::class);
        $this->travel(31)->seconds();
        $this->runOrderMailWorker();
        $this->assertDatabaseCount('jobs', 0);
        $mail = $messages->sole()->getOriginalMessage();
        $this->assertSame($order->email, $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('Đã xác nhận', $mail->getTextBody());
        $this->assertStringContainsString(route('tracking.show', $order->tracking_token), $mail->getTextBody());

        $this->patch($url, ['kind' => 'status', 'value' => 'preparing'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('jobs', 0);
        $this->patch($url, ['kind' => 'status', 'value' => 'ready'])->assertSessionHasNoErrors();
        $this->runOrderMailWorker();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('Sẵn sàng nhận', $messages->last()->getOriginalMessage()->getTextBody());
    }

    public function test_resend_tracking_is_neutral_rate_limited_and_uses_stored_recipient(): void
    {
        $order = DB::table('orders')->where('code', 'NB-DEMO001')->first();
        $route = '/tra-cuu/gui-lai';
        $notice = 'Nếu mã đơn và email trùng khớp, tiệm sẽ gửi liên kết tra cứu đến email đã đặt đơn.';
        foreach ([
            [$order->code, 'different@example.test'],
            ['NB-NOORDR', $order->email],
            [strtolower($order->code), strtoupper($order->email)],
            [$order->code, 'wrong@example.test'],
        ] as [$code, $email]) {
            $this->post($route, compact('code', 'email'))->assertRedirect('/tra-cuu')->assertSessionHas('notice', $notice);
        }
        $this->assertDatabaseCount('jobs', 1);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->post($route, ['code' => $order->code, 'email' => $order->email])->assertTooManyRequests();
        $this->assertDatabaseCount('jobs', 1);

        $this->runOrderMailWorker();
        $this->assertDatabaseCount('jobs', 0);
        $mail = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame($order->email, $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString(route('tracking.show', $order->tracking_token), $mail->getTextBody());
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

    public function test_reorder_requires_ownership_and_adds_only_available_stock_at_current_price(): void
    {
        $order = DB::table('orders')->where('code', 'NB-DEMO001')->first();
        $url = '/don-cua-toi/'.$order->code;
        $this->post($url.'/mua-lai')->assertRedirect('/dang-nhap');
        $this->actingAs(User::factory()->create())->post($url.'/mua-lai')->assertNotFound();
        $this->get('/tra-cuu/'.$order->tracking_token)->assertInertia(fn (Assert $page) => $page->where('canReorder', false));

        $variant = $this->variant();
        $unavailable = DB::table('variants')->where('product_id', $variant->product_id)->where('id', '<>', $variant->id)->first();
        DB::table('variants')->where('id', $variant->id)->update(['stock' => 3, 'price' => 415000]);
        DB::table('variants')->where('id', $unavailable->id)->update(['active' => false]);
        DB::table('order_items')->where('order_id', $order->id)->update(['quantity' => 3, 'message' => 'Lời chúc đơn cũ']);
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'variant_id' => $unavailable->id, 'quantity' => 1,
            'product_name' => 'Bánh Kem Dâu', 'variant_name' => $unavailable->name, 'unit_price' => 490000,
        ]);

        $this->actingAs($this->account('khach'))->get($url)->assertInertia(fn (Assert $page) => $page->where('canReorder', true));
        $this->post('/gio-hang', ['variant_id' => $variant->id, 'quantity' => 1, 'message' => 'Lời chúc trong giỏ'])->assertSessionHasNoErrors();
        $key = session('checkout_key');
        $this->post($url.'/mua-lai')->assertRedirect('/gio-hang')
            ->assertSessionHas('notice', fn ($notice) => str_starts_with($notice, 'Đã thêm 2 bánh vào giỏ') && str_contains($notice, '2 bánh chưa thể thêm'));
        $this->assertNotSame($key, session('checkout_key'));
        $this->get('/gio-hang')->assertInertia(fn (Assert $page) => $page->has('lines', 2)
            ->where('lines.0.quantity', 1)->where('lines.0.message', 'Lời chúc trong giỏ')->where('lines.0.available', true)
            ->where('lines.1.quantity', 2)->where('lines.1.variant_id', $variant->id)->where('lines.1.unit_price', 415000)
            ->where('lines.1.message', null)->where('lines.1.option_fee', 0)->where('lines.1.available', true));
        $key = session('checkout_key');
        $this->from($url)->post($url.'/mua-lai')->assertRedirect($url)->assertSessionHas('notice');
        $this->assertSame($key, session('checkout_key'));
        $this->assertDatabaseHas('variants', ['id' => $variant->id, 'stock' => 3]);
    }

    public function test_inventory_tracks_order_cancellation_and_manual_change_without_overwriting_stock_from_stale_forms(): void
    {
        $variant = $this->variant();
        $product = DB::table('products')->find($variant->product_id);
        $checkout = $this->cartAndFields($variant, quantity: 2);
        $this->post('/gio-hang', ['variant_id' => $variant->id, 'quantity' => 1, 'note' => 'Gói riêng'])->assertSessionHasNoErrors();
        $checkout = $this->confirmQuote(array_replace($checkout, ['key' => session('checkout_key')]));
        $this->post('/dat-banh', $checkout)->assertSessionHasNoErrors();
        $this->post('/dat-banh', $checkout)->assertSessionHasNoErrors();
        $order = DB::table('orders')->latest('id')->first();
        $balance = $variant->stock - 3;
        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $variant->id, 'order_id' => $order->id, 'reason' => 'order',
            'stock_before' => $variant->stock, 'stock_after' => $balance,
        ]);
        $this->assertSame(1, DB::table('stock_movements')->where('variant_id', $variant->id)->count());
        $this->actingAs($this->account('nhanvien'))->get('/quan-tri/ton-kho')->assertForbidden();

        $edit = '/quan-tri/san-pham/'.$product->id;
        $admin = $this->account('admin');
        $this->actingAs($admin);
        $fields = (array) $product + ['variants' => []];
        $fields['variants'][] = array_intersect_key((array) $variant, array_flip(['id', 'name', 'price', 'stock', 'active']))
            + ['expected_stock' => $variant->stock];
        $this->post($edit, $fields)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('variants', ['id' => $variant->id, 'stock' => $balance]);
        $fields['variants'][0]['stock'] = $balance + 5;
        $fields['variants'][0]['stock_note'] = 'Kiểm kê cuối ca';
        $this->post($edit, $fields)->assertSessionHasErrors('variants.0.stock');
        $this->assertSame(1, DB::table('stock_movements')->where('variant_id', $variant->id)->count());
        $fields['variants'][0]['expected_stock'] = $balance;
        $this->post($edit, $fields)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $variant->id, 'actor_id' => $admin->id, 'reason' => 'manual',
            'stock_before' => $balance, 'stock_after' => $balance + 5, 'note' => 'Kiểm kê cuối ca',
        ]);

        $this->actingAs($this->account('nhanvien'));
        $cancel = ['kind' => 'status', 'value' => 'cancelled'];
        $this->patch('/quan-tri/don-hang/'.$order->id, $cancel)->assertSessionHasNoErrors();
        $this->patch('/quan-tri/don-hang/'.$order->id, $cancel)->assertSessionHasErrors('value');
        $this->assertDatabaseHas('variants', ['id' => $variant->id, 'stock' => $variant->stock + 5]);
        $this->assertSame(3, DB::table('stock_movements')->where('variant_id', $variant->id)->count());
        $this->actingAs($admin)->get('/quan-tri/ton-kho?reason=cancel&product='.$product->id)
            ->assertInertia(fn (Assert $page) => $page->component('AdminInventory')->where('movements.total', 1)
                ->where('movements.data.0.order_code', $order->code)->where('movements.data.0.stock_before', $balance + 5)
                ->where('movements.data.0.stock_after', $variant->stock + 5)->where('movements.data.0.actor', $this->account('nhanvien')->name));
    }

    public function test_sales_report_uses_completion_date_and_aggregates_cakes_across_variants(): void
    {
        $order = DB::table('orders')->where('code', 'NB-DEMO001')->first();
        $variant = $this->variant();
        $secondSize = DB::table('variants')->where('product_id', $variant->product_id)->where('id', '<>', $variant->id)->value('id');
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'variant_id' => $secondSize, 'quantity' => 4,
            'product_name' => 'Tên bánh tại lúc đặt', 'variant_name' => 'Phiên bản cũ', 'unit_price' => 490000,
        ]);
        foreach ([
            ['NB-DEMO001', 'completed', 'paid', '2026-11-15 00:00:00', 345000],
            ['NB-DEMO002', 'shipping', 'paid', '2026-11-15 12:00:00', 90000],
            ['NB-DEMO003', 'completed', 'unpaid', '2026-11-15 12:00:00', 100000],
            ['NB-DEMO004', 'completed', 'paid', '2026-11-16 00:00:00', 390000],
            ['NB-DEMO005', 'completed', 'paid', '2026-11-15 23:59:59', 125000],
        ] as [$code, $status, $payment, $date, $total]) {
            DB::table('orders')->where('code', $code)->update([
                'status' => $status, 'payment' => $payment, 'completed_at' => $date, 'total' => $total, 'created_at' => '2026-11-14 11:00:00',
            ]);
        }

        $url = '/quan-tri/bao-cao';
        $this->get($url)->assertRedirect('/dang-nhap');
        $this->actingAs($this->account('khach'))->get($url)->assertForbidden();
        $this->actingAs($this->account('nhanvien'))->get($url.'?from=2026-11-15&to=2026-11-15')
            ->assertInertia(fn (Assert $page) => $page->component('AdminReports')
                ->where('totals.revenue', 470000)->where('totals.order_count', 2)
                ->where('days.total', 1)->where('days.data.0.day', '2026-11-15')->where('days.data.0.revenue', 470000)
                ->has('top', 2)->where('top.0.id', $variant->product_id)->where('top.0.quantity', 5)->where('top.0.order_count', 1));
        $this->get($url.'?from=2026-11-18&to=2026-11-18')->assertInertia(fn (Assert $page) => $page
            ->where('totals.revenue', 0)->where('totals.order_count', 0)->where('days.total', 0)->has('top', 0));
        $this->get($url.'?from=2026-11-18&to=2026-11-15')->assertSessionHasErrors('to');
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
        $product = DB::table('products')->where('name', 'Bánh QA')->first();
        $productUrl = '/banh/'.$product->slug;
        $this->get($productUrl)->assertOk();
        $this->get(json_decode($product->images, true)[0])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/gio-hang', [
            'variant_id' => DB::table('variants')->where('product_id', $product->id)->value('id'), 'quantity' => 1,
        ])->assertRedirect($productUrl)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stock_movements', ['actor_id' => $this->account('admin')->id, 'reason' => 'initial', 'stock_before' => 0, 'stock_after' => 5]);
    }

    public function test_admin_can_save_shop_config_and_disable_online_bank(): void
    {
        Storage::fake('public');
        $fields = Store::settings();
        $fields['name'] = 'Tiệm Bánh QA';
        $fields['logo'] = UploadedFile::fake()->image('logo.png');
        $fields['remove_logo'] = false;
        $fields['slots'] = ['10:00', '17:00'];
        $fields['slot_limits'] = ['10:00' => '3', '17:00' => null];
        $fields['closed_dates'] = ['2026-11-16'];
        $fields['bank_name'] = $fields['bank_account'] = $fields['bank_holder'] = null;
        $fields['bank_demo'] = false;
        $fields['bank_due_minutes'] = '90';
        $fields['order_policy'] = "Liên hệ tiệm để đổi giờ.\nHoàn tiền sau khi đối soát.";

        $this->actingAs($this->account('admin'))->post('/quan-tri/cai-dat', $fields)->assertSessionHasNoErrors();
        $stored = Store::settings();
        $this->assertSame(['10:00', '17:00'], $stored['slots']);
        $this->assertSame(['10:00' => 3], $stored['slot_limits']);
        $this->assertSame(['2026-11-16'], $stored['closed_dates']);
        $this->assertSame(90, $stored['bank_due_minutes']);
        $this->get('/chinh-sach-dat-banh')->assertInertia(fn (Assert $page) => $page
            ->component('OrderPolicy')->where('policy', $fields['order_policy']));
        $this->assertNull(Store::bank($stored));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('shop.name', 'Tiệm Bánh QA')->where('shop.logo', $stored['logo']));
        $this->get($stored['logo'])->assertOk();
        $fields['logo'] = null;
        $this->post('/quan-tri/cai-dat', array_replace($fields, ['slot_limits' => ['18:00' => 3]]))->assertSessionHasErrors('slot_limits');
        $this->post('/quan-tri/cai-dat', array_replace($fields, ['slot_limits' => ['10:00' => 0]]))->assertSessionHasErrors('slot_limits.10:00');
        $this->post('/quan-tri/cai-dat', array_replace($fields, ['slot_limits' => ['10:00' => '', '17:00' => '']]))->assertSessionHasNoErrors();
        $this->assertSame([], Store::settings()['slot_limits']);
        $fields['bank_name'] = 'Chỉ tên ngân hàng';
        $this->post('/quan-tri/cai-dat', $fields)->assertSessionHasErrors(['bank_holder', 'bank_account']);
    }

    private function variant(): object
    {
        $productId = DB::table('products')->where('slug', 'banh-kem-dau')->value('id');

        return DB::table('variants')->where('product_id', $productId)->orderBy('id')->first();
    }

    private function runOrderMailWorker(): void
    {
        $this->artisan('queue:work', ['connection' => 'order-mail', '--once' => true, '--sleep' => 0])->assertSuccessful();
    }

    private function account(string $prefix): User
    {
        return User::where('email', $prefix.'@tiembanh.test')->firstOrFail();
    }

    private function cartAndFields(object $variant, ?string $message = null, int $quantity = 1, array $overrides = []): array
    {
        $this->post('/gio-hang', compact('message', 'quantity') + ['variant_id' => $variant->id])
            ->assertSessionHasNoErrors();
        $this->get('/dat-banh')->assertOk();
        $lines = app(Checkout::class)->cart(session('cart'));
        $slot = app(Checkout::class)->slots(Store::settings(), max(array_column($lines, 'prep_minutes')))[0];

        return $this->confirmQuote(array_replace([
            'key' => session('checkout_key'), 'name' => 'Khách Đặt Bánh', 'email' => 'test@example.test', 'phone' => '0900123456',
            'fulfillment' => 'pickup', 'slot' => $slot, 'method' => 'cod',
        ], $overrides));
    }

    private function confirmQuote(array $fields): array
    {
        $preview = $this->postJson('/dat-banh/bao-gia', array_intersect_key($fields, array_flip(['fulfillment', 'region', 'coupon'])))->assertOk();

        return array_replace($fields, ['quote_token' => $preview->json('quote_token')]);
    }
}
