<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * List all categories with optional search and expense count.
     */
    public function index(Request $request)
    {
        $query = Category::withCount('expenses')->latest();

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $categories = $query->get();

        return response()->json($categories);
    }

    /**
     * Create a new category.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['accountant', 'hr_accountant', 'admin', 'super_admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized. Finance desk access required.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:100|unique:categories,name',
            'icon' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
        ], [
            'name.unique' => 'A category with this name already exists.',
            'name.required' => 'Category title is required.',
        ]);

        $category = Category::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'icon' => $request->icon ?: '📦',
            'description' => $request->description,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => "Category '{$category->name}' created successfully.",
            'category' => $category,
        ], 201);
    }

    /**
     * Update an existing category.
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!in_array($user->role, ['accountant', 'hr_accountant', 'admin', 'super_admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $category = Category::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:100|unique:categories,name,' . $id,
            'icon' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        $category->update([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'icon' => $request->icon ?: $category->icon,
            'description' => $request->description,
            'is_active' => $request->has('is_active') ? $request->is_active : $category->is_active,
        ]);

        return response()->json([
            'message' => "Category '{$category->name}' updated successfully.",
            'category' => $category,
        ]);
    }

    /**
     * Toggle active/inactive status.
     */
    public function toggleStatus(Request $request, $id)
    {
        $user = $request->user();
        if (!in_array($user->role, ['accountant', 'hr_accountant', 'admin', 'super_admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $category = Category::findOrFail($id);
        $category->is_active = !$category->is_active;
        $category->save();

        $status = $category->is_active ? 'activated' : 'deactivated';

        return response()->json([
            'message' => "Category '{$category->name}' has been {$status}.",
            'category' => $category,
        ]);
    }

    /**
     * Delete a category.
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!in_array($user->role, ['accountant', 'hr_accountant', 'admin', 'super_admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $category = Category::withCount('expenses')->findOrFail($id);

        if ($category->expenses_count > 0) {
            return response()->json([
                'message' => "Cannot delete '{$category->name}' because {$category->expenses_count} expense vouchers are linked to it. You can deactivate it instead.",
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => "Category '{$category->name}' deleted successfully.",
        ]);
    }
}
