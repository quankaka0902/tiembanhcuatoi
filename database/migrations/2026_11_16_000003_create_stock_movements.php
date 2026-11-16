<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 16);
            $table->unsignedInteger('stock_before');
            $table->unsignedInteger('stock_after');
            $table->string('note', 250)->nullable();
            $table->timestamp('created_at')->index();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'payment', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'payment', 'completed_at']);
        });

        Schema::dropIfExists('stock_movements');
    }
};
