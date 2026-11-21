<?php

use App\Models\Product;
use App\Services\Inventory\StockLedger;

beforeEach(function () {
    $this->product = Product::factory()->create();
    $ledger = new StockLedger;

    $ledger->receive($this->product, 10, '3000');
    $ledger->receive($this->product, 30, '3400');
    $ledger->issue($this->product, 15);
    $ledger->adjustTo($this->product, 24);
});

it('passes when the product cache matches the replayed ledger', function () {
    $this->artisan('stock:rebuild')
        ->expectsOutputToContain('Semua stok cocok')
        ->assertSuccessful();
});

it('detects a tampered cache without touching it', function () {
    $this->product->forceFill(['stock_on_hand' => 999, 'avg_cost' => '1'])->saveQuietly();

    $this->artisan('stock:rebuild')->assertFailed();

    expect($this->product->fresh()->stock_on_hand)->toBe(999);
});

it('repairs a tampered cache from the ledger with --fix', function () {
    $this->product->forceFill(['stock_on_hand' => 999, 'avg_cost' => '1'])->saveQuietly();

    $this->artisan('stock:rebuild --fix')->assertSuccessful();

    expect($this->product->fresh())
        ->stock_on_hand->toBe(24)
        ->avg_cost->toBe('3300.0000');
});
