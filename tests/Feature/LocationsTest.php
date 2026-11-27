<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeliveryAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BranchFixture;
use Tests\TestCase;

class LocationsTest extends TestCase
{
    use BranchFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 27)->setTime(8, 0));
        $this->prepareBranches();
        foreach ([1, 2] as $branch) {
            $settings = json_decode(DB::table('branches')->where('id', $branch)->value('settings'), true);
            $settings['regions'] = [['key' => 'local-'.$branch, 'area_code' => 'vung-chung', 'name' => '[THỬ] Vùng chung', 'fee' => 20000]];
            DB::table('branches')->where('id', $branch)->update(['settings' => json_encode($settings)]);
        }
    }

    public function test_public_locations_have_no_promised_time_without_cart_and_no_private_configuration(): void
    {
        $response = $this->get('/co-so')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Locations')
            ->has('locations', 2)->where('locations.0.earliest_slot', null)->where('locations.1.earliest_slot', null)
            ->where('areas.0.code', 'vung-chung')->missing('addresses'));
        $this->assertStringNotContainsString('bank_account', $response->getContent());
        $this->assertStringNotContainsString('encrypted_payload', $response->getContent());
        $this->get('/co-so/thu-b')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Location')->where('location.id', 2));
        DB::table('branches')->where('id', 2)->update(['active' => false]);
        $this->get('/co-so/thu-b')->assertNotFound();
    }

    public function test_recommendation_never_echoes_or_flashes_address_even_on_html_validation_failure(): void
    {
        $fields = ['fulfillment' => 'delivery', 'method' => 'cod', 'goal' => 'fee', 'area_code' => 'vung-chung',
            'address_line' => '[THỬ] ĐỊA CHỈ RIÊNG KHÔNG LƯU', 'delivery_note' => '[THỬ] Ghi chú bí mật',
            'pin' => ['lat' => 10.123456, 'lng' => 106.123456, 'confirmed' => true]];
        $response = $this->withSession(['cart' => $this->branchCart()])->postJson('/co-so/goi-y', $fields)->assertOk()->assertJsonCount(2, 'candidates');
        $this->assertStringNotContainsString('ĐỊA CHỈ RIÊNG', $response->getContent());
        $this->assertStringNotContainsString('10.123456', $response->getContent());
        $response->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('customer_delivery_addresses', 0);
        $this->post('/co-so/goi-y', array_replace($fields, ['area_code' => 'unknown']))->assertStatus(422)->assertJsonValidationErrors('area_code')->assertSessionMissing('_old_input');
    }

    public function test_address_book_requires_explicit_consent_and_encrypts_label_address_and_pin(): void
    {
        $owner = User::where('role', 'customer')->firstOrFail();
        $this->actingAs($owner)->postJson('/tai-khoan/dia-chi', $this->fields(false))->assertStatus(422)->assertJsonValidationErrors('save_consent');
        $this->assertDatabaseCount('customer_delivery_addresses', 0);
        $this->postJson('/tai-khoan/dia-chi', $this->fields())->assertCreated()->assertJsonPath('addresses.0.address.address_line', '[THỬ] Địa chỉ riêng');
        $row = DB::table('customer_delivery_addresses')->first();
        $this->assertStringNotContainsString('Địa chỉ riêng', $row->encrypted_payload);
        $this->assertStringNotContainsString('Nhãn riêng', $row->encrypted_payload);
        $this->assertStringNotContainsString('10.123456', $row->encrypted_payload);
        $this->assertNotNull($row->consented_at);
        $this->getJson('/tai-khoan/dia-chi')->assertOk()->assertJsonCount(1, 'addresses')->assertJsonPath('addresses.0.id', $row->id);
        $this->get('/tai-khoan/dia-chi')->assertOk()->assertInertia(fn (Assert $page) => $page->component('AddressBook')->has('addresses', 1));
    }

    public function test_malformed_nested_address_input_returns_private_422_instead_of_server_error(): void
    {
        $base = ['fulfillment' => 'delivery', 'method' => 'cod', 'area_code' => 'vung-chung', 'address_line' => '[THỬ] Địa chỉ không flash'];
        foreach ([['normalized_address' => 'x'], ['normalized_address' => 7], ['normalized_address' => ['pin' => 'x']], ['pin' => 'x'],
            ['normalized_address' => ['area_code' => 'vung-chung', 'address_line' => ['bad']]]] as $change) {
            $response = $this->withSession(['cart' => $this->branchCart()])->post('/co-so/goi-y', array_replace($base, $change));
            $response->assertStatus(422)->assertSessionMissing('_old_input');
            $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
            $this->assertStringNotContainsString('Địa chỉ không flash', $response->getContent());
        }
        $this->postJson('/co-so/goi-y', ['fulfillment' => 'delivery', 'method' => 'cod',
            'normalized_address' => ['area_code' => 'vung-chung', 'address_line' => '[THỬ] Cấu trúc hợp lệ']])->assertOk()->assertJsonCount(2, 'candidates');
    }

    public function test_only_owner_can_update_delete_and_book_changes_do_not_edit_order_snapshot(): void
    {
        $owner = User::where('role', 'customer')->firstOrFail();
        $this->actingAs($owner)->postJson('/tai-khoan/dia-chi', $this->fields())->assertCreated();
        $row = DB::table('customer_delivery_addresses')->first();
        $orderId = DB::table('orders')->orderBy('id')->value('id');
        $snapshot = DeliveryAddress::seal(DeliveryAddress::normalize($this->fields()['address'], [['key' => 'vung-chung']]));
        DB::table('orders')->where('id', $orderId)->update(['delivery_snapshot' => $snapshot]);
        $other = User::factory()->create(['role' => 'customer', 'active' => true]);
        $this->actingAs($other)->getJson('/tai-khoan/dia-chi')->assertOk()->assertJsonCount(0, 'addresses');
        $this->patchJson('/tai-khoan/dia-chi/'.$row->id, $this->fields())->assertNotFound();
        $this->deleteJson('/tai-khoan/dia-chi/'.$row->id)->assertNotFound();
        $this->actingAs($owner)->patchJson('/tai-khoan/dia-chi/'.$row->id, array_replace($this->fields(), ['label' => '[THỬ] Đã sửa']))->assertOk()->assertJsonPath('addresses.0.label', '[THỬ] Đã sửa');
        $this->deleteJson('/tai-khoan/dia-chi/'.$row->id)->assertOk()->assertJsonCount(0, 'addresses');
        $this->assertSame($snapshot, DB::table('orders')->where('id', $orderId)->value('delivery_snapshot'));
    }

    public function test_inactive_account_is_rechecked_and_invalid_inertia_input_is_not_flashed(): void
    {
        $owner = User::where('role', 'customer')->firstOrFail();
        $this->actingAs($owner)->from('/tai-khoan/dia-chi')->withHeader('X-Inertia', 'true')
            ->post('/tai-khoan/dia-chi', $this->fields(false))->assertRedirect()->assertSessionHasErrors('save_consent')->assertSessionMissing('_old_input');
        $this->flushHeaders();
        DB::table('users')->where('id', $owner->id)->update(['active' => false]);
        $this->getJson('/tai-khoan/dia-chi')->assertForbidden();
        $this->postJson('/tai-khoan/dia-chi', $this->fields())->assertForbidden();
    }

    public function test_corrupt_saved_payload_is_not_exposed_and_address_limit_is_handled_privately(): void
    {
        $owner = User::where('role', 'customer')->firstOrFail();
        for ($i = 0; $i < 20; $i++) {
            DB::table('customer_delivery_addresses')->insert(['user_id' => $owner->id, 'encrypted_payload' => 'CORRUPT-PRIVATE-CIPHER', 'consented_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $response = $this->actingAs($owner)->getJson('/tai-khoan/dia-chi')->assertOk()->assertJsonCount(20, 'addresses')->assertJsonPath('addresses.0.readable', false)->assertJsonPath('addresses.0.address', null);
        $this->assertStringNotContainsString('CORRUPT-PRIVATE-CIPHER', $response->getContent());
        $this->postJson('/tai-khoan/dia-chi', $this->fields())->assertStatus(422)->assertJsonValidationErrors('label')->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('customer_delivery_addresses', 20);
    }

    public function test_inertia_address_actions_return_to_book_even_when_referer_is_locations(): void
    {
        $owner = User::where('role', 'customer')->firstOrFail();
        $this->actingAs($owner)->from('/co-so')->withHeader('X-Inertia', 'true')
            ->post('/tai-khoan/dia-chi', $this->fields())->assertRedirect('/tai-khoan/dia-chi')
            ->assertSessionHas('notice')->assertSessionMissing('_old_input');
        $this->from('/co-so')->post('/tai-khoan/dia-chi', $this->fields(false))->assertRedirect('/tai-khoan/dia-chi')
            ->assertSessionHasErrors('save_consent')->assertSessionMissing('_old_input');
        $addressId = DB::table('customer_delivery_addresses')->where('user_id', $owner->id)->value('id');
        $this->from('/co-so')->delete('/tai-khoan/dia-chi/'.$addressId)->assertRedirect('/tai-khoan/dia-chi')
            ->assertSessionHas('notice')->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('customer_delivery_addresses', 0);
    }

    private function fields(bool $consent = true): array
    {
        return ['save_consent' => $consent, 'label' => '[THỬ] Nhãn riêng', 'address' => ['country_code' => 'VN', 'area_code' => 'vung-chung',
            'address_line' => '[THỬ] Địa chỉ riêng', 'delivery_note' => '[THỬ] Ghi chú giao',
            'pin' => ['lat' => 10.123456, 'lng' => 106.123456, 'confirmed' => true]]];
    }
}
