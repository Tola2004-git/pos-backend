<?php

namespace App\Http\Controllers;

use App\Models\CashierShift;
use App\Models\Order;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class CashierShiftController extends Controller
{
    // Cashiers only ever see their own shifts (enforced server-side, same
    // pattern as OrderController) - admins see everyone's, for the review queue.
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

    public function show(int $id)
    {
        $shift = CashierShift::with(['user', 'reviewer'])->findOrFail($id);

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

        // Symfony's JsonResponse constructor replaces a null $data with an
        // empty \ArrayObject() (its documented XSSI-safety convention), so
        // response()->json(null) actually serializes to "{}", not "null".
        // Wrapping in a key sidesteps that - the outer array is never null,
        // so json_encode() emits a real {"shift":null} the client can check.
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

        // Cash sales rung up by this cashier during the shift window -
        // matched by payment method name since PaymentMethod has no
        // dedicated "type" column (see usePOS.js's own cash-detection logic).
        $cashTotals = Order::where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$shift->opened_at, $closedAt])
            ->whereHas('paymentMethod', function ($q) {
                $q->whereRaw('LOWER(name) = ?', ['cash']);
            })
            ->selectRaw('COALESCE(SUM(amount_paid_usd), 0) as total_usd, COALESCE(SUM(amount_paid_khr), 0) as total_khr')
            ->first();

        $expectedUsd = (float) $shift->opening_cash_usd + (float) $cashTotals->total_usd;
        $expectedKhr = (float) $shift->opening_cash_khr + (float) $cashTotals->total_khr;

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
