<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('slug', 120)->unique();
            $table->string('name', 150);
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
            $table->boolean('active')->default(false);
            $table->json('settings');
            $table->timestamps();
        });
        Schema::create('chain_settings', function (Blueprint $table) {
            $table->id();
            $table->json('data');
        });
        Schema::create('branch_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('variant_id')->constrained();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('price')->nullable();
            $table->unsignedSmallInteger('prep_minutes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'variant_id']);
        });
        Schema::create('branch_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('role', 20)->default('staff');
            $table->boolean('active')->default(true);
            $table->boolean('can_reconcile')->default(false);
            $table->timestamps();
            $table->unique(['branch_id', 'user_id']);
        });
        Schema::create('branch_slot_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->dateTime('scheduled_at');
            $table->unsignedInteger('booked')->default(0);
            $table->unique(['branch_id', 'scheduled_at']);
        });
        Schema::create('checkout_request_locks', function (Blueprint $table) {
            $table->string('request_key', 64)->primary();
            $table->foreignId('branch_id')->constrained();
            $table->timestamp('created_at');
        });
        Schema::create('branch_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->string('action', 80);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->index();
        });
        Schema::create('branch_migration_state', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 20)->default('legacy');
            $table->timestamp('verified_at')->nullable();
            $table->json('baseline')->nullable();
            $table->json('verification')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->json('branch_snapshot')->nullable();
            $table->json('sales_context')->nullable();
        });
        Schema::table('stock_movements', fn (Blueprint $table) => $table->foreignId('branch_id')->nullable()->constrained());
        Schema::table('coupons', fn (Blueprint $table) => $table->foreignId('branch_id')->nullable()->constrained());
        Schema::table('users', fn (Blueprint $table) => $table->unsignedInteger('authorization_version')->default(0));

        $settings = json_decode(DB::table('store_settings')->where('id', 1)->value('data') ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        DB::table('branches')->insert([
            'id' => 1, 'code' => 'DEFAULT', 'slug' => 'co-so-chinh',
            'name' => $settings['name'] ?? 'Cơ sở hiện tại', 'timezone' => 'Asia/Ho_Chi_Minh',
            'active' => true, 'settings' => json_encode(['accepting_orders' => false], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $brandKeys = ['name', 'logo', 'logo_approved', 'about_text', 'faq_items', 'public_content_approved', 'order_policy', 'privacy_notice'];
        DB::table('chain_settings')->insert(['id' => 1, 'data' => json_encode(array_intersect_key($settings, array_flip($brandKeys)), JSON_THROW_ON_ERROR)]);
        DB::table('branch_migration_state')->insert(['id' => 1, 'mode' => 'legacy', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Test teardown may contract only disposable, explicitly isolated databases.
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $database = $connection->getDatabaseName();
        $isolated = ($driver === 'sqlite' && $database === ':memory:')
            || ($driver === 'mysql' && str_ends_with($database, '_test'));
        if (! app()->environment('testing') || ! $isolated || $connection->getConfig('url')) {
            throw new RuntimeException('Không xóa schema cơ sở đã mở rộng. Dùng bakery:branches --rollback-read hoặc kế hoạch phục hồi đã duyệt.');
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['branch_snapshot', 'sales_context']);
        });
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('branch_id'));
        Schema::table('coupons', fn (Blueprint $table) => $table->dropConstrainedForeignId('branch_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('authorization_version'));
        foreach (['branch_audits', 'checkout_request_locks', 'branch_slot_locks', 'branch_memberships', 'branch_variants', 'branch_migration_state', 'chain_settings', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
