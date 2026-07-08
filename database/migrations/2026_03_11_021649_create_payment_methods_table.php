<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');          // Cash, QR Code, Credit Card, Bank Transfer
            $table->string('icon')->nullable();         // emoji icon
            $table->string('description')->nullable();
            $table->string('bank_name')->nullable();    // ABA, ACLEDA, Wing...
            $table->string('account_number')->nullable(); // 000123456
            $table->string('account_name')->nullable();   // John Doe
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
