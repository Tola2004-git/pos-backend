<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardReportsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);
    }

    private function cashier(): User
    {
        return User::create([
            'name' => 'Cashier',
            'email' => 'cashier_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'role' => 'cashier',
        ]);
    }

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function completedOrder(array $overrides = []): Order
    {
        // created_at isn't mass-assignable (not in Order::$fillable), so a
        // backdated timestamp passed through Order::create() is silently
        // dropped and the row gets "now" instead - set it directly after
        // building the model so tests can actually control the order's date.
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $order = new Order(array_merge([
            'order_number' => 'ORD-' . uniqid('', true),
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'amount_paid' => 0,
            'change_amount' => 0,
            'status' => 'completed',
        ], $overrides));

        if ($createdAt) {
            $order->created_at = $createdAt;
        }

        $order->save();

        return $order;
    }

    public function test_profit_summary_treats_items_without_a_product_as_missing_recipe_cost(): void
    {
        $admin = $this->admin();

        $ingredient = Ingredient::create([
            'name' => 'Flour',
            'unit' => 'kg',
            'quantity' => 100,
            'low_stock_threshold' => 5,
            'cost_per_unit' => 2, // $2/unit
            'status' => true,
        ]);

        $product = Product::create([
            'name' => 'Bread',
            'price' => 10,
            'qty' => 50,
            'status' => true,
        ]);
        $product->ingredients()->attach($ingredient->id, ['quantity' => 1]); // unit_cost = $2

        $order = $this->completedOrder(['total' => 25]);
        // Has a recipe: revenue 20, cogs = 2 (qty) * $2 = $4.
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => 10,
            'quantity' => 2,
            'subtotal' => 20,
        ]);
        // A custom line item with no product at all - cost is unknowable.
        $order->items()->create([
            'product_id' => null,
            'product_name' => 'Custom Charge',
            'price' => 5,
            'quantity' => 1,
            'subtotal' => 5,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor($admin))
            ->getJson('/api/orders/profit-summary?period=day');

        $response->assertStatus(200)
            ->assertJsonPath('revenue', 25)
            ->assertJsonPath('cogs', 4)
            ->assertJsonPath('profit', 21)
            ->assertJsonPath('products_without_recipe_count', 1);
    }

    public function test_category_sales_includes_products_without_a_category(): void
    {
        $admin = $this->admin();

        $category = Category::create(['name' => 'Drinks', 'status' => true]);
        $categorized = Product::create([
            'name' => 'Coffee', 'category_id' => $category->id, 'price' => 3, 'qty' => 20, 'status' => true,
        ]);
        $uncategorized = Product::create([
            'name' => 'Mystery Item', 'category_id' => null, 'price' => 7, 'qty' => 20, 'status' => true,
        ]);

        $order = $this->completedOrder(['total' => 10]);
        $order->items()->create([
            'product_id' => $categorized->id, 'product_name' => $categorized->name,
            'price' => 3, 'quantity' => 1, 'subtotal' => 3,
        ]);
        $order->items()->create([
            'product_id' => $uncategorized->id, 'product_name' => $uncategorized->name,
            'price' => 7, 'quantity' => 1, 'subtotal' => 7,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor($admin))
            ->getJson('/api/orders/category-sales?period=day');

        $response->assertStatus(200);
        $rows = $response->json();

        $this->assertCount(2, $rows, 'Uncategorized product revenue must not be dropped from the report.');

        $uncategorizedRow = collect($rows)->firstWhere('category_id', null);
        $this->assertNotNull($uncategorizedRow, 'Expected an uncategorized bucket in the response.');
        $this->assertEquals(7, $uncategorizedRow['revenue']);

        $categorizedRow = collect($rows)->firstWhere('category_id', $category->id);
        $this->assertEquals(3, $categorizedRow['revenue']);
    }

    public function test_profit_summary_is_admin_only(): void
    {
        $cashier = $this->cashier();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor($cashier))
            ->getJson('/api/orders/profit-summary?period=day');

        $response->assertStatus(403);
    }

    public function test_custom_date_range_computes_current_and_previous_windows(): void
    {
        $admin = $this->admin();
        $today = now();

        $this->completedOrder(['total' => 10, 'created_at' => $today]);
        $this->completedOrder(['total' => 15, 'created_at' => $today->copy()->subDays(3)]);
        // Falls in the equal-length window immediately before the custom range.
        $this->completedOrder(['total' => 7, 'created_at' => $today->copy()->subDays(6)]);
        // Older than either window - must be excluded from both.
        $this->completedOrder(['total' => 999, 'created_at' => $today->copy()->subDays(20)]);

        $dateFrom = $today->copy()->subDays(4)->toDateString();
        $dateTo = $today->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor($admin))
            ->getJson("/api/orders/sales-summary?date_from={$dateFrom}&date_to={$dateTo}");

        $response->assertStatus(200)
            ->assertJsonPath('current.orders_count', 2)
            ->assertJsonPath('current.total_sales', 25)
            ->assertJsonPath('previous.orders_count', 1)
            ->assertJsonPath('previous.total_sales', 7);
    }

    public function test_custom_range_wider_than_a_year_is_capped(): void
    {
        $admin = $this->admin();
        $today = now();

        // Outside the 366-day cap anchored to date_to (today).
        $this->completedOrder(['total' => 999, 'created_at' => $today->copy()->subDays(390)]);
        // Inside the capped window.
        $this->completedOrder(['total' => 42, 'created_at' => $today->copy()->subDays(350)]);

        $dateFrom = $today->copy()->subDays(500)->toDateString();
        $dateTo = $today->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor($admin))
            ->getJson("/api/orders/sales-summary?date_from={$dateFrom}&date_to={$dateTo}");

        $response->assertStatus(200)
            ->assertJsonPath('current.orders_count', 1)
            ->assertJsonPath('current.total_sales', 42);
    }

    public function test_custom_range_in_the_weekly_bucket_band_does_not_error(): void
    {
        $admin = $this->admin();
        $today = now();

        $this->completedOrder(['total' => 20, 'created_at' => $today]);
        $this->completedOrder(['total' => 30, 'created_at' => $today->copy()->subDays(50)]);

        $dateFrom = $today->copy()->subDays(60)->toDateString();
        $dateTo = $today->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor($admin))
            ->getJson("/api/orders/sales-summary?date_from={$dateFrom}&date_to={$dateTo}");

        $response->assertStatus(200)
            ->assertJsonPath('current.orders_count', 2);
    }
}
