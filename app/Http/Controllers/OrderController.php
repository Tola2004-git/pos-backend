<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Table;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::query()->with(['items', 'user', 'paymentMethod', 'table']);

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('order_number', 'like', '%' . $request->search . '%')
                    ->orWhere('customer_name', 'like', '%' . $request->search . '%')
                    ->orWhere('customer_phone', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->status && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $orders = $query->latest()->paginate($request->per_page ?? 15);
        return response()->json($orders);
    }

    public function show(int $id)
    {
        $order = Order::with(['items.product', 'user', 'paymentMethod', 'table'])->findOrFail($id);
        return response()->json($order);
    }

    public function update(Request $request, int $id)
    {
        $order = Order::with('items')->findOrFail($id);

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Only pending orders can be edited.'], 422);
        }

        $request->validate([
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|exists:products,id',
            'items.*.quantity'    => 'required|integer|min:1',
            'payment_method_id'   => 'nullable|exists:payment_methods,id',
            'amount_paid'         => 'nullable|numeric|min:0',
            'amount_paid_usd'     => 'nullable|numeric|min:0',
            'amount_paid_khr'     => 'nullable|numeric|min:0',
            'exchange_rate_used'  => 'nullable|numeric|min:0',
            'status'              => 'nullable|in:pending,completed,cancelled,refunded',
            'table_id'            => 'nullable|exists:tables,id',
            'order_type'          => 'nullable|in:takeaway,self-seating,dine-in',
            'customer_name'       => 'nullable|string|max:255',
            'customer_phone'      => 'nullable|string|max:255',
            'note'                => 'nullable|string',
            'subtotal'            => 'nullable|numeric|min:0',
            'tax'                 => 'nullable|numeric|min:0',
            'total'               => 'nullable|numeric|min:0',
            'promotion_id'        => 'nullable|exists:promotions,id',
            'promotion_name'      => 'nullable|string|max:255',
            'promotion_type'      => 'nullable|string|max:255',
            'promotion_value'     => 'nullable|numeric',
            'discount_amount'     => 'nullable|numeric|min:0',
        ]);

        if ($request->order_type === 'dine-in' && ! $request->table_id) {
            return response()->json(['message' => 'Please select a table for dine-in orders.'], 422);
        }

        $subtotal = 0;
        $orderItems = [];

        foreach ($request->items as $item) {
            $product = Product::findOrFail($item['product_id']);
            $itemSubtotal = $product->price * $item['quantity'];
            $subtotal += $itemSubtotal;
            $orderItems[] = [
                'product_id'   => $product->id,
                'product_name' => $product->name,
                'price'        => $product->price,
                'quantity'     => $item['quantity'],
                'subtotal'     => $itemSubtotal,
            ];
        }

        $discount = 0;
        $promotion = null;
        $tax = (float) ($request->tax ?? 0);
        $subtotalFromPayload = $request->subtotal;
        $totalFromPayload = $request->total;

        if ($subtotalFromPayload !== null) {
            $subtotal = (float) $subtotalFromPayload;
        }

        $activePromotions = Promotion::with(['products', 'categories'])
            ->where('status', true)
            ->where(function ($query) {
                $today = now()->toDateString();
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', $today);
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $today);
            })
            ->get();

        if ($request->promotion_id) {
            $promotion = $activePromotions->firstWhere('id', $request->promotion_id);
        }

        $promotionsToApply = $promotion ? collect([$promotion]) : $activePromotions;

        foreach ($promotionsToApply as $promo) {
            if ($promo->min_purchase && $subtotal < $promo->min_purchase) {
                continue;
            }

            if ($promo->apply_to === 'all') {
                if ($promo->type === 'percentage') {
                    $discount += ($subtotal * $promo->value) / 100;
                } else {
                    $discount += $promo->value;
                }
                continue;
            }

            foreach ($orderItems as $item) {
                $matches = false;

                if ($promo->apply_to === 'product') {
                    $matches = $promo->products->contains('id', $item['product_id']);
                }

                if ($promo->apply_to === 'category') {
                    $product = Product::find($item['product_id']);
                    $matches = $product && $promo->categories->contains('id', $product->category_id);
                }

                if (! $matches) {
                    continue;
                }

                if ($promo->type === 'percentage') {
                    $discount += ($item['subtotal'] * $promo->value) / 100;
                } else {
                    $discount += $promo->value * $item['quantity'];
                }
            }
        }

        $discount = round((float) $discount, 2);

        if ($totalFromPayload !== null) {
            $total = (float) $totalFromPayload;
        } else {
            $total = $subtotal - $discount + $tax;
        }

        foreach ($order->items as $existingItem) {
            $product = Product::find($existingItem->product_id);
            if ($product) {
                $product->increment('qty', $existingItem->quantity);
            }
        }
        $order->items()->delete();

        $order->update([
            'customer_name'     => $request->customer_name ?? $order->customer_name,
            'customer_phone'    => $request->customer_phone ?? $order->customer_phone,
            'payment_method_id' => $request->payment_method_id ?? $order->payment_method_id,
            'amount_paid'       => $request->amount_paid ?? $order->amount_paid,
            'amount_paid_usd'   => $request->amount_paid_usd ?? $order->amount_paid_usd,
            'amount_paid_khr'   => $request->amount_paid_khr ?? $order->amount_paid_khr,
            'exchange_rate_used' => $request->exchange_rate_used ?? $order->exchange_rate_used,
            'status'            => $request->status ?? $order->status,
            'table_id'          => $request->table_id ?? $order->table_id,
            'order_type'        => $request->order_type ?? $order->order_type,
            'subtotal'          => $subtotal,
            'discount'          => $discount,
            'tax'               => $tax,
            'total'             => $total,
            'promotion_id'      => $request->promotion_id ?? $order->promotion_id,
            'note'              => $request->note ?? $order->note,
        ]);

        foreach ($orderItems as $item) {
            $order->items()->create($item);
            Product::find($item['product_id'])?->decrement('qty', $item['quantity']);
        }

        $oldTableId = $order->getOriginal('table_id');
        $newTableId = $request->table_id ?? $order->table_id;
        $newStatus = $request->status ?? $order->status;

        if ($request->table_id !== null && $request->table_id != $oldTableId) {
            if ($oldTableId) {
                $oldTable = Table::find($oldTableId);
                if ($oldTable) {
                    $oldTable->status = 'available';
                    $oldTable->save();
                }
            }

            if ($newTableId) {
                $newTable = Table::find($newTableId);
                if ($newTable) {
                    $newTable->status = $newStatus === 'completed' ? 'occupied' : 'reserved';
                    $newTable->save();
                }
            }
        } else {
            $this->syncTableStatusForOrder($order);
        }

        // Ensure items include their related product when returning the updated order
        $order->load(['items.product', 'user', 'paymentMethod', 'table']);
        return response()->json(['message' => 'Order updated!', 'order' => $order]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|exists:products,id',
            'items.*.quantity'    => 'required|integer|min:1',
            'payment_method_id'   => 'nullable|exists:payment_methods,id',
            'amount_paid'         => 'required|numeric|min:0',
            'amount_paid_usd'     => 'nullable|numeric|min:0',
            'amount_paid_khr'     => 'nullable|numeric|min:0',
            'exchange_rate_used'  => 'nullable|numeric|min:0',
            'promotion_id'        => 'nullable|exists:promotions,id',
            'status'              => 'nullable|in:pending,completed,cancelled,refunded',
            'table_id'            => 'nullable|exists:tables,id',
            'pager_number'        => 'nullable|string|max:255',
            'order_type'          => 'nullable|in:takeaway,self-seating,dine-in',
        ]);

        $user = JWTAuth::parseToken()->authenticate();

        $subtotal = 0;
        $orderItems = [];

        foreach ($request->items as $item) {
            $product = Product::findOrFail($item['product_id']);

            if ($product->qty < $item['quantity']) {
                return response()->json([
                    'message' => "Insufficient stock for {$product->name}!"
                ], 422);
            }

            $itemSubtotal = $product->price * $item['quantity'];
            $subtotal += $itemSubtotal;

            $orderItems[] = [
                'product_id'   => $product->id,
                'product_name' => $product->name,
                'price'        => $product->price,
                'quantity'     => $item['quantity'],
                'subtotal'     => $itemSubtotal,
            ];
        }

        $discount = 0;
        $promotion = null;

        $activePromotions = Promotion::with(['products', 'categories'])
            ->where('status', true)
            ->where(function ($query) {
                $today = now()->toDateString();
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', $today);
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $today);
            })
            ->get();

        if ($request->promotion_id) {
            $promotion = $activePromotions->firstWhere('id', $request->promotion_id);
        }

        $promotionsToApply = $promotion ? collect([$promotion]) : $activePromotions;

        foreach ($promotionsToApply as $promo) {
            if ($promo->apply_to === 'all') {
                if ($promo->type === 'percentage') {
                    $discount += ($subtotal * $promo->value) / 100;
                } else {
                    $discount += $promo->value;
                }
                continue;
            }

            foreach ($orderItems as $item) {
                if ($promo->min_purchase && $subtotal < $promo->min_purchase) {
                    continue;
                }

                $matches = false;

                if ($promo->apply_to === 'product') {
                    $matches = $promo->products->contains('id', $item['product_id']);
                }

                if ($promo->apply_to === 'category') {
                    $product = Product::find($item['product_id']);
                    $matches = $product && $promo->categories->contains('id', $product->category_id);
                }

                if (! $matches) {
                    continue;
                }

                if ($promo->type === 'percentage') {
                    $discount += ($item['subtotal'] * $promo->value) / 100;
                } else {
                    $discount += $promo->value * $item['quantity'];
                }
            }
        }

        $tax   = $request->tax ?? 0;
        $total = $subtotal - $discount + $tax;
        $status = $request->status ?? 'completed';

        if ($request->order_type === 'dine-in' && ! $request->table_id) {
            return response()->json(['message' => 'Please select a table for dine-in orders.'], 422);
        }

        $table = null;
        if ($request->table_id) {
            $table = Table::findOrFail($request->table_id);

            if (! in_array($table->status, ['available', 'occupied', 'reserved'])) {
                return response()->json(['message' => 'Selected table is not available.'], 422);
            }
        }

        if ($status === 'completed' && $request->amount_paid < $total) {
            return response()->json(['message' => 'Amount paid is less than total!'], 422);
        }

        if ($status === 'pending' && $request->amount_paid < 0) {
            return response()->json(['message' => 'Amount paid must be at least 0 for pending orders!'], 422);
        }

        $change = $request->amount_paid - $total;
        if ($status !== 'completed') {
            $change = 0;
        }

        $orderNumber = 'ORD-' . strtoupper(uniqid());

        $order = Order::create([
            'order_number'      => $orderNumber,
            'user_id'           => $user->id,
            'customer_name'     => $request->customer_name ?? null,
            'customer_phone'    => $request->customer_phone ?? null,
            'pager_number'      => $request->pager_number ?? null,
            'order_type'        => $request->order_type ?? 'takeaway',
            'subtotal'          => $subtotal,
            'discount'          => $discount,
            'tax'               => $tax,
            'total'             => $total,
            'payment_method_id' => $request->payment_method_id,
            'promotion_id'      => $request->promotion_id,
            'amount_paid'       => $request->amount_paid,
            'amount_paid_usd'   => $request->amount_paid_usd ?? 0,
            'amount_paid_khr'   => $request->amount_paid_khr ?? 0,
            'exchange_rate_used' => $request->exchange_rate_used ?? null,
            'change_amount'     => $change,
            'status'            => $status,
            'table_id'          => $request->table_id,
            'table_name'        => $request->table_id ? Table::find($request->table_id)?->name : null,
            'note'              => $request->note ?? null,
        ]);

        $this->syncTableStatusForOrder($order);

        foreach ($orderItems as $item) {
            $order->items()->create($item);
            Product::find($item['product_id'])->decrement('qty', $item['quantity']);
        }

        $order->load(['items.product', 'user', 'paymentMethod']);

        if ($order->status === 'completed') {
            $exportDate = $order->created_at->toDateString();

            // Runs after the JSON response is flushed to the client, so the
            // Excel build + Google Drive upload never adds latency to checkout.
            dispatch(function () use ($exportDate) {
                Artisan::call('app:export-daily-receipts', ['date' => $exportDate]);
            })->afterResponse();
        }

        return response()->json(['message' => 'Order created!', 'order' => $order], 201);
    }

    public function changeTable(Request $request, int $id)
    {
        $request->validate([
            'table_id' => 'required|exists:tables,id',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $order = Order::lockForUpdate()->findOrFail($id);

            if (! in_array($order->status, ['pending', 'completed'], true)) {
                return response()->json(['message' => 'Only pending or completed orders can be moved.'], 422);
            }

            $newTable = Table::findOrFail($request->table_id);
            $oldTable = Table::find($order->table_id);

            if ($oldTable && $oldTable->id == $newTable->id) {
                return response()->json(['message' => 'Already assigned.', 'order' => $order]);
            }

            // Shared tables (Option 1) let a table hold multiple concurrent active orders
            // (e.g. one completed order and one pending order side by side). Move all of
            // them together so a table move never strands an order on the old table.
            $ordersToMove = $order->table_id
                ? Order::where('table_id', $order->table_id)
                    ->whereIn('status', ['pending', 'completed'])
                    ->lockForUpdate()
                    ->get()
                : collect([$order]);

            $currentStatus = $oldTable ? $oldTable->status : 'reserved';

            if ($oldTable) {
                $oldTable->update(['status' => 'available']);
            }

            $newTable->update(['status' => $currentStatus]);

            foreach ($ordersToMove as $movingOrder) {
                $movingOrder->table_id = $newTable->id;
                $movingOrder->table_name = $newTable->name;
                $movingOrder->save();
            }

            $order->refresh();
            $order->load('table');

            return response()->json([
                'success' => true,
                'message' => $ordersToMove->count() > 1
                    ? "Table changed successfully ({$ordersToMove->count()} orders moved)"
                    : 'Table changed successfully',
                'order' => $order,
                'moved_order_ids' => $ordersToMove->pluck('id'),
            ]);
        });
    }

    public function cancel(int $id)
    {
        $order = Order::with('items')->findOrFail($id);

        if ($order->status === 'cancelled') {
            return response()->json(['message' => 'Order already cancelled!'], 422);
        }

        foreach ($order->items as $item) {
            if ($item->product_id) {
                Product::find($item->product_id)?->increment('qty', $item->quantity);
            }
        }

        $order->update(['status' => 'cancelled']);
        return response()->json(['message' => 'Order cancelled!', 'order' => $order]);
    }

    public function latest()
    {
        $order = Order::with(['paymentMethod', 'user'])
            ->where('status', 'completed')
            ->latest()
            ->first();
        return response()->json($order);
    }

    private function syncTableStatusForOrder(Order $order): void
    {
        if (! $order->table_id) {
            return;
        }

        $table = Table::find($order->table_id);
        if (! $table) {
            return;
        }

        $table->status = match ($order->status) {
 
            'pending' => in_array($table->status, ['occupied', 'reserved'], true) ? $table->status : 'reserved',
            'completed' => 'occupied',
            default => 'available',
        };

        $table->save();
    }
}
