<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Category;

class CategoryController extends Controller
{
    public function index()
    {
        return response()->json(Category::latest()->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:categories',
        ]);

        $category = Category::create([
            'name'   => $request->name,
            'status' => $request->status ?? true,
        ]);

        return response()->json(['message' => 'Category created!', 'category' => $category]);
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $request->validate([
            'name' => 'required|string|unique:categories,name,' . $id,
        ]);

        $category->update([
            'name'   => $request->name,
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