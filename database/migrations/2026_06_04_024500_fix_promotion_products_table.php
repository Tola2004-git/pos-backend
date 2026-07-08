<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('promotion_products')) {
            Schema::create('promotion_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('promotion_id')->constrained('promotions')->onDelete('cascade');
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->timestamps();
            });
            return;
        }

        Schema::table('promotion_products', function (Blueprint $table) {
            if (!Schema::hasColumn('promotion_products', 'promotion_id')) {
                $table->unsignedBigInteger('promotion_id')->after('id');
                $table->foreign('promotion_id')->references('id')->on('promotions')->onDelete('cascade');
            }

            if (!Schema::hasColumn('promotion_products', 'product_id')) {
                $table->unsignedBigInteger('product_id')->after('promotion_id');
                $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            }
        });
    }

    public function down(): void
    {
        Schema::table('promotion_products', function (Blueprint $table) {
            if (Schema::hasColumn('promotion_products', 'product_id')) {
                $table->dropForeign(['product_id']);
                $table->dropColumn('product_id');
            }
            if (Schema::hasColumn('promotion_products', 'promotion_id')) {
                $table->dropForeign(['promotion_id']);
                $table->dropColumn('promotion_id');
            }
        });

        if (Schema::hasTable('promotion_products')) {
            // do not drop table entirely in down to avoid data loss
        }
    }
};
