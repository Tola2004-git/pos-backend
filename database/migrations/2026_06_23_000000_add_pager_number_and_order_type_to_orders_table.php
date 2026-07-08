<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'pager_number')) {
                $table->string('pager_number')->nullable()->after('customer_phone');
            }
            if (!Schema::hasColumn('orders', 'order_type')) {
                $table->enum('order_type', ['takeaway', 'self-seating', 'dine-in'])->default('takeaway')->after('pager_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'order_type')) {
                $table->dropColumn('order_type');
            }
            if (Schema::hasColumn('orders', 'pager_number')) {
                $table->dropColumn('pager_number');
            }
        });
    }
};
