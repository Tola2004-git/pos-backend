<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('amount_paid_usd', 10, 2)->default(0)->after('amount_paid');
            $table->decimal('amount_paid_khr', 14, 2)->default(0)->after('amount_paid_usd');
            $table->decimal('exchange_rate_used', 10, 2)->nullable()->after('amount_paid_khr');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['amount_paid_usd', 'amount_paid_khr', 'exchange_rate_used']);
        });
    }
};
