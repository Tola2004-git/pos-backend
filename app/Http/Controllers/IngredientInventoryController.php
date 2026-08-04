<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AuditLog;
use App\Models\Ingredient;
use App\Models\IngredientStockLog;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class IngredientInventoryController extends Controller
{
    public function restock(Request $request)
    {
        $request->validate([
            'ingredient_id'  => 'required|exists:bakery_ingredients,id',
            'action'         => 'required|in:add,remove',
            'quantity'       => 'required|numeric|min:0.01',
            'cost_per_unit'  => 'nullable|numeric|min:0',
            'expiry_date'    => 'nullable|date',
        ]);

        $user = JWTAuth::parseToken()->authenticate();

        try {
            $ingredient = DB::transaction(function () use ($request, $user) {
                $ingredient = Ingredient::where('id', $request->ingredient_id)->lockForUpdate()->firstOrFail();
                $qtyBefore = $ingredient->quantity;

                if ($request->action === 'add') {
                    $ingredient->quantity += $request->quantity;
                    if ($request->filled('expiry_date')) {
                        $ingredient->expiry_date = $request->expiry_date;
                    }
                    // Restocking is when the admin actually knows the fresh
                    // purchase price - let it update cost_per_unit here too
                    // instead of requiring a separate Edit Ingredient trip.
                    // Optional: omitting it (null) leaves the old cost as-is.
                    if ($request->filled('cost_per_unit')) {
                        $ingredient->cost_per_unit = $request->cost_per_unit;
                    }
                } else {
                    if ($ingredient->quantity < $request->quantity) {
                        throw new \RuntimeException('Insufficient stock!');
                    }
                    $ingredient->quantity -= $request->quantity;
                }

                $ingredient->save();

                IngredientStockLog::create([
                    'ingredient_id' => $ingredient->id,
                    'user_id'       => $user->id,
                    'action'        => $request->action,
                    'quantity'      => $request->quantity,
                    'qty_before'    => $qtyBefore,
                    'qty_after'     => $ingredient->quantity,
                    'supplier'      => $request->supplier ?? null,
                    'note'          => $request->note ?? null,
                ]);

                return $ingredient;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::record($user->id, 'ingredient_restocked', 'Ingredient', $ingredient->id, ($request->action === 'add' ? 'Added' : 'Removed') . " {$request->quantity} of \"{$ingredient->name}\"");

        return response()->json(['message' => 'Stock updated!', 'ingredient' => $ingredient]);
    }

    public function history(Request $request)
    {
        $query = IngredientStockLog::with(['ingredient:id,name,unit', 'user:id,name']);

        if ($request->ingredient_id) {
            $query->where('ingredient_id', $request->ingredient_id);
        }

        if ($request->action && $request->action !== 'all') {
            $query->where('action', $request->action);
        }

        if ($request->search) {
            $query->whereHas('ingredient', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            });
        }

        // See InventoryController::history() for why id is needed as a
        // tiebreaker: an edited order's cancel_restore + re-sale pair share
        // an identical created_at, and latest() alone doesn't guarantee
        // their relative order.
        $logs = $query->latest()->orderByDesc('id')->paginate($request->per_page ?? 15);
        return response()->json($logs);
    }
}
