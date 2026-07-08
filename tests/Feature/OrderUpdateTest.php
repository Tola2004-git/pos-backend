<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Product;
use App\Models\Table;
use App\Models\Order;
use App\Models\Promotion;
use Illuminate\Support\Facades\Hash;

class OrderUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_order_can_be_updated_with_new_items_and_table(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);

        $product = Product::create([
            'name' => 'Burger',
            'price' => 12.50,
            'qty' => 10,
            'status' => true,
        ]);

        $table = Table::create([
            'name' => 'Table 1',
            'capacity' => 4,
            'status' => 'available',
        ]);

        $order = Order::create([
            'order_number' => 'ORD-TEST-1',
            'user_id' => $user->id,
            'customer_name' => 'Old Customer',
            'subtotal' => 12.50,
            'discount' => 0,
            'tax' => 0,
            'total' => 12.50,
            'amount_paid' => 0,
            'change_amount' => 0,
            'status' => 'pending',
            'order_type' => 'takeaway',
            'table_id' => null,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => $product->price,
            'quantity' => 1,
            'subtotal' => 12.50,
        ]);

        $token = auth('api')->login($user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/orders/' . $order->id, [
                'customer_name' => 'Updated Customer',
                'customer_phone' => '12345678',
                'order_type' => 'dine-in',
                'table_id' => $table->id,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 2,
                    ],
                ],
                'subtotal' => 25.00,
                'total' => 30.00,
                'tax' => 5.00,
                'amount_paid' => 0,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('order.customer_name', 'Updated Customer')
            ->assertJsonPath('order.table_id', $table->id)
            ->assertJsonPath('order.order_type', 'dine-in')
            ->assertJsonPath('order.subtotal', '25.00')
            ->assertJsonPath('order.total', '30.00');
    }

    public function test_pending_order_update_applies_selected_promotion(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test2@example.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);

        $product = Product::create([
            'name' => 'Pizza',
            'price' => 20.00,
            'qty' => 10,
            'status' => true,
        ]);

        $promotion = Promotion::create([
            'name' => 'Weekend Deal',
            'type' => 'percentage',
            'value' => 10,
            'apply_to' => 'all',
            'min_purchase' => 0,
            'status' => true,
        ]);

        $order = Order::create([
            'order_number' => 'ORD-TEST-2',
            'user_id' => $user->id,
            'subtotal' => 20.00,
            'discount' => 0,
            'tax' => 0,
            'total' => 20.00,
            'amount_paid' => 0,
            'change_amount' => 0,
            'status' => 'pending',
            'order_type' => 'takeaway',
            'table_id' => null,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => $product->price,
            'quantity' => 1,
            'subtotal' => 20.00,
        ]);

        $token = auth('api')->login($user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/orders/' . $order->id, [
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 2,
                    ],
                ],
                'promotion_id' => $promotion->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('order.promotion_id', $promotion->id)
            ->assertJsonPath('order.discount', '4.00')
            ->assertJsonPath('order.total', '36.00');
    }
}
