<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cashier_cash_movements', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cashier_cash_movements', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });
    }
};
