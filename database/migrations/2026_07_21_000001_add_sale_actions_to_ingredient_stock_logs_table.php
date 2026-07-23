<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredient_stock_logs', function (Blueprint $table) {
            $table->enum('action', ['add', 'remove', 'sale', 'cancel_restore', 'refund_restore'])->change();
        });
    }

    public function down(): void
    {
        DB::statement("UPDATE ingredient_stock_logs SET action = 'remove' WHERE action = 'sale'");
        DB::statement("UPDATE ingredient_stock_logs SET action = 'add' WHERE action IN ('cancel_restore', 'refund_restore')");
        Schema::table('ingredient_stock_logs', function (Blueprint $table) {
            $table->enum('action', ['add', 'remove'])->change();
        });
    }
};
