<?php

namespace App\Http\Controllers;

use App\Events\ShiftChanged;
use App\Models\AuditLog;
use App\Models\CashierCashMovement;
use App\Models\CashierShift;
use App\Models\Order;
use App\Models\User;
use App\Support\RealtimeBroadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'opening_cash_usd' => 'required|numeric|min:0|max:100000',
            'opening_cash_khr' => 'nullable|numeric|min:0|max:500000000',
        ]);

        $user = JWTAuth::parseToken()->authenticate();

        return DB::transaction(function () use ($request, $user) {
            // Locks the user's own row as a mutex - there's no "open shift"
            // row to lock before one exists, so this serializes concurrent
            // open() calls from the same user instead (a double-click or two
            // tabs both racing to open one), closing the window where both
            // could pass the "no open shift yet" check before either commits.
            User::where('id', $user->id)->lockForUpdate()->first();

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

            RealtimeBroadcaster::send(new ShiftChanged($shift->id, 'opened'));

            return response()->json(['shift' => $shift]);
        });
    }

    public function close(Request $request, int $id)
    {
        $request->validate([
            'counted_cash_usd' => 'required|numeric|min:0|max:100000',
            'counted_cash_khr' => 'nullable|numeric|min:0|max:500000000',
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
        // amount_paid_usd/amount_paid_khr record what the customer handed
        // over (gross tender), not what's left in the drawer after change is
        // given back - summing them as-is overstates expected cash by every
        // change amount. Change is physically given back in KHR (small riel
        // notes stand in for USD coins), regardless of which currency was
        // tendered, so it's netted out of the KHR side only, converted at
        // that order's own exchange rate.
        $cashTotals = Order::where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$shift->opened_at, $closedAt])
            ->whereHas('paymentMethod', function ($q) {
                $q->where('is_cash', true);
            })
            ->selectRaw("
                COALESCE(SUM(amount_paid_usd), 0) as total_usd,
                COALESCE(SUM(amount_paid_khr) - SUM(change_amount * COALESCE(exchange_rate_used, 4100)), 0) as total_khr
            ")
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

        RealtimeBroadcaster::send(new ShiftChanged($shift->id, 'closed'));

        return response()->json(['shift' => $shift->fresh()]);
    }

    public function addCashMovement(Request $request, int $id)
    {
        $request->validate([
            'type'             => 'required|in:cash_in,cash_out',
            'amount_usd'       => 'nullable|numeric|min:0|max:100000',
            'amount_khr'       => 'nullable|numeric|min:0|max:500000000',
            'reason'           => 'required|string|max:255',
            'idempotency_key'  => 'nullable|string|max:100',
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

        // Guards against a fast double-tap or a retried request silently
        // recording the same cash movement twice and skewing close()'s
        // reconciliation - same pattern as Order::store()'s idempotency_key.
        if ($request->idempotency_key) {
            $existing = CashierCashMovement::where('idempotency_key', $request->idempotency_key)->first();
            if ($existing) {
                return response()->json(['movement' => $existing], 200);
            }
        }

        try {
            $movement = $shift->cashMovements()->create([
                'user_id'         => $user->id,
                'type'            => $request->type,
                'amount_usd'      => $request->amount_usd ?? 0,
                'amount_khr'      => $request->amount_khr ?? 0,
                'reason'          => $request->reason,
                'idempotency_key' => $request->idempotency_key ?: null,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000' && $request->idempotency_key) {
                $existing = CashierCashMovement::where('idempotency_key', $request->idempotency_key)->first();
                if ($existing) {
                    return response()->json(['movement' => $existing], 200);
                }
            }
            throw $e;
        }

        RealtimeBroadcaster::send(new ShiftChanged($shift->id, 'cash_movement'));

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

        AuditLog::record($user->id, 'shift_reviewed', 'CashierShift', $shift->id, "Reviewed shift for \"{$shift->user->name}\"" . ($request->review_note ? ": {$request->review_note}" : ''));

        RealtimeBroadcaster::send(new ShiftChanged($shift->id, 'reviewed'));

        return response()->json(['shift' => $shift->fresh(['user', 'reviewer'])]);
    }
}
