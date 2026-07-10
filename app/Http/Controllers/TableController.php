<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Table;

class TableController extends Controller
{
    public function index()
    {
        $tables = Table::with(['currentOrder.items'])->get();
        return response()->json($tables);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string',
            'capacity' => 'required|integer|min:1',
        ]);

        $table = Table::create([
            'name'     => $request->name,
            'capacity' => $request->capacity,
            'status'   => 'available',
            'note'     => $request->note ?? null,
        ]);

        return response()->json(['message' => 'Table created!', 'table' => $table]);
    }

    public function update(Request $request, int $id)
    {
        $table = Table::findOrFail($id);

        $request->validate([
            'name'     => 'required|string',
            'capacity' => 'required|integer|min:1',
        ]);

        $table->update([
            'name'     => $request->name,
            'capacity' => $request->capacity,
            'status'   => $request->status ?? $table->status,
            'note'     => $request->note ?? null,
        ]);

        return response()->json(['message' => 'Table updated!', 'table' => $table]);
    }

    public function clear(Request $request, int $id)
    {
        $table = Table::findOrFail($id);
        $table->update(['status' => 'available']);

        return response()->json(['message' => 'Table cleared!', 'table' => $table]);
    }

    public function destroy(int $id)
    {
        $table = Table::findOrFail($id);

        if (in_array($table->status, ['occupied', 'reserved'])) {
            return response()->json(['message' => 'Cannot delete occupied table!'], 422);
        }

        $table->delete();
        return response()->json(['message' => 'Table deleted!']);
    }
}