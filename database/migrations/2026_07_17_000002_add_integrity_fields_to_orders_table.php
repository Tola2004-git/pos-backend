<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('refunded_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable()->after('refunded_by');
            $table->text('refund_reason')->nullable()->after('refunded_at');

            // Lets the client safely retry a checkout request (network error,
            // double-click bypassing the UI guard, duplicate tab) without a
            // second identical order being created server-side.
            $table->string('idempotency_key')->nullable()->unique()->after('order_number');

            $table->unsignedInteger('receipt_reprint_count')->default(0)->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn(['refunded_at', 'refund_reason', 'idempotency_key', 'receipt_reprint_count']);
        });
    }
};
