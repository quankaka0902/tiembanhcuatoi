<?php

namespace Tests\Feature;

use App\Jobs\SendOrderEmail;
use App\Services\OrderNotices;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OrderNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bakery.demo_seed' => true]);
        $this->seed();
    }

    public function test_event_notification_is_immutable_and_deduplicated_on_retry(): void
    {
        $order = DB::table('orders')->where('code', 'NB-DEMO001')->first();
        $payload = ['old_slot' => '2026-11-25 09:00', 'new_slot' => '2026-11-26 11:00'];
        $id = OrderNotices::record($order->id, 'schedule', $payload, dedupeKey: 'change:one');
        $same = OrderNotices::record($order->id, 'schedule', $payload, dedupeKey: 'change:one');
        $this->assertSame($id, $same);
        $this->assertDatabaseCount('order_notifications', 1);
        $this->assertDatabaseCount('jobs', 1);
        DB::table('orders')->where('id', $order->id)->update(['scheduled_at' => '2026-12-01 14:00', 'status' => 'cancelled']);
        $job = new SendOrderEmail($order->id, null, $id);
        $job->handle();
        $job->handle();
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $mail = $messages->sole()->getOriginalMessage();
        $this->assertStringContainsString('09:00 25/11/2026', $mail->getTextBody());
        $this->assertStringContainsString('11:00 26/11/2026', $mail->getTextBody());
        $this->assertStringNotContainsString('01/12/2026', $mail->getTextBody());
        $notice = DB::table('order_notifications')->find($id);
        $this->assertNotNull($notice->sent_at);
        $this->assertSame(1, $notice->attempts);
        $this->assertSame($notice->message_id, $mail->getHeaders()->get('Message-ID')->getId());
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled', 'scheduled_at' => '2026-12-01 14:00']);
    }

    public function test_notification_and_queue_roll_back_with_order_transaction(): void
    {
        $order = DB::table('orders')->first();
        DB::beginTransaction();
        try {
            OrderNotices::record($order->id, 'cancelled', dedupeKey: 'cancel:rollback');
            $this->assertDatabaseCount('order_notifications', 1);
            $this->assertDatabaseCount('jobs', 1);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('order_notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_mail_failure_can_be_retried_without_changing_order_or_exposing_failure_details(): void
    {
        $order = DB::table('orders')->first();
        $id = OrderNotices::record($order->id, 'payment_paid', dedupeKey: 'payment:one');
        $job = new SendOrderEmail($order->id, null, $id);
        Event::listen(MessageSending::class, fn () => throw new RuntimeException('private-link-and-recipient'));
        try {
            $job->handle();
            $this->fail('Mail transport should fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('private-link-and-recipient', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertDatabaseHas('order_notifications', ['id' => $id, 'sent_at' => null, 'attempts' => 1, 'last_error' => 'RuntimeException']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => $order->status, 'payment' => $order->payment]);
        Event::forget(MessageSending::class);
        $job->handle();
        $job->handle();
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        $notice = DB::table('order_notifications')->find($id);
        $this->assertNotNull($notice->sent_at);
        $this->assertSame(2, $notice->attempts);
        $this->assertNull($notice->last_error);
    }

    public function test_guest_action_link_is_encrypted_in_storage_and_absent_from_job_payload(): void
    {
        $order = DB::table('orders')->first();
        $token = bin2hex(random_bytes(32));
        $id = OrderNotices::actionLink($order->id, $token, now()->addMinutes(15));
        $this->assertSame($id, OrderNotices::actionLink($order->id, $token, now()->addMinutes(15)));
        $notice = DB::table('order_notifications')->find($id);
        $this->assertStringNotContainsString($token, json_encode((array) $notice));
        $this->assertStringContainsString('/thao-tac-don/'.$token, Crypt::decryptString($notice->encrypted_body));
        $this->assertStringNotContainsString($token, DB::table('jobs')->value('payload'));
        (new SendOrderEmail($order->id, null, $id))->handle();
        $mail = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame($order->email, $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('/thao-tac-don/'.$token, $mail->getTextBody());
        $this->assertStringContainsString('dùng một lần', $mail->getTextBody());
    }

    public function test_cancelled_paid_order_notice_does_not_claim_refund_and_does_not_leak_internal_note(): void
    {
        $order = DB::table('orders')->first();
        DB::table('orders')->where('id', $order->id)->update(['status' => 'cancelled', 'payment' => 'paid']);
        $id = OrderNotices::record($order->id, 'cancelled', ['note' => 'private staff reasoning'], dedupeKey: 'cancel:paid');
        (new SendOrderEmail($order->id, null, $id))->handle();
        $body = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString('đang chờ tiệm xử lý hoàn tiền', $body);
        $this->assertStringContainsString('chưa phải xác nhận đã hoàn tiền', $body);
        $this->assertStringNotContainsString('private staff reasoning', $body);
    }

    public function test_legacy_tracking_resend_can_be_sent_again_but_same_job_is_not_sent_twice(): void
    {
        $order = DB::table('orders')->first();
        $first = new SendOrderEmail($order->id);
        $first->handle();
        $first->handle();
        (new SendOrderEmail($order->id))->handle();
        $this->assertCount(2, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('order_notifications', 2);
        // Handling an old-style job does not enqueue a redundant copy of itself.
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_request_response_is_delivered_without_internal_notes(): void
    {
        $order = DB::table('orders')->first();
        $id = OrderNotices::record($order->id, 'request_rejected', [
            'kind' => 'reschedule', 'response_message' => 'Khung giờ đã hết chỗ, vui lòng liên hệ tiệm.',
            'note' => 'private decision audit',
        ], dedupeKey: 'request:reply');
        (new SendOrderEmail($order->id, null, $id))->handle();
        $body = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString('Khung giờ đã hết chỗ, vui lòng liên hệ tiệm.', $body);
        $this->assertStringNotContainsString('private decision audit', $body);
    }

    public function test_retry_of_job_from_previous_release_uses_stable_queue_uuid(): void
    {
        $order = DB::table('orders')->first();
        foreach (['old-database-row', 'new-row-after-queue-retry'] as $rowId) {
            $job = new SendOrderEmail($order->id);
            unset($job->deliveryKey);
            $queueJob = Mockery::mock(QueueJob::class);
            $queueJob->shouldReceive('uuid')->andReturn('stable-original-payload-uuid');
            $queueJob->shouldReceive('getJobId')->zeroOrMoreTimes()->andReturn($rowId);
            $job->setJob($queueJob);
            $job->handle();
        }
        $this->assertDatabaseCount('order_notifications', 1);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_suppressed_message_is_not_marked_delivered(): void
    {
        $order = DB::table('orders')->first();
        $id = OrderNotices::record($order->id, 'request_created', ['kind' => 'cancel'], dedupeKey: 'request:suppressed');
        Event::listen(MessageSending::class, fn () => false);
        try {
            (new SendOrderEmail($order->id, null, $id))->handle();
            $this->fail('Suppressed mail must remain retryable.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('order_notifications', ['id' => $id, 'sent_at' => null, 'attempts' => 1]);
            $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        }
    }
}
