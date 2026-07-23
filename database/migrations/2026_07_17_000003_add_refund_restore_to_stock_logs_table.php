<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_logs', function (Blueprint $table) {
            $table->enum('action', ['add', 'remove', 'sale', 'cancel_restore', 'refund_restore'])->change();
        });
    }

    public function down(): void
    {
        DB::statement("UPDATE stock_logs SET action = 'cancel_restore' WHERE action = 'refund_restore'");
        Schema::table('stock_logs', function (Blueprint $table) {
            $table->enum('action', ['add', 'remove', 'sale', 'cancel_restore'])->change();
        });
    }
};
