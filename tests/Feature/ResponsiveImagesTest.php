<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminCatalog;
use App\Services\ResponsiveImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ResponsiveImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['bakery.demo_seed' => true]);
    }

    public function test_uploaded_original_is_retained_and_webp_dimensions_are_real_without_upscaling(): void
    {
        $url = app(AdminCatalog::class)->uploadedImage(UploadedFile::fake()->image('cake.jpg', 1200, 800));
        $this->assertMatchesRegularExpression('~^/anh/[A-Za-z0-9]{40}\.jpg$~', $url);
        $images = app(ResponsiveImages::class);
        $original = $images->sourcePath($url);
        $hash = hash_file('sha256', $original);
        $asset = $images->asset($url);
        $this->assertSame([320, 640, 960, 1200], array_column($asset['sources'], 'width'));
        $this->assertSame(960, $asset['width']);
        foreach ($asset['sources'] as $source) {
            $path = $images->sourcePath($source['src']);
            $info = getimagesize($path);
            $this->assertSame([$source['width'], $source['height']], [$info[0], $info[1]]);
            $this->assertSame('image/webp', $info['mime']);
            $response = $this->get($source['src'])->assertOk();
            $this->assertTrue($response->baseResponse->headers->hasCacheControlDirective('immutable'));
            $this->assertSame(31536000, (int) $response->baseResponse->headers->getCacheControlDirective('max-age'));
        }
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($asset, $images->generate($url));
        $this->assertDatabaseCount('responsive_images', 1);
        $this->assertSame($hash, hash_file('sha256', $original));
        foreach (Storage::disk('public')->files('bakery') as $file) {
            $this->assertMatchesRegularExpression('~^bakery/[A-Za-z0-9]{40}\.(?:jpg|jpeg|png|webp)$~', $file);
        }
    }

    public function test_small_transparent_image_is_not_upscaled_and_keeps_alpha(): void
    {
        $upload = UploadedFile::fake()->image('transparent.png', 160, 80);
        $image = imagecreatetruecolor(160, 80);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagepng($image, $upload->getPathname());
        imagedestroy($image);
        $url = app(AdminCatalog::class)->uploadedImage($upload);
        $images = app(ResponsiveImages::class);
        $asset = $images->asset($url);
        $this->assertSame([160], array_column($asset['sources'], 'width'));
        $this->assertSame(80, $asset['height']);
        $derived = imagecreatefromwebp($images->sourcePath($asset['src']));
        $this->assertGreaterThanOrEqual(120, (imagecolorat($derived, 0, 0) >> 24) & 127);
        imagedestroy($derived);
    }

    public function test_jpeg_exif_rotation_is_applied_without_modifying_original(): void
    {
        $upload = UploadedFile::fake()->image('phone.jpg', 80, 40);
        $image = imagecreatetruecolor(80, 40);
        imagefilledrectangle($image, 0, 0, 39, 39, imagecolorallocate($image, 220, 0, 0));
        imagefilledrectangle($image, 40, 0, 79, 39, imagecolorallocate($image, 0, 0, 220));
        imagejpeg($image, $upload->getPathname(), 95);
        imagedestroy($image);
        $jpeg = file_get_contents($upload->getPathname());
        $exif = "Exif\0\0II\x2a\0".pack('V', 8).pack('v', 1).pack('vvVv', 0x112, 3, 1, 6)."\0\0".pack('V', 0);
        file_put_contents($upload->getPathname(), substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
        $hash = hash_file('sha256', $upload->getPathname());
        $url = app(AdminCatalog::class)->uploadedImage($upload);
        $images = app(ResponsiveImages::class);
        $asset = $images->asset($url);
        $this->assertSame([40, 80], [$asset['width'], $asset['height']]);
        $this->assertSame($hash, hash_file('sha256', $images->sourcePath($url)));
        $derived = imagecreatefromwebp($images->sourcePath($asset['src']));
        $top = imagecolorsforindex($derived, imagecolorat($derived, 20, 10));
        $bottom = imagecolorsforindex($derived, imagecolorat($derived, 20, 70));
        $this->assertGreaterThan($top['blue'], $top['red']);
        $this->assertGreaterThan($bottom['red'], $bottom['blue']);
        imagedestroy($derived);
    }

    public function test_missing_and_same_length_corrupt_variants_fall_back_to_original_and_can_be_repaired(): void
    {
        $url = app(AdminCatalog::class)->uploadedImage(UploadedFile::fake()->image('cake.jpg', 1000, 600));
        $images = app(ResponsiveImages::class);
        $asset = $images->asset($url);
        $missing = $asset['sources'][0];
        unlink($images->sourcePath($missing['src']));
        $response = $this->get($missing['src'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertTrue($response->baseResponse->headers->hasCacheControlDirective('no-store'));
        $corrupt = $asset['sources'][1];
        $path = $images->sourcePath($corrupt['src']);
        file_put_contents($path, str_repeat('x', filesize($path)));
        $response = $this->get($corrupt['src'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertTrue($response->baseResponse->headers->hasCacheControlDirective('no-store'));
        $repaired = $images->generate($url);
        $this->assertSame($asset['srcset'], $repaired['srcset']);
        foreach ($repaired['sources'] as $source) {
            $this->assertSame('image/webp', getimagesize($images->sourcePath($source['src']))['mime']);
        }
        $this->assertDatabaseCount('responsive_images', 1);
    }

    public function test_untrusted_urls_and_invalid_image_limits_are_rejected_without_metadata(): void
    {
        $images = app(ResponsiveImages::class);
        foreach (['https://example.test/a.jpg', 'file:///etc/passwd', '/images/../secret.jpg', '/images/arbitrary.jpg', '/images/strawberry.webp?x=1', '/anh/../../secret.jpg'] as $url) {
            $this->assertNull($images->sourcePath($url));
            $this->assertNull($images->asset($url)['srcset']);
        }
        foreach ([UploadedFile::fake()->image('wide.png', 5001, 1), UploadedFile::fake()->create('invalid.jpg', 1, 'image/jpeg')] as $upload) {
            try {
                app(AdminCatalog::class)->uploadedImage($upload);
                $this->fail('Invalid image must be rejected.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('responsive_images', 0);
            }
        }
    }

    public function test_page_lookup_does_not_generate_and_malformed_cache_falls_back_without_server_error(): void
    {
        $upload = UploadedFile::fake()->image('legacy.jpg', 800, 500);
        $path = $upload->store('bakery', 'public');
        $url = '/anh/'.basename($path);
        Route::get('/responsive-image-test', fn () => response()->json(app(ResponsiveImages::class)->asset($url)));
        $this->getJson('/responsive-image-test')->assertOk()->assertJsonPath('src', $url)->assertJsonPath('srcset', null);
        $this->assertDatabaseCount('responsive_images', 0);
        app(ResponsiveImages::class)->generate($url);
        DB::table('responsive_images')->where('source_url', $url)->update(['metadata' => json_encode(['width' => 'invalid', 'sources' => 'invalid'])]);
        $this->getJson('/responsive-image-test')->assertOk()->assertJsonPath('src', $url)->assertJsonPath('srcset', null)->assertJsonPath('width', null);
        $this->get($url)->assertOk();
    }

    public function test_cli_processes_whitelisted_seed_sources_idempotently_without_approving_menu(): void
    {
        $this->seed();
        $this->artisan('bakery:images', ['--missing' => true])->assertSuccessful();
        $this->assertDatabaseCount('responsive_images', 6);
        $files = Storage::disk('public')->files('bakery');
        $this->artisan('bakery:images', ['--rebuild' => true])->assertSuccessful();
        $this->assertSame($files, Storage::disk('public')->files('bakery'));
        $this->assertDatabaseCount('responsive_images', 6);
        $this->assertSame(0, DB::table('products')->where('images_approved', true)->count());
        $this->assertSame(0, DB::table('products')->where('details_verified', true)->count());
    }
}
