<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\DeleteProduct;
use App\Actions\Catalog\SaveProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'category' => $request->integer('category') ?: null,
            'status' => in_array($request->query('status'), ['active', 'inactive', 'archived', 'low'], true)
                ? (string) $request->query('status')
                : 'all',
        ];

        $products = Product::query()
            ->withSellableStock()
            ->with(['category', 'baseUnit', 'units' => fn ($q) => $q->with('unit')->orderBy('conversion')])
            ->when($filters['status'] === 'archived', fn (Builder $q) => $q->onlyTrashed())
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when($filters['status'] === 'low', fn (Builder $q) => $q->where('is_active', true)->lowStock())
            ->when($filters['category'], fn (Builder $q, int $id) => $q->where('category_id', $id))
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = '%'.mb_strtolower($filters['q']).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(sku) LIKE ?', [$term])
                    ->orWhereHas('units', fn (Builder $u) => $u->where('barcode', $filters['q'])));
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'category' => $p->category?->name,
                'baseUnit' => $p->baseUnit->name,
                'stock' => $p->stock_on_hand,
                'sellableStock' => (int) $p->sellable_stock,
                'minStock' => $p->min_stock,
                'avgCost' => (float) $p->avg_cost,
                'isActive' => $p->is_active,
                'tracksExpiry' => $p->tracks_expiry,
                'archived' => $p->trashed(),
                'units' => $p->units->map(fn (ProductUnit $u) => [
                    'unit' => $u->unit->name,
                    'conversion' => $u->conversion,
                    'price' => $u->price,
                ])->all(),
            ]);

        return Inertia::render('catalog/products/index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('catalog/products/form', [
            'product' => null,
            ...self::options(),
        ]);
    }

    public function store(ProductRequest $request, SaveProduct $save): RedirectResponse
    {
        $product = $save->handle($request->productAttributes(), $request->units(), tracksExpiry: $request->tracksExpiry(), by: $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Produk {$product->name} ditambahkan."]);

        return to_route('products.index');
    }

    public function edit(Product $product): Response
    {
        $product->load(['units' => fn ($q) => $q->orderBy('conversion')]);

        return Inertia::render('catalog/products/form', [
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category_id' => $product->category_id,
                'base_unit_id' => $product->base_unit_id,
                'min_stock' => $product->min_stock,
                'is_active' => $product->is_active,
                'tracks_expiry' => $product->tracks_expiry,
                'stock' => $product->stock_on_hand,
                'avgCost' => (float) $product->avg_cost,
                'hasHistory' => DeleteProduct::hasHistory($product),
                'units' => $product->units->map(fn (ProductUnit $u) => [
                    'unit_id' => $u->unit_id,
                    'conversion' => $u->conversion,
                    'price' => $u->price,
                    'barcode' => $u->barcode ?? '',
                ])->all(),
            ],
            ...self::options(),
        ]);
    }

    public function update(ProductRequest $request, Product $product, SaveProduct $save): RedirectResponse
    {
        $save->handle($request->productAttributes(), $request->units(), $product, $request->tracksExpiry(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Produk {$product->name} disimpan."]);

        return to_route('products.index');
    }

    public function destroy(Product $product, DeleteProduct $delete): RedirectResponse
    {
        $result = $delete->handle($product);

        Inertia::flash('toast', ['type' => 'success', 'message' => $result === DeleteProduct::DELETED
            ? "Produk {$product->name} dihapus."
            : "Produk {$product->name} diarsipkan karena sudah punya riwayat stok."]);

        return to_route('products.index');
    }

    public function restore(Product $product): RedirectResponse
    {
        $product->restore();
        $product->forceFill(['is_active' => true])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Produk {$product->name} dipulihkan."]);

        return back();
    }

    /** @return array<string, mixed> */
    private static function options(): array
    {
        return [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'units' => Unit::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
