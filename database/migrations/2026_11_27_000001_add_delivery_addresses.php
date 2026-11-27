<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->text('delivery_snapshot')->nullable());
        Schema::create('customer_delivery_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('encrypted_payload');
            $table->timestamp('consented_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $connection = DB::connection();
        $isolated = ($connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:')
            || ($connection->getDriverName() === 'mysql' && str_ends_with($connection->getDatabaseName(), '_test'));
        if (! app()->environment('testing') || ! $isolated || $connection->getConfig('url')) {
            throw new RuntimeException('Giữ bản chụp và sổ địa chỉ. Quay lui vận hành cần kế hoạch bảo toàn dữ liệu đã duyệt.');
        }
        Schema::dropIfExists('customer_delivery_addresses');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('delivery_snapshot'));
    }
};
