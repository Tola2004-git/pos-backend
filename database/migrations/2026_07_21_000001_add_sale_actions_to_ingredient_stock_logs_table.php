<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE ingredient_stock_logs MODIFY action ENUM('add', 'remove', 'sale', 'cancel_restore', 'refund_restore') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("UPDATE ingredient_stock_logs SET action = 'remove' WHERE action = 'sale'");
        DB::statement("UPDATE ingredient_stock_logs SET action = 'add' WHERE action IN ('cancel_restore', 'refund_restore')");
        DB::statement("ALTER TABLE ingredient_stock_logs MODIFY action ENUM('add', 'remove') NOT NULL");
    }
};
