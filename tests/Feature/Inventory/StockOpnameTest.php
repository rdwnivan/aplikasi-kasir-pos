<?php

use App\Actions\Inventory\CreateStockOpname;
use App\Actions\Inventory\PostStockOpname;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->ledger = app(StockLedger::class);

    $this->short = Product::factory()->create();
    $this->surplus = Product::factory()->create();
    $this->exact = Product::factory()->create();

    foreach ([$this->short, $this->surplus, $this->exact] as $product) {
        $this->ledger->receive($product, 20, '1500');
    }
});

it('saves a draft without touching stock', function () {
    $opname = app(CreateStockOpname::class)->handle($this->user, [$this->short->id => 17]);

    expect($opname)
        ->status->toBe(StockOpname::STATUS_DRAFT)
        ->number->toStartWith('SO-');
    expect($this->short->fresh()->stock_on_hand)->toBe(20);
    expect(StockMovement::where('type', StockMovementType::Opname)->count())->toBe(0);
});

it('posts differences as opname movements and records system vs counted qty', function () {
    $opname = app(CreateStockOpname::class)->handle($this->user, [
        $this->short->id => 17,
        $this->surplus->id => 22,
        $this->exact->id => 20,
    ]);

    $posted = app(PostStockOpname::class)->handle($opname, $this->user);

    expect($posted->status)->toBe(StockOpname::STATUS_POSTED)
        ->and($posted->posted_at)->not->toBeNull();

    $items = $posted->items->keyBy('product_id');
    expect($items[$this->short->id])->system_qty->toBe(20)->difference->toBe(-3);
    expect($items[$this->surplus->id])->system_qty->toBe(20)->difference->toBe(2);
    expect($items[$this->exact->id])->system_qty->toBe(20)->difference->toBe(0);

    expect([$this->short->fresh()->stock_on_hand, $this->surplus->fresh()->stock_on_hand, $this->exact->fresh()->stock_on_hand])
        ->toBe([17, 22, 20]);

    // Only real differences produce ledger rows.
    expect($posted->stockMovements()->count())->toBe(2);
});

it('compares against stock at posting time, not at draft time', function () {
    $opname = app(CreateStockOpname::class)->handle($this->user, [$this->short->id => 15]);

    // A sale happens between counting and posting.
    $this->ledger->issue($this->short, 4);

    app(PostStockOpname::class)->handle($opname, $this->user);

    $item = $opname->items()->sole();
    expect($item)->system_qty->toBe(16)->difference->toBe(-1);
    expect($this->short->fresh()->stock_on_hand)->toBe(15);
});

it('refuses to post the same opname twice', function () {
    $opname = app(CreateStockOpname::class)->handle($this->user, [$this->short->id => 17]);
    app(PostStockOpname::class)->handle($opname, $this->user);

    expect(fn () => app(PostStockOpname::class)->handle($opname, $this->user))
        ->toThrow(ValidationException::class);

    expect($this->short->fresh()->stock_on_hand)->toBe(17);
});

it('rejects negative counts and unknown products', function (Closure $counts) {
    app(CreateStockOpname::class)->handle($this->user, $counts($this));
})->with([
    'negative' => [fn ($t) => [$t->short->id => -1]],
    'unknown product' => [fn () => [999999 => 5]],
    'empty' => [fn () => []],
])->throws(ValidationException::class);
