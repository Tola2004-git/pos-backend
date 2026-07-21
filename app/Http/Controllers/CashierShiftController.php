<?php

namespace App\Http\Controllers;

use App\Models\CashierCashMovement;
use App\Models\CashierShift;
use App\Models\Order;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class CashierShiftController extends Controller
{
    public function index(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $query = CashierShift::with(['user', 'reviewer'])->latest('opened_at');

        if ($user->role === 'cashier') {
            $query->where('user_id', $user->id);
        }

        if ($request->status && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        return response()->json($query->paginate($request->per_page ?? 15));
    }

    public function cashMovementsSummary(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $query = CashierCashMovement::query();

        if ($user->role === 'cashier') {
            $query->where('user_id', $user->id);
        }

        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $row = $query->selectRaw("
            COALESCE(SUM(CASE WHEN type = 'cash_in' THEN amount_usd ELSE 0 END), 0) as cash_in_usd,
            COALESCE(SUM(CASE WHEN type = 'cash_out' THEN amount_usd ELSE 0 END), 0) as cash_out_usd,
            COALESCE(SUM(CASE WHEN type = 'cash_in' THEN amount_khr ELSE 0 END), 0) as cash_in_khr,
            COALESCE(SUM(CASE WHEN type = 'cash_out' THEN amount_khr ELSE 0 END), 0) as cash_out_khr,
            COUNT(*) as movements_count
        ")->first();

        return response()->json([
            'cash_in_usd' => (float) $row->cash_in_usd,
            'cash_out_usd' => (float) $row->cash_out_usd,
            'cash_in_khr' => (float) $row->cash_in_khr,
            'cash_out_khr' => (float) $row->cash_out_khr,
            'net_usd' => (float) $row->cash_in_usd - (float) $row->cash_out_usd,
            'net_khr' => (float) $row->cash_in_khr - (float) $row->cash_out_khr,
            'movements_count' => (int) $row->movements_count,
        ]);
    }

    public function show(int $id)
    {
        $shift = CashierShift::with(['user', 'reviewer', 'cashMovements'])->findOrFail($id);

        $user = JWTAuth::parseToken()->authenticate();
        if ($user->role === 'cashier' && $shift->user_id !== $user->id) {
            abort(403, 'You can only view your own shifts.');
        }

        return response()->json($shift);
    }

    public function current()
    {
        $user = JWTAuth::parseToken()->authenticate();

        $shift = CashierShift::where('user_id', $user->id)
            ->where('status', 'open')
            ->first();
        return response()->json(['shift' => $shift]);
    }

    public function open(Request $request)
    {
        $request->validate([
            'opening_cash_usd' => 'required|numeric|min:0',
            'opening_cash_khr' => 'nullable|numeric|min:0',
        ]);

        $user = JWTAuth::parseToken()->authenticate();

        if (CashierShift::where('user_id', $user->id)->where('status', 'open')->exists()) {
            return response()->json(['message' => 'You already have an open shift.'], 422);
        }

        $shift = CashierShift::create([
            'user_id'          => $user->id,
            'opened_at'        => now(),
            'opening_cash_usd' => $request->opening_cash_usd,
            'opening_cash_khr' => $request->opening_cash_khr ?? 0,
            'status'           => 'open',
        ]);

        return response()->json(['shift' => $shift]);
    }

    public function close(Request $request, int $id)
    {
        $request->validate([
            'counted_cash_usd' => 'required|numeric|min:0',
            'counted_cash_khr' => 'nullable|numeric|min:0',
            'note'             => 'nullable|string',
        ]);

        $shift = CashierShift::findOrFail($id);
        $user = JWTAuth::parseToken()->authenticate();

        if ($shift->user_id !== $user->id) {
            abort(403, 'You can only close your own shift.');
        }

        if ($shift->status !== 'open') {
            return response()->json(['message' => 'This shift is already closed.'], 422);
        }

        $closedAt = now();
        $cashTotals = Order::where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$shift->opened_at, $closedAt])
            ->whereHas('paymentMethod', function ($q) {
                $q->where('is_cash', true);
            })
            ->selectRaw('COALESCE(SUM(amount_paid_usd), 0) as total_usd, COALESCE(SUM(amount_paid_khr), 0) as total_khr')
            ->first();

        $movementTotals = $shift->cashMovements()
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'cash_in' THEN amount_usd ELSE 0 END), 0)
                    - COALESCE(SUM(CASE WHEN type = 'cash_out' THEN amount_usd ELSE 0 END), 0) as net_usd,
                COALESCE(SUM(CASE WHEN type = 'cash_in' THEN amount_khr ELSE 0 END), 0)
                    - COALESCE(SUM(CASE WHEN type = 'cash_out' THEN amount_khr ELSE 0 END), 0) as net_khr
            ")
            ->first();

        $expectedUsd = (float) $shift->opening_cash_usd + (float) $cashTotals->total_usd + (float) $movementTotals->net_usd;
        $expectedKhr = (float) $shift->opening_cash_khr + (float) $cashTotals->total_khr + (float) $movementTotals->net_khr;

        $shift->update([
            'closed_at'         => $closedAt,
            'expected_cash_usd' => $expectedUsd,
            'expected_cash_khr' => $expectedKhr,
            'counted_cash_usd'  => $request->counted_cash_usd,
            'counted_cash_khr'  => $request->counted_cash_khr ?? 0,
            'variance_usd'      => $request->counted_cash_usd - $expectedUsd,
            'variance_khr'      => ($request->counted_cash_khr ?? 0) - $expectedKhr,
            'note'              => $request->note,
            'status'            => 'pending_review',
        ]);

        return response()->json(['shift' => $shift->fresh()]);
    }

    public function addCashMovement(Request $request, int $id)
    {
        $request->validate([
            'type'        => 'required|in:cash_in,cash_out',
            'amount_usd'  => 'nullable|numeric|min:0|max:100000',
            'amount_khr'  => 'nullable|numeric|min:0|max:500000000',
            'reason'      => 'required|string|max:255',
        ]);

        if ((float) ($request->amount_usd ?? 0) <= 0 && (float) ($request->amount_khr ?? 0) <= 0) {
            return response()->json(['message' => 'Enter an amount in USD or KHR.'], 422);
        }

        $shift = CashierShift::findOrFail($id);
        $user = JWTAuth::parseToken()->authenticate();

        if ($shift->user_id !== $user->id) {
            abort(403, 'You can only record cash movements on your own shift.');
        }

        if ($shift->status !== 'open') {
            return response()->json(['message' => 'This shift is already closed.'], 422);
        }

        $movement = $shift->cashMovements()->create([
            'user_id'    => $user->id,
            'type'       => $request->type,
            'amount_usd' => $request->amount_usd ?? 0,
            'amount_khr' => $request->amount_khr ?? 0,
            'reason'     => $request->reason,
        ]);

        return response()->json(['movement' => $movement], 201);
    }

    public function review(Request $request, int $id)
    {
        $request->validate([
            'review_note' => 'nullable|string',
        ]);

        $shift = CashierShift::findOrFail($id);

        if ($shift->status !== 'pending_review') {
            return response()->json(['message' => 'This shift is not awaiting review.'], 422);
        }

        $user = JWTAuth::parseToken()->authenticate();

        $shift->update([
            'status'      => 'reviewed',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_note' => $request->review_note,
        ]);

        return response()->json(['shift' => $shift->fresh(['user', 'reviewer'])]);
    }
}
