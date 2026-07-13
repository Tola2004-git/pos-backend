<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ingredient;
use App\Models\IngredientStockLog;
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
        ]);

        $ingredient = Ingredient::findOrFail($request->ingredient_id);
        $qtyBefore = $ingredient->quantity;

        if ($request->action === 'add') {
            $ingredient->quantity += $request->quantity;
        } else {
            if ($ingredient->quantity < $request->quantity) {
                return response()->json(['message' => 'Insufficient stock!'], 422);
            }
            $ingredient->quantity -= $request->quantity;
        }

        $ingredient->save();

        // Log
        $user = JWTAuth::parseToken()->authenticate();
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

        return response()->json(['message' => 'Stock updated!', 'ingredient' => $ingredient]);
    }

    // Stock History
    public function history(Request $request)
    {
        $query = IngredientStockLog::with(['ingredient', 'user']);

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
