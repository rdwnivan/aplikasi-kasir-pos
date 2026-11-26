<?php

use App\Enums\Role;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\Reports\ShiftCloseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:'.Role::Owner->value])
    ->prefix('laporan')
    ->name('reports.')
    ->group(function () {
        Route::redirect('/', '/laporan/penjualan');
        Route::get('penjualan', [ReportController::class, 'sales'])->name('sales');
        Route::get('produk-terlaris', [ReportController::class, 'products'])->name('products');
        Route::get('persediaan', [ReportController::class, 'inventory'])->name('inventory');
        Route::get('kasir', [ReportController::class, 'shifts'])->name('shifts');
        Route::get('kasir/shift/{shift}/tutup', [ShiftCloseController::class, 'edit'])->name('shifts.close.edit');
        Route::put('kasir/shift/{shift}/tutup', [ShiftCloseController::class, 'update'])->name('shifts.close.update');

        Route::prefix('excel')->name('export.')->middleware('throttle:30,1')->group(function () {
            Route::get('penjualan', [ReportExportController::class, 'sales'])->name('sales');
            Route::get('produk-terlaris', [ReportExportController::class, 'products'])->name('products');
            Route::get('persediaan', [ReportExportController::class, 'inventory'])->name('inventory');
            Route::get('kasir', [ReportExportController::class, 'shifts'])->name('shifts');
        });
    });
