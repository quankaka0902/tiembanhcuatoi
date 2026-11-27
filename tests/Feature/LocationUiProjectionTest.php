<?php

namespace Tests\Feature;

use App\Services\PublicRendering;
use Tests\TestCase;

class LocationUiProjectionTest extends TestCase
{
    private function page(string $component = 'Locations', string $url = '/co-so'): array
    {
        return ['component' => $component, 'url' => $url, 'props' => [
            'shop' => ['name' => 'Tiệm kiểm thử', 'branch_mode' => true, 'branch' => ['id' => 1, 'name' => 'Cơ sở thử']],
            'locations' => [['id' => 1, 'name' => 'Cơ sở thử', 'address' => 'Địa chỉ công khai của cơ sở']],
            'location' => ['id' => 1, 'slug' => 'co-so-thu', 'name' => 'Cơ sở thử'],
            'areas' => [['code' => 'vung-thu', 'name' => 'Vùng thử']], 'current_branch_id' => 1,
            'auth' => null, 'cartCount' => 0, 'notice' => null, 'errors' => [],
            'deliveryDraft' => ['address_line' => 'PRIVATE_RECIPIENT_LINE', 'pin' => ['lat' => 12.345678, 'lng' => 109.876543]],
            'address_line' => 'PRIVATE_RECIPIENT_LINE', 'pin' => ['lat' => 12.345678, 'lng' => 109.876543],
            'savedAddresses' => [['address' => ['address_line' => 'PRIVATE_SAVED_LINE']]],
            'branchContext' => ['allowed' => [1, 2], 'private_note' => 'PRIVATE_MEMBERSHIP'],
            'cartSummary' => ['quantity' => 12], 'csrf_token' => 'PRIVATE_CSRF',
        ]];
    }

    public function test_location_ssr_projection_contains_only_public_initial_data(): void
    {
        $public = (new PublicRendering)->ssrPage($this->page());
        $this->assertNotNull($public);
        $this->assertSame('Địa chỉ công khai của cơ sở', $public['props']['locations'][0]['address']);
        $this->assertSame(1, $public['props']['current_branch_id']);
        foreach (['deliveryDraft', 'address_line', 'pin', 'savedAddresses', 'branchContext', 'cartSummary', 'csrf_token'] as $key) {
            $this->assertArrayNotHasKey($key, $public['props']);
        }
        foreach (['PRIVATE_RECIPIENT_LINE', 'PRIVATE_SAVED_LINE', 'PRIVATE_MEMBERSHIP', 'PRIVATE_CSRF', '12.345678'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($public, JSON_THROW_ON_ERROR));
        }
    }

    public function test_location_ssr_url_strips_precise_address_query_and_private_pages_stay_local_to_php(): void
    {
        $rendering = new PublicRendering;
        $public = $rendering->ssrPage($this->page('Location', '/co-so/co-so-thu?address_line=PRIVATE_RECIPIENT_LINE&pin%5Blat%5D=12.345678'));
        $this->assertSame('/co-so/co-so-thu', $public['url']);
        foreach (['AddressBook', 'TransferApproval', 'AdminDispatch', 'Order'] as $component) {
            $this->assertNull($rendering->ssrPage($this->page($component, '/tai-khoan/dia-chi')));
        }
        $this->assertNull($rendering->ssrPage($this->page('Locations', '/co-so/goi-y')));
    }
}
