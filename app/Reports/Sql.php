<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Small driver-specific SQL pieces the reports need (PostgreSQL in production, SQLite in
 * tests). Timestamps are stored in the app timezone (Asia/Jakarta), so bucketing the stored
 * value gives the store's calendar day.
 */
final class Sql
{
    /**
     * Expression that turns a timestamp column into "YYYY-MM-DD" or "YYYY-MM".
     *
     * @param  literal-string  $column  a column name from code, never user input
     * @return literal-string
     */
    public static function bucket(string $column, string $group): string
    {
        $driver = DB::connection()->getDriverName();
        $month = $group === 'month';

        return match ($driver) {
            'pgsql' => $month ? "to_char({$column}, 'YYYY-MM')" : "to_char({$column}, 'YYYY-MM-DD')",
            'mysql', 'mariadb' => $month ? "DATE_FORMAT({$column}, '%Y-%m')" : "DATE_FORMAT({$column}, '%Y-%m-%d')",
            default => $month ? "strftime('%Y-%m', {$column})" : "strftime('%Y-%m-%d', {$column})",
        };
    }

    /**
     * Per sale, the part paid in cash (into the drawer) and the part paid by QRIS. A `split`
     * sale has both (DESIGN.md §11). Shared by the reports and Shift::cashSalesTotal().
     *
     * @return literal-string
     */
    public static function cashPart(): string
    {
        return "CASE sales.payment_method WHEN 'cash' THEN sales.total WHEN 'split' THEN sales.split_cash_amount ELSE 0 END";
    }

    /** @return literal-string */
    public static function qrisPart(): string
    {
        return "CASE sales.payment_method WHEN 'qris' THEN sales.total WHEN 'split' THEN sales.total - sales.split_cash_amount ELSE 0 END";
    }

    /** Whole rupiah from whatever the driver returns for SUM() (string, int, float or null). */
    public static function rupiah(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value) : 0;
    }
}
