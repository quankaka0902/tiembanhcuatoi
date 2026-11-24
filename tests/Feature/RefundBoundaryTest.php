<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RefundBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_refund_accepts_valid_checkout_total_above_old_limit_but_requires_exact_amount_and_permission(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(8, 0));
        config(['bakery.demo_seed' => true, 'inertia.testing.ensure_pages_exist' => false]);
        $this->seed();
        $variant = DB::table('variants')->orderBy('id')->first();
        DB::table('variants')->where('id', $variant->id)->update(['price' => 100000000, 'stock' => 5]);
        $accessoryIds = [];
        for ($index = 1; $index <= 20; $index++) {
            // All prices, accessory count and quantity are inside the existing catalog/cart limits.
            $accessoryIds[] = DB::table('product_accessories')->insertGetId([
                'product_id' => $variant->product_id, 'name' => 'Phụ kiện thử '.$index,
                'price' => 100000000, 'active' => true, 'approved' => true,
            ]);
        }
        $this->post('/gio-hang', ['variant_id' => $variant->id, 'quantity' => 5, 'accessories' => $accessoryIds])->assertSessionHasNoErrors();
        $this->get('/dat-banh')->assertOk();
        $quote = $this->postJson('/dat-banh/bao-gia', ['fulfillment' => 'pickup'])->assertOk();
        $this->assertSame(10500000000, $quote->json('total'));
        $this->post('/dat-banh', [
            'key' => session('checkout_key'), 'name' => 'Khách thử giới hạn', 'email' => 'khach@tiembanh.test',
            'phone' => '0900123456', 'fulfillment' => 'pickup', 'slot' => '2026-11-25 14:00',
            'method' => 'bank', 'quote_token' => $quote->json('quote_token'),
        ])->assertSessionHasNoErrors();
        $order = DB::table('orders')->latest('id')->first();
        $this->assertSame(10500000000, $order->total);
        $admin = User::where('email', 'admin@tiembanh.test')->firstOrFail();
        $admin->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'])->save();
        $url = '/quan-tri/don-hang/'.$order->id;
        $this->actingAs($admin)->withSession(['staff_mfa_verified' => $admin->staffProof()]);
        $this->patch($url, ['kind' => 'payment', 'value' => 'paid'])->assertSessionHasNoErrors();
        $this->patch($url, ['kind' => 'status', 'value' => 'cancelled', 'note' => 'Khách thử hủy trước khi chuẩn bị.'])->assertSessionHasNoErrors();
        $payload = [
            'kind' => 'payment', 'value' => 'refunded', 'refund_method' => 'bank',
            'refund_reference' => 'BANK-REFUND-BOUNDARY-TEST', 'refund_amount' => (string) $order->total,
            'refund_verified' => true, 'note' => 'Đã đối chiếu bằng chứng hoàn tiền mẫu.',
        ];
        $eventsBefore = DB::table('order_events')->where('order_id', $order->id)->count();
        $noticesBefore = DB::table('order_notifications')->where('order_id', $order->id)->count();
        $stockBefore = DB::table('variants')->orderBy('id')->pluck('stock', 'id')->all();
        $movementsBefore = DB::table('stock_movements')->count();
        foreach ([-1, '10500000000.5'] as $amount) {
            $this->patch($url, [...$payload, 'refund_amount' => $amount])->assertSessionHasErrors('refund_amount');
        }
        foreach ([$order->total - 1, $order->total + 1] as $amount) {
            $this->patch($url, [...$payload, 'refund_amount' => $amount])->assertSessionHasErrors('refund_reference');
        }
        $staff = User::where('email', 'nhanvien@tiembanh.test')->firstOrFail();
        $staff->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'])->save();
        $this->actingAs($staff)->withSession(['staff_mfa_verified' => $staff->staffProof()])->patch($url, $payload)->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment' => 'paid']);
        $this->assertSame($eventsBefore, DB::table('order_events')->where('order_id', $order->id)->count());
        $this->assertSame($noticesBefore, DB::table('order_notifications')->where('order_id', $order->id)->count());
        $this->actingAs($admin)->withSession(['staff_mfa_verified' => $admin->staffProof()])->patch($url, $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment' => 'refunded']);
        $evidence = json_decode(DB::table('orders')->where('id', $order->id)->value('refund_evidence'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($order->total, $evidence['amount']);
        $this->assertSame('bank', $evidence['method']);
        $this->assertSame($admin->id, $evidence['verified_by']);
        $this->patch($url, $payload)->assertSessionHasErrors('value');
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('kind', 'payment')->where('value', 'refunded')->count());
        $this->assertSame(1, DB::table('order_notifications')->where('order_id', $order->id)->where('type', 'payment_refunded')->count());
        $this->assertSame($stockBefore, DB::table('variants')->orderBy('id')->pluck('stock', 'id')->all());
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
    }
}
