<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('courier_id')->constrained('users');
            $table->string('code', 36)->unique();
            $table->json('order_ids');
            $table->json('order_codes');
            $table->string('status', 20)->default('assigned');
            $table->unsignedInteger('version')->default(1);
            $table->string('adapter', 20)->default('manual');
            $table->string('adapter_reference', 80)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });
        Schema::create('fulfillment_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->string('kind', 12);
            $table->string('status', 20)->default('pending');
            $table->foreignId('trip_id')->nullable()->constrained('delivery_trips');
            $table->foreignId('courier_id')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->boolean('legacy_inferred')->default(false);
            foreach (['ready_at', 'started_at', 'delivered_at', 'failed_at', 'cancelled_at', 'collected_at'] as $field) {
                $table->timestamp($field)->nullable();
            }
            $table->timestamps();
            $table->index(['branch_id', 'status', 'courier_id']);
        });
        Schema::create('fulfillment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('fulfillment_jobs');
            $table->unsignedInteger('sequence');
            $table->foreignId('trip_id')->nullable()->constrained('delivery_trips');
            $table->foreignId('courier_id')->nullable()->constrained('users');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('outcome', 20);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at');
            $table->text('evidence')->nullable();
            $table->string('reason', 250)->nullable();
            $table->unique(['job_id', 'sequence']);
        });
        Schema::create('fulfillment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('fulfillment_jobs');
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->foreignId('trip_id')->nullable()->constrained('delivery_trips');
            $table->foreignId('courier_id')->nullable()->constrained('users');
            $table->string('action', 32);
            $table->string('previous_status', 20)->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('version');
            $table->string('reason', 250)->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('dispatch_commands', function (Blueprint $table) {
            $table->id();
            $table->string('request_key', 64)->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('kind', 32);
            $table->string('payload_hash', 64);
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        $database = DB::getDatabaseName();
        if (! app()->environment('testing') || DB::connection()->getConfig('url')
            || ! (($driver === 'sqlite' && $database === ':memory:') || ($driver === 'mysql' && str_ends_with($database, '_test')))) {
            throw new RuntimeException('Giữ lịch sử điều phối; cần kế hoạch phục hồi đã duyệt để bỏ schema.');
        }
        foreach (['dispatch_commands', 'fulfillment_events', 'fulfillment_attempts', 'fulfillment_jobs', 'delivery_trips'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
