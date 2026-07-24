<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Events\TableChanged;
use App\Models\Table;
use App\Support\RealtimeBroadcaster;

class TableController extends Controller
{
    public function index()
    {
        $tables = Table::with(['currentOrder.items'])->get();
        return response()->json($tables);
    }

    // Counts-only feed for widgets (e.g. the dashboard) that just need the
    // available/occupied/reserved tallies and would otherwise have to pull
    // every table plus its live order/items just to count statuses client-side.
    public function counts()
    {
        $counts = Table::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'available' => (int) ($counts['available'] ?? 0),
            'occupied'  => (int) ($counts['occupied'] ?? 0),
            'reserved'  => (int) ($counts['reserved'] ?? 0),
        ]);
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

        RealtimeBroadcaster::send(new TableChanged($table->id, 'created'));

        return response()->json(['message' => 'Table created!', 'table' => $table]);
    }

    public function update(Request $request, int $id)
    {
        $table = Table::findOrFail($id);

        $request->validate([
            'name'     => ['required', 'string', Rule::unique('tables', 'name')->ignore($id)],
            'capacity' => 'required|integer|min:1|max:50',
            'status'   => 'nullable|in:available,occupied,reserved',
        ]);

        $table->update([
            'name'     => $request->name,
            'capacity' => $request->capacity,
            'status'   => $request->status ?? $table->status,
            'notes'    => $request->note ?? null,
        ]);

        RealtimeBroadcaster::send(new TableChanged($table->id, 'updated'));

        return response()->json(['message' => 'Table updated!', 'table' => $table]);
    }

    public function clear(Request $request, int $id)
    {
        $table = Table::with('currentOrder')->findOrFail($id);

        // A completed order (occupied - paid, customer just left) is safe to
        // clear away from. A pending order (reserved - held, unpaid) is not:
        // clearing the table would orphan it - still sitting in the system
        // with this table_id and its stock already deducted, but with no
        // table left to surface it on until someone stumbles onto it in the
        // Orders list. Make the cashier resolve (complete/cancel) it first.
        if ($table->currentOrder && $table->currentOrder->status === 'pending') {
            return response()->json([
                'message' => 'This table has a pending order - complete or cancel it before clearing the table.',
            ], 422);
        }

        $table->update(['status' => 'available']);

        RealtimeBroadcaster::send(new TableChanged($table->id, 'cleared'));

        return response()->json(['message' => 'Table cleared!', 'table' => $table]);
    }

    public function moveReservation(Request $request, int $id)
    {
        $request->validate([
            'target_table_id' => 'required|exists:tables,id|different:id',
        ]);

        // Locks both rows for the duration of the transaction so two
        // concurrent moves can't both read the target as "available" and
        // double-assign it - the second request's lock wait re-reads the
        // already-updated status once the first commits.
        return DB::transaction(function () use ($request, $id) {
            $table = Table::with('currentOrder')->lockForUpdate()->findOrFail($id);

            if ($table->currentOrder) {
                return response()->json([
                    'message' => 'This table has a live order - move it from the order instead.',
                ], 422);
            }

            if (! in_array($table->status, ['occupied', 'reserved'], true)) {
                return response()->json(['message' => 'This table has nothing to move.'], 422);
            }

            $target = Table::lockForUpdate()->findOrFail($request->target_table_id);

            if ($target->status !== 'available') {
                return response()->json(['message' => 'Please choose an available table to move to.'], 422);
            }

            $fromStatus = $table->status;

            $table->update(['status' => 'available']);
            $target->update(['status' => $fromStatus]);

            RealtimeBroadcaster::send(new TableChanged($table->id, 'moved'));
            RealtimeBroadcaster::send(new TableChanged($target->id, 'moved'));

            return response()->json([
                'message' => 'Table moved!',
                'from' => $table->fresh(),
                'to' => $target->fresh(),
            ]);
        });
    }

    public function destroy(int $id)
    {
        $table = Table::findOrFail($id);

        if (in_array($table->status, ['occupied', 'reserved'])) {
            return response()->json(['message' => 'Cannot delete occupied table!'], 422);
        }

        $tableId = $table->id;
        $table->delete();

        RealtimeBroadcaster::send(new TableChanged($tableId, 'deleted'));

        return response()->json(['message' => 'Table deleted!']);
    }
}