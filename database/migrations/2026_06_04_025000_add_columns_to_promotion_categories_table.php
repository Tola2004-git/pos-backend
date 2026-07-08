<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('promotion_categories', 'promotion_id')) {
                $table->unsignedBigInteger('promotion_id')->after('id');
                $table->foreign('promotion_id')->references('id')->on('promotions')->onDelete('cascade');
            }

            if (!Schema::hasColumn('promotion_categories', 'category_id')) {
                $table->unsignedBigInteger('category_id')->after('promotion_id');
                $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            }
        });
    }

    public function down(): void
    {
        Schema::table('promotion_categories', function (Blueprint $table) {
            if (Schema::hasColumn('promotion_categories', 'category_id')) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            }

            if (Schema::hasColumn('promotion_categories', 'promotion_id')) {
                $table->dropForeign(['promotion_id']);
                $table->dropColumn('promotion_id');
            }
        });
    }
};
