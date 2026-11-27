<?php

namespace Database\Seeders;

use App\Actions\Inventory\ReceiveGoods;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

/**
 * Typical warung / minimarket stock. Opening stock is booked through ReceiveGoods, so the
 * demo starts with a real ledger, real goods receipts and a real moving-average HPP.
 * Barcodes are made up (prefix 899 = Indonesia, but these do not belong to real products).
 */
class CatalogSeeder extends Seeder
{
    /**
     * [sku, name, category, base unit, min stock, units: [unit, conversion, sell price, buy price per unit], opening qty in largest unit]
     *
     * Sell prices include PPN (effective 11%); buy prices are about 88% of the largest unit's
     * price without PPN, so the demo shows a gross margin of roughly 10–15%.
     */
    private const PRODUCTS = [
        ['MIE-001', 'Indomie Goreng 85g', 'Makanan Instan', 'pcs', 40, [['pcs', 1, 3500, null], ['dus', 40, 125000, 99000]], 5],
        ['MIE-002', 'Indomie Soto 70g', 'Makanan Instan', 'pcs', 40, [['pcs', 1, 3300, null], ['dus', 40, 118000, 93500]], 3],
        ['MNM-001', 'Aqua Botol 600ml', 'Minuman', 'pcs', 24, [['pcs', 1, 4000, null], ['dus', 24, 85000, 67500]], 4],
        ['MNM-002', 'Teh Botol Sosro 450ml', 'Minuman', 'pcs', 24, [['pcs', 1, 5000, null], ['dus', 24, 110000, 87000]], 3],
        ['KOP-001', 'Kopi Kapal Api Special Mix Sachet', 'Minuman', 'pcs', 20, [['pcs', 1, 1500, null], ['renceng', 10, 14000, 11000]], 10],
        ['SMB-001', 'Beras Rojolele 5kg', 'Sembako', 'karung', 5, [['karung', 1, 78000, 62000]], 20],
        ['SMB-002', 'Gula Pasir Gulaku 1kg', 'Sembako', 'pcs', 10, [['pcs', 1, 18500, 14700]], 30],
        ['SMB-003', 'Minyak Goreng Bimoli 2L', 'Sembako', 'pcs', 6, [['pcs', 1, 40000, null], ['dus', 6, 230000, 182000]], 4],
        ['SMB-004', 'Telur Ayam Negeri', 'Sembako', 'butir', 30, [['butir', 1, 2200, null], ['tray', 30, 62000, 49000]], 4],
        ['MDI-001', 'Sabun Lifebuoy 75g', 'Perawatan Diri', 'pcs', 8, [['pcs', 1, 4500, null], ['pak', 4, 17000, 13500]], 6],
        ['MDI-002', 'Pasta Gigi Pepsodent 190g', 'Perawatan Diri', 'pcs', 6, [['pcs', 1, 14500, 11500]], 12],
        ['RT-001', 'Rinso Anti Noda 770g', 'Kebutuhan Rumah', 'pcs', 5, [['pcs', 1, 24000, 19000]], 10],
        ['SUS-001', 'Susu UHT Ultra Milk 250ml', 'Minuman', 'pcs', 24, [['pcs', 1, 6500, null], ['dus', 24, 150000, 118900]], 3],
        ['ROT-001', 'Roti Tawar Sari Roti', 'Makanan', 'pcs', 5, [['pcs', 1, 16000, 12700]], 15],
    ];

    /**
     * Products that track expiry (DESIGN.md §19) and how many days a delivery keeps, from the
     * day it arrives. Also used by DemoSalesSeeder for restocks.
     */
    public const SHELF_LIFE_DAYS = [
        'MNM-002' => 120,
        'SUS-001' => 45,
        'ROT-001' => 3,
    ];

    /**
     * Expiry dates for a delivery of $qty (in the line's unit) arriving on $arrived: a carton or
     * two from an older lot that expires sooner, the rest fresh.
     *
     * @return list<array{expires_on: string, qty: int, code: null}>|null null for products that do not track expiry
     */
    public static function batchesFor(Product $product, int $qty, CarbonInterface $arrived): ?array
    {
        $days = self::SHELF_LIFE_DAYS[$product->sku] ?? null;
        if ($days === null || ! $product->tracks_expiry) {
            return null;
        }

        $fresh = $arrived->copy()->addDays($days)->toDateString();
        if ($qty < 3 || $days < 10) {
            return [['expires_on' => $fresh, 'qty' => $qty, 'code' => null]];
        }

        return [
            ['expires_on' => $arrived->copy()->addDays(intdiv($days, 4))->toDateString(), 'qty' => 1, 'code' => null],
            ['expires_on' => $fresh, 'qty' => $qty - 1, 'code' => null],
        ];
    }

    public function run(): void
    {
        $receiver = User::query()->where('email', 'gudang@kasir.test')->firstOrFail();
        $supplier = Supplier::query()->firstOrCreate(
            ['name' => 'CV Sumber Rejeki Grosir'],
            ['phone' => '0812-3456-7890', 'address' => 'Jl. Pasar Baru No. 12, Bandung'],
        );

        $lines = [];
        $openingDay = now()->subDays(7);

        foreach (self::PRODUCTS as $i => [$sku, $name, $category, $baseUnit, $minStock, $units, $openingQty]) {
            $product = Product::query()->firstOrCreate(['sku' => $sku], [
                'name' => $name,
                'category_id' => Category::query()->firstOrCreate(['name' => $category])->id,
                'base_unit_id' => Unit::query()->firstOrCreate(['name' => $baseUnit])->id,
                'min_stock' => $minStock,
            ]);

            if (! $product->wasRecentlyCreated) {
                continue; // already seeded: leave its stock and ledger alone
            }

            foreach ($units as $u => [$unitName, $conversion, $price]) {
                $product->units()->create([
                    'unit_id' => Unit::query()->firstOrCreate(['name' => $unitName])->id,
                    'conversion' => $conversion,
                    'price' => $price,
                    'barcode' => self::ean13(sprintf('899%04d%05d', $i + 1, $u + 1)),
                ]);
            }

            if (isset(self::SHELF_LIFE_DAYS[$sku])) {
                app(StockLedger::class)->startTracking($product, $receiver);
            }

            // Buy in the largest unit that has a purchase price.
            [$buyUnit, , , $buyPrice] = collect($units)->last(fn ($unit) => $unit[3] !== null);
            $line = [
                'product_id' => $product->id,
                'unit_id' => (int) Unit::query()->where('name', $buyUnit)->value('id'),
                'qty' => $openingQty,
                'unit_price' => $buyPrice,
            ];
            $batches = self::batchesFor($product, $openingQty, $openingDay);
            $lines[] = $batches === null ? $line : [...$line, 'batches' => $batches];
        }

        if ($lines !== []) {
            app(ReceiveGoods::class)->handle($receiver, $lines, $supplier->id, $openingDay, 'Stok awal');
        }
    }

    /** Append the EAN-13 check digit to a 12-digit code. */
    private static function ean13(string $digits): string
    {
        $sum = 0;
        foreach (str_split($digits) as $pos => $digit) {
            $sum += (int) $digit * ($pos % 2 === 0 ? 1 : 3);
        }

        return $digits.((10 - $sum % 10) % 10);
    }
}
