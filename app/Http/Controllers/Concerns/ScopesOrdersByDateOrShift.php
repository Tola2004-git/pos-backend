<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use App\Models\CashierShift;

trait ScopesOrdersByDateOrShift
{
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
}
