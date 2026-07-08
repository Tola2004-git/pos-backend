<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with('category');

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('sku', 'like', '%' . $request->search . '%')
                    ->orWhere('barcode', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->category_id && $request->category_id !== 'all') {
            $query->where('category_id', $request->category_id);
        }

        if ($request->status !== null && $request->status !== 'all') {
            $query->where('status', (int) $request->status);
        }

        $perPage = $request->per_page ?? 10;
        $products = $query->latest()->paginate($perPage);

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'  => 'required|string',
            'price' => 'required|numeric|min:0',
        ]);

        $product = Product::create([
            'name'        => $request->name,
            'category_id' => $request->category_id ?? null,
            'price'       => $request->price,
            'sku'         => $request->sku ?? null,
            'barcode'     => $request->barcode ?? null,
            'qty'         => 0,
            'image'       => $request->image ?? null,
            'status'      => $request->status ?? true,
        ]);

        return response()->json(['message' => 'Product created!', 'product' => $product->load('category')]);
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $request->validate([
            'name'  => 'required|string',
            'price' => 'required|numeric|min:0',
        ]);

        $product->update([
            'name'        => $request->name,
            'category_id' => $request->category_id ?? null,
            'price'       => $request->price,
            'sku'         => $request->sku ?? null,
            'barcode'     => $request->barcode ?? null,
            'image'       => $request->image ?? $product->image,
            'status'      => $request->status ?? $product->status,
        ]);

        return response()->json(['message' => 'Product updated!', 'product' => $product->load('category')]);
    }

    public function destroy($id)
    {
        Product::findOrFail($id)->delete();
        return response()->json(['message' => 'Product deleted!']);
    }
}
