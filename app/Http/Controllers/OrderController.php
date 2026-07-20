<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Events\OrderChanged;
use App\Events\TableChanged;
use App\Models\CashierShift;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\StockLog;
use App\Models\Table;
use App\Support\RealtimeBroadcaster;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrderController extends Controller
{
    // Sanity ceilings for cash tendered - high enough to cover any plausible
    // bill handed to a cashier, low enough to reject a typo'd/forged amount
    // (e.g. "999999999") that would otherwise pollute change_amount and the
    // shift's cash-drawer totals.
    private const MAX_AMOUNT_PAID_USD = 100000;
    private const MAX_AMOUNT_PAID_KHR = 500000000;

    public function index(Request $request)
    {
        $query = Order::query()->with(['items', 'user', 'paymentMethod', 'table', 'refundedBy']);

        // Cashiers only see their own sales - enforced server-side so it
        // can't be bypassed by a client omitting/spoofing a filter param.
        // Admins keep the unrestricted view across every cashier.
        $user = JWTAuth::parseToken()->authenticate();
        if ($user->role === 'cashier') {
            $query->where('user_id', $user->id);
        }

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

        $this->applyDateOrShiftScope($query, $request, $user, 'created_at');

        // Only meaningful for admins - cashiers are already locked to their own
        // orders above, so this can't be used to see another cashier's sales.
        if ($request->cashier_id) {
            $query->where('user_id', $request->cashier_id);
        }

        $orders = $query->latest()->paginate($request->per_page ?? 15);
        return response()->json($orders);
    }

    // Scopes a query to either the cashier's currently open shift
    // (?current_shift=true, from shift.opened_at to now) or an explicit
    // date_from/date_to range - the shift takes priority when both are
    // present and an open shift actually exists, since "current shift"
    // is a more precise window than whichever date the client defaulted
    // its date pickers to. Falls back to the date range (or no bound at
    // all) whenever current_shift isn't requested, isn't a cashier, or
    // the cashier has no open shift right now.
    private function applyDateOrShiftScope($query, Request $request, $user, string $column)
    {
        if ($request->boolean('current_shift') && $user->role === 'cashier') {
            $shift = CashierShift::where('user_id', $user->id)->where('status', 'open')->first();
            if ($shift) {
                $query->where($column, '>=', $shift->opened_at);
                return;
            }
        }

        if ($request->date_from) {
            $query->whereDate($column, '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate($column, '<=', $request->date_to);
        }
    }

    // Per-cashier sales totals for a date range (or the current shift) -
    // admins get every cashier side by side, a cashier gets only their own
    // row (same self-scoping as index() above, so this can't be used to see
    // another cashier's totals). Also breaks the total down by how it was
    // paid, since a cashier's end-of-shift cash count needs the cash portion
    // isolated from card/QR payments that never touch the drawer.
    public function salesByCashier(Request $request)
    {
        $query = Order::query()->where('orders.status', 'completed');

        $user = JWTAuth::parseToken()->authenticate();
        if ($user->role === 'cashier') {
            $query->where('orders.user_id', $user->id);
        }

        $this->applyDateOrShiftScope($query, $request, $user, 'orders.created_at');

        $summary = $query
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'orders.payment_method_id')
            ->groupBy('orders.user_id', 'users.name')
            ->selectRaw('
                orders.user_id,
                users.name,
                COUNT(*) as orders_count,
                SUM(orders.total) as total_sales,
                SUM(CASE WHEN payment_methods.is_cash = 1 THEN orders.amount_paid_usd ELSE 0 END) as cash_usd_total,
                SUM(CASE WHEN payment_methods.is_cash = 1 THEN orders.amount_paid_khr ELSE 0 END) as cash_khr_total,
                SUM(CASE WHEN payment_methods.name IS NOT NULL AND payment_methods.is_cash = 0 THEN orders.total ELSE 0 END) as digital_total
            ')
            ->orderByDesc('total_sales')
            ->get();

        return response()->json($summary);
    }

    // Daily sales totals for a date range - powers the dashboard's sales
    // trend chart with a single query instead of one request per day. Same
    // role-scoping as salesByCashier: a cashier only sees their own daily
    // totals, never the whole store's.
    public function salesTrend(Request $request)
    {
        $query = Order::query()->where('orders.status', 'completed');

        $user = JWTAuth::parseToken()->authenticate();
        if ($user->role === 'cashier') {
            $query->where('orders.user_id', $user->id);
        }

        $this->applyDateOrShiftScope($query, $request, $user, 'orders.created_at');

        $summary = $query
            ->selectRaw('DATE(orders.created_at) as date, COUNT(*) as orders_count, SUM(orders.total) as total_sales')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json($summary);
    }

    public function show(int $id)
    {
        $order = Order::with(['items.product', 'user', 'paymentMethod', 'table', 'refundedBy'])->findOrFail($id);

        $user = JWTAuth::parseToken()->authenticate();
        if ($user->role === 'cashier' && $order->user_id !== $user->id) {
            abort(403, 'You can only view your own orders.');
        }

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
            'amount_paid'         => 'nullable|numeric|min:0|max:' . self::MAX_AMOUNT_PAID_USD,
            'amount_paid_usd'     => 'nullable|numeric|min:0|max:' . self::MAX_AMOUNT_PAID_USD,
            'amount_paid_khr'     => 'nullable|numeric|min:0|max:' . self::MAX_AMOUNT_PAID_KHR,
            'exchange_rate_used'  => 'nullable|numeric|min:0',
            // 'refunded' is intentionally excluded - refunds go through the
            // dedicated refund() endpoint so stock restore, cash-drawer impact,
            // and a reason are always recorded together.
            'status'              => 'nullable|in:pending,completed,cancelled',
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

        $user = JWTAuth::parseToken()->authenticate();

        // Cashiers may only edit their own orders; admins can edit any order.
        if ($user->role === 'cashier' && $order->user_id !== $user->id) {
            abort(403, 'You can only edit your own orders.');
        }

        if (
            $user->role === 'cashier'
            && !CashierShift::where('user_id', $user->id)->where('status', 'open')->exists()
        ) {
            return response()->json(['message' => 'Please open your shift before selling.'], 422);
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
        $subtotal = round($subtotal, 2);

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
        $tax = round($tax, 2);

        if ($totalFromPayload !== null) {
            $total = (float) $totalFromPayload;
        } else {
            $total = $subtotal - $discount + $tax;
        }
        $total = max(0, round($total, 2));

        $newStatus = $request->status ?? $order->status;
        $amountPaid = $request->amount_paid !== null ? (float) $request->amount_paid : (float) $order->amount_paid;

        if ($newStatus === 'completed' && $amountPaid < $total) {
            return response()->json(['message' => 'Amount paid is less than total!'], 422);
        }

        $change = $newStatus === 'completed' ? round($amountPaid - $total, 2) : 0;

        try {
            DB::transaction(function () use ($order, $orderItems, $request, $user, $subtotal, $discount, $tax, $total, $change) {
                // Restore stock for the items being replaced first, and lock each
                // product row for the rest of the transaction so a concurrent
                // sale/restock on the same product can't interleave and desync qty.
                foreach ($order->items as $existingItem) {
                    if (! $existingItem->product_id) {
                        continue;
                    }
                    $product = Product::where('id', $existingItem->product_id)->lockForUpdate()->first();
                    if (! $product) {
                        continue;
                    }
                    $qtyBefore = $product->qty;
                    $product->increment('qty', $existingItem->quantity);

                    StockLog::create([
                        'product_id' => $product->id,
                        'user_id'    => $user->id,
                        'action'     => 'cancel_restore',
                        'quantity'   => $existingItem->quantity,
                        'qty_before' => $qtyBefore,
                        'qty_after'  => $qtyBefore + $existingItem->quantity,
                        'note'       => "Order {$order->order_number} edited",
                    ]);
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
                    'change_amount'     => $change,
                    'promotion_id'      => $request->promotion_id ?? $order->promotion_id,
                    'note'              => $request->note ?? $order->note,
                ]);

                foreach ($orderItems as $item) {
                    $order->items()->create($item);

                    // Authoritative, lock-protected check: the row is already
                    // locked above if it existed in the old item set, but a
                    // product added fresh in this edit needs its own lock here.
                    $product = Product::where('id', $item['product_id'])->lockForUpdate()->firstOrFail();
                    if ($product->qty < $item['quantity']) {
                        throw new \RuntimeException("Insufficient stock for {$product->name}!");
                    }
                    $qtyBefore = $product->qty;
                    $product->decrement('qty', $item['quantity']);

                    StockLog::create([
                        'product_id' => $product->id,
                        'user_id'    => $user->id,
                        'action'     => 'sale',
                        'quantity'   => $item['quantity'],
                        'qty_before' => $qtyBefore,
                        'qty_after'  => $qtyBefore - $item['quantity'],
                        'note'       => "Order {$order->order_number} edited",
                    ]);
                }
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
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

        RealtimeBroadcaster::send(new OrderChanged($order->id, 'updated'));
        if ($oldTableId) {
            RealtimeBroadcaster::send(new TableChanged((int) $oldTableId, 'updated'));
        }
        if ($newTableId && $newTableId != $oldTableId) {
            RealtimeBroadcaster::send(new TableChanged((int) $newTableId, 'updated'));
        }

        return response()->json(['message' => 'Order updated!', 'order' => $order]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|exists:products,id',
            'items.*.quantity'    => 'required|integer|min:1',
            'payment_method_id'   => 'nullable|exists:payment_methods,id',
            'amount_paid'         => 'required|numeric|min:0|max:' . self::MAX_AMOUNT_PAID_USD,
            'amount_paid_usd'     => 'nullable|numeric|min:0|max:' . self::MAX_AMOUNT_PAID_USD,
            'amount_paid_khr'     => 'nullable|numeric|min:0|max:' . self::MAX_AMOUNT_PAID_KHR,
            'exchange_rate_used'  => 'nullable|numeric|min:0',
            'promotion_id'        => 'nullable|exists:promotions,id',
            // 'refunded' is intentionally excluded - an order is never created
            // pre-refunded, only transitioned there via the refund() endpoint.
            'status'              => 'nullable|in:pending,completed,cancelled',
            'table_id'            => 'nullable|exists:tables,id',
            'pager_number'        => 'nullable|string|max:255',
            'order_type'          => 'nullable|in:takeaway,self-seating,dine-in',
            'idempotency_key'     => 'nullable|string|max:100',
        ]);

        $user = JWTAuth::parseToken()->authenticate();

        if ($user->role === 'cashier' && !CashierShift::where('user_id', $user->id)->where('status', 'open')->exists()) {
            return response()->json(['message' => 'Please open your shift before selling.'], 422);
        }

        // A retried checkout (network error, duplicate tab, a client bug
        // bypassing the submit-button guard) carries the same key - return
        // the order that was already created instead of charging twice.
        if ($request->idempotency_key) {
            $existing = Order::where('user_id', $user->id)
                ->where('idempotency_key', $request->idempotency_key)
                ->first();
            if ($existing) {
                $existing->load(['items.product', 'user', 'paymentMethod']);
                return response()->json(['message' => 'Order already created!', 'order' => $existing], 200);
            }
        }

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

        $subtotal = round($subtotal, 2);
        $discount = round((float) $discount, 2);
        $tax   = round((float) ($request->tax ?? 0), 2);
        $total = max(0, round($subtotal - $discount + $tax, 2));
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

        $change = round($request->amount_paid - $total, 2);
        if ($status !== 'completed') {
            $change = 0;
        }

        $idempotencyKey = $request->idempotency_key ?: null;

        // uniqid() is time-based and unique() alone would surface a collision
        // as a raw 500 - regenerate and retry a handful of times instead.
        $order = null;
        for ($attempts = 0; $attempts < 5; $attempts++) {
            $orderNumber = 'ORD-' . strtoupper(uniqid('', true));
            try {
                $order = DB::transaction(function () use ($request, $user, $orderItems, $subtotal, $discount, $tax, $total, $change, $status, $orderNumber, $idempotencyKey) {
                    $order = Order::create([
                        'order_number'      => $orderNumber,
                        'idempotency_key'   => $idempotencyKey,
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

                        // Authoritative, lock-protected recheck: the earlier qty
                        // check above is only a fast-path UX guard and isn't safe
                        // against concurrent orders racing the same product.
                        $product = Product::where('id', $item['product_id'])->lockForUpdate()->firstOrFail();
                        if ($product->qty < $item['quantity']) {
                            throw new \RuntimeException("Insufficient stock for {$product->name}!");
                        }
                        $qtyBefore = $product->qty;
                        $product->decrement('qty', $item['quantity']);

                        StockLog::create([
                            'product_id' => $product->id,
                            'user_id'    => $user->id,
                            'action'     => 'sale',
                            'quantity'   => $item['quantity'],
                            'qty_before' => $qtyBefore,
                            'qty_after'  => $qtyBefore - $item['quantity'],
                            'note'       => "Order {$order->order_number}",
                        ]);
                    }

                    return $order;
                });
                break;
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            } catch (\Illuminate\Database\QueryException $e) {
                $isDuplicateOrderNumber = $e->getCode() === '23000'
                    && str_contains($e->getMessage(), 'order_number');
                if (! $isDuplicateOrderNumber || $attempts >= 4) {
                    throw $e;
                }
                // order_number collided - loop around and try a fresh one.
            }
        }

        $order->load(['items.product', 'user', 'paymentMethod']);

        RealtimeBroadcaster::send(new OrderChanged($order->id, 'created'));
        if ($request->table_id) {
            RealtimeBroadcaster::send(new TableChanged((int) $request->table_id, 'created'));
        }

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

        $user = JWTAuth::parseToken()->authenticate();

        return DB::transaction(function () use ($request, $id, $user) {
            $order = Order::lockForUpdate()->findOrFail($id);

            // Cashiers may only move their own orders; admins can move any order.
            if ($user->role === 'cashier' && $order->user_id !== $user->id) {
                abort(403, 'You can only move your own orders.');
            }

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

            if ($oldTable) {
                RealtimeBroadcaster::send(new TableChanged($oldTable->id, 'moved'));
            }
            RealtimeBroadcaster::send(new TableChanged($newTable->id, 'moved'));
            foreach ($ordersToMove as $movingOrder) {
                RealtimeBroadcaster::send(new OrderChanged($movingOrder->id, 'moved'));
            }

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

        $user = JWTAuth::parseToken()->authenticate();

        // Cashiers may only cancel their own sales; admins can cancel any order.
        if ($user->role === 'cashier' && $order->user_id !== $user->id) {
            abort(403, 'You can only cancel your own orders.');
        }

        DB::transaction(function () use ($order, $user) {
            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                if (! $product) {
                    continue;
                }
                $qtyBefore = $product->qty;
                $product->increment('qty', $item->quantity);

                StockLog::create([
                    'product_id' => $product->id,
                    'user_id'    => $user->id,
                    'action'     => 'cancel_restore',
                    'quantity'   => $item->quantity,
                    'qty_before' => $qtyBefore,
                    'qty_after'  => $qtyBefore + $item->quantity,
                    'note'       => "Order {$order->order_number} cancelled",
                ]);
            }

            $order->update(['status' => 'cancelled']);
        });

        $this->syncTableStatusForOrder($order);

        RealtimeBroadcaster::send(new OrderChanged($order->id, 'cancelled'));
        if ($order->table_id) {
            RealtimeBroadcaster::send(new TableChanged((int) $order->table_id, 'cancelled'));
        }

        return response()->json(['message' => 'Order cancelled!', 'order' => $order]);
    }

    // Admin-only, deliberately separate from update(): a refund always
    // restores stock, requires a reason, and records who approved it - a
    // cashier flipping their own completed order's status field can't
    // silently skip any of that.
    public function refund(Request $request, int $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $order = Order::with('items')->findOrFail($id);

        if ($order->status !== 'completed') {
            return response()->json(['message' => 'Only completed orders can be refunded.'], 422);
        }

        $user = JWTAuth::parseToken()->authenticate();

        DB::transaction(function () use ($order, $user, $request) {
            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                if (! $product) {
                    continue;
                }
                $qtyBefore = $product->qty;
                $product->increment('qty', $item->quantity);

                StockLog::create([
                    'product_id' => $product->id,
                    'user_id'    => $user->id,
                    'action'     => 'refund_restore',
                    'quantity'   => $item->quantity,
                    'qty_before' => $qtyBefore,
                    'qty_after'  => $qtyBefore + $item->quantity,
                    'note'       => "Order {$order->order_number} refunded: {$request->reason}",
                ]);
            }

            $order->update([
                'status'        => 'refunded',
                'refunded_by'   => $user->id,
                'refunded_at'   => now(),
                'refund_reason' => $request->reason,
            ]);
        });

        $this->syncTableStatusForOrder($order);

        RealtimeBroadcaster::send(new OrderChanged($order->id, 'refunded'));
        if ($order->table_id) {
            RealtimeBroadcaster::send(new TableChanged((int) $order->table_id, 'refunded'));
        }

        return response()->json(['message' => 'Order refunded!', 'order' => $order->fresh(['items.product', 'user', 'paymentMethod', 'refundedBy'])]);
    }

    // Increments and returns the reprint counter for loss-prevention visibility
    // (the receipt itself is rendered client-side; this just tracks how many
    // times it's been reprinted and by whom it was last requested).
    public function recordReceiptPrint(int $id)
    {
        $order = Order::findOrFail($id);

        $user = JWTAuth::parseToken()->authenticate();
        if ($user->role === 'cashier' && $order->user_id !== $user->id) {
            abort(403, 'You can only print receipts for your own orders.');
        }

        $order->increment('receipt_reprint_count');

        return response()->json(['receipt_reprint_count' => $order->receipt_reprint_count]);
    }

    public function latest()
    {
        $order = Order::with(['paymentMethod', 'user'])
            ->where('status', 'completed')
            ->latest()
            ->first();

        // See CashierShiftController::current() - response()->json(null)
        // silently becomes "{}", not "null", so wrap it to keep the
        // falsy-check on the frontend meaningful.
        return response()->json(['order' => $order]);
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
