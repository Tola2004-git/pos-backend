<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ingredient;
use App\Models\IngredientStockLog;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class IngredientInventoryController extends Controller
{
    // Restock (add or remove)
    public function restock(Request $request)
    {
        $request->validate([
            'ingredient_id' => 'required|exists:bakery_ingredients,id',
            'action'        => 'required|in:add,remove',
            'quantity'      => 'required|numeric|min:0.01',
            'expiry_date'   => 'nullable|date',
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

        return response()->json(['message' => 'Stock updated!', 'ingredient' => $ingredient]);
    }

    // Stock History
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

        $logs = $query->latest()->paginate($request->per_page ?? 15);
        return response()->json($logs);
    }
}
