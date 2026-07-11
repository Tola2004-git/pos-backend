<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'name'     => 'required|string|unique:tables,name',
            'capacity' => 'required|integer|min:1|max:50',
        ]);

        $table = Table::create([
            'name'     => $request->name,
            'capacity' => $request->capacity,
            'status'   => 'available',
            'notes'    => $request->note ?? null,
        ]);

        return response()->json(['message' => 'Table created!', 'table' => $table]);
    }

    public function update(Request $request, int $id)
    {
        $table = Table::findOrFail($id);

        $request->validate([
            'name'     => ['required', 'string', Rule::unique('tables', 'name')->ignore($id)],
            'capacity' => 'required|integer|min:1|max:50',
        ]);

        $table->update([
            'name'     => $request->name,
            'capacity' => $request->capacity,
            'status'   => $request->status ?? $table->status,
            'notes'    => $request->note ?? null,
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