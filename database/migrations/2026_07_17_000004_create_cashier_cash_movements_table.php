<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['cash_in', 'cash_out']);
            $table->decimal('amount_usd', 12, 2)->default(0);
            $table->decimal('amount_khr', 14, 2)->default(0);
            $table->string('reason');

            $table->timestamps();

            $table->index(['cashier_shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_cash_movements');
    }
};
