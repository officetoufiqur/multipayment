<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Product::insert([
            [
                'name' => 'Product 1',
                'price' => 1000,
                'description' => 'Product 1 description',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Product 2',
                'price' => 2000,
                'description' => 'Product 2 description',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Product 3',
                'price' => 3000,
                'description' => 'Product 3 description',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
