<?php

use App\Actions\Sales\CloseShift;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\DiscardHeldSale;
use App\Actions\Sales\HoldSale;
use App\Actions\Sales\OpenShift;
use App\Enums\DiscountType;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-11-21 10:00:00');
    config(['pos.tax' => ['enabled' => true, 'rate' => '12', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true]]);

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $dus = Unit::factory()->create(['name' => 'dus']);

    $this->indomie = Product::factory()->create(['name' => 'Indomie Goreng', 'base_unit_id' => $pcs->id]);
    $this->perPcs = ProductUnit::factory()->for($this->indomie)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 3500]);
    $this->perDus = ProductUnit::factory()->for($this->indomie)->create(['unit_id' => $dus->id, 'conversion' => 40, 'price' => 125000]);
    app(StockLedger::class)->receive($this->indomie, 200, '2800');

    $this->cashier = User::factory()->create();
    $this->shift = app(OpenShift::class)->handle($this->cashier, 200000);
    $this->checkout = app(CompleteSale::class);
});

it('completes a cash sale: numbers it, snapshots tax, takes stock out and records HPP', function () {
    $sale = $this->checkout->handle($this->cashier, [
        ['product_unit_id' => $this->perDus->id, 'qty' => 1],
        ['product_unit_id' => $this->perPcs->id, 'qty' => 5],
    ], paidAmount: 150000);

    expect($sale)
        ->number->toBe('INV-20261121-0001')
        ->status->toBe(SaleStatus::Completed)
        ->subtotal->toBe(142500)
        ->total->toBe(142500)
        ->tax_amount->toBe(14122)       // 142.500 × 0,11 / 1,11
        ->taxable_amount->toBe(128378)
        ->dpp_amount->toBe(117680)      // 11/12 × 128.378
        ->tax_rate->toBe('12.00')
        ->change_amount->toBe(7500)
        ->cogs_total->toBe('126000.0000'); // 45 pcs × 2.800

    expect($sale->items->pluck('product_name')->unique()->all())->toBe(['Indomie Goreng']);
    expect($sale->items->firstWhere('unit_name', 'dus'))
        ->line_total->toBe(125000)
        ->unit_cost->toBe('2800.0000')
        ->cogs->toBe('112000.0000');

    expect($this->indomie->fresh()->stock_on_hand)->toBe(155);
    // SUM() comes back as a string on Postgres.
    expect((int) $sale->stockMovements()->where('type', StockMovementType::Sale)->sum('qty'))->toBe(-45);
});

it('always prices from the catalogue, ignoring any price the client sends', function () {
    $sale = $this->checkout->handle($this->cashier, [
        ['product_unit_id' => $this->perPcs->id, 'qty' => 2, 'unit_price' => 1],
    ], paidAmount: 7000);

    expect($sale->total)->toBe(7000);
});

it('stores the transaction discount', function () {
    $sale = $this->checkout->handle($this->cashier, [
        ['product_unit_id' => $this->perDus->id, 'qty' => 1],
    ], paidAmount: 120000, discountType: DiscountType::Amount, discountValue: 5000);

    expect($sale)
        ->discount_type->toBe(DiscountType::Amount)
        ->discount_amount->toBe(5000)
        ->total->toBe(120000);
});

it('rejects underpayment without writing anything', function () {
    expect(fn () => $this->checkout->handle($this->cashier, [
        ['product_unit_id' => $this->perPcs->id, 'qty' => 2],
    ], paidAmount: 6999))->toThrow(ValidationException::class);

    expect(Sale::count())->toBe(0);
    expect($this->indomie->fresh()->stock_on_hand)->toBe(200);
});

it('rolls back the whole sale, invoice number included, when stock runs out', function () {
    expect(fn () => $this->checkout->handle($this->cashier, [
        ['product_unit_id' => $this->perPcs->id, 'qty' => 1],
        ['product_unit_id' => $this->perDus->id, 'qty' => 5], // 200 pcs, only 199 left after line 1
    ], paidAmount: 1000000))->toThrow(InsufficientStockException::class);

    expect(Sale::count())->toBe(0)
        ->and($this->indomie->fresh()->stock_on_hand)->toBe(200)
        ->and(StockMovement::where('type', StockMovementType::Sale)->count())->toBe(0);

    $next = $this->checkout->handle($this->cashier, [['product_unit_id' => $this->perPcs->id, 'qty' => 1]], paidAmount: 3500);
    expect($next->number)->toBe('INV-20261121-0001');
});

it('requires an open shift', function () {
    $other = User::factory()->create();

    $this->checkout->handle($other, [['product_unit_id' => $this->perPcs->id, 'qty' => 1]], paidAmount: 3500);
})->throws(ValidationException::class, 'Buka shift dulu');

describe('held carts', function () {
    it('parks a cart without touching stock or using an invoice number', function () {
        $held = app(HoldSale::class)->handle($this->cashier, [
            ['product_unit_id' => $this->perPcs->id, 'qty' => 3],
        ], label: 'Bu Ani');

        expect($held)
            ->status->toBe(SaleStatus::Held)
            ->number->toBeNull()
            ->held_label->toBe('Bu Ani')
            ->total->toBe(10500);
        expect($this->indomie->fresh()->stock_on_hand)->toBe(200);
    });

    it('turns into a completed sale and disappears from the held list', function () {
        $held = app(HoldSale::class)->handle($this->cashier, [['product_unit_id' => $this->perPcs->id, 'qty' => 3]]);

        $sale = $this->checkout->handle($this->cashier, [
            ['product_unit_id' => $this->perPcs->id, 'qty' => 4], // one more added after resuming
        ], paidAmount: 14000, fromHeld: $held);

        expect(Sale::where('status', SaleStatus::Held)->count())->toBe(0)
            ->and($sale->total)->toBe(14000)
            ->and($this->indomie->fresh()->stock_on_hand)->toBe(196);
    });

    it('cannot be completed twice', function () {
        $held = app(HoldSale::class)->handle($this->cashier, [['product_unit_id' => $this->perPcs->id, 'qty' => 1]]);
        $this->checkout->handle($this->cashier, [['product_unit_id' => $this->perPcs->id, 'qty' => 1]], 3500, fromHeld: $held);

        expect(fn () => $this->checkout->handle($this->cashier, [['product_unit_id' => $this->perPcs->id, 'qty' => 1]], 3500, fromHeld: $held))
            ->toThrow(ValidationException::class);

        expect(Sale::count())->toBe(1);
    });

    it('cannot be discarded by another cashier', function () {
        $held = app(HoldSale::class)->handle($this->cashier, [['product_unit_id' => $this->perPcs->id, 'qty' => 1]]);
        $other = User::factory()->create();

        expect(fn () => app(DiscardHeldSale::class)->handle($held, $other))->toThrow(ValidationException::class);
        expect($held->fresh())->not->toBeNull();
    });
});

describe('shifts', function () {
    it('refuses to open a second shift for the same cashier', function () {
        app(OpenShift::class)->handle($this->cashier, 100000);
    })->throws(ValidationException::class, 'belum ditutup');

    it('closes with expected cash = float + cash sales, and records the difference', function () {
        $this->checkout->handle($this->cashier, [['product_unit_id' => $this->perDus->id, 'qty' => 1]], paidAmount: 150000);
        $this->checkout->handle($this->cashier, [['product_unit_id' => $this->perPcs->id, 'qty' => 2]], paidAmount: 10000);

        // 200.000 + 125.000 + 7.000 = 332.000; drawer is 2.000 short
        $closed = app(CloseShift::class)->handle($this->shift, countedCash: 330000);

        expect($closed)
            ->expected_cash->toBe(332000)
            ->counted_cash->toBe(330000)
            ->cash_difference->toBe(-2000)
            ->closed_at->not->toBeNull();
    });

    it('will not close while a cart is still held', function () {
        app(HoldSale::class)->handle($this->cashier, [['product_unit_id' => $this->perPcs->id, 'qty' => 1]]);

        app(CloseShift::class)->handle($this->shift, 200000);
    })->throws(ValidationException::class, 'ditahan');

    it('blocks sales after the shift is closed', function () {
        app(CloseShift::class)->handle($this->shift, 200000);

        $this->checkout->handle($this->cashier, [['product_unit_id' => $this->perPcs->id, 'qty' => 1]], paidAmount: 3500);
    })->throws(ValidationException::class, 'Buka shift dulu');
});
