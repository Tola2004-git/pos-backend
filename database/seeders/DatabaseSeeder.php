<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;
    public function run(): void
    {
        User::create([
            'name' => 'Sun Coco Admin',
            'email' => 'suncoco@gmail.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
        ]);

        $this->call([
            PaymentMethodSeeder::class,
        ]);
    }
}
