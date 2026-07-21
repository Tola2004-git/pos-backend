<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Events\OrderChanged;
use App\Events\TableChanged;
use App\Models\CashierShift;
use App\Models\Ingredient;
use App\Models\IngredientStockLog;
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

    private const MAX_AMOUNT_PAID_USD = 100000;
    private const MAX_AMOUNT_PAID_KHR = 500000000;

    public function index(Request $request)
    {
        $query = Order::query()->with(['items', 'user', 'paymentMethod', 'table', 'refundedBy']);

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

        if ($request->cashier_id) {
            $query->where('user_id', $request->cashier_id);
        }

        $orders = $query->latest()->paginate($request->per_page ?? 15);
        return response()->json($orders);
    }

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

    private function resolvePeriodRanges(string $period, $now)
    {
        switch ($period) {
            case 'week':
                $currentFrom = $now->copy()->startOfWeek();
                $previousFrom = $currentFrom->copy()->subWeek();
                $previousTo = $previousFrom->copy()->addSeconds($currentFrom->diffInSeconds($now));
                $trendFrom = $now->copy()->subWeeks(7)->startOfWeek();
                $groupExpr = "DATE_SUB(DATE(orders.created_at), INTERVAL WEEKDAY(orders.created_at) DAY)";
                break;
            case 'month':
                $currentFrom = $now->copy()->startOfMonth();
                $previousFrom = $currentFrom->copy()->subMonth();
                $previousTo = $previousFrom->copy()->addSeconds($currentFrom->diffInSeconds($now));
                $trendFrom = $now->copy()->subMonths(5)->startOfMonth();
                $groupExpr = "DATE_FORMAT(orders.created_at, '%Y-%m-01')";
                break;
            case 'year':
                $currentFrom = $now->copy()->startOfYear();
                $previousFrom = $currentFrom->copy()->subYear();
                $previousTo = $previousFrom->copy()->addSeconds($currentFrom->diffInSeconds($now));
                $trendFrom = $now->copy()->subYears(4)->startOfYear();
                $groupExpr = "DATE_FORMAT(orders.created_at, '%Y-01-01')";
                break;
            default: // day
                $currentFrom = $now->copy()->startOfDay();
                $previousFrom = $now->copy()->subDay()->startOfDay();
                $previousTo = $now->copy()->subDay()->endOfDay();
                $trendFrom = $now->copy()->subDays(6)->startOfDay();
                $groupExpr = "DATE(orders.created_at)";
                break;
        }

        return [$currentFrom, $previousFrom, $previousTo, $trendFrom, $groupExpr];
    }

    public function salesSummary(Request $request)
    {
        $period = in_array($request->period, ['day', 'week', 'month', 'year'], true)
            ? $request->period
            : 'day';

        $now = now();
        [$currentFrom, $previousFrom, $previousTo, $trendFrom, $groupExpr] =
            $this->resolvePeriodRanges($period, $now);

        $user = JWTAuth::parseToken()->authenticate();
        $scoped = function (string $status = 'completed') use ($user) {
            $q = Order::query()->where('orders.status', $status);
            if ($user->role === 'cashier') {
                $q->where('orders.user_id', $user->id);
            }
            return $q;
        };

        $summarize = function ($from, $to) use ($scoped) {
            $row = $scoped()
                ->whereBetween('orders.created_at', [$from, $to])
                ->selectRaw('COUNT(*) as orders_count, COALESCE(SUM(orders.total), 0) as total_sales')
                ->first();

            return [
                'orders_count' => (int) $row->orders_count,
                'total_sales' => (float) $row->total_sales,
            ];
        };

        $trend = $scoped()
            ->where('orders.created_at', '>=', $trendFrom)
            ->selectRaw("$groupExpr as bucket, SUM(orders.total) as total_sales")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $refundRow = $scoped('refunded')
            ->whereBetween('orders.refunded_at', [$currentFrom, $now])
            ->selectRaw('COUNT(*) as refunds_count, COALESCE(SUM(orders.total), 0) as refunds_total')
            ->first();

        return response()->json([
            'period' => $period,
            'current' => $summarize($currentFrom, $now),
            'previous' => $summarize($previousFrom, $previousTo),
            'trend' => $trend,
            'refunds' => [
                'count' => (int) $refundRow->refunds_count,
                'total' => (float) $refundRow->refunds_total,
            ],
        ]);
    }

    public function topProducts(Request $request)
    {
        $period = in_array($request->period, ['day', 'week', 'month', 'year'], true)
            ? $request->period
            : 'day';

        $now = now();
        [$currentFrom] = $this->resolvePeriodRanges($period, $now);

        $user = JWTAuth::parseToken()->authenticate();

        $query = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'completed')
            ->whereNotNull('order_items.product_id')
            ->whereBetween('orders.created_at', [$currentFrom, $now]);

        if ($user->role === 'cashier') {
            $query->where('orders.user_id', $user->id);
        }

        $topProducts = $query
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->selectRaw('
                order_items.product_id,
                order_items.product_name,
                SUM(order_items.quantity) as quantity_sold,
                SUM(order_items.subtotal) as revenue
            ')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        return response()->json($topProducts);
    }

    public function categorySales(Request $request)
    {
        $period = in_array($request->period, ['day', 'week', 'month', 'year'], true)
            ? $request->period
            : 'day';

        $now = now();
        [$currentFrom] = $this->resolvePeriodRanges($period, $now);

        $user = JWTAuth::parseToken()->authenticate();

        $query = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$currentFrom, $now]);

        if ($user->role === 'cashier') {
            $query->where('orders.user_id', $user->id);
        }

        $categorySales = $query
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('
                categories.id as category_id,
                categories.name as category_name,
                SUM(order_items.subtotal) as revenue,
                SUM(order_items.quantity) as quantity_sold
            ')
            ->orderByDesc('revenue')
            ->get();

        return response()->json($categorySales);
    }

    public function profitSummary(Request $request)
    {
        $period = in_array($request->period, ['day', 'week', 'month', 'year'], true)
            ? $request->period
            : 'day';

        $now = now();
        [$currentFrom] = $this->resolvePeriodRanges($period, $now);

        $user = JWTAuth::parseToken()->authenticate();

        $unitCosts = DB::table('product_ingredients')
            ->join('bakery_ingredients', 'bakery_ingredients.id', '=', 'product_ingredients.ingredient_id')
            ->groupBy('product_ingredients.product_id')
            ->selectRaw('product_ingredients.product_id, SUM(product_ingredients.quantity * bakery_ingredients.cost_per_unit) as unit_cost');

        $itemsQuery = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoinSub($unitCosts, 'costs', 'costs.product_id', '=', 'order_items.product_id')
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$currentFrom, $now]);

        if ($user->role === 'cashier') {
            $itemsQuery->where('orders.user_id', $user->id);
        }

        $row = $itemsQuery->selectRaw('
            COALESCE(SUM(order_items.subtotal), 0) as revenue,
            COALESCE(SUM(order_items.quantity * costs.unit_cost), 0) as cogs,
            COUNT(DISTINCT CASE WHEN costs.unit_cost IS NULL THEN order_items.product_id END) as products_without_recipe_count
        ')->first();

        $revenue = (float) $row->revenue;
        $cogs = (float) $row->cogs;
        $profit = $revenue - $cogs;

        return response()->json([
            'period' => $period,
            'revenue' => $revenue,
            'cogs' => $cogs,
            'profit' => $profit,
            'margin_pct' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0,
            'products_without_recipe_count' => (int) $row->products_without_recipe_count,
        ]);
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
                    $this->adjustIngredientStock(
                        $existingItem->product_id,
                        $existingItem->quantity,
                        'cancel_restore',
                        $user->id,
                        "Order {$order->order_number} edited",
                    );
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
                    $this->adjustIngredientStock(
                        $item['product_id'],
                        $item['quantity'],
                        'sale',
                        $user->id,
                        "Order {$order->order_number} edited",
                    );
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
                        $this->adjustIngredientStock(
                            $item['product_id'],
                            $item['quantity'],
                            'sale',
                            $user->id,
                            "Order {$order->order_number}",
                        );
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
            }
        }

        $order->load(['items.product', 'user', 'paymentMethod']);

        RealtimeBroadcaster::send(new OrderChanged($order->id, 'created'));
        if ($request->table_id) {
            RealtimeBroadcaster::send(new TableChanged((int) $request->table_id, 'created'));
        }

        if ($order->status === 'completed') {
            $exportDate = $order->created_at->toDateString();
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
                $this->adjustIngredientStock(
                    $item->product_id,
                    $item->quantity,
                    'cancel_restore',
                    $user->id,
                    "Order {$order->order_number} cancelled",
                );
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
                $this->adjustIngredientStock(
                    $item->product_id,
                    $item->quantity,
                    'refund_restore',
                    $user->id,
                    "Order {$order->order_number} refunded: {$request->reason}",
                );
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
        return response()->json(['order' => $order]);
    }

    private function adjustIngredientStock(int $productId, float $quantitySold, string $action, int $userId, string $note): void
    {
        $product = Product::with('ingredients')->find($productId);
        if (! $product) {
            return;
        }

        foreach ($product->ingredients as $ingredient) {
            $consumed = (float) $ingredient->pivot->quantity * $quantitySold;

            $row = Ingredient::where('id', $ingredient->id)->lockForUpdate()->first();
            if (! $row) {
                continue;
            }

            $qtyBefore = $row->quantity;

            if (in_array($action, ['cancel_restore', 'refund_restore'], true)) {
                $row->increment('quantity', $consumed);
            } else {
                if ($row->quantity < $consumed) {
                    throw new \RuntimeException("Insufficient stock for ingredient \"{$row->name}\" (needed for {$product->name})!");
                }
                $row->decrement('quantity', $consumed);
            }

            IngredientStockLog::create([
                'ingredient_id' => $row->id,
                'user_id'       => $userId,
                'action'        => $action,
                'quantity'      => $consumed,
                'qty_before'    => $qtyBefore,
                'qty_after'     => $row->quantity,
                'note'          => $note,
            ]);
        }
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
