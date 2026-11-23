<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_key', 36);
            $table->string('payload_hash', 64);
            $table->string('kind', 16);
            $table->string('status', 16)->default('pending');
            $table->string('previous_status', 16);
            $table->dateTime('previous_slot');
            $table->dateTime('requested_slot')->nullable();
            $table->string('reason', 200);
            $table->string('response_message', 200)->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('handled_at')->nullable();
            // Nullable unique key enforces one open request per order on MySQL and SQLite.
            $table->unsignedBigInteger('open_order_id')->nullable()->unique();
            $table->timestamps();
            $table->unique(['order_id', 'request_key']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_action_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('order_change_request_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('created_at');
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_action_links');
        Schema::dropIfExists('order_change_requests');
    }
};
