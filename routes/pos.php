<?php

use App\Enums\Role;
use App\Http\Controllers\Pos\HeldSaleController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\ReceiptController;
use App\Http\Controllers\Pos\SaleController;
use App\Http\Controllers\Pos\ShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:'.Role::Cashier->value.'|'.Role::Owner->value])
    ->prefix('kasir')
    ->name('pos.')
    ->group(function () {
        Route::get('/', [PosController::class, 'index'])->name('index');

        Route::post('shift', [ShiftController::class, 'store'])->name('shift.store');
        Route::get('shift/tutup', [ShiftController::class, 'edit'])->name('shift.edit');
        Route::put('shift', [ShiftController::class, 'update'])->name('shift.update');

        Route::post('transaksi', [SaleController::class, 'store'])->name('sales.store');
        Route::get('struk/{sale}', [ReceiptController::class, 'show'])->name('receipts.show');

        Route::post('ditahan', [HeldSaleController::class, 'store'])->name('held.store');
        Route::delete('ditahan/{sale}', [HeldSaleController::class, 'destroy'])->name('held.destroy');
    });
