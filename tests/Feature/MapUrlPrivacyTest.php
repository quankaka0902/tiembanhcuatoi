<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MapUrlPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bakery.demo_seed' => true, 'inertia.testing.ensure_pages_exist' => false, 'public-rendering.ssr_enabled' => false]);
        $this->seed();
    }

    private function storeMap(string $url): void
    {
        $settings = array_replace(Store::settings(), ['business_verified' => true, 'maps_url' => $url]);
        DB::table('store_settings')->where('id', 1)->update(['data' => json_encode($settings, JSON_THROW_ON_ERROR)]);
    }

    public function test_preexisting_encoded_map_credentials_are_hidden_from_public_info_and_locations_http(): void
    {
        foreach (['%6bey', '%256bey', 'a%70i%5Fkey', 'ACCESS_TOKEN', 'auth%5Btoken%5D'] as $key) {
            $this->storeMap('https://www.google.com/maps?'.$key.'=MAP_PRIVATE_TEST_VALUE');
            foreach (['/lien-he', '/cua-hang'] as $url) {
                $response = $this->get($url)->assertOk()->assertDontSee('MAP_PRIVATE_TEST_VALUE')
                    ->assertInertia(fn (Assert $page) => $page->where('info.store.maps_url', null));
                $this->assertStringNotContainsString('MAP_PRIVATE_TEST_VALUE', $response->getContent());
            }
            $response = $this->get('/co-so')->assertOk()->assertDontSee('MAP_PRIVATE_TEST_VALUE')
                ->assertInertia(fn (Assert $page) => $page->where('locations.0.maps_url', null));
            $this->assertStringNotContainsString('MAP_PRIVATE_TEST_VALUE', $response->getContent());
            $slug = DB::table('branches')->where('id', 1)->value('slug');
            $this->get('/co-so/'.$slug)->assertOk()->assertDontSee('MAP_PRIVATE_TEST_VALUE')
                ->assertInertia(fn (Assert $page) => $page->where('location.maps_url', null));
        }
    }

    public function test_safe_map_search_link_is_preserved_for_public_information(): void
    {
        $url = 'https://www.google.com/maps/search/?api=1&query=Tiem+kiem+thu';
        $this->storeMap($url);
        $this->get('/cua-hang')->assertOk()->assertInertia(fn (Assert $page) => $page->where('info.store.maps_url', $url));
        $this->get('/co-so')->assertOk()->assertInertia(fn (Assert $page) => $page->where('locations.0.maps_url', $url));
    }

    public function test_admin_cannot_save_encoded_credentials_in_map_url(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $admin->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'])->save();
        $this->actingAs($admin)->withSession(['staff_mfa_verified' => $admin->staffProof()]);
        $fields = Store::settings() + ['remove_logo' => false];
        unset($fields['logo']);
        foreach (['%6bey', '%256bey', 'a%70i%5Fkey', 'ACCESS_TOKEN', 'auth%5Btoken%5D'] as $key) {
            $fields['maps_url'] = 'https://www.google.com/maps?'.$key.'=MAP_PRIVATE_TEST_VALUE';
            $this->post('/quan-tri/cai-dat', $fields)->assertSessionHasErrors('maps_url');
            $this->assertNotSame($fields['maps_url'], Store::settings()['maps_url']);
        }
    }
}
