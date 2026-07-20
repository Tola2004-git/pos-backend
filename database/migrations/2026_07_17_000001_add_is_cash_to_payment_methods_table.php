<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('is_cash')->default(false)->after('name');
        });

        // Backfill from the existing name-based convention so shift
        // reconciliation keeps working for methods that already exist.
        DB::table('payment_methods')
            ->whereRaw('LOWER(name) LIKE ?', ['%cash%'])
            ->update(['is_cash' => true]);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('is_cash');
        });
    }
};
