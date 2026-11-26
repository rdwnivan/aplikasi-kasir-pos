<?php

namespace App\Http\Controllers;

use App\Exports\Spreadsheet;
use App\Reports\InventoryValuation;
use App\Reports\SalesSummary;
use App\Reports\ShiftRecap;
use App\Reports\TopProducts;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The same reports as the /laporan pages, as .xlsx downloads with the same filters. */
class ReportExportController extends Controller
{
    public function sales(Request $request): BinaryFileResponse
    {
        $group = $request->query('group') === 'month' ? 'month' : 'day';
        $range = DateRange::fromRequest($request, defaultDays: $group === 'month' ? 365 : 30);
        $report = SalesSummary::build($range, $group);
        $t = $report['totals'];

        return (new Spreadsheet)->sheet(
            $group === 'month' ? 'Penjualan bulanan' : 'Penjualan harian',
            self::title('Laporan penjualan & laba kotor', $range, 'Laba kotor = penjualan tanpa PPN, setelah retur, dikurangi HPP.'),
            [
                ['label' => $group === 'month' ? 'Bulan' : 'Tanggal', 'width' => 12],
                ['label' => 'Transaksi', 'format' => Spreadsheet::NUMBER, 'width' => 10],
                ['label' => 'Penjualan (incl. PPN)', 'format' => Spreadsheet::RUPIAH, 'width' => 18],
                ['label' => 'Tunai', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'QRIS', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'Retur', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'PPN', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'Penjualan bersih', 'format' => Spreadsheet::RUPIAH, 'width' => 16],
                ['label' => 'HPP', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'Laba kotor', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'Margin', 'format' => Spreadsheet::PERCENT, 'width' => 9],
            ],
            array_map(fn (array $r) => [
                $r['period'], $r['transactions'], $r['gross'], $r['cash'], $r['qris'], -$r['refunds'],
                $r['tax'], $r['netSales'], $r['cogs'], $r['grossProfit'], $r['margin'],
            ], $report['rows']),
            ['Total', $t['transactions'], $t['gross'], $t['cash'], $t['qris'], -$t['refunds'], $t['tax'], $t['netSales'], $t['cogs'], $t['grossProfit'], $t['margin']],
        )->download(self::filename('penjualan', $range));
    }

    public function products(Request $request): BinaryFileResponse
    {
        $range = DateRange::fromRequest($request, defaultDays: 30);
        $rows = TopProducts::build($range, 500);

        return (new Spreadsheet)->sheet(
            'Produk terlaris',
            self::title('Produk terlaris', $range, 'Jumlah dalam satuan dasar, dikurangi retur pada periode yang sama. Laba kotor tanpa PPN, sama dengan laporan penjualan.'),
            [
                ['label' => 'Peringkat', 'format' => Spreadsheet::NUMBER, 'width' => 9],
                ['label' => 'SKU', 'width' => 12],
                ['label' => 'Produk', 'width' => 32],
                ['label' => 'Satuan', 'width' => 9],
                ['label' => 'Terjual', 'format' => Spreadsheet::NUMBER, 'width' => 10],
                ['label' => 'Retur', 'format' => Spreadsheet::NUMBER, 'width' => 10],
                ['label' => 'Bersih', 'format' => Spreadsheet::NUMBER, 'width' => 10],
                ['label' => 'Omzet (incl. PPN)', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'Penjualan bersih', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'HPP', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'Laba kotor', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'Margin', 'format' => Spreadsheet::PERCENT, 'width' => 9],
            ],
            array_map(fn (array $r, int $i) => [
                $i + 1, $r['sku'], $r['name'], $r['baseUnit'], $r['soldQty'], $r['returnedQty'], $r['netQty'], $r['revenue'], $r['netSales'], $r['cogs'], $r['profit'], $r['margin'],
            ], $rows, array_keys($rows)),
        )->download(self::filename('produk-terlaris', $range));
    }

    public function inventory(): BinaryFileResponse
    {
        $report = InventoryValuation::build();
        $asOf = CarbonImmutable::now(DateRange::TIMEZONE);
        $title = ['Nilai persediaan', 'Per '.$asOf->translatedFormat('j M Y H:i').' WIB', 'Stok × HPP rata-rata (moving average), produk aktif.'];

        return (new Spreadsheet)
            ->sheet(
                'Per produk',
                $title,
                [
                    ['label' => 'SKU', 'width' => 12],
                    ['label' => 'Produk', 'width' => 32],
                    ['label' => 'Kategori', 'width' => 18],
                    ['label' => 'Stok', 'format' => Spreadsheet::NUMBER, 'width' => 10],
                    ['label' => 'Satuan', 'width' => 9],
                    ['label' => 'HPP rata-rata', 'format' => '"Rp" #,##0.00', 'width' => 16],
                    ['label' => 'Nilai', 'format' => Spreadsheet::RUPIAH, 'width' => 16],
                ],
                array_map(fn (array $r) => [$r['sku'], $r['name'], $r['category'], $r['stock'], $r['baseUnit'], $r['avgCost'], $r['value']], $report['rows']),
                ['', 'Total', '', '', '', '', $report['total']],
            )
            ->sheet(
                'Per kategori',
                $title,
                [
                    ['label' => 'Kategori', 'width' => 24],
                    ['label' => 'Produk', 'format' => Spreadsheet::NUMBER, 'width' => 10],
                    ['label' => 'Nilai', 'format' => Spreadsheet::RUPIAH, 'width' => 16],
                ],
                array_map(fn (array $c) => [$c['name'], $c['products'], $c['value']], $report['categories']),
                ['Total', $report['units'], $report['total']],
            )
            ->download('nilai-persediaan-'.$asOf->format('Y-m-d').'.xlsx');
    }

    public function shifts(Request $request): BinaryFileResponse
    {
        $range = DateRange::fromRequest($request, defaultDays: 7);
        $report = ShiftRecap::build($range);
        $title = self::title('Rekap kasir & shift', $range, 'Selisih = uang dihitung − kas seharusnya (negatif berarti kurang).');
        $time = fn (?string $iso) => $iso === null ? '' : CarbonImmutable::parse($iso)->setTimezone(DateRange::TIMEZONE)->format('Y-m-d H:i');

        return (new Spreadsheet)
            ->sheet(
                'Per kasir',
                $title,
                [
                    ['label' => 'Kasir', 'width' => 20],
                    ['label' => 'Shift', 'format' => Spreadsheet::NUMBER, 'width' => 8],
                    ['label' => 'Transaksi', 'format' => Spreadsheet::NUMBER, 'width' => 10],
                    ['label' => 'Tunai', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'QRIS', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Retur', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Total selisih', 'format' => Spreadsheet::RUPIAH],
                ],
                array_map(fn (array $c) => [$c['cashier'], $c['shifts'], $c['transactions'], $c['cashSales'], $c['qrisSales'], -$c['refunds'], $c['difference']], $report['cashiers']),
            )
            ->sheet(
                'Per shift',
                $title,
                [
                    ['label' => 'Kasir', 'width' => 20],
                    ['label' => 'Dibuka', 'width' => 17],
                    ['label' => 'Ditutup', 'width' => 17],
                    ['label' => 'Transaksi', 'format' => Spreadsheet::NUMBER, 'width' => 10],
                    ['label' => 'Modal awal', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Tunai', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'QRIS', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Retur', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Kas seharusnya', 'format' => Spreadsheet::RUPIAH, 'width' => 16],
                    ['label' => 'Dihitung', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Selisih', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Ditutup oleh', 'width' => 20],
                    ['label' => 'Alasan', 'width' => 40],
                ],
                array_map(fn (array $s) => [
                    $s['cashier'], $time($s['openedAt']), $time($s['closedAt']), $s['transactions'], $s['openingCash'],
                    $s['cashSales'], $s['qrisSales'], -$s['refunds'], $s['expectedCash'], $s['countedCash'], $s['difference'],
                    $s['closedBy'] ?? '', $s['closeReason'] ?? '',
                ], $report['shifts']),
            )
            ->download(self::filename('rekap-kasir', $range));
    }

    /** @return list<string> */
    private static function title(string $name, DateRange $range, string $note): array
    {
        return [
            $name.' — '.config('pos.store.name'),
            'Periode '.$range->from->translatedFormat('j M Y').' s.d. '.$range->to->translatedFormat('j M Y').' (WIB)',
            $note,
        ];
    }

    private static function filename(string $name, DateRange $range): string
    {
        return "laporan-{$name}-{$range->from->toDateString()}_{$range->to->toDateString()}.xlsx";
    }
}
