<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('order_events')->nullOnDelete();
            $table->string('event_key', 64)->unique();
            $table->string('type', 40);
            $table->string('recipient');
            $table->string('subject');
            // Contains private tracking/action links; never store their plaintext in the queue.
            $table->text('encrypted_body');
            $table->string('message_id', 100)->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 100)->nullable();
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notifications');
    }
};
