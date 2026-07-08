<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            if (!Schema::hasColumn('promotions', 'name')) {
                $table->string('name')->after('id');
            }

            if (!Schema::hasColumn('promotions', 'type')) {
                $table->enum('type', ['percentage', 'fixed', 'bogo'])->default('percentage')->after('name');
            }

            if (!Schema::hasColumn('promotions', 'value')) {
                $table->decimal('value', 8, 2)->default(0)->after('type');
            }

            if (!Schema::hasColumn('promotions', 'apply_to')) {
                $table->enum('apply_to', ['all', 'product', 'category'])->default('all')->after('value');
            }

            if (!Schema::hasColumn('promotions', 'min_purchase')) {
                $table->decimal('min_purchase', 10, 2)->nullable()->after('apply_to');
            }

            if (!Schema::hasColumn('promotions', 'start_date')) {
                $table->date('start_date')->nullable()->after('min_purchase');
            }

            if (!Schema::hasColumn('promotions', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }

            if (!Schema::hasColumn('promotions', 'status')) {
                $table->boolean('status')->default(true)->after('end_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            // drop columns only if they exist
            $cols = [];
            if (Schema::hasColumn('promotions', 'status')) $cols[] = 'status';
            if (Schema::hasColumn('promotions', 'end_date')) $cols[] = 'end_date';
            if (Schema::hasColumn('promotions', 'start_date')) $cols[] = 'start_date';
            if (Schema::hasColumn('promotions', 'min_purchase')) $cols[] = 'min_purchase';
            if (Schema::hasColumn('promotions', 'apply_to')) $cols[] = 'apply_to';
            if (Schema::hasColumn('promotions', 'value')) $cols[] = 'value';
            if (Schema::hasColumn('promotions', 'type')) $cols[] = 'type';
            if (Schema::hasColumn('promotions', 'name')) $cols[] = 'name';

            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
