<?php

namespace App\Services\Documents;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Issues document numbers like GR-20261121-0001. The counter row is locked for the rest of
 * the surrounding transaction, so two receipts posted at the same moment never share a number.
 * Call it inside the transaction that creates the document.
 */
final class DocumentNumber
{
    public const GOODS_RECEIPT = 'GR';

    public const STOCK_OPNAME = 'SO';

    public const SALE = 'INV';

    public const SALE_RETURN = 'RET';

    public function next(string $prefix, ?CarbonInterface $date = null): string
    {
        $key = $prefix.'-'.($date ?? now())->format('Ymd');

        return DB::transaction(function () use ($key) {
            DB::table('document_sequences')->insertOrIgnore(['key' => $key, 'last_value' => 0]);

            $current = DB::table('document_sequences')->where('key', $key)->lockForUpdate()->value('last_value');
            $next = $current + 1;

            DB::table('document_sequences')->where('key', $key)->update(['last_value' => $next]);

            return sprintf('%s-%04d', $key, $next);
        });
    }
}
