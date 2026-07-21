<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AuditLog;
use App\Models\Promotion;
use Illuminate\Support\Facades\Auth;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $query = Promotion::query();

        if ($request->search) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->status !== null && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        return response()->json(
            $query->with(['products:id,name,image', 'categories:id,name'])->latest()->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255|unique:promotions,name',
            'type'         => 'required|in:percentage,fixed,bogo',
            'value'        => 'required|numeric|min:0',
            'apply_to'     => 'required|in:all,product,category',
            'min_purchase' => 'nullable|numeric|min:0',
            'start_date'   => 'nullable|date',
            'end_date'     => 'nullable|date|after_or_equal:start_date',
            'status'       => 'boolean',
            'product_ids'  => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
        ]);

        $promotion = Promotion::create($data);

        if ($data['apply_to'] === 'product' && !empty($data['product_ids'])) {
            $promotion->products()->sync($data['product_ids']);
        }

        if ($data['apply_to'] === 'category' && !empty($data['category_ids'])) {
            $promotion->categories()->sync($data['category_ids']);
        }

        return response()->json(['message' => 'Promotion created!', 'data' => $promotion], 201);
    }

    public function update(Request $request, int $id)
    {
        $promotion = Promotion::findOrFail($id);

        $data = $request->validate([
            'name'         => 'required|string|max:255|unique:promotions,name,' . $promotion->id,
            'type'         => 'required|in:percentage,fixed,bogo',
            'value'        => 'required|numeric|min:0',
            'apply_to'     => 'required|in:all,product,category',
            'min_purchase' => 'nullable|numeric|min:0',
            'start_date'   => 'nullable|date',
            'end_date'     => 'nullable|date|after_or_equal:start_date',
            'status'       => 'boolean',
            'product_ids'  => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
        ]);

        $promotion->update($data);

        if ($data['apply_to'] === 'product') {
            $promotion->products()->sync($data['product_ids'] ?? []);
        } else {
            $promotion->products()->detach();
        }

        if ($data['apply_to'] === 'category') {
            $promotion->categories()->sync($data['category_ids'] ?? []);
        } else {
            $promotion->categories()->detach();
        }

        return response()->json(['message' => 'Promotion updated!', 'data' => $promotion]);
    }

    public function destroy(int $id)
    {
        $promotion = Promotion::findOrFail($id);
        $promotion->delete();

        AuditLog::record(Auth::id(), 'promotion_deleted', 'Promotion', $id, "Deleted promotion \"{$promotion->name}\"");

        return response()->json(['message' => 'Promotion deleted!']);
    }
}
