<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->default(1)->index();
            $table->unsignedInteger('prep_minutes_snapshot')->nullable();
            $table->boolean('bank_prepay_required')->nullable();
            $table->timestamp('prepayment_waived_at')->nullable();
            $table->timestamp('production_started_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->boolean('inventory_review_required')->default(false);
            $table->timestamp('inventory_processed_at')->nullable();
            $table->timestamp('coupon_released_at')->nullable();
            $table->json('refund_evidence')->nullable();
        });
        // Historical cancellations stay closed. Never replay their stock/coupon effects.
        DB::table('orders')->where('status', 'cancelled')->update(['inventory_processed_at' => DB::raw('updated_at')]);
        DB::table('orders')->where('status', 'cancelled')->whereNotNull('stock_restored_at')
            ->update(['coupon_released_at' => DB::raw('stock_restored_at')]);
        DB::table('orders')->whereIn('status', ['shipping', 'delivery_failed'])->update(['dispatched_at' => DB::raw('updated_at')]);
        Schema::table('order_events', function (Blueprint $table) {
            $table->string('kind', 32)->change();
            $table->string('value', 80)->change();
            $table->text('note')->nullable()->change();
            $table->string('previous_value', 80)->nullable();
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedInteger('affected_quantity')->nullable();
        });
        Schema::create('order_inventory_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('order_item_id')->unique()->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->unsignedInteger('unmade');
            $table->unsignedInteger('sellable');
            $table->unsignedInteger('waste');
            $table->string('reason', 250);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_inventory_dispositions');
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('affected_quantity'));
        Schema::table('order_events', fn (Blueprint $table) => $table->dropColumn('previous_value'));
        // Expanded audit fields intentionally remain wide to retain existing history.
        Schema::table('orders', fn (Blueprint $table) => $table->dropIndex(['location_id']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn([
            'location_id', 'prep_minutes_snapshot', 'bank_prepay_required', 'prepayment_waived_at',
            'production_started_at', 'dispatched_at', 'inventory_review_required', 'inventory_processed_at', 'coupon_released_at', 'refund_evidence',
        ]));
    }
};
