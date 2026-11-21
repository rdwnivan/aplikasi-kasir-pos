<?php

use App\Actions\Sales\CloseShift;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Actions\Sales\ProcessSaleReturn;
use App\Enums\DiscountType;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-11-22 09:00:00');
    config(['pos.tax' => ['enabled' => true, 'rate' => '12', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true]]);
    $this->seed(RoleSeeder::class);

    $this->owner = User::factory()->create(['pin_hash' => Hash::make('1234')])->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->create()->assignRole(Role::Cashier->value);

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $dus = Unit::factory()->create(['name' => 'dus']);
    $this->mie = Product::factory()->create(['name' => 'Indomie', 'base_unit_id' => $pcs->id]);
    $this->miePcs = ProductUnit::factory()->for($this->mie)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 3500]);
    $this->mieDus = ProductUnit::factory()->for($this->mie)->create(['unit_id' => $dus->id, 'conversion' => 40, 'price' => 125000]);
    $this->aqua = Product::factory()->create(['name' => 'Aqua', 'base_unit_id' => $pcs->id]);
    $this->aquaPcs = ProductUnit::factory()->for($this->aqua)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 4000]);

    $ledger = app(StockLedger::class);
    $ledger->receive($this->mie, 200, '2800');
    $ledger->receive($this->aqua, 50, '3000');

    $this->shift = app(OpenShift::class)->handle($this->cashier, 100000);

    // 3 pcs Indomie (10.500) + 1 dus (125.000) + 2 Aqua (8.000) = 143.500, minus 10% = 129.150
    $this->sale = app(CompleteSale::class)->handle($this->cashier, [
        ['product_unit_id' => $this->miePcs->id, 'qty' => 3],
        ['product_unit_id' => $this->mieDus->id, 'qty' => 1],
        ['product_unit_id' => $this->aquaPcs->id, 'qty' => 2],
    ], paidAmount: 130000, discountType: DiscountType::Percent, discountValue: 10);

    // A later purchase at a higher cost moves the average; returns must not use it.
    $ledger->receive($this->mie, 40, '3400');

    $this->returns = app(ProcessSaleReturn::class);
    $this->items = $this->sale->items->keyBy(fn ($i) => $i->product_unit_id);
});

it('splits the paid total across lines, to the rupiah', function () {
    $shares = ProcessSaleReturn::lineShares($this->sale, $this->sale->items->keyBy('id')->all());

    expect($this->sale->total)->toBe(129150)
        ->and(array_sum($shares))->toBe(129150)
        ->and($shares[$this->items[$this->miePcs->id]->id])->toBe(9450)      // 10.500 × 0,9
        ->and($shares[$this->items[$this->mieDus->id]->id])->toBe(112500)
        ->and($shares[$this->items[$this->aquaPcs->id]->id])->toBe(7200);
});

it('returns part of a line: refunds what was paid, restocks at the original HPP', function () {
    $avgBefore = $this->mie->fresh()->avg_cost;

    $return = $this->returns->handle($this->cashier, $this->sale, [
        $this->items[$this->miePcs->id]->id => 1,
    ], '1234', 'Kemasan sobek');

    expect($return)
        ->number->toBe('RET-20261122-0001')
        ->total->toBe(3150)                 // 9.450 / 3
        ->approved_by->toBe($this->owner->id)
        ->shift_id->toBe($this->shift->id)
        ->reason->toBe('Kemasan sobek')
        ->cogs_total->toBe('2800.0000');

    $movement = StockMovement::query()->where('type', StockMovementType::SaleReturn)->sole();
    expect($movement)
        ->qty->toBe(1)
        ->unit_cost->toBe('2800.0000')
        ->reference_type->toBe((new SaleReturn)->getMorphClass())
        ->and($this->mie->fresh()->stock_on_hand)->toBe(200 - 43 + 40 + 1)
        // Re-weighted with the original 2.800, not the current average.
        ->and($this->mie->fresh()->avg_cost)->not->toBe($avgBefore);
});

it('refunds the exact sale total and PPN once everything is back', function () {
    $mie = $this->items[$this->miePcs->id]->id;
    $dus = $this->items[$this->mieDus->id]->id;
    $aqua = $this->items[$this->aquaPcs->id]->id;

    $this->returns->handle($this->cashier, $this->sale, [$mie => 1, $aqua => 1], '1234');
    $this->returns->handle($this->cashier, $this->sale, [$mie => 1], '1234');
    $this->returns->handle($this->cashier, $this->sale, [$mie => 1, $dus => 1, $aqua => 1], '1234');

    $returns = SaleReturn::all();
    expect($returns->sum('total'))->toBe($this->sale->total)
        ->and($returns->sum('tax_amount'))->toBe($this->sale->tax_amount)
        ->and((float) $returns->sum('cogs_total'))->toBe((float) $this->sale->fresh()->cogs_total)
        ->and($this->aqua->fresh()->stock_on_hand)->toBe(50);

    expect(Artisan::call('stock:rebuild'))->toBe(0);
});

it('never returns more than was sold, counting earlier returns', function () {
    $mie = $this->items[$this->miePcs->id]->id;
    $this->returns->handle($this->cashier, $this->sale, [$mie => 2], '1234');

    expect(fn () => $this->returns->handle($this->cashier, $this->sale, [$mie => 2], '1234'))
        ->toThrow(ValidationException::class, 'Maksimal 1 pcs Indomie');

    expect(SaleReturn::count())->toBe(1);
});

it('refuses items from another sale and empty returns', function () {
    expect(fn () => $this->returns->handle($this->cashier, $this->sale, [999999 => 1], '1234'))
        ->toThrow(ValidationException::class, 'bukan bagian dari transaksi');

    expect(fn () => $this->returns->handle($this->cashier, $this->sale, [], '1234'))
        ->toThrow(ValidationException::class);
});

describe('owner PIN', function () {
    it('rejects a wrong PIN and changes nothing', function () {
        expect(fn () => $this->returns->handle($this->cashier, $this->sale, [$this->items[$this->miePcs->id]->id => 1], '9999'))
            ->toThrow(ValidationException::class, 'PIN owner salah.');

        expect(SaleReturn::count())->toBe(0)
            ->and(StockMovement::where('type', StockMovementType::SaleReturn)->count())->toBe(0);
    });

    it('locks out after five wrong PINs', function () {
        RateLimiter::clear('owner-pin:'.$this->cashier->id);
        $line = [$this->items[$this->miePcs->id]->id => 1];

        foreach (range(1, 5) as $_) {
            try {
                $this->returns->handle($this->cashier, $this->sale, $line, '0000');
            } catch (ValidationException) {
            }
        }

        expect(fn () => $this->returns->handle($this->cashier, $this->sale, $line, '1234'))
            ->toThrow(ValidationException::class, 'Terlalu banyak PIN salah');
    });

    it('needs an owner who has set a PIN', function () {
        $this->owner->forceFill(['pin_hash' => null])->save();

        expect(fn () => $this->returns->handle($this->cashier, $this->sale, [$this->items[$this->miePcs->id]->id => 1], '1234'))
            ->toThrow(ValidationException::class, 'Owner belum mengatur PIN');
    });
});

it('takes the refund from the processing shift, which then expects less cash', function () {
    app(CloseShift::class)->handle($this->shift, $this->shift->expectedCash());

    // The customer comes back the next day; another cashier handles it.
    $other = User::factory()->create()->assignRole(Role::Cashier->value);
    expect(fn () => $this->returns->handle($other, $this->sale, [$this->items[$this->aquaPcs->id]->id => 1], '1234'))
        ->toThrow(ValidationException::class, 'Buka shift dulu');

    $today = app(OpenShift::class)->handle($other, 50000);
    $return = $this->returns->handle($other, $this->sale, [$this->items[$this->aquaPcs->id]->id => 1], '1234');

    expect($return->shift_id)->toBe($today->id)
        ->and($return->total)->toBe(3600)
        ->and($today->fresh()->expectedCash())->toBe(50000 - 3600)
        ->and($this->shift->fresh()->expected_cash)->toBe(100000 + 129150);

    $closed = app(CloseShift::class)->handle($today, 46400);
    expect($closed->cash_difference)->toBe(0);
});
