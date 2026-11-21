<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('catalog/categories', [
            'items' => Category::query()->withCount('products')->orderBy('name')->get()
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name, 'usage' => $c->products_count]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $category = Category::create($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Kategori {$category->name} ditambahkan."]);

        return back();
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $category->update($this->validated($request, $category));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kategori disimpan.']);

        return back();
    }

    /** Products in a deleted category simply become uncategorised. */
    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Kategori {$category->name} dihapus."]);

        return back();
    }

    /** @return array{name: string} */
    private function validated(Request $request, ?Category $category = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        /** @var array{name: string} */
        return $request->validate(
            ['name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($category?->id)]],
            attributes: ['name' => 'nama kategori'],
        );
    }
}
