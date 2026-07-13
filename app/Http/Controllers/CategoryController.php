<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Category;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::latest();

        if ($request->type) {
            $query->where('type', $request->type);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $type = $request->type ?? 'product';

        $request->validate([
            'name' => [
                'required',
                'string',
                Rule::unique('categories')->where(fn ($q) => $q->where('type', $type)),
            ],
        ]);

        $category = Category::create([
            'name'   => $request->name,
            'type'   => $type,
            'status' => $request->status ?? true,
        ]);

        return response()->json(['message' => 'Category created!', 'category' => $category]);
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);
        $type = $request->type ?? $category->type;

        $request->validate([
            'name' => [
                'required',
                'string',
                Rule::unique('categories')->where(fn ($q) => $q->where('type', $type))->ignore($id),
            ],
        ]);

        $category->update([
            'name'   => $request->name,
            'type'   => $type,
            'status' => $request->status ?? $category->status,
        ]);

        return response()->json(['message' => 'Category updated!', 'category' => $category]);
    }

    public function destroy($id)
    {
        Category::findOrFail($id)->delete();
        return response()->json(['message' => 'Category deleted!']);
    }
}
