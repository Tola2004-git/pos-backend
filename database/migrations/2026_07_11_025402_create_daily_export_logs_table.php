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
        Schema::create('daily_export_logs', function (Blueprint $table) {
            $table->id();
            $table->date('export_date')->unique();
            $table->string('file_path');
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_export_logs');
    }
};
