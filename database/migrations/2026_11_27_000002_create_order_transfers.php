<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('open_order_id')->nullable()->unique()->constrained('orders');
            $table->foreignId('from_branch_id')->constrained('branches');
            $table->foreignId('to_branch_id')->constrained('branches');
            $table->foreignId('proposed_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('executed_by')->nullable()->constrained('users');
            $table->string('status', 20)->default('pending');
            $table->string('request_key', 64)->unique();
            $table->string('request_payload_hash', 64);
            $table->string('source_fingerprint', 64);
            $table->string('quote_fingerprint', 64);
            $table->longText('encrypted_snapshot');
            $table->string('reason', 250);
            $table->string('approval_token_hash', 64)->nullable()->unique();
            $table->dateTime('approval_expires_at')->nullable();
            $table->string('approval_request_key', 64)->nullable();
            $table->dateTime('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['from_branch_id', 'status']);
        });
        Schema::create('order_transfer_commands', function (Blueprint $table) {
            $table->string('request_key', 64)->primary();
            $table->foreignId('transfer_id')->constrained('order_transfers');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('action', 20);
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        $connection = DB::connection();
        $isolated = ($connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:')
            || ($connection->getDriverName() === 'mysql' && str_ends_with($connection->getDatabaseName(), '_test'));
        if (! app()->environment('testing') || ! $isolated || $connection->getConfig('url')) {
            throw new RuntimeException('Giữ lịch sử chuyển cơ sở. Quay lui vận hành cần kế hoạch bảo toàn dữ liệu đã duyệt.');
        }
        Schema::dropIfExists('order_transfer_commands');
        Schema::dropIfExists('order_transfers');
    }
};
