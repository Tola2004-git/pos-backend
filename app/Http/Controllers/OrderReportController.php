<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Http\Controllers\Concerns\ScopesOrdersByDateOrShift;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrderReportController extends Controller
{
    use ScopesOrdersByDateOrShift;

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
                SUM(CASE WHEN payment_methods.is_cash = 1 THEN orders.amount_paid_khr - (orders.change_amount * COALESCE(orders.exchange_rate_used, 4100)) ELSE 0 END) as cash_khr_total,
                SUM(CASE WHEN payment_methods.name IS NOT NULL AND payment_methods.is_cash = 0 THEN orders.total ELSE 0 END) as digital_total
            ')
            ->orderByDesc('total_sales')
            ->get();

        return response()->json($summary);
    }

    private function dateGroupExpr(string $granularity): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return match ($granularity) {
                'week' => "date(orders.created_at, '-' || ((CAST(strftime('%w', orders.created_at) AS INTEGER) + 6) % 7) || ' days')",
                'month' => "strftime('%Y-%m-01', orders.created_at)",
                'year' => "strftime('%Y-01-01', orders.created_at)",
                default => "DATE(orders.created_at)",
            };
        }

        return match ($granularity) {
            'week' => "DATE_SUB(DATE(orders.created_at), INTERVAL WEEKDAY(orders.created_at) DAY)",
            'month' => "DATE_FORMAT(orders.created_at, '%Y-%m-01')",
            'year' => "DATE_FORMAT(orders.created_at, '%Y-01-01')",
            default => "DATE(orders.created_at)",
        };
    }

    // CONCAT() is MySQL/Postgres syntax; SQLite has no CONCAT function and
    // uses the || operator instead.
    private function concatExpr(string $prefix, string $column): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "'{$prefix}' || {$column}";
        }

        return "CONCAT('{$prefix}', {$column})";
    }

    private function resolvePeriodRanges(string $period, $now)
    {
        switch ($period) {
            case 'week':
                $currentFrom = $now->copy()->startOfWeek();
                $previousFrom = $currentFrom->copy()->subWeek();
                $previousTo = $previousFrom->copy()->addSeconds($currentFrom->diffInSeconds($now));
                $trendFrom = $now->copy()->subWeeks(7)->startOfWeek();
                $groupExpr = $this->dateGroupExpr('week');
                break;
            case 'month':
                $currentFrom = $now->copy()->startOfMonth();
                $previousFrom = $currentFrom->copy()->subMonth();
                $previousTo = $previousFrom->copy()->addSeconds($currentFrom->diffInSeconds($now));
                $trendFrom = $now->copy()->subMonths(5)->startOfMonth();
                $groupExpr = $this->dateGroupExpr('month');
                break;
            case 'year':
                $currentFrom = $now->copy()->startOfYear();
                $previousFrom = $currentFrom->copy()->subYear();
                $previousTo = $previousFrom->copy()->addSeconds($currentFrom->diffInSeconds($now));
                $trendFrom = $now->copy()->subYears(4)->startOfYear();
                $groupExpr = $this->dateGroupExpr('year');
                break;
            default:
                $currentFrom = $now->copy()->startOfDay();
                $previousFrom = $now->copy()->subDay()->startOfDay();
                $previousTo = $previousFrom->copy()->addSeconds($currentFrom->diffInSeconds($now));
                $trendFrom = $now->copy()->subDays(6)->startOfDay();
                $groupExpr = $this->dateGroupExpr('day');
                break;
        }

        return [$currentFrom, $previousFrom, $previousTo, $trendFrom, $groupExpr];
    }

    private function resolveRange(Request $request, $now): array
    {
        $period = in_array($request->period, ['day', 'week', 'month', 'year'], true)
            ? $request->period
            : 'day';

        if ($request->date_from && $request->date_to) {
            try {
                $currentFrom = Carbon::parse($request->date_from)->startOfDay();
                $currentTo = Carbon::parse($request->date_to)->endOfDay();
            } catch (\Exception $e) {
                $currentFrom = null;
            }

            if ($currentFrom && $currentFrom->lte($currentTo)) {
                if ($currentFrom->diffInDays($currentTo) + 1 > OrderController::MAX_CUSTOM_RANGE_DAYS) {
                    $currentFrom = $currentTo->copy()->subDays(OrderController::MAX_CUSTOM_RANGE_DAYS - 1)->startOfDay();
                }

                $days = $currentFrom->diffInDays($currentTo) + 1;
                $previousTo = $currentFrom->copy()->subSecond();
                $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

                if ($days <= 35) {
                    $groupExpr = $this->dateGroupExpr('day');
                } elseif ($days <= 180) {
                    $groupExpr = $this->dateGroupExpr('week');
                } else {
                    $groupExpr = $this->dateGroupExpr('month');
                }

                return [$period, $currentFrom, $currentTo, $previousFrom, $previousTo, $currentFrom, $groupExpr];
            }
        }

        [$currentFrom, $previousFrom, $previousTo, $trendFrom, $groupExpr] =
            $this->resolvePeriodRanges($period, $now);

        return [$period, $currentFrom, $now, $previousFrom, $previousTo, $trendFrom, $groupExpr];
    }

    public function salesSummary(Request $request)
    {
        $now = now();
        [$period, $currentFrom, $currentTo, $previousFrom, $previousTo, $trendFrom, $groupExpr] =
            $this->resolveRange($request, $now);

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
            ->where('orders.created_at', '<=', $currentTo)
            ->selectRaw("$groupExpr as bucket, SUM(orders.total) as total_sales")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $refundsBetween = function ($from, $to) use ($scoped) {
            $row = $scoped('refunded')
                ->whereBetween('orders.refunded_at', [$from, $to])
                ->selectRaw('COUNT(*) as refunds_count, COALESCE(SUM(orders.total), 0) as refunds_total')
                ->first();

            return [
                'count' => (int) $row->refunds_count,
                'total' => (float) $row->refunds_total,
            ];
        };

        $refunds = $refundsBetween($currentFrom, $currentTo);
        $previousRefunds = $refundsBetween($previousFrom, $previousTo);

        return response()->json([
            'period' => $period,
            'current' => $summarize($currentFrom, $currentTo),
            'previous' => $summarize($previousFrom, $previousTo),
            'trend' => $trend,
            'refunds' => $refunds + ['previous_total' => $previousRefunds['total']],
        ]);
    }

    public function topProducts(Request $request)
    {
        $now = now();
        [, $currentFrom, $currentTo] = $this->resolveRange($request, $now);

        $user = JWTAuth::parseToken()->authenticate();

        $query = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'completed')
            ->whereNotNull('order_items.product_id')
            ->whereBetween('orders.created_at', [$currentFrom, $currentTo]);

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
        $now = now();
        [, $currentFrom, $currentTo] = $this->resolveRange($request, $now);

        $user = JWTAuth::parseToken()->authenticate();

        $query = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$currentFrom, $currentTo]);

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
        $now = now();
        [$period, $currentFrom, $currentTo] = $this->resolveRange($request, $now);

        $unitCosts = DB::table('product_ingredients')
            ->join('bakery_ingredients', 'bakery_ingredients.id', '=', 'product_ingredients.ingredient_id')
            ->groupBy('product_ingredients.product_id')
            ->selectRaw('product_ingredients.product_id, SUM(product_ingredients.quantity * bakery_ingredients.cost_per_unit) as unit_cost');

        $itemsQuery = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoinSub($unitCosts, 'costs', 'costs.product_id', '=', 'order_items.product_id')
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$currentFrom, $currentTo]);

        $productKey = $this->concatExpr('p', 'order_items.product_id');
        $itemKey = $this->concatExpr('i', 'order_items.id');

        $row = $itemsQuery->selectRaw("
            COALESCE(SUM(order_items.subtotal), 0) as revenue,
            COALESCE(SUM(order_items.quantity * costs.unit_cost), 0) as cogs,
            COUNT(DISTINCT CASE WHEN costs.unit_cost IS NULL THEN
                COALESCE($productKey, $itemKey)
            END) as products_without_recipe_count
        ")->first();

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
}
