<?php

use App\Enums\Role;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\StockCardController;
use App\Http\Controllers\Catalog\SupplierController;
use App\Http\Controllers\Catalog\UnitController;
use App\Http\Controllers\Inventory\GoodsReceiptController;
use App\Http\Controllers\Inventory\StockDisposalController;
use App\Http\Controllers\Inventory\StockOpnameController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use Illuminate\Support\Facades\Route;

/*
| Master data and stock documents: owner and warehouse staff.
*/
Route::middleware(['auth', 'verified', 'role:'.Role::Owner->value.'|'.Role::Warehouse->value])->group(function () {
    Route::resource('produk', ProductController::class)
        ->except('show')
        ->parameters(['produk' => 'product'])
        ->names('products');
    Route::post('produk/{product}/pulihkan', [ProductController::class, 'restore'])
        ->withTrashed()
        ->name('products.restore');
    Route::get('produk/{product}/kartu-stok', [StockCardController::class, 'show'])
        ->withTrashed()
        ->name('products.stock-card');

    Route::resource('kategori', CategoryController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['kategori' => 'category'])
        ->names('categories');

    Route::resource('satuan', UnitController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['satuan' => 'unit'])
        ->names('units');

    Route::resource('supplier', SupplierController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('suppliers');

    Route::resource('pesanan-pembelian', PurchaseOrderController::class)
        ->parameters(['pesanan-pembelian' => 'purchaseOrder'])
        ->names('purchase-orders');
    Route::prefix('pesanan-pembelian/{purchaseOrder}')->name('purchase-orders.')->controller(PurchaseOrderController::class)->group(function () {
        Route::post('pesan', 'order')->name('order');
        Route::get('terima', 'receive')->name('receive');
        Route::post('terima', 'storeReceipt')->name('receive.store');
        Route::post('tutup', 'close')->name('close');
        Route::post('batal', 'cancel')->name('cancel');
    });

    Route::resource('penerimaan', GoodsReceiptController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['penerimaan' => 'goodsReceipt'])
        ->names('goods-receipts');

    Route::resource('stok-opname', StockOpnameController::class)
        ->parameters(['stok-opname' => 'stockOpname'])
        ->names('stock-opnames');
    Route::post('stok-opname/{stockOpname}/posting', [StockOpnameController::class, 'post'])
        ->name('stock-opnames.post');

    // Buang barang kedaluwarsa (DESIGN.md §19).
    Route::resource('stok-dibuang', StockDisposalController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['stok-dibuang' => 'stockDisposal'])
        ->names('stock-disposals');
});
