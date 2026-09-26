<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CashierInventoryAccessTest extends TestCase
{
    use RefreshDatabase;

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
        /** @var \Tymon\JWTAuth\JWTGuard $guard */
        $guard = auth('api');

        return $guard->login($user);
    }

    public function test_cashier_can_add_and_remove_product_stock_with_audit_history(): void
    {
        $cashier = $this->cashier();
        $product = Product::create([
            'name' => 'Croissant',
            'price' => 3.50,
            'qty' => 4,
            'status' => true,
        ]);
        $token = $this->tokenFor($cashier);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/inventory/restock', [
                'product_id' => $product->id,
                'action' => 'add',
                'quantity' => 6,
                'note' => 'Morning stock count',
            ])
            ->assertOk()
            ->assertJsonPath('product.qty', 10);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/inventory/restock', [
                'product_id' => $product->id,
                'action' => 'remove',
                'quantity' => 2,
                'note' => 'Damaged items',
            ])
            ->assertOk()
            ->assertJsonPath('product.qty', 8);

        $this->assertDatabaseHas('stock_logs', [
            'product_id' => $product->id,
            'user_id' => $cashier->id,
            'action' => 'add',
            'qty_before' => 4,
            'qty_after' => 10,
        ]);
        $this->assertDatabaseHas('stock_logs', [
            'product_id' => $product->id,
            'user_id' => $cashier->id,
            'action' => 'remove',
            'qty_before' => 10,
            'qty_after' => 8,
        ]);

        $this->assertDatabaseCount('stock_logs', 2);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $cashier->id,
            'action' => 'product_restocked',
            'subject_type' => 'Product',
            'subject_id' => $product->id,
        ]);
    }

    public function test_cashier_cannot_change_low_stock_threshold(): void
    {
        $token = $this->tokenFor($this->cashier());

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/settings/low-stock-threshold', ['threshold' => 5])
            ->assertForbidden();
    }

    public function test_cashier_shift_open_and_close_are_written_to_audit_logs(): void
    {
        $cashier = $this->cashier();
        $token = $this->tokenFor($cashier);

        $opened = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/cashier-shifts/open', [
                'opening_cash_usd' => 100,
                'opening_cash_khr' => 400000,
            ])
            ->assertOk()
            ->json('shift');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/cashier-shifts/' . $opened['id'] . '/close', [
                'counted_cash_usd' => 95,
                'counted_cash_khr' => 410000,
                'note' => 'End of shift count',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $cashier->id,
            'action' => 'shift_opened',
            'subject_type' => 'CashierShift',
            'subject_id' => $opened['id'],
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $cashier->id,
            'action' => 'shift_closed',
            'subject_type' => 'CashierShift',
            'subject_id' => $opened['id'],
        ]);
        $this->assertDatabaseCount('audit_logs', 2);

        $closeLog = \App\Models\AuditLog::where('action', 'shift_closed')->firstOrFail();
        $this->assertStringContainsString('variance: $-5.00 / 10,000 KHR', $closeLog->description);
    }

    public function test_cashier_cannot_access_dashboard_reports_or_stock_history(): void
    {
        $token = $this->tokenFor($this->cashier());
        $headers = ['Authorization' => 'Bearer ' . $token];

        $this->withHeaders($headers)->getJson('/api/orders/sales-summary')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/orders/sales-by-cashier')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/orders/top-products')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/orders/category-sales')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/inventory/history')->assertForbidden();
    }
}