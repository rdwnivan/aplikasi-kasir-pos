<?php

use App\Actions\Sales\CloseShift;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Actions\Sales\ProcessSaleReturn;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Reports\InventoryValuation;
use App\Reports\SalesSummary;
use App\Reports\ShiftRecap;
use App\Reports\TopProducts;
use App\Services\Inventory\StockLedger;
use App\Support\DateRange;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['pos.tax' => ['enabled' => true, 'rate' => '12', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true]]);
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create(['pin_hash' => Hash::make('1234')])->assignRole(Role::Owner->value);
    $this->andi = User::factory()->create(['name' => 'Andi'])->assignRole(Role::Cashier->value);

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $drinks = Category::factory()->create(['name' => 'Minuman']);
    $this->aqua = Product::factory()->create(['name' => 'Aqua', 'base_unit_id' => $pcs->id, 'category_id' => $drinks->id]);
    $this->aquaUnit = ProductUnit::factory()->for($this->aqua)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 11100]);
    $this->mie = Product::factory()->create(['name' => 'Mie', 'base_unit_id' => $pcs->id]);
    $this->mieUnit = ProductUnit::factory()->for($this->mie)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 3330]);

    Carbon::setTestNow('2026-11-20 08:00:00');
    $ledger = app(StockLedger::class);
    $ledger->receive($this->aqua, 100, '8000');
    $ledger->receive($this->mie, 100, '2000');

    // Day 1 (20 Nov): Andi sells 2 Aqua at 23:59 Jakarta time.
    $this->shift1 = app(OpenShift::class)->handle($this->andi, 100000);
    Carbon::setTestNow('2026-11-20 23:59:00');
    $this->sale1 = app(CompleteSale::class)->handle($this->andi, [['product_unit_id' => $this->aquaUnit->id, 'qty' => 2]], paidAmount: 22200);
    app(CloseShift::class)->handle($this->shift1, 122000); // 200 short

    // Day 2 (21 Nov): 3 Mie at 00:01, then 1 Aqua comes back from day 1.
    Carbon::setTestNow('2026-11-21 00:01:00');
    $this->shift2 = app(OpenShift::class)->handle($this->andi, 50000);
    app(CompleteSale::class)->handle($this->andi, [['product_unit_id' => $this->mieUnit->id, 'qty' => 3]], paidAmount: 10000);
    Carbon::setTestNow('2026-11-21 09:00:00');
    app(ProcessSaleReturn::class)->handle($this->andi, $this->sale1, [$this->sale1->items[0]->id => 1], '1234');
});

it('splits days at midnight in Asia/Jakarta and computes profit without PPN, net of returns', function () {
    $report = SalesSummary::build(DateRange::of('2026-11-20', '2026-11-21'));

    [$day1, $day2] = $report['rows'];

    // 22.200 incl. PPN → PPN 2.200 → net 20.000; HPP 16.000.
    expect($day1)->toMatchArray([
        'period' => '2026-11-20',
        'transactions' => 1,
        'gross' => 22200,
        'cash' => 22200,
        'refunds' => 0,
        'tax' => 2200,
        'netSales' => 20000,
        'cogs' => 16000,
        'grossProfit' => 4000,
        'margin' => 20.0,
    ]);

    // 9.990 incl. PPN (PPN 990 → 9.000 net), minus the 11.100 refund (PPN 1.100 → 10.000),
    // HPP 6.000 − 8.000 returned.
    expect($day2)->toMatchArray([
        'period' => '2026-11-21',
        'transactions' => 1,
        'gross' => 9990,
        'refunds' => 11100,
        'tax' => 990 - 1100,
        'netSales' => 9000 - 10000,
        'cogs' => 6000 - 8000,
        'grossProfit' => 1000,
    ]);

    expect($report['totals'])->toMatchArray([
        'transactions' => 2,
        'gross' => 32190,
        'refunds' => 11100,
        'netSales' => 19000,
        'cogs' => 14000,
        'grossProfit' => 5000,
    ]);
});

it('fills quiet days with zeros and groups by month', function () {
    $daily = SalesSummary::build(DateRange::of('2026-11-19', '2026-11-22'));
    expect(array_column($daily['rows'], 'period'))->toBe(['2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'])
        ->and($daily['rows'][0]['transactions'])->toBe(0);

    $monthly = SalesSummary::build(DateRange::of('2026-10-01', '2026-11-30'), 'month');
    expect(array_column($monthly['rows'], 'period'))->toBe(['2026-10', '2026-11'])
        ->and($monthly['rows'][1]['grossProfit'])->toBe(5000);
});

it('ranks products by quantity sold net of returns', function () {
    $rows = TopProducts::build(DateRange::of('2026-11-20', '2026-11-21'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['name' => 'Mie', 'soldQty' => 3, 'returnedQty' => 0, 'netQty' => 3])
        ->and($rows[1])->toMatchArray(['name' => 'Aqua', 'soldQty' => 2, 'returnedQty' => 1, 'netQty' => 1, 'cogs' => 8000]);
});

it('values stock at the moving-average HPP, per product and category', function () {
    $report = InventoryValuation::build();

    // Aqua: 99 × 8.000; Mie: 97 × 2.000.
    expect($report['total'])->toBe(99 * 8000 + 97 * 2000)
        ->and($report['rows'][0])->toMatchArray(['name' => 'Aqua', 'stock' => 99, 'value' => 792000])
        ->and(collect($report['categories'])->firstWhere('name', 'Minuman')['value'])->toBe(792000)
        ->and(collect($report['categories'])->firstWhere('name', 'Tanpa kategori')['value'])->toBe(194000);
});

it('recaps shifts and cashiers, including the drawer difference', function () {
    $report = ShiftRecap::build(DateRange::of('2026-11-20', '2026-11-21'));

    expect($report['shifts'])->toHaveCount(2)
        ->and($report['shifts'][1])->toMatchArray([
            'cashier' => 'Andi', 'transactions' => 1, 'cashSales' => 22200,
            'expectedCash' => 122200, 'countedCash' => 122000, 'difference' => -200,
        ])
        ->and($report['shifts'][0])->toMatchArray([
            'transactions' => 1, 'cashSales' => 9990, 'refunds' => 11100,
            'expectedCash' => 50000 + 9990 - 11100, 'closedAt' => null,
        ])
        ->and($report['cashiers'][0])->toMatchArray([
            'cashier' => 'Andi', 'shifts' => 2, 'transactions' => 2, 'refunds' => 11100, 'difference' => -200,
        ]);
});

describe('pages', function () {
    it('are for the owner only', function () {
        foreach (['reports.sales', 'reports.products', 'reports.inventory', 'reports.shifts'] as $route) {
            $this->actingAs($this->andi)->get(route($route))->assertForbidden();
            $this->actingAs($this->owner)->get(route($route))->assertOk();
        }
    });

    it('pass the filters through', function () {
        $this->actingAs($this->owner)
            ->get(route('reports.sales', ['from' => '2026-11-20', 'to' => '2026-11-21']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/sales')
                ->where('filters.group', 'day')
                ->has('report.rows', 2)
                ->where('report.totals.grossProfit', 5000));

        $this->actingAs($this->owner)
            ->get(route('reports.sales', ['group' => 'month', 'from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.group', 'month')->has('report.rows', 1));

        $this->actingAs($this->owner)
            ->get(route('reports.products', ['from' => '2026-11-21', 'to' => '2026-11-21']))
            ->assertInertia(fn (Assert $page) => $page->component('reports/products')->has('rows', 2));
    });

    it('swap a backwards range and ignore garbage dates', function () {
        $this->actingAs($this->owner)
            ->get(route('reports.sales', ['from' => '2026-11-21', 'to' => '2026-11-20']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-11-20')->where('filters.to', '2026-11-21'));

        $this->actingAs($this->owner)
            ->get(route('reports.sales', ['from' => '2026-02-31', 'to' => 'kemarin']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.to', '2026-11-21')->has('report.rows', 30));
    });
});
