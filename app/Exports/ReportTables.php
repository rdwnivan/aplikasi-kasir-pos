<?php

namespace App\Exports;

use App\Reports\Expiry;
use App\Reports\InventoryValuation;
use App\Reports\Receivables;
use App\Reports\SalesSummary;
use App\Reports\ShiftRecap;
use App\Reports\TopProducts;
use App\Support\DateRange;
use Carbon\CarbonImmutable;

/**
 * The tables of the /laporan reports for export: which columns, in what order, with which
 * totals. The single definition behind both the Excel and the PDF download.
 */
final class ReportTables
{
    /** @param  'day'|'month'  $group */
    public static function sales(DateRange $range, string $group): ReportDocument
    {
        $report = SalesSummary::build($range, $group);
        $t = $report['totals'];
        $monthly = $group === 'month';

        return (new ReportDocument(
            'Laporan penjualan & laba kotor',
            self::period($range),
            'Laba kotor = penjualan tanpa PPN, setelah retur, dikurangi HPP.',
            self::filename('penjualan', $range),
            landscape: true,
        ))->sheet(
            $monthly ? 'Penjualan bulanan' : 'Penjualan harian',
            [
                ['label' => $monthly ? 'Bulan' : 'Tanggal', 'kind' => $monthly ? 'month' : 'date', 'width' => 12],
                ['label' => 'Transaksi', 'format' => Spreadsheet::NUMBER, 'width' => 10],
                ['label' => 'Penjualan (incl. PPN)', 'format' => Spreadsheet::RUPIAH, 'width' => 18],
                ['label' => 'Tunai', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'QRIS', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'Kasbon', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'Retur', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'PPN', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'Penjualan bersih', 'format' => Spreadsheet::RUPIAH, 'width' => 16],
                ['label' => 'HPP', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'Laba kotor', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'Margin', 'format' => Spreadsheet::PERCENT, 'width' => 9],
            ],
            array_map(fn (array $r) => [
                $r['period'], $r['transactions'], $r['gross'], $r['cash'], $r['qris'], $r['credit'], -$r['refunds'],
                $r['tax'], $r['netSales'], $r['cogs'], $r['grossProfit'], $r['margin'],
            ], $report['rows']),
            ['Total', $t['transactions'], $t['gross'], $t['cash'], $t['qris'], $t['credit'], -$t['refunds'], $t['tax'], $t['netSales'], $t['cogs'], $t['grossProfit'], $t['margin']],
        );
    }

    public static function products(DateRange $range): ReportDocument
    {
        $rows = TopProducts::build($range, 500);

        return (new ReportDocument(
            'Produk terlaris',
            self::period($range),
            'Jumlah dalam satuan dasar, dikurangi retur pada periode yang sama. Laba kotor tanpa PPN, sama dengan laporan penjualan.',
            self::filename('produk-terlaris', $range),
            landscape: true,
        ))->sheet(
            'Produk terlaris',
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
        );
    }

    public static function inventory(): ReportDocument
    {
        $report = InventoryValuation::build();
        $asOf = CarbonImmutable::now(DateRange::TIMEZONE);

        return (new ReportDocument(
            'Nilai persediaan',
            'Per '.$asOf->translatedFormat('j M Y H.i').' WIB',
            'Stok × HPP rata-rata (moving average), produk aktif.',
            'nilai-persediaan-'.$asOf->format('Y-m-d'),
        ))
            ->sheet(
                'Per produk',
                [
                    ['label' => 'SKU', 'width' => 12],
                    ['label' => 'Produk', 'width' => 32],
                    ['label' => 'Kategori', 'width' => 18],
                    ['label' => 'Stok', 'format' => Spreadsheet::NUMBER, 'width' => 10],
                    ['label' => 'Satuan', 'width' => 9],
                    ['label' => 'HPP rata-rata', 'format' => Spreadsheet::RUPIAH_CENTS, 'width' => 16],
                    ['label' => 'Nilai', 'format' => Spreadsheet::RUPIAH, 'width' => 16],
                ],
                array_map(fn (array $r) => [$r['sku'], $r['name'], $r['category'], $r['stock'], $r['baseUnit'], $r['avgCost'], $r['value']], $report['rows']),
                ['', 'Total', '', '', '', '', $report['total']],
            )
            ->sheet(
                'Per kategori',
                [
                    ['label' => 'Kategori', 'width' => 24],
                    ['label' => 'Produk', 'format' => Spreadsheet::NUMBER, 'width' => 10],
                    ['label' => 'Nilai', 'format' => Spreadsheet::RUPIAH, 'width' => 16],
                ],
                array_map(fn (array $c) => [$c['name'], $c['products'], $c['value']], $report['categories']),
                ['Total', $report['units'], $report['total']],
            );
    }

    public static function shifts(DateRange $range): ReportDocument
    {
        $report = ShiftRecap::build($range);
        $time = fn (?string $iso) => $iso === null ? '' : CarbonImmutable::parse($iso)->setTimezone(DateRange::TIMEZONE)->format('Y-m-d H:i');

        return (new ReportDocument(
            'Rekap kasir & shift',
            self::period($range),
            'Selisih = uang dihitung − kas seharusnya (negatif berarti kurang). Pelunasan QRIS tidak masuk laci.',
            self::filename('rekap-kasir', $range),
            landscape: true,
        ))
            ->sheet(
                'Per kasir',
                [
                    ['label' => 'Kasir', 'width' => 20],
                    ['label' => 'Shift', 'format' => Spreadsheet::NUMBER, 'width' => 8],
                    ['label' => 'Transaksi', 'format' => Spreadsheet::NUMBER, 'width' => 10],
                    ['label' => 'Tunai', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'QRIS', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Kasbon', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Pelunasan tunai', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Pelunasan QRIS', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Retur', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Total selisih', 'format' => Spreadsheet::RUPIAH],
                ],
                array_map(fn (array $c) => [$c['cashier'], $c['shifts'], $c['transactions'], $c['cashSales'], $c['qrisSales'], $c['creditSales'], $c['payments'], $c['qrisPayments'], -$c['refunds'], $c['difference']], $report['cashiers']),
            )
            ->sheet(
                'Per shift',
                [
                    ['label' => 'Kasir', 'width' => 20],
                    ['label' => 'Dibuka', 'kind' => 'datetime', 'width' => 17],
                    ['label' => 'Ditutup', 'kind' => 'datetime', 'width' => 17],
                    ['label' => 'Transaksi', 'format' => Spreadsheet::NUMBER, 'width' => 10],
                    ['label' => 'Modal awal', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Tunai', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'QRIS', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Kasbon', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Pelunasan tunai', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Pelunasan QRIS', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Retur', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Kas seharusnya', 'format' => Spreadsheet::RUPIAH, 'width' => 16],
                    ['label' => 'Dihitung', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Selisih', 'format' => Spreadsheet::RUPIAH],
                    ['label' => 'Ditutup oleh', 'width' => 20],
                    ['label' => 'Alasan', 'width' => 40],
                ],
                array_map(fn (array $s) => [
                    $s['cashier'], $time($s['openedAt']), $time($s['closedAt']), $s['transactions'], $s['openingCash'],
                    $s['cashSales'], $s['qrisSales'], $s['creditSales'], $s['payments'], $s['qrisPayments'], -$s['refunds'], $s['expectedCash'], $s['countedCash'], $s['difference'],
                    $s['closedBy'] ?? '', $s['closeReason'] ?? '',
                ], $report['shifts']),
            );
    }

    /** Kasbon owed per customer as of now, with its age (DESIGN.md §17). */
    /** Batches with stock by expiry status (DESIGN.md §19), optionally one status only. */
    public static function expiry(?string $status = null): ReportDocument
    {
        $report = Expiry::build($status);
        $asOf = CarbonImmutable::now(DateRange::TIMEZONE);

        return (new ReportDocument(
            'Laporan kedaluwarsa',
            'Per '.$asOf->translatedFormat('j M Y H.i').' WIB',
            "Batch bersisa dari produk yang dilacak kedaluwarsanya. Hampir kedaluwarsa = {$report['warnDays']} hari lagi atau kurang. Nilai = sisa × HPP rata-rata.",
            'laporan-kedaluwarsa-'.$asOf->format('Y-m-d'),
        ))->sheet(
            'Kedaluwarsa',
            [
                ['label' => 'SKU', 'width' => 12],
                ['label' => 'Produk', 'width' => 28],
                ['label' => 'Kode batch', 'width' => 12],
                ['label' => 'Kedaluwarsa', 'kind' => 'date', 'width' => 13],
                ['label' => 'Sisa', 'format' => Spreadsheet::NUMBER, 'width' => 8],
                ['label' => 'Satuan', 'width' => 8],
                ['label' => 'HPP rata-rata', 'format' => Spreadsheet::RUPIAH_CENTS, 'width' => 15],
                ['label' => 'Nilai', 'format' => Spreadsheet::RUPIAH, 'width' => 14],
                ['label' => 'Status', 'width' => 18],
            ],
            array_map(fn (array $r) => [
                $r['sku'], $r['product'], $r['code'] ?? '', $r['expiresOn'] ?? '', $r['qty'], $r['baseUnit'], $r['avgCost'], $r['value'], $r['statusLabel'],
            ], $report['rows']),
            ['', 'Total', '', '', '', '', '', array_sum(array_column($report['rows'], 'value')), ''],
        );
    }

    public static function receivables(): ReportDocument
    {
        $report = Receivables::build();
        $t = $report['totals'];
        $asOf = CarbonImmutable::now(DateRange::TIMEZONE);
        $time = fn (?string $iso) => $iso === null ? '' : CarbonImmutable::parse($iso)->setTimezone(DateRange::TIMEZONE)->format('Y-m-d H:i');

        return (new ReportDocument(
            'Laporan piutang (kasbon)',
            'Per '.$asOf->translatedFormat('j M Y H.i').' WIB',
            'Umur dihitung dari tanggal setiap kasbon yang belum lunas; pelunasan menutup kasbon tertua lebih dulu.',
            'laporan-piutang-'.$asOf->format('Y-m-d'),
        ))->sheet(
            'Piutang',
            [
                ['label' => 'Pelanggan', 'width' => 22],
                ['label' => 'No. HP', 'width' => 14],
                ['label' => 'Utang', 'format' => Spreadsheet::RUPIAH],
                ['label' => '0–30 hari', 'format' => Spreadsheet::RUPIAH],
                ['label' => '31–60 hari', 'format' => Spreadsheet::RUPIAH],
                ['label' => '61–90 hari', 'format' => Spreadsheet::RUPIAH],
                ['label' => '> 90 hari', 'format' => Spreadsheet::RUPIAH],
                ['label' => 'Kasbon tertua', 'kind' => 'datetime', 'width' => 16],
                ['label' => 'Pelunasan terakhir', 'kind' => 'datetime', 'width' => 16],
                ['label' => 'Batas kredit', 'format' => Spreadsheet::RUPIAH],
            ],
            array_map(fn (array $r) => [
                $r['customer'], $r['phone'] ?? '', $r['balance'], $r['age0to30'], $r['age31to60'], $r['age61to90'], $r['age90plus'],
                $time($r['oldestAt']), $time($r['lastPaymentAt']), $r['creditLimit'],
            ], $report['rows']),
            ['Total', '', $t['balance'], $t['age0to30'], $t['age31to60'], $t['age61to90'], $t['age90plus'], '', '', ''],
        );
    }

    private static function period(DateRange $range): string
    {
        return 'Periode '.$range->from->translatedFormat('j M Y').' s.d. '.$range->to->translatedFormat('j M Y').' (WIB)';
    }

    private static function filename(string $name, DateRange $range): string
    {
        return "laporan-{$name}-{$range->from->toDateString()}_{$range->to->toDateString()}";
    }
}
