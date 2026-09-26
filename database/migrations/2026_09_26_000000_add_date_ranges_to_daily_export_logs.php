<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_export_logs', function (Blueprint $table) {
            $table->date('date_from')->nullable()->after('export_date');
            $table->date('date_to')->nullable()->after('date_from');
            $table->dropUnique(['export_date']);
            $table->unique(['date_from', 'date_to']);
        });

        DB::table('daily_export_logs')->whereNull('date_from')->update([
            'date_from' => DB::raw('export_date'),
            'date_to' => DB::raw('export_date'),
        ]);
    }

    public function down(): void
    {
        Schema::table('daily_export_logs', function (Blueprint $table) {
            $table->dropUnique(['date_from', 'date_to']);
            $table->dropColumn(['date_from', 'date_to']);
            $table->unique('export_date');
        });
    }
};