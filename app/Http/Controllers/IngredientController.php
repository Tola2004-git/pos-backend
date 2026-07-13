<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ingredient;

class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $query = Ingredient::with('category');

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('supplier', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->category_id && $request->category_id !== 'all') {
            $query->where('category_id', $request->category_id);
        }

        if ($request->status !== null && $request->status !== 'all') {
            $query->where('status', (int) $request->status);
        }

        if ($request->stock_status) {
            switch ($request->stock_status) {
                case 'in_stock':
                    $query->whereColumn('quantity', '>', 'low_stock_threshold');
                    break;
                case 'low_stock':
                    $query->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'low_stock_threshold');
                    break;
                case 'out_of_stock':
                    $query->where('quantity', '<=', 0);
                    break;
            }
        }

        $perPage = $request->per_page ?? 10;
        $ingredients = $query->latest()->paginate($perPage);

        return response()->json($ingredients);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'                 => 'required|string',
            'unit'                 => 'required|string',
            'quantity'             => 'nullable|numeric|min:0',
            'low_stock_threshold'  => 'nullable|numeric|min:0',
            'cost_per_unit'        => 'nullable|numeric|min:0',
        ]);

        $ingredient = Ingredient::create([
            'name'                 => $request->name,
            'category_id'          => $request->category_id ?? null,
            'unit'                 => $request->unit,
            'quantity'             => $request->quantity ?? 0,
            'low_stock_threshold'  => $request->low_stock_threshold ?? 0,
            'cost_per_unit'        => $request->cost_per_unit ?? 0,
            'supplier'             => $request->supplier ?? null,
            'status'               => $request->status ?? true,
            'note'                 => $request->note ?? null,
        ]);

        return response()->json(['message' => 'Ingredient created!', 'ingredient' => $ingredient->load('category')]);
    }

    public function update(Request $request, $id)
    {
        $ingredient = Ingredient::findOrFail($id);

        $request->validate([
            'name'                 => 'required|string',
            'unit'                 => 'required|string',
            'quantity'             => 'nullable|numeric|min:0',
            'low_stock_threshold'  => 'nullable|numeric|min:0',
            'cost_per_unit'        => 'nullable|numeric|min:0',
        ]);

        $ingredient->update([
            'name'                 => $request->name,
            'category_id'          => $request->category_id ?? null,
            'unit'                 => $request->unit,
            'low_stock_threshold'  => $request->low_stock_threshold ?? $ingredient->low_stock_threshold,
            'cost_per_unit'        => $request->cost_per_unit ?? $ingredient->cost_per_unit,
            'supplier'             => $request->supplier ?? null,
            'status'               => $request->status ?? $ingredient->status,
            'note'                 => $request->note ?? null,
        ]);

        return response()->json(['message' => 'Ingredient updated!', 'ingredient' => $ingredient->load('category')]);
    }

    public function destroy($id)
    {
        $ingredient = Ingredient::findOrFail($id);

        if ($ingredient->stockLogs()->exists()) {
            return response()->json([
                'message' => 'Cannot delete an ingredient with existing stock history. Please change its status to Inactive instead.',
            ], 422);
        }

        $ingredient->delete();
        return response()->json(['message' => 'Ingredient deleted!']);
    }
}
