<?php

use App\Enums\Role;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\StockCardController;
use App\Http\Controllers\Catalog\SupplierController;
use App\Http\Controllers\Catalog\UnitController;
use App\Http\Controllers\Inventory\GoodsReceiptController;
use App\Http\Controllers\Inventory\StockOpnameController;
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

    Route::resource('penerimaan', GoodsReceiptController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['penerimaan' => 'goodsReceipt'])
        ->names('goods-receipts');

    Route::resource('stok-opname', StockOpnameController::class)
        ->parameters(['stok-opname' => 'stockOpname'])
        ->names('stock-opnames');
    Route::post('stok-opname/{stockOpname}/posting', [StockOpnameController::class, 'post'])
        ->name('stock-opnames.post');
});
