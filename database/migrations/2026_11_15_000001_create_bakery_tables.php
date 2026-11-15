<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 15)->default('customer');
            $table->boolean('active')->default(true);
            $table->boolean('can_reconcile')->default(false);
        });

        Schema::create('store_settings', function (Blueprint $table) {
            $table->id();
            $table->json('data');
        });
        DB::table('store_settings')->insert(['id' => 1, 'data' => json_encode(config('bakery.defaults'), JSON_THROW_ON_ERROR)]);

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('icon', 20)->default('cake');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained();
            $table->string('name', 150);
            $table->string('slug', 170)->unique();
            $table->text('description');
            $table->text('ingredients')->nullable();
            $table->text('allergens')->nullable();
            $table->json('images');
            $table->unsignedSmallInteger('prep_minutes')->default(60);
            $table->boolean('customizable')->default(false);
            $table->unsignedInteger('message_fee')->default(0);
            $table->boolean('featured')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->string('name', 100);
            $table->unsignedInteger('price');
            $table->unsignedInteger('stock');
            $table->boolean('active')->default(true);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('kind', 12);
            $table->unsignedInteger('amount');
            $table->unsignedInteger('minimum')->default(0);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used')->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('active')->default(true);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 22)->unique();
            $table->string('tracking_token', 64)->unique();
            $table->string('request_key', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained();
            $table->string('coupon_code', 32)->nullable();
            $table->string('name', 120);
            $table->string('email');
            $table->string('phone', 20);
            $table->string('fulfillment', 15);
            $table->string('address')->nullable();
            $table->string('region_label', 100)->nullable();
            $table->dateTime('scheduled_at');
            $table->string('method', 12);
            $table->string('payment', 16)->default('unpaid');
            $table->string('status', 16)->default('pending');
            $table->json('bank_snapshot')->nullable();
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('delivery_fee')->default(0);
            $table->unsignedBigInteger('total');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('stock_restored_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained();
            $table->string('product_name', 150);
            $table->string('variant_name', 100);
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('option_fee')->default(0);
            $table->unsignedSmallInteger('quantity');
            $table->string('message', 60)->nullable();
            $table->string('note', 250)->nullable();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 12);
            $table->string('value', 16);
            $table->string('note', 250)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['order_events', 'order_items', 'orders', 'coupons', 'variants', 'products', 'categories', 'store_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'active', 'can_reconcile']));
    }
};
