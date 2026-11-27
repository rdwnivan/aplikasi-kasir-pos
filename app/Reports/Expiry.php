<?php

namespace App\Reports;

use App\Models\StockBatch;
use App\Support\DateRange;
use App\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * Batches with stock of products that track expiry, by status, as of today in WIB
 * (DESIGN.md §19.3). Value = remaining qty × the product's moving-average HPP (§19 P3), so the
 * expired part shows what throwing it away will cost. Shared by the report page, its exports
 * and the dashboard card.
 */
final class Expiry
{
    public const STATUSES = [StockBatch::STATUS_EXPIRED, StockBatch::STATUS_SOON, StockBatch::STATUS_OK, StockBatch::STATUS_UNDATED];

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, array{count: int, qty: int, value: int}>, warnDays: int, asOf: string}
     */
    public static function build(?string $status = null): array
    {
        $today = CarbonImmutable::now(DateRange::TIMEZONE)->startOfDay();

        $all = array_map(fn (StockBatch $b) => self::row($b, $today), self::openBatches());

        $totals = [];
        foreach ([...self::STATUSES, 'all'] as $key) {
            $rows = $key === 'all' ? $all : array_filter($all, fn (array $r) => $r['status'] === $key);
            $totals[$key] = [
                'count' => count($rows),
                'qty' => array_sum(array_column($rows, 'qty')),
                'value' => array_sum(array_column($rows, 'value')),
            ];
        }

        $rows = in_array($status, self::STATUSES, true)
            ? array_values(array_filter($all, fn (array $r) => $r['status'] === $status))
            : $all;

        return ['rows' => $rows, 'totals' => $totals, 'warnDays' => StockBatch::warnDays(), 'asOf' => $today->toDateString()];
    }

    /**
     * The dashboard card: counts, and the batches that need looking at first. Null when no
     * product tracks expiry with stock, so the card stays away for shops that don't use it.
     *
     * @return array<string, mixed>|null
     */
    public static function summary(int $limit = 5): ?array
    {
        $report = self::build();
        if ($report['rows'] === []) {
            return null;
        }

        $urgent = array_values(array_filter($report['rows'], fn (array $r) => in_array($r['status'], [StockBatch::STATUS_EXPIRED, StockBatch::STATUS_SOON], true)));

        return [
            'warnDays' => $report['warnDays'],
            'expired' => $report['totals'][StockBatch::STATUS_EXPIRED],
            'soon' => $report['totals'][StockBatch::STATUS_SOON],
            'undated' => $report['totals'][StockBatch::STATUS_UNDATED],
            'items' => array_slice($urgent, 0, $limit),
        ];
    }

    /** @return list<StockBatch> earliest date first, batches without a date last */
    private static function openBatches(): array
    {
        return array_values(StockBatch::query()
            ->with('product.baseUnit')
            ->where('qty_on_hand', '>', 0)
            ->whereHas('product', fn ($q) => $q->where('tracks_expiry', true))
            ->orderByRaw('CASE WHEN expires_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_on')
            ->orderBy('product_id')
            ->orderBy('id')
            ->get()
            ->all());
    }

    /** @return array<string, mixed> */
    private static function row(StockBatch $batch, CarbonImmutable $today): array
    {
        $product = $batch->product;
        $status = $batch->status($today);

        return [
            'batchId' => $batch->id,
            'productId' => $product->id,
            'sku' => $product->sku,
            'product' => $product->name,
            'code' => $batch->code,
            'label' => $batch->label(),
            'expiresOn' => $batch->expires_on?->toDateString(),
            'days' => $batch->expires_on === null ? null : (int) $today->diffInDays(CarbonImmutable::parse($batch->expires_on->toDateString(), DateRange::TIMEZONE), false),
            'qty' => $batch->qty_on_hand,
            'baseUnit' => $product->baseUnit->name,
            'avgCost' => (float) $product->avg_cost,
            'value' => (int) Decimal::round(Decimal::mul((string) $batch->qty_on_hand, (string) $product->avg_cost), 0),
            'status' => $status,
            'statusLabel' => StockBatch::statusLabel($status),
        ];
    }
}
