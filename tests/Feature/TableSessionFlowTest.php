<?php

namespace Tests\Feature;

use App\Http\Controllers\OrderController;
use App\Models\Order;
use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TableSessionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_order_can_be_moved_to_another_table(): void
    {
        $oldTable = Table::create([
            'name' => 'Table 1',
            'capacity' => 4,
            'status' => 'occupied',
        ]);

        $newTable = Table::create([
            'name' => 'Table 2',
            'capacity' => 4,
            'status' => 'available',
        ]);

        $order = Order::create([
            'order_number' => 'ORD-TEST-1',
            'customer_name' => 'Guest',
            'subtotal' => 10,
            'discount' => 0,
            'tax' => 0,
            'total' => 10,
            'amount_paid' => 0,
            'change_amount' => 0,
            'status' => 'pending',
            'table_id' => $oldTable->id,
        ]);

        $controller = new OrderController();
        $request = Request::create('/orders/' . $order->id . '/change-table', 'POST', [
            'table_id' => $newTable->id,
        ]);

        $response = $controller->changeTable($request, $order->id);

        $this->assertEquals(200, $response->getStatusCode());

        $order->refresh();
        $oldTable->refresh();
        $newTable->refresh();

        $this->assertSame($newTable->id, $order->table_id);
        $this->assertSame('available', $oldTable->status);
        $this->assertContains($newTable->status, ['occupied', 'reserved']);
    }

    public function test_moving_a_shared_table_moves_all_its_active_orders(): void
    {
        $oldTable = Table::create([
            'name' => 'Table 1',
            'capacity' => 4,
            'status' => 'occupied',
        ]);

        $newTable = Table::create([
            'name' => 'Table 2',
            'capacity' => 4,
            'status' => 'available',
        ]);

        $completedOrder = Order::create([
            'order_number' => 'ORD-TEST-2',
            'customer_name' => 'Guest 1',
            'subtotal' => 10,
            'discount' => 0,
            'tax' => 0,
            'total' => 10,
            'amount_paid' => 10,
            'change_amount' => 0,
            'status' => 'completed',
            'table_id' => $oldTable->id,
        ]);

        $pendingOrder = Order::create([
            'order_number' => 'ORD-TEST-3',
            'customer_name' => 'Guest 2 (friend joining later)',
            'subtotal' => 5,
            'discount' => 0,
            'tax' => 0,
            'total' => 5,
            'amount_paid' => 0,
            'change_amount' => 0,
            'status' => 'pending',
            'table_id' => $oldTable->id,
        ]);

        $controller = new OrderController();
        $request = Request::create('/orders/' . $completedOrder->id . '/change-table', 'POST', [
            'table_id' => $newTable->id,
        ]);

        $response = $controller->changeTable($request, $completedOrder->id);

        $this->assertEquals(200, $response->getStatusCode());

        $completedOrder->refresh();
        $pendingOrder->refresh();
        $oldTable->refresh();

        $this->assertSame($newTable->id, $completedOrder->table_id);
        $this->assertSame($newTable->id, $pendingOrder->table_id, 'The pending order sharing the table must move too.');
        $this->assertSame('available', $oldTable->status);
    }

    public function test_table_clear_action_returns_it_to_available(): void
    {
        $table = Table::create([
            'name' => 'Table 3',
            'capacity' => 2,
            'status' => 'occupied',
        ]);

        $controller = new \App\Http\Controllers\TableController();
        $request = Request::create('/tables/' . $table->id . '/clear', 'POST');

        $response = $controller->clear($request, $table->id);

        $this->assertEquals(200, $response->getStatusCode());

        $table->refresh();
        $this->assertSame('available', $table->status);
    }
}
