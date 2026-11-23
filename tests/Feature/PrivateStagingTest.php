<?php

namespace Tests\Feature;

use App\Services\LaunchReadiness;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PrivateStagingTest extends TestCase
{
    private const ORIGIN = 'https://staging.example.test';

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'staging';
        $this->password = bin2hex(random_bytes(32));
        config([
            'app.url' => self::ORIGIN,
            'app.debug' => false,
            'bakery.staging.password' => $this->password,
            'bakery.staging.recipients' => ['qa@example.test'],
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'bakery_rehearsal_test',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.require_tls' => true,
            'mail.mailers.smtp.url' => null,
        ]);
    }

    public function test_staging_requires_https_correct_host_and_basic_auth_before_access(): void
    {
        $this->get(self::ORIGIN.'/up')->assertUnauthorized()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get(self::ORIGIN.'/dang-nhap')->assertUnauthorized();
        $this->withBasicAuth('staging', 'incorrect')->get(self::ORIGIN.'/up')->assertUnauthorized();

        $this->withBasicAuth('staging', $this->password)->get('http://staging.example.test/up')->assertForbidden();
        $this->get('https://wrong.example.test/up')->assertForbidden();
        $this->get(self::ORIGIN.'/up')->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        config(['database.connections.mysql.url' => 'mysql://test@localhost/bakery_live']);
        $this->app['db']->purge('mysql');
        $this->get(self::ORIGIN.'/up')->assertServiceUnavailable();
        $this->artisan('bakery:preflight', ['--staging' => true])->expectsOutputToContain('_test')->assertFailed();
    }

    public function test_staging_delivers_only_when_all_recipients_and_transport_are_approved(): void
    {
        $smtp = new ArrayTransport;
        Mail::mailer('smtp')->setSymfonyTransport($smtp);
        Mail::raw('Thư thử', fn ($message) => $message->to('qa@example.test'));
        Mail::raw('Địa chỉ ngoài danh sách', fn ($message) => $message->to('outside@example.test'));
        Mail::raw('Đồng gửi ngoài danh sách', fn ($message) => $message->to('qa@example.test')->cc('outside@example.test'));
        Mail::raw('Đồng gửi ẩn ngoài danh sách', fn ($message) => $message->to('qa@example.test')->bcc('outside@example.test'));
        Mail::mailer('array')->raw('Kênh gửi khác', fn ($message) => $message->to('qa@example.test'));
        $this->assertCount(1, $smtp->messages());
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());

        config(['bakery.staging.recipients' => ['qa@example.test', 'invalid']]);
        Mail::raw('Danh sách không hợp lệ', fn ($message) => $message->to('qa@example.test'));
        $this->assertCount(1, $smtp->messages());

        config(['bakery.staging.recipients' => ['qa@example.test'], 'mail.mailers.smtp.require_tls' => false]);
        Mail::raw('Thiếu TLS', fn ($message) => $message->to('qa@example.test'));
        $this->assertCount(1, $smtp->messages());
    }

    public function test_preflight_rejects_unsafe_session_cookie_configuration(): void
    {
        config([
            'bakery.demo_seed' => false,
            'bakery.rehearsal' => false,
            'session.secure' => true,
            'session.encrypt' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.domain' => null,
            'mail.from.address' => 'acceptance@bakery.example.com',
        ]);
        $this->assertSame([], LaunchReadiness::configurationIssues());

        $cookieError = 'Cấu hình cookie phiên HttpOnly và SameSite=lax hoặc strict.';
        config(['session.http_only' => false]);
        $this->assertContains($cookieError, LaunchReadiness::configurationIssues());

        config(['session.http_only' => true]);
        foreach ([null, 'none'] as $sameSite) {
            config(['session.same_site' => $sameSite]);
            $this->assertContains($cookieError, LaunchReadiness::configurationIssues());
        }

        config(['session.same_site' => 'strict']);
        $this->assertSame([], LaunchReadiness::configurationIssues());

        $secureError = 'Cấu hình APP_URL HTTPS, SESSION_SECURE_COOKIE=true và SESSION_ENCRYPT=true.';
        config(['session.secure' => false]);
        $this->assertContains($secureError, LaunchReadiness::configurationIssues());

        config(['session.secure' => true, 'session.encrypt' => false]);
        $this->assertContains($secureError, LaunchReadiness::configurationIssues());
    }

    public function test_preflight_requires_an_effective_smtp_host_and_accepts_a_connection_url(): void
    {
        $this->app['env'] = 'production';
        config([
            'bakery.demo_seed' => false, 'bakery.rehearsal' => false,
            'session.secure' => true, 'session.encrypt' => true,
            'session.http_only' => true, 'session.same_site' => 'lax',
            'mail.from.address' => 'acceptance@bakery.example.com',
            'mail.mailers.smtp.host' => '',
        ]);
        $message = 'Cấu hình SMTP và địa chỉ gửi thư của cửa hàng.';
        $this->assertContains($message, LaunchReadiness::configurationIssues());
        config(['mail.mailers.smtp.url' => 'smtps://mail.example.test:465']);
        $this->assertSame([], LaunchReadiness::configurationIssues());
    }
}
