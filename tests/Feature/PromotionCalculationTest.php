<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PromotionCalculationTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);

        return auth('api')->login($admin);
    }

    private function product(string $name, float $price, ?int $categoryId = null): Product
    {
        return Product::create([
            'name' => $name,
            'price' => $price,
            'qty' => 50,
            'status' => true,
            'category_id' => $categoryId,
        ]);
    }

    private function productPromo(string $name, string $type, float $value, Product $product): Promotion
    {
        $promo = Promotion::create([
            'name' => $name,
            'type' => $type,
            'value' => $value,
            'apply_to' => 'product',
            'status' => true,
        ]);
        $promo->products()->attach($product->id);

        return $promo;
    }

    private function checkout(array $items, array $extra = [])
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->postJson('/api/orders', array_merge([
                'items' => $items,
                'amount_paid' => 1000,
            ], $extra));
    }

    public function test_only_the_best_of_several_promotions_applies_to_an_item(): void
    {
        $water = $this->product('Kulen Water', 1.00);
        $this->productPromo('Five', 'percentage', 5, $water);
        $best = $this->productPromo('Fifteen', 'percentage', 15, $water);
        $this->productPromo('Ten', 'percentage', 10, $water);

        $this->checkout([['product_id' => $water->id, 'quantity' => 2]])
            ->assertCreated()
            ->assertJsonPath('order.discount', '0.30')
            ->assertJsonPath('order.total', '1.70')
            ->assertJsonPath('order.promotion_id', $best->id);
    }

    public function test_each_item_gets_its_own_best_promotion(): void
    {
        $drinks = Category::create(['name' => 'Drinks']);
        $coconut = $this->product('Coconut', 10.00);
        $latte = $this->product('Latte', 20.00, $drinks->id);

        $this->productPromo('Coconut Deal', 'percentage', 10, $coconut);
        $drinkPromo = Promotion::create([
            'name' => 'Drinks Deal',
            'type' => 'percentage',
            'value' => 20,
            'apply_to' => 'category',
            'status' => true,
        ]);
        $drinkPromo->categories()->attach($drinks->id);

        // 10% of $10 + 20% of $20 - neither item loses its discount.
        $this->checkout([
            ['product_id' => $coconut->id, 'quantity' => 1],
            ['product_id' => $latte->id, 'quantity' => 1],
        ])
            ->assertCreated()
            ->assertJsonPath('order.discount', '5.00')
            ->assertJsonPath('order.total', '25.00')
            ->assertJsonPath('order.promotion_id', $drinkPromo->id);
    }

    public function test_order_wide_fixed_promotion_competes_instead_of_stacking(): void
    {
        $bread = $this->product('Sourdough', 10.00);
        $this->productPromo('Bread 5%', 'percentage', 5, $bread);
        $orderPromo = Promotion::create([
            'name' => '$2 Off',
            'type' => 'fixed',
            'value' => 2,
            'apply_to' => 'all',
            'status' => true,
        ]);

        // $2 order discount beats $0.50 item discount; they don't add up to $2.50.
        $this->checkout([['product_id' => $bread->id, 'quantity' => 1]])
            ->assertCreated()
            ->assertJsonPath('order.discount', '2.00')
            ->assertJsonPath('order.promotion_id', $orderPromo->id);
    }

    public function test_fixed_discount_never_exceeds_the_item_price(): void
    {
        $donut = $this->product('Donut', 0.75);
        $this->productPromo('Big Fixed', 'fixed', 5, $donut);

        $this->checkout([['product_id' => $donut->id, 'quantity' => 1]])
            ->assertCreated()
            ->assertJsonPath('order.discount', '0.75')
            ->assertJsonPath('order.total', '0.00');
    }

    public function test_client_cannot_choose_which_promotion_applies(): void
    {
        $water = $this->product('Kulen Water', 10.00);
        $small = $this->productPromo('Small', 'percentage', 5, $water);
        $big = $this->productPromo('Big', 'percentage', 30, $water);

        $this->checkout(
            [['product_id' => $water->id, 'quantity' => 1]],
            ['promotion_id' => $small->id, 'discount_amount' => 9.99],
        )
            ->assertCreated()
            ->assertJsonPath('order.discount', '3.00')
            ->assertJsonPath('order.promotion_id', $big->id);
    }

    public function test_expired_and_min_purchase_promotions_are_skipped(): void
    {
        $cake = $this->product('Cake', 5.00);
        $expired = $this->productPromo('Expired', 'percentage', 50, $cake);
        $expired->update(['end_date' => now()->subDay()->toDateString()]);
        $minPurchase = $this->productPromo('Big Spender', 'percentage', 40, $cake);
        $minPurchase->update(['min_purchase' => 100]);
        $valid = $this->productPromo('Valid', 'percentage', 10, $cake);

        $this->checkout([['product_id' => $cake->id, 'quantity' => 1]])
            ->assertCreated()
            ->assertJsonPath('order.discount', '0.50')
            ->assertJsonPath('order.promotion_id', $valid->id);
    }
}
