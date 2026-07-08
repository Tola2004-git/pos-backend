<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('payment_methods')->updateOrInsert(
            ['name' => 'Cash'],
            [
                'icon' => '💵',
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Ensure ABA bank exists and replace any legacy 'QR Code' entry
        DB::table('payment_methods')->updateOrInsert(
            ['name' => 'ABA'],
            [
                'icon' => null,
                'logo' => '/assets/banks/aba.jpeg',
                'bank_name' => 'ABA',
                'description' => 'ABA Bank',
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // If there is an existing record named 'QR Code', update it to ABA
        DB::table('payment_methods')
            ->where('name', 'QR Code')
            ->update([
                'name' => 'ABA',
                'logo' => '/assets/banks/aba.jpeg',
                'bank_name' => 'ABA',
                'description' => 'ABA Bank',
                'status' => true,
                'updated_at' => now(),
            ]);
    }
}