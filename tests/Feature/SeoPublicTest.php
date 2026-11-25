<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PublicSeo;
use App\Services\Store;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SeoPublicTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://bakery.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['bakery.demo_seed' => true, 'inertia.testing.ensure_pages_exist' => false, 'app.url' => self::ORIGIN, 'inertia.ssr.enabled' => false]);
        $this->seed();
        User::where('role', 'admin')->each(fn (User $user) => $user->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'])->save());
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->withSession(['staff_mfa_verified' => $user instanceof User ? $user->staffProof() : null]);

        return parent::actingAs($user, $guard);
    }

    public function test_default_robots_and_sitemap_do_not_publish_demo_or_private_urls(): void
    {
        $this->assertFalse(PublicSeo::indexingEnabled());
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('<loc>', $xml);
        $response = $this->get('/')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');
        $dom = $this->html($response->getContent());
        $this->assertSame(1, $dom->query('//head/title')->length);
        $this->assertSame('noindex, follow', $dom->evaluate('string(//meta[@name="robots"]/@content)'));
        $this->assertSame(self::ORIGIN.'/', $dom->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertSame(1, $dom->query('//body//h1')->length);
        $this->assertGreaterThan(0, $dom->query('//body//a[@href="/banh/banh-kem-dau"]')->length);
        $this->assertSame([], $this->schemaType($dom, 'Bakery'));
        $this->get('/gioi-thieu')->assertOk()->assertInertia(fn (Assert $page) => $page->where('info.approved', false)->where('info.intro', null));
    }

    public function test_rehearsal_stays_noindex_even_if_production_and_indexing_flags_are_enabled(): void
    {
        $this->publicFixture();
        $this->assertTrue(PublicSeo::indexingEnabled());
        config(['bakery.rehearsal' => true]);
        $this->assertFalse(PublicSeo::indexingEnabled());
        $this->get('/')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
        $this->assertStringNotContainsString('<loc>', $this->get('/sitemap.xml')->assertOk()->getContent());
    }

    public function test_clean_category_and_pagination_are_distinct_while_filters_have_clean_noindex_canonical(): void
    {
        $this->publicFixture();
        $page = $this->get('/thuc-don?page=2')->assertOk()->assertHeader('X-Robots-Tag', 'index, follow');
        $dom = $this->html($page->getContent());
        $this->assertSame(self::ORIGIN.'/thuc-don?page=2', $dom->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertStringContainsString('Trang 2', $dom->evaluate('string(//head/title)'));
        $category = $this->get('/thuc-don/banh-kem')->assertOk();
        $this->assertSame(self::ORIGIN.'/thuc-don/banh-kem', $this->html($category->getContent())->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->get('/thuc-don?category=banh-kem')->assertStatus(301)->assertRedirect(self::ORIGIN.'/thuc-don/banh-kem');
        $filtered = $this->get('/thuc-don/banh-kem?q=dau&address=private-input')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');
        $filteredHead = $this->html($filtered->getContent());
        $this->assertSame(self::ORIGIN.'/thuc-don/banh-kem', $filteredHead->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertStringNotContainsString('private-input', $filteredHead->evaluate('string(//head)'));
        $this->get('/thuc-don?page=9999')->assertNotFound();
    }

    public function test_product_schema_uses_each_visible_variant_price_and_availability_and_no_offers_while_paused(): void
    {
        $this->publicFixture();
        $product = $this->product();
        DB::table('products')->where('id', $product->id)->update(['details_verified' => true, 'images_approved' => true]);
        $variants = DB::table('variants')->where('product_id', $product->id)->orderBy('id')->get();
        DB::table('variants')->where('id', $variants[0]->id)->update(['stock' => 0]);
        $response = $this->get('/banh/'.$product->slug)->assertOk();
        $dom = $this->html($response->getContent());
        $schema = $this->schemaType($dom, 'Product');
        $this->assertCount(2, $schema['offers']);
        $this->assertSame((int) $variants[0]->price, $schema['offers'][0]['price']);
        $this->assertSame((int) $variants[1]->price, $schema['offers'][1]['price']);
        $this->assertSame('VND', $schema['offers'][0]['priceCurrency']);
        $this->assertSame('https://schema.org/OutOfStock', $schema['offers'][0]['availability']);
        $this->assertSame('https://schema.org/InStock', $schema['offers'][1]['availability']);
        $this->assertStringNotContainsString('AggregateOffer', json_encode($schema));
        $this->assertGreaterThan(0, $dom->query('//body//h1')->length);
        $this->assertStringContainsString($product->ingredients, $dom->evaluate('string(//body)'));
        $settings = array_replace(Store::settings(), ['accepting_orders' => false]);
        $this->settings($settings);
        $paused = $this->schemaType($this->html($this->get('/banh/'.$product->slug)->assertOk()->getContent()), 'Product');
        $this->assertArrayNotHasKey('offers', $paused);
        $this->assertStringNotContainsString('PreOrder', json_encode($paused));
        DB::table('variants')->where('product_id', $product->id)->update(['stock' => 0]);
        $this->get('/banh/'.$product->slug)->assertOk()->assertHeader('X-Robots-Tag', 'index, follow');
    }

    public function test_sitemap_contains_only_approved_canonical_public_urls_and_hides_inactive_products(): void
    {
        $this->publicFixture();
        $product = $this->product();
        DB::table('products')->where('id', $product->id)->update(['details_verified' => true, 'images_approved' => true]);
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        foreach (['/thuc-don/banh-kem', '/banh/'.$product->slug, '/gioi-thieu', '/cau-hoi-thuong-gap', '/cua-hang'] as $path) {
            $this->assertStringContainsString(self::ORIGIN.$path, $xml);
        }
        foreach (['/quan-tri', '/gio-hang', '/dat-banh', '/tra-cuu', '/don-cua-toi', '/thao-tac-don', 'croissant-bo-phap'] as $private) {
            $this->assertStringNotContainsString($private, $xml);
        }
        $this->assertSame(0, preg_match('~<loc>[^<]*\?~', $xml));
        DB::table('products')->where('id', $product->id)->update(['active' => false]);
        $this->get('/banh/'.$product->slug)->assertNotFound();
        $this->assertStringNotContainsString('/banh/'.$product->slug, $this->get('/sitemap.xml')->getContent());
        $this->get('/robots.txt')->assertSee('Disallow: /quan-tri')->assertSee('Sitemap: '.self::ORIGIN.'/sitemap.xml');
    }

    public function test_canonical_never_uses_request_host_or_invalid_config_origin(): void
    {
        $this->publicFixture();
        $response = $this->get('https://attacker.invalid/thuc-don?probe=private-sentinel')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.path', '/thuc-don')->where('products.first_page_url', '/thuc-don?page=1')
                ->where('products.next_page_url', '/thuc-don?page=2')
                ->where('products', fn ($value) => ! str_contains(json_encode($value), 'private-sentinel') && ! str_contains(json_encode($value), 'attacker.invalid')));
        $dom = $this->html($response->getContent());
        $this->assertSame(self::ORIGIN.'/thuc-don', $dom->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertSame(self::ORIGIN.'/thuc-don', $dom->evaluate('string(//meta[@property="og:url"]/@content)'));
        $head = $dom->query('//head')->item(0);
        $this->assertStringNotContainsString('attacker.invalid', $head->ownerDocument->saveHTML($head));
        $this->assertGreaterThan(0, $dom->query('//head/script[@type="module"][@src]')->length);
        foreach ($dom->query('//head/script[@src]/@src | //head/link[@href]/@href') as $attribute) {
            $this->assertTrue(str_starts_with($attribute->value, self::ORIGIN.'/') || str_starts_with($attribute->value, '/'));
        }
        config(['app.url' => 'https://user:secret@bad.test/?address=private']);
        $bad = $this->html($this->get('/')->assertOk()->getContent());
        $this->assertSame(0, $bad->query('//link[@rel="canonical"]')->length);
        foreach ($bad->query('//head/script[@src]/@src') as $attribute) {
            $this->assertStringStartsWith('/build/', $attribute->value);
        }
        $this->assertFalse(PublicSeo::indexingEnabled());
    }

    public function test_business_schema_uses_only_approved_source_and_never_invents_coordinates_reviews_or_hours(): void
    {
        $this->assertSame([], $this->schemaType($this->html($this->get('/cua-hang')->getContent()), 'Bakery'));
        $this->publicFixture();
        $dom = $this->html($this->get('/cua-hang')->assertOk()->getContent());
        $bakery = $this->schemaType($dom, 'Bakery');
        $this->assertSame('Địa chỉ thử đã đối chiếu', $bakery['address']['streetAddress']);
        $this->assertSame('VN', $bakery['address']['addressCountry']);
        $this->assertArrayNotHasKey('geo', $bakery);
        $this->assertArrayNotHasKey('aggregateRating', $bakery);
        $this->assertArrayNotHasKey('openingHoursSpecification', $bakery);
        $settings = array_replace(Store::settings(), ['business_opening_hours' => [['days' => [1, 3], 'opens' => '08:30', 'closes' => '17:30']]]);
        $this->settings($settings);
        $hours = $this->schemaType($this->html($this->get('/cua-hang')->getContent()), 'Bakery')['openingHoursSpecification'];
        $this->assertSame(['https://schema.org/Monday', 'https://schema.org/Wednesday'], $hours[0]['dayOfWeek']);
        $this->assertSame('08:30', $hours[0]['opens']);
    }

    public function test_admin_slug_changes_keep_old_product_and_category_urls_as_same_origin_redirects(): void
    {
        $product = $this->product();
        $this->actingAs(User::where('role', 'admin')->first());
        $fields = $this->productFields($product);
        $this->post('/quan-tri/san-pham/'.$product->id, [...$fields, 'slug' => 'banh-duong-dan-moi'])->assertSessionHasNoErrors();
        $this->get('/banh/'.$product->slug.'?address=drop-this')->assertStatus(301)->assertRedirect(self::ORIGIN.'/banh/banh-duong-dan-moi');
        $this->assertDatabaseHas('product_slug_history', ['product_id' => $product->id, 'slug' => $product->slug]);
        $other = DB::table('products')->where('slug', 'croissant-bo-phap')->first();
        $this->post('/quan-tri/san-pham/'.$other->id, [...$this->productFields($other), 'slug' => $product->slug])->assertSessionHasErrors('slug');
        $category = DB::table('categories')->where('slug', 'banh-kem')->first();
        $this->post('/quan-tri/danh-muc/'.$category->id, ['name' => $category->name, 'slug' => 'banh-kem-moi', 'icon' => $category->icon])->assertSessionHasNoErrors();
        $this->get('/thuc-don/banh-kem?address=drop-this')->assertStatus(301)->assertRedirect(self::ORIGIN.'/thuc-don/banh-kem-moi');
        DB::table('products')->where('id', $product->id)->update(['active' => false]);
        $this->get('/banh/'.$product->slug)->assertNotFound();
    }

    public function test_admin_public_content_is_validated_and_changed_sources_require_fresh_approval(): void
    {
        $fields = Store::settings() + ['remove_logo' => false];
        $fields['logo'] = null;
        $this->actingAs(User::where('role', 'admin')->first());
        $this->post('/quan-tri/cai-dat', [...$fields, 'business_verified' => true])->assertSessionHasErrors('business_street_address');
        $this->post('/quan-tri/cai-dat', [...$fields, 'maps_url' => 'javascript:alert(1)'])->assertSessionHasErrors('maps_url');
        $this->post('/quan-tri/cai-dat', [...$fields, 'business_opening_hours' => [['days' => [9], 'opens' => '08:00', 'closes' => '18:00']]])->assertSessionHasErrors('business_opening_hours.0.days.0');
        $valid = [...$fields, ...$this->businessFields(), 'public_content_approved' => true, 'about_text' => 'Giới thiệu do người phụ trách kiểm thử duyệt.',
            'faq_items' => json_encode([['question' => 'Câu hỏi thử?', 'answer' => 'Câu trả lời thử đã được duyệt.']])];
        $this->post('/quan-tri/cai-dat', $valid)->assertSessionHasNoErrors();
        $this->get('/gioi-thieu')->assertInertia(fn (Assert $page) => $page->where('info.approved', true)->where('info.intro', $valid['about_text']));
        $changed = Store::settings() + ['remove_logo' => false];
        unset($changed['business_verified'], $changed['public_content_approved']);
        $changed['logo'] = null;
        $changed['address'] = 'Địa chỉ vừa thay đổi, cần đối chiếu lại';
        $changed['about_text'] = 'Nội dung vừa thay đổi, cần duyệt lại';
        $this->post('/quan-tri/cai-dat', $changed)->assertSessionHasNoErrors();
        $this->assertFalse(Store::settings()['business_verified']);
        $this->assertFalse(Store::settings()['public_content_approved']);
        $this->get('/lien-he')->assertInertia(fn (Assert $page) => $page->where('info.store.address', null));
    }

    public function test_head_and_json_ld_escape_admin_supplied_markup(): void
    {
        $this->publicFixture();
        $product = $this->product();
        $name = '</title><script id="injected-script">alert(1)</script>';
        DB::table('products')->where('id', $product->id)->update(['name' => $name, 'details_verified' => true, 'images_approved' => true]);
        $dom = $this->html($this->get('/banh/'.$product->slug)->assertOk()->getContent());
        $this->assertSame(1, $dom->query('//head/title')->length);
        $this->assertSame(0, $dom->query('//script[@id="injected-script"]')->length);
        $this->assertSame($name, $this->schemaType($dom, 'Product')['name']);
    }

    private function publicFixture(): void
    {
        $this->app['env'] = 'production';
        config(['bakery.demo_seed' => false, 'bakery.seo.indexing_enabled' => true]);
        $this->settings(array_replace(Store::settings(), $this->businessFields(), [
            'accepting_orders' => true, 'public_content_approved' => true, 'about_text' => 'Nội dung giả lập dùng riêng trong PHPUnit.',
            'faq_items' => [['question' => 'Câu hỏi thử?', 'answer' => 'Trả lời thử.']],
        ]));
    }

    private function businessFields(): array
    {
        return ['business_verified' => true, 'business_street_address' => 'Địa chỉ thử đã đối chiếu',
            'business_locality' => 'Thành phố thử', 'business_country_code' => 'VN', 'phone' => '0900123456'];
    }

    private function settings(array $settings): void
    {
        DB::table('store_settings')->where('id', 1)->update(['data' => json_encode($settings, JSON_THROW_ON_ERROR)]);
    }

    private function product(): object
    {
        return DB::table('products')->where('slug', 'banh-kem-dau')->firstOrFail();
    }

    private function productFields(object $product): array
    {
        $variant = DB::table('variants')->where('product_id', $product->id)->orderBy('id')->first();

        return array_intersect_key((array) $product, array_flip(['name', 'category_id', 'description', 'ingredients', 'allergens', 'prep_minutes', 'message_fee', 'active', 'featured', 'customizable']))
            + ['variants' => [array_intersect_key((array) $variant, array_flip(['id', 'name', 'price', 'stock', 'active'])) + ['expected_stock' => $variant->stock]]];
    }

    private function html(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    private function schemaType(DOMXPath $xpath, string $type): array
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $data = json_decode($script->nodeValue, true, flags: JSON_THROW_ON_ERROR);
            foreach (array_is_list($data) ? $data : [$data] as $item) {
                if (($item['@type'] ?? null) === $type) {
                    return $item;
                }
            }
        }

        return [];
    }
}
