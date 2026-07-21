<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        // `fields` is an opt-in lightweight mode for callers (e.g. the
        // global low-stock poller) that only need a couple of columns -
        // skipping the default `image` column matters because it can carry
        // several hundred KB of inline base64 data per product, and this
        // endpoint is polled every 30s from every page in the app.
        if ($request->fields) {
            $columns = collect(explode(',', $request->fields))
                ->map(fn ($c) => trim($c))
                ->filter()
                ->push('id')
                ->unique()
                ->values()
                ->all();
            $query = Product::query()->select($columns);
        } else {
            $query = Product::with('category');
        }

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
            'name'    => 'required|string',
            'price'   => 'required|numeric|min:0',
            'sku'     => 'nullable|string|unique:products,sku',
            'barcode' => 'nullable|string|unique:products,barcode',
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
            'name'    => 'required|string',
            'price'   => 'required|numeric|min:0',
            'sku'     => 'nullable|string|unique:products,sku,' . $id,
            'barcode' => 'nullable|string|unique:products,barcode,' . $id,
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
        $product = Product::findOrFail($id);

        if ($product->orderItems()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a product with existing sales history. Please change its status to Inactive instead.',
            ], 422);
        }

        $product->delete();
        return response()->json(['message' => 'Product deleted!']);
    }

    // The recipe (which ingredients, and how much of each, go into one unit
    // of this product) - drives the dashboard's COGS/profit estimate.
    public function ingredients($id)
    {
        $product = Product::with('ingredients')->findOrFail($id);

        return response()->json($product->ingredients);
    }

    public function syncIngredients(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $request->validate([
            'ingredients'               => 'array',
            'ingredients.*.ingredient_id' => 'required|exists:bakery_ingredients,id',
            'ingredients.*.quantity'      => 'required|numeric|min:0.001',
        ]);

        $sync = collect($request->ingredients ?? [])
            ->keyBy('ingredient_id')
            ->map(fn ($row) => ['quantity' => $row['quantity']])
            ->toArray();

        $product->ingredients()->sync($sync);

        return response()->json([
            'message'     => 'Recipe updated!',
            'ingredients' => $product->ingredients()->get(),
        ]);
    }
}
