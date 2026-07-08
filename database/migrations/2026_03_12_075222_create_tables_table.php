<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('capacity')->default(4);
            $table->enum('status', ['available', 'occupied', 'reserved'])->default('available');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table){
            $table->foreignId('table_id')->nullable()->constrained('tables')->nullOnDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table){
            $table->dropForeign(['table_id']);
            $table->dropColumn('table_id');
        });
        Schema::dropIfExists('tables');
    }
};
