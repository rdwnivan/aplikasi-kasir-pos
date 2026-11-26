<?php

use App\Enums\Role;
use App\Http\Controllers\Auth\ForcedPasswordController;
use App\Http\Controllers\Users\AccountEventController;
use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
| Pengguna: owners add, edit, deactivate and reset accounts (DESIGN.md §12).
*/
Route::middleware(['auth', 'verified', 'role:'.Role::Owner->value])
    ->prefix('pengguna')
    ->name('users.')
    ->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        // Before {user} routes; there is no GET {user}, but keep the literal path first anyway.
        Route::get('riwayat', [AccountEventController::class, 'index'])->name('events.index');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::put('{user}', [UserController::class, 'update'])->name('update');
        Route::post('{user}/nonaktifkan', [UserController::class, 'deactivate'])->name('deactivate');
        Route::post('{user}/aktifkan', [UserController::class, 'reactivate'])->name('reactivate');
        Route::put('{user}/kata-sandi', [UserController::class, 'password'])
            ->middleware('throttle:20,1')
            ->name('password');
        Route::delete('{user}/pin', [UserController::class, 'pin'])->name('pin');
        Route::delete('{user}/2fa', [UserController::class, 'signInFactors'])
            ->middleware('throttle:6,1')
            ->name('sign-in-factors');
    });

// After an owner set the password: the user picks their own before anything else.
Route::middleware('auth')->group(function () {
    Route::get('ganti-kata-sandi', [ForcedPasswordController::class, 'edit'])->name('forced-password.edit');
    Route::put('ganti-kata-sandi', [ForcedPasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('forced-password.update');
});
