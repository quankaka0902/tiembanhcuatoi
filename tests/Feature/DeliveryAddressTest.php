<?php

namespace Tests\Feature;

use App\Services\DeliveryAddress;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeliveryAddressTest extends TestCase
{
    private array $regions = [['key' => 'a-local', 'area_code' => 'vung-chung', 'name' => '[THỬ] Vùng chung', 'fee' => 30000]];

    public function test_normalization_preserves_vietnamese_without_inventing_administrative_divisions(): void
    {
        $address = DeliveryAddress::normalize(['country_code' => 'vn', 'area_code' => ' VUNG-CHUNG ',
            'address_line' => "  [THỬ]  12 Nguyễn\nThị Minh Khai  ", 'delivery_note' => " Gọi\ttrước ", 'ward_name' => 'Không được suy đoán'], $this->regions);
        $this->assertSame('[THỬ] 12 Nguyễn Thị Minh Khai', $address['address_line']);
        $this->assertSame('Gọi trước', $address['delivery_note']);
        $this->assertSame('VN', $address['country_code']);
        $this->assertSame('vung-chung', $address['area_code']);
        $this->assertNull($address['ward_code']);
        $this->assertArrayNotHasKey('ward_name', $address);
        $this->assertSame('a-local', DeliveryAddress::regionForArea($this->regions, 'VUNG-CHUNG')['key']);
        $this->assertSame('old-key', DeliveryAddress::regionForArea([['key' => 'old-key']], 'old-key')['key']);
    }

    public function test_unknown_area_and_unconfirmed_or_invalid_pin_are_rejected(): void
    {
        foreach ([['area_code' => 'unknown'], ['country_code' => 'US'], ['pin' => ['lat' => 10, 'lng' => 106, 'confirmed' => false]],
            ['pin' => ['lat' => 91, 'lng' => 106, 'confirmed' => true]]] as $override) {
            try {
                DeliveryAddress::normalize(array_replace(['area_code' => 'vung-chung', 'address_line' => '[THỬ] Địa chỉ'], $override), $this->regions);
                $this->fail('Invalid address/pin was accepted.');
            } catch (ValidationException $failure) {
                $this->assertNotEmpty($failure->errors());
            }
        }
    }

    public function test_encrypted_snapshot_is_authenticated_and_fingerprint_is_stable(): void
    {
        $address = DeliveryAddress::normalize(['area_code' => 'vung-chung', 'address_line' => '[THỬ] Địa chỉ riêng',
            'pin' => ['lat' => 10.123456, 'lng' => 106.123456, 'confirmed' => true]], $this->regions);
        $cipher = DeliveryAddress::seal($address);
        $this->assertNotSame($cipher, DeliveryAddress::seal($address));
        $this->assertStringNotContainsString('Địa chỉ riêng', $cipher);
        $this->assertSame($address, DeliveryAddress::open($cipher));
        $this->assertSame(DeliveryAddress::fingerprint($address), DeliveryAddress::fingerprint(array_reverse($address, true)));
        $this->assertNotSame(DeliveryAddress::fingerprint($address), DeliveryAddress::fingerprint(array_replace($address, ['delivery_note' => 'Khác'])));
        $this->assertNull(DeliveryAddress::open(null));
        $this->expectException(DecryptException::class);
        DeliveryAddress::open(substr($cipher, 0, -2).'xx');
    }
}
