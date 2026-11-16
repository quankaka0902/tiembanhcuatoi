<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('bank_due_at')->nullable();
            $table->index(['method', 'payment', 'bank_due_at']);
        });

        DB::table('orders')->where('method', 'bank')->select('id', 'created_at', 'scheduled_at')
            ->chunkById(250, function ($orders) {
                foreach ($orders as $order) {
                    $deadline = CarbonImmutable::parse($order->created_at ?? $order->scheduled_at)->addMinutes(60)->toDateTimeString();
                    DB::table('orders')->where('id', $order->id)->update(['bank_due_at' => min($deadline, $order->scheduled_at)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['method', 'payment', 'bank_due_at']);
            $table->dropColumn('bank_due_at');
        });
    }
};
