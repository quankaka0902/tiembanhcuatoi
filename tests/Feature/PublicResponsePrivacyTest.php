<?php

namespace Tests\Feature;

use App\Http\Middleware\PublicResponsePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PublicResponsePrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_static_robots_file_cannot_bypass_environment_or_staging_policy(): void
    {
        $this->assertFileDoesNotExist(public_path('robots.txt'));
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_public_html_and_inertia_payloads_are_never_shared_cached_even_when_indexable(): void
    {
        foreach (['/', '/banh/banh-thu', '/thuc-don/banh-kem'] as $path) {
            $request = Request::create($path);
            $request->attributes->set('public_seo_indexable', true);
            $response = (new PublicResponsePolicy)->handle($request, fn () => new Response('personalized navigation', 200, ['Cache-Control' => 'public, max-age=3600', 'Vary' => 'X-Inertia']));
            $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
            $this->assertSame('index, follow', $response->headers->get('X-Robots-Tag'));
            $this->assertContains('X-Inertia', $response->getVary());
            $this->assertContains('Cookie', $response->getVary());
        }
    }

    public function test_private_and_error_responses_cannot_be_indexed_or_shared_cached(): void
    {
        foreach (['/tra-cuu', '/don-cua-toi', '/gio-hang', '/dat-banh', '/dang-nhap', '/quen-mat-khau', '/quan-tri'] as $path) {
            $request = Request::create($path);
            // An accidental SEO attribute must not turn a private route into a public one.
            $request->attributes->set('public_seo_indexable', true);
            $response = (new PublicResponsePolicy)->handle($request, fn () => new Response('private', 200));
            $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
            $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        }
        $request = Request::create('/banh/missing');
        $request->attributes->set('public_seo_indexable', true);
        $response = (new PublicResponsePolicy)->handle($request, fn () => new Response('missing', 404));
        $this->assertStringStartsWith('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function test_public_image_cache_survives_but_staging_never_reuses_it(): void
    {
        $request = Request::create('/anh/'.str_repeat('a', 40).'.webp');
        $image = fn () => new Response('image bytes', 200, ['Content-Type' => 'image/webp', 'Cache-Control' => 'public, max-age=31536000, immutable']);
        $response = (new PublicResponsePolicy)->handle($request, $image);
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('X-Robots-Tag'));
        $this->app['env'] = 'staging';
        $response = (new PublicResponsePolicy)->handle($request, $image);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }
}
