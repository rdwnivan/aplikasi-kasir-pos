<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\StockOpname;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kartu stok: a product's ledger, newest first, with the running balance and HPP after
 * each movement exactly as the StockLedger recorded them.
 */
class StockCardController extends Controller
{
    public function show(Request $request, Product $product): Response
    {
        $canSeeSales = $request->user()->hasRole(Role::Owner->value);
        $product->load('baseUnit');

        $movements = $product->stockMovements()
            ->with(['user', 'reference'])
            ->orderByDesc('id')
            ->paginate(30)
            ->through(fn (StockMovement $m) => [
                'id' => $m->id,
                'at' => $m->created_at->toIso8601String(),
                'type' => $m->type->value,
                'typeLabel' => $m->type->label(),
                'qty' => $m->qty,
                'unitCost' => (float) $m->unit_cost,
                'stockAfter' => $m->stock_after,
                'avgCostAfter' => (float) $m->avg_cost_after,
                'user' => $m->user?->name,
                'note' => $m->note,
                'reference' => self::reference($m->reference, $canSeeSales),
            ]);

        return Inertia::render('catalog/products/stock-card', [
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'baseUnit' => $product->baseUnit->name,
                'stock' => $product->stock_on_hand,
                'minStock' => $product->min_stock,
                'avgCost' => (float) $product->avg_cost,
                'archived' => $product->trashed(),
            ],
            'movements' => $movements,
        ]);
    }

    /** @return array{label: string, url: string|null}|null */
    private static function reference(?Model $reference, bool $canSeeSales): ?array
    {
        [$label, $route] = match (true) {
            $reference instanceof GoodsReceipt => [$reference->number, 'goods-receipts.show'],
            $reference instanceof StockOpname => [$reference->number, 'stock-opnames.show'],
            $reference instanceof Sale => [$reference->number ?? '#'.$reference->id, 'pos.sales.show'],
            $reference instanceof SaleReturn => [$reference->number, 'pos.sales.show'],
            default => [null, null],
        };

        if ($label === null || $reference === null) {
            return null;
        }

        // Sales live on the till side; warehouse staff get the number without a link.
        $target = $reference instanceof SaleReturn ? $reference->sale_id : $reference->getKey();
        $allowed = $route !== 'pos.sales.show' || $canSeeSales;

        return [
            'label' => $label,
            'url' => $allowed ? route($route, $target) : null,
        ];
    }
}
