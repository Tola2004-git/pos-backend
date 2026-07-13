<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE stock_logs MODIFY action ENUM('add', 'remove', 'sale', 'cancel_restore') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("UPDATE stock_logs SET action = 'remove' WHERE action IN ('sale')");
        DB::statement("UPDATE stock_logs SET action = 'add' WHERE action IN ('cancel_restore')");
        DB::statement("ALTER TABLE stock_logs MODIFY action ENUM('add', 'remove') NOT NULL");
    }
};
