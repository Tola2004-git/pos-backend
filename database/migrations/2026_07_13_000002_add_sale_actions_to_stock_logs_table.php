<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Blueprint::change() compiles to a native MODIFY on MySQL and to
        // Laravel's built-in recreate-the-table strategy on SQLite (used by
        // the test suite) - the raw "ALTER TABLE ... MODIFY" this replaced
        // is MySQL-only syntax and fails outright against SQLite.
        Schema::table('stock_logs', function (Blueprint $table) {
            $table->enum('action', ['add', 'remove', 'sale', 'cancel_restore'])->change();
        });
    }

    public function down(): void
    {
        DB::statement("UPDATE stock_logs SET action = 'remove' WHERE action IN ('sale')");
        DB::statement("UPDATE stock_logs SET action = 'add' WHERE action IN ('cancel_restore')");
        Schema::table('stock_logs', function (Blueprint $table) {
            $table->enum('action', ['add', 'remove'])->change();
        });
    }
};
