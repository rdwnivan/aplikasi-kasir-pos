<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Webhooks\MidtransWebhookController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

// Server-to-server: Midtrans payment notifications (signature-verified, CSRF-exempt).
Route::post('webhooks/midtrans', MidtransWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.midtrans');

require __DIR__.'/settings.php';
require __DIR__.'/pos.php';
require __DIR__.'/inventory.php';
require __DIR__.'/reports.php';
