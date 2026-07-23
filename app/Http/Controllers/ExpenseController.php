<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class ExpenseController extends Controller
{
    public const CATEGORIES = ['rent', 'utilities', 'salary', 'supplies', 'maintenance', 'other'];

    public function index(Request $request)
    {
        $query = Expense::with('user:id,name');

        if ($request->search) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        if ($request->category && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        if ($request->date_from) {
            $query->whereDate('expense_date', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('expense_date', '<=', $request->date_to);
        }

        $expenses = $query->orderByDesc('expense_date')->latest('id')->paginate($request->per_page ?? 15);

        return response()->json($expenses);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'        => 'required|string|max:255',
            'category'     => 'required|in:' . implode(',', self::CATEGORIES),
            'amount_usd'   => 'nullable|numeric|min:0',
            'amount_khr'   => 'nullable|numeric|min:0',
            'expense_date' => 'required|date',
            'note'         => 'nullable|string',
        ]);

        if ((float) ($data['amount_usd'] ?? 0) <= 0 && (float) ($data['amount_khr'] ?? 0) <= 0) {
            return response()->json(['message' => 'Enter an amount in USD or KHR.'], 422);
        }

        $user = JWTAuth::parseToken()->authenticate();

        $expense = Expense::create([
            ...$data,
            'amount_usd' => $data['amount_usd'] ?? 0,
            'amount_khr' => $data['amount_khr'] ?? 0,
            'user_id'    => $user->id,
        ]);

        return response()->json(['message' => 'Expense recorded!', 'data' => $expense->load('user:id,name')], 201);
    }

    public function update(Request $request, int $id)
    {
        $expense = Expense::findOrFail($id);

        $data = $request->validate([
            'title'        => 'required|string|max:255',
            'category'     => 'required|in:' . implode(',', self::CATEGORIES),
            'amount_usd'   => 'nullable|numeric|min:0',
            'amount_khr'   => 'nullable|numeric|min:0',
            'expense_date' => 'required|date',
            'note'         => 'nullable|string',
        ]);

        if ((float) ($data['amount_usd'] ?? 0) <= 0 && (float) ($data['amount_khr'] ?? 0) <= 0) {
            return response()->json(['message' => 'Enter an amount in USD or KHR.'], 422);
        }

        $expense->update([
            ...$data,
            'amount_usd' => $data['amount_usd'] ?? 0,
            'amount_khr' => $data['amount_khr'] ?? 0,
        ]);

        return response()->json(['message' => 'Expense updated!', 'data' => $expense->load('user:id,name')]);
    }

    public function destroy(int $id)
    {
        $expense = Expense::findOrFail($id);
        $user = JWTAuth::parseToken()->authenticate();

        $expense->delete();

        AuditLog::record($user->id, 'expense_deleted', 'Expense', $id, "Deleted expense \"{$expense->title}\"");

        return response()->json(['message' => 'Expense deleted!']);
    }

    public function summary(Request $request)
    {
        $period = in_array($request->period, ['day', 'week', 'month', 'year'], true)
            ? $request->period
            : 'day';

        $now = now();

        if ($request->date_from && $request->date_to) {
            try {
                $fromDate = Carbon::parse($request->date_from)->startOfDay();
                $toDate = Carbon::parse($request->date_to)->startOfDay();
            } catch (\Exception $e) {
                $fromDate = null;
            }

            if ($fromDate && $fromDate->lte($toDate)) {
                // Mirrors OrderController::MAX_CUSTOM_RANGE_DAYS so a stray
                // multi-year range can't be requested from either endpoint.
                if ($fromDate->diffInDays($toDate) + 1 > OrderController::MAX_CUSTOM_RANGE_DAYS) {
                    $fromDate = $toDate->copy()->subDays(OrderController::MAX_CUSTOM_RANGE_DAYS - 1);
                }
                $from = $fromDate->toDateString();
                $to = $toDate->toDateString();
            }
        }

        if (! isset($from, $to)) {
            $from = (match ($period) {
                'week'  => $now->copy()->startOfWeek(),
                'month' => $now->copy()->startOfMonth(),
                'year'  => $now->copy()->startOfYear(),
                default => $now->copy()->startOfDay(),
            })->toDateString();
            $to = $now->toDateString();
        }

        $query = Expense::whereBetween('expense_date', [$from, $to]);

        $totals = (clone $query)
            ->selectRaw('COALESCE(SUM(amount_usd), 0) as total_usd, COALESCE(SUM(amount_khr), 0) as total_khr, COUNT(*) as expenses_count')
            ->first();

        $byCategory = (clone $query)
            ->selectRaw('category, COALESCE(SUM(amount_usd), 0) as total_usd, COALESCE(SUM(amount_khr), 0) as total_khr')
            ->groupBy('category')
            ->orderByDesc('total_usd')
            ->get();

        return response()->json([
            'period'          => $period,
            'total_usd'       => (float) $totals->total_usd,
            'total_khr'       => (float) $totals->total_khr,
            'expenses_count'  => (int) $totals->expenses_count,
            'by_category'     => $byCategory,
        ]);
    }
}
