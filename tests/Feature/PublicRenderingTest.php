<?php

namespace Tests\Feature;

use App\Services\PublicRendering;
use App\Services\PublicRenderingGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicRenderingTest extends TestCase
{
    public function test_public_php_html_and_safe_head_exist_without_javascript_or_node(): void
    {
        $this->withoutVite();
        Http::fake();
        $page = $this->home();
        $page['props']['seo']['description'] = 'Nội dung </script><script>không thực thi</script>';
        $page['props']['seo']['jsonLd'] = [['@type' => 'WebPage', 'name' => '</script><script>không thực thi</script>']];
        $html = view('app', ['page' => $page])->render();
        $this->assertStringContainsString('data-rendering="php"', $html);
        $this->assertStringContainsString('<h1>Chọn chiếc bánh', $html);
        $this->assertStringContainsString('Bánh dữ liệu kiểm thử', $html);
        $this->assertStringContainsString('inertia="canonical"', $html);
        $this->assertStringContainsString('\\u003C/script\\u003E', $html);
        $this->assertStringNotContainsString('<script>không thực thi</script>', $html);
        Http::assertNothingSent();
    }

    public function test_private_or_personalized_payloads_never_reach_ssr(): void
    {
        config(['public-rendering.ssr_enabled' => true]);
        Http::fake();
        $gateway = app(PublicRenderingGateway::class);
        foreach (['Order', 'OrderAction', 'Cart', 'Checkout', 'Tracking', 'History', 'Auth', 'AdminOrder', 'Error'] as $component) {
            $page = $this->home();
            $page['component'] = $component;
            $page['url'] = '/tra-cuu/'.str_repeat('a', 64);
            $page['props']['private_token'] = 'Must never leave PHP';
            $this->assertNull($gateway->dispatch($page));
        }
        foreach (['auth' => ['name' => 'Khách riêng'], 'cartCount' => 1, 'notice' => 'Nội dung riêng', 'errors' => ['email' => 'Không đúng']] as $field => $value) {
            $page = $this->home();
            $page['props'][$field] = $value;
            $this->assertNull($gateway->dispatch($page));
        }
        Http::assertNothingSent();
    }

    public function test_ssr_failure_redirect_and_invalid_host_keep_php_fallback_available(): void
    {
        config(['public-rendering.ssr_enabled' => true]);
        $gateway = app(PublicRenderingGateway::class);
        Http::fake(['*' => Http::response(['body' => '<div id="app" data-page="{}">not accepted</div>'], 302, ['Location' => 'https://outside.invalid/render'])]);
        $this->assertNull($gateway->dispatch($this->home()));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:13714/render');
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertNull($gateway->dispatch($this->home()));
        Http::fake(['*' => Http::failedConnection()]);
        $this->assertNull($gateway->dispatch($this->home()));
        config(['public-rendering.ssr_url' => 'https://outside.invalid']);
        Http::fake();
        $this->assertNull($gateway->dispatch($this->home()));
        Http::assertNothingSent();
    }

    public function test_ssr_projection_drops_unapproved_shared_fields_and_marks_react_hydration(): void
    {
        config(['public-rendering.ssr_enabled' => true]);
        $page = $this->home();
        $page['url'] = '/?tracking_token='.str_repeat('f', 64);
        $page['props']['private_extra'] = ['token' => 'Never forwarded'];
        Http::fake(['*' => Http::response(['body' => '<div id="app" data-page="{}"><h1>Rendered</h1></div>'])]);
        $response = app(PublicRenderingGateway::class)->dispatch($page);
        $this->assertStringContainsString('data-react-ssr="true"', $response->body);
        Http::assertSent(fn ($request) => ! isset($request->data()['props']['private_extra']) && $request->data()['url'] === '/');
        $this->assertFalse(app(PublicRendering::class)->isPublic(['component' => 'Home', 'url' => '/tra-cuu/'.str_repeat('a', 64)]));
    }

    public function test_nested_paginator_urls_are_rebuilt_from_validated_filters_before_rendering(): void
    {
        config(['public-rendering.ssr_enabled' => true]);
        $page = $this->home();
        $page['component'] = 'Catalog';
        $page['url'] = '/thuc-don?probe=private-sentinel';
        $page['props']['filters'] = ['q' => 'dau', 'sort' => 'new'];
        $page['props']['products'] = ['data' => [], 'current_page' => 1, 'last_page' => 2, 'total' => 9,
            'next_page_url' => 'https://evil.invalid/thuc-don?probe=private-sentinel&page=2',
            'path' => 'https://evil.invalid/thuc-don',
            'links' => [['url' => 'https://evil.invalid/?probe=private-sentinel']]];
        Http::fake(['*' => Http::response(['body' => '<div id="app" data-page="{}"><h1>Menu</h1></div>'])]);
        $this->assertNotNull(app(PublicRenderingGateway::class)->dispatch($page));
        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            return ! str_contains($payload, 'evil.invalid') && ! str_contains($payload, 'private-sentinel')
                && $request->data()['props']['products']['next_page_url'] === '/thuc-don?q=dau&sort=new&page=2';
        });
        $this->assertSame('/thuc-don?q=dau&sort=new&page=2', app(PublicRendering::class)->props($page)['products']['next_page_url']);
    }

    public function test_php_projection_renders_category_product_policy_and_information_pages(): void
    {
        $this->withoutVite();
        $base = $this->home();
        $cases = [
            ['Catalog', '/thuc-don/banh-kem', ['currentCategory' => ['name' => 'Danh mục kiểm thử', 'slug' => 'banh-kem'], 'products' => ['data' => [], 'current_page' => 1, 'last_page' => 1, 'total' => 0]], 'Danh mục kiểm thử'],
            ['Product', '/banh/banh-kiem-thu', ['product' => ['name' => 'Bánh kiểm thử public', 'slug' => 'banh-kiem-thu', 'description' => 'Thông tin thử', 'category' => 'Kiểm thử', 'images' => [], 'variants' => [], 'ingredients' => null, 'allergens' => null, 'storage_instructions' => null, 'prep_minutes' => 60]], 'Bánh kiểm thử public'],
            ['OrderPolicy', '/chinh-sach-dat-banh', ['policy' => 'Chính sách kiểm thử', 'privacy' => null], 'Chính sách kiểm thử'],
            ['PublicInfo', '/cau-hoi-thuong-gap', ['info' => ['kind' => 'faq', 'title' => 'Câu hỏi thử', 'approved' => false, 'intro' => null, 'faqs' => [['question' => 'Câu hỏi dữ liệu thử?', 'answer' => 'Trả lời thử.']]]], 'Câu hỏi dữ liệu thử?'],
        ];
        foreach ($cases as [$component, $url, $props, $expected]) {
            $page = array_replace($base, ['component' => $component, 'url' => $url, 'props' => array_replace($base['props'], $props)]);
            $html = view('app', ['page' => $page])->render();
            $this->assertStringContainsString('data-rendering="php"', $html);
            $this->assertStringContainsString('<h1', $html);
            $this->assertStringContainsString($expected, $html);
            $this->assertStringNotContainsString('@elseif', $html);
        }
    }

    public function test_ssr_url_keeps_clean_category_path_and_only_explicit_valid_query_keys(): void
    {
        $page = $this->home();
        $page['component'] = 'Catalog';
        $page['url'] = '/thuc-don/banh-kem';
        $page['props']['filters'] = ['category' => 'banh-kem', 'sort' => 'featured'];
        $renderer = app(PublicRendering::class);
        $this->assertSame('/thuc-don/banh-kem', $renderer->ssrPage($page)['url']);

        $page['url'] = '/thuc-don/banh-kem?guests=8&page=2&sort=new&address=private-sentinel';
        $page['props']['filters'] = ['category' => 'banh-kem', 'guests' => 8, 'page' => 2, 'sort' => 'new'];
        $this->assertSame('/thuc-don/banh-kem?guests=8&page=2&sort=new', $renderer->ssrPage($page)['url']);
        $this->assertStringNotContainsString('private-sentinel', $renderer->ssrPage($page)['url']);
    }

    private function home(): array
    {
        return ['component' => 'Home', 'url' => '/', 'version' => null, 'props' => [
            'shop' => ['name' => 'Tiệm kiểm thử', 'accepting_orders' => false, 'pause_message' => 'Dữ liệu thử'],
            'auth' => null, 'cartCount' => 0, 'notice' => null, 'errors' => [],
            'seo' => ['title' => 'Trang chủ kiểm thử', 'description' => 'Nội dung thử', 'robots' => 'noindex, nofollow', 'canonical' => 'https://example.invalid/', 'jsonLd' => []],
            'categories' => [], 'occasions' => [], 'featured' => [[
                'id' => 1, 'name' => 'Bánh dữ liệu kiểm thử', 'slug' => 'banh-kiem-thu',
                'description' => 'Chỉ dùng kiểm tra render', 'images' => [], 'price' => 100000,
                'stock' => 1, 'images_approved' => false, 'details_verified' => false,
            ]],
        ]];
    }
}
