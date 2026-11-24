<?php

use App\Services\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->text('search_text')->nullable());
        DB::table('products')->orderBy('id')->chunkById(100, function ($products) {
            foreach ($products as $product) {
                DB::table('products')->where('id', $product->id)->update(['search_text' => Catalog::searchText(
                    $product->name, $product->description, json_decode($product->occasions ?? '[]', true) ?? [], json_decode($product->flavors ?? '[]', true) ?? [],
                )]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('search_text'));
    }
};
