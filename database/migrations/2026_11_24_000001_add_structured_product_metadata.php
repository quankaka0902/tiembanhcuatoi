<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('occasions')->nullable();
            $table->json('flavors')->nullable();
            $table->text('storage_instructions')->nullable();
            $table->boolean('details_verified')->default(false);
            $table->boolean('images_approved')->default(false);
        });
        Schema::table('variants', function (Blueprint $table) {
            $table->string('size_label', 80)->nullable();
            $table->unsignedSmallInteger('servings_min')->nullable();
            $table->unsignedSmallInteger('servings_max')->nullable();
            $table->boolean('servings_verified')->default(false);
        });
        Schema::create('product_accessories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('price');
            $table->boolean('active')->default(true);
            $table->boolean('approved')->default(false);
            $table->timestamps();
            $table->index(['product_id', 'active', 'approved']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->json('accessories_snapshot')->nullable();
            $table->unsignedInteger('accessory_fee')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['accessories_snapshot', 'accessory_fee']));
        Schema::dropIfExists('product_accessories');
        Schema::table('variants', fn (Blueprint $table) => $table->dropColumn(['size_label', 'servings_min', 'servings_max', 'servings_verified']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn([
            'occasions', 'flavors', 'storage_instructions', 'details_verified', 'images_approved',
        ]));
    }
};
