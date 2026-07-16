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
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            $table->decimal('opening_cash_usd', 12, 2)->default(0);
            $table->decimal('opening_cash_khr', 14, 2)->default(0);

            // Filled in at close time: opening float + cash sales recorded
            // during the shift window, vs what the cashier actually counted.
            $table->decimal('expected_cash_usd', 12, 2)->nullable();
            $table->decimal('expected_cash_khr', 14, 2)->nullable();
            $table->decimal('counted_cash_usd', 12, 2)->nullable();
            $table->decimal('counted_cash_khr', 14, 2)->nullable();
            $table->decimal('variance_usd', 12, 2)->nullable();
            $table->decimal('variance_khr', 14, 2)->nullable();

            $table->text('note')->nullable();
            $table->enum('status', ['open', 'pending_review', 'reviewed'])->default('open');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cashier_shifts');
    }
};
