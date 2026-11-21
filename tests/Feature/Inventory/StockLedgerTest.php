<?php

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Inventory\StockLedger;

beforeEach(function () {
    $this->ledger = new StockLedger;
    $this->product = Product::factory()->create();
});

describe('receive', function () {
    it('sets the average to the incoming cost when there is no stock yet', function () {
        $this->ledger->receive($this->product, 10, '3000');

        expect($this->product->fresh())
            ->stock_on_hand->toBe(10)
            ->avg_cost->toBe('3000.0000');
    });

    it('re-weights the moving average on later receipts', function () {
        $this->ledger->receive($this->product, 10, '3000');
        $this->ledger->receive($this->product, 30, '3400');

        // (10 × 3000 + 30 × 3400) / 40 = 3300
        expect($this->product->fresh())
            ->stock_on_hand->toBe(40)
            ->avg_cost->toBe('3300.0000');
    });

    it('keeps exact precision for non-terminating averages', function () {
        $this->ledger->receive($this->product, 2, '1000');
        $this->ledger->receive($this->product, 1, '1001');

        // 3001 / 3 = 1000.33333… → 1000.3333
        expect($this->product->fresh()->avg_cost)->toBe('1000.3333');
    });

    it('starts a fresh average once stock has been fully sold', function () {
        $this->ledger->receive($this->product, 5, '2000');
        $this->ledger->issue($this->product, 5);
        $this->ledger->receive($this->product, 5, '2600');

        expect($this->product->fresh()->avg_cost)->toBe('2600.0000');
    });

    it('re-weights the average on a sale return at its original cost', function () {
        $this->ledger->receive($this->product, 10, '1000');
        $this->ledger->receive($this->product, 1, '1550', StockMovementType::SaleReturn);

        // (10 × 1000 + 1 × 1550) / 11 = 1050
        expect($this->product->fresh()->avg_cost)->toBe('1050.0000');
    });

    it('rejects non-positive quantities and types that do not carry a cost', function (int $qty, StockMovementType $type) {
        $this->ledger->receive($this->product, $qty, '1000', $type);
    })->with([
        'zero qty' => [0, StockMovementType::Purchase],
        'negative qty' => [-1, StockMovementType::Purchase],
        'opname as receipt' => [1, StockMovementType::Opname],
        'sale as receipt' => [1, StockMovementType::Sale],
    ])->throws(InvalidArgumentException::class);
});

describe('issue', function () {
    it('reduces stock, costs the movement at the current average, and keeps the average', function () {
        $this->ledger->receive($this->product, 10, '3000');
        $this->ledger->receive($this->product, 10, '3500');

        $movement = $this->ledger->issue($this->product, 4);

        expect($movement)
            ->type->toBe(StockMovementType::Sale)
            ->qty->toBe(-4)
            ->unit_cost->toBe('3250.0000')
            ->stock_after->toBe(16);

        expect($this->product->fresh())
            ->stock_on_hand->toBe(16)
            ->avg_cost->toBe('3250.0000');
    });

    it('refuses to oversell and leaves the ledger untouched', function () {
        $this->ledger->receive($this->product, 3, '1000');

        expect(fn () => $this->ledger->issue($this->product, 4))
            ->toThrow(InsufficientStockException::class);

        expect($this->product->fresh()->stock_on_hand)->toBe(3);
        expect(StockMovement::count())->toBe(1);
    });

    it('keeps the caller\'s product instance in sync', function () {
        $this->ledger->receive($this->product, 8, '1000');
        $this->ledger->issue($this->product, 3);

        expect($this->product->stock_on_hand)->toBe(5);
    });
});

describe('adjustTo (stock opname)', function () {
    it('records the shortfall as a negative movement at the current average', function () {
        $this->ledger->receive($this->product, 20, '1500');

        $movement = $this->ledger->adjustTo($this->product, 17);

        expect($movement)
            ->type->toBe(StockMovementType::Opname)
            ->qty->toBe(-3)
            ->unit_cost->toBe('1500.0000');
        expect($this->product->fresh()->stock_on_hand)->toBe(17);
    });

    it('records a surplus without changing the average', function () {
        $this->ledger->receive($this->product, 20, '1500');

        $this->ledger->adjustTo($this->product, 25);

        expect($this->product->fresh())
            ->stock_on_hand->toBe(25)
            ->avg_cost->toBe('1500.0000');
    });

    it('writes nothing when the count matches', function () {
        $this->ledger->receive($this->product, 20, '1500');

        expect($this->ledger->adjustTo($this->product, 20))->toBeNull();
        expect(StockMovement::count())->toBe(1);
    });
});

it('makes stock movements immutable', function () {
    $movement = $this->ledger->receive($this->product, 1, '1000');

    expect(fn () => $movement->update(['qty' => 99]))->toThrow(LogicException::class);
    expect(fn () => $movement->delete())->toThrow(LogicException::class);
});

it('links a movement to the document that caused it', function () {
    $other = Product::factory()->create();

    $movement = $this->ledger->receive($this->product, 1, '1000', reference: $other, note: 'uji');

    expect($movement->reference)->toBeInstanceOf(Product::class)
        ->and($movement->reference->is($other))->toBeTrue()
        ->and($movement->note)->toBe('uji');
});
