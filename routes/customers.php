<?php

use App\Enums\Role;
use App\Http\Controllers\Customers\CustomerController;
use Illuminate\Support\Facades\Route;

/*
| Pelanggan & kasbon (DESIGN.md §17). Cashiers and owners read the list and each customer's
| kasbon card; changes and write-offs are for owners only.
*/
Route::middleware(['auth', 'verified', 'role:'.Role::Cashier->value.'|'.Role::Owner->value])
    ->prefix('pelanggan')
    ->name('customers.')
    ->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::get('{customer}', [CustomerController::class, 'show'])->name('show');

        Route::middleware('role:'.Role::Owner->value)->group(function () {
            Route::post('/', [CustomerController::class, 'store'])->name('store');
            Route::put('{customer}', [CustomerController::class, 'update'])->name('update');
            Route::post('{customer}/nonaktifkan', [CustomerController::class, 'deactivate'])->name('deactivate');
            Route::post('{customer}/aktifkan', [CustomerController::class, 'reactivate'])->name('reactivate');
            Route::post('{customer}/hapus-utang', [CustomerController::class, 'writeOff'])->name('write-off');
        });
    });
