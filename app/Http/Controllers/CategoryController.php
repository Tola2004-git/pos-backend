<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AuditLog;
use App\Models\Category;
use Illuminate\Support\Facades\Auth;
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

        AuditLog::record(Auth::id(), 'category_created', 'Category', $category->id, "Created category \"{$category->name}\"");

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

        AuditLog::record(Auth::id(), 'category_updated', 'Category', $category->id, "Updated category \"{$category->name}\"");

        return response()->json(['message' => 'Category updated!', 'category' => $category]);
    }

    public function destroy($id)
    {
        $category = Category::findOrFail($id);

        // promotion_categories has an onDelete('cascade') FK, so without this
        // guard deleting the category would silently strip it from any
        // promotion scoped to it - leaving that promotion applying to
        // nothing, with no error or warning to the admin who deleted it.
        if ($category->promotions()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a category that is used by a promotion. Remove it from the promotion first.',
            ], 422);
        }

        $name = $category->name;
        $category->delete();

        AuditLog::record(Auth::id(), 'category_deleted', 'Category', $id, "Deleted category \"{$name}\"");

        return response()->json(['message' => 'Category deleted!']);
    }
}
