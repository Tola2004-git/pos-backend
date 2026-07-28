<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockLog;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class InventoryController extends Controller
{
    // List products with stock filter
    public function index(Request $request)
    {
        $threshold = $request->threshold ?? 10;
        $query = Product::with('category');

        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('sku', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->stock_status) {
            switch ($request->stock_status) {
                case 'in_stock':
                    $query->where('qty', '>', $threshold);
                    break;
                case 'low_stock':
                    $query->where('qty', '>', 0)->where('qty', '<=', $threshold);
                    break;
                case 'out_of_stock':
                    $query->where('qty', '<=', 0);
                    break;
            }
        }

        $products = $query->latest()->paginate($request->per_page ?? 10);
        return response()->json($products);
    }

    // Restock (add or remove)
    public function restock(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'action'     => 'required|in:add,remove',
            'quantity'   => 'required|integer|min:1',
        ]);

        $user = JWTAuth::parseToken()->authenticate();

        try {
            $product = DB::transaction(function () use ($request, $user) {
                $product = Product::where('id', $request->product_id)->lockForUpdate()->firstOrFail();
                $qtyBefore = $product->qty;

                if ($request->action === 'add') {
                    $product->qty += $request->quantity;
                } else {
                    if ($product->qty < $request->quantity) {
                        throw new \RuntimeException('Insufficient stock!');
                    }
                    $product->qty -= $request->quantity;
                }

                $product->save();

                StockLog::create([
                    'product_id' => $product->id,
                    'user_id'    => $user->id,
                    'action'     => $request->action,
                    'quantity'   => $request->quantity,
                    'qty_before' => $qtyBefore,
                    'qty_after'  => $product->qty,
                    'supplier'   => $request->supplier ?? null,
                    'note'       => $request->note ?? null,
                ]);

                return $product;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::record($user->id, 'product_restocked', 'Product', $product->id, ($request->action === 'add' ? 'Added' : 'Removed') . " {$request->quantity} of \"{$product->name}\"");

        return response()->json(['message' => 'Stock updated!', 'product' => $product]);
    }

    // Stock History
    public function history(Request $request)
    {

        $query = StockLog::with(['product:id,name', 'user:id,name']);

        if ($request->product_id) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->action && $request->action !== 'all') {
            $query->where('action', $request->action);
        }

        if ($request->search) {
            $query->whereHas('product', function($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            });
        }

        // An edited order writes its cancel_restore + re-sale pair in the
        // same request, so they share an identical created_at down to the
        // second - latest() alone has no tiebreaker for that tie, so the
        // pair's display order isn't guaranteed and can show the restore as
        // if it happened after the sale that actually followed it. id is
        // monotonically increasing with insertion order, so it always
        // resolves ties correctly.
        $logs = $query->latest()->orderByDesc('id')->paginate($request->per_page ?? 15);
        return response()->json($logs);
    }
}