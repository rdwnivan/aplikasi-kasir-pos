<?php

use App\Models\Product;
use App\Models\StockBatch;
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

describe('batches (DESIGN.md §19)', function () {
    beforeEach(function () {
        $this->tracked = Product::factory()->create(['name' => 'Roti Tawar']);
        $ledger = new StockLedger;
        $ledger->receive($this->tracked, 4, '9000');
        $ledger->startTracking($this->tracked);
        $ledger->receive($this->tracked, 6, '9500', batches: [
            ['expires_on' => now()->addDays(3)->toDateString(), 'qty' => 2, 'code' => null],
            ['expires_on' => now()->addDays(6)->toDateString(), 'qty' => 4, 'code' => 'B7'],
        ]);
        $ledger->issue($this->tracked, 5);
    });

    it('passes when every batch matches its movements', function () {
        $this->artisan('stock:rebuild')->expectsOutputToContain('Semua stok cocok')->assertSuccessful();
    });

    it('detects and repairs a tampered batch cache', function () {
        $batch = StockBatch::query()->where('code', 'B7')->sole();
        $batch->forceFill(['qty_on_hand' => 99])->saveQuietly();

        $this->artisan('stock:rebuild')->expectsOutputToContain('batch')->assertFailed();
        $this->artisan('stock:rebuild --fix')->assertSuccessful();

        expect($batch->fresh()->qty_on_hand)->toBe(4);
        $this->artisan('stock:rebuild')->assertSuccessful();
    });

    it('detects a tracked product whose stock is not all in batches', function () {
        // Flag switched on behind the ledger's back: the old stock never moved into a batch.
        $this->product->forceFill(['tracks_expiry' => true])->saveQuietly();

        $this->artisan('stock:rebuild')->expectsOutputToContain('tanpa batch')->assertFailed();
    });
});
