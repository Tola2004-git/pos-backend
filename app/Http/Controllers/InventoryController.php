<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
                // Lock the row for the duration of the transaction so a concurrent
                // restock/order on the same product can't read a stale qty and
                // push the stock negative (check-then-act race).
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

        return response()->json(['message' => 'Stock updated!', 'product' => $product]);
    }

    // Stock History
    public function history(Request $request)
    {
        $query = StockLog::with(['product', 'user']);

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

        $logs = $query->latest()->paginate($request->per_page ?? 15);
        return response()->json($logs);
    }
}