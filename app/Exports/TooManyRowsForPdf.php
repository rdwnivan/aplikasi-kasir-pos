<?php

namespace App\Exports;

use RuntimeException;

final class TooManyRowsForPdf extends RuntimeException
{
    public function __construct(public readonly int $rows, public readonly int $limit)
    {
        parent::__construct("Terlalu banyak baris untuk PDF ({$rows}, maksimal {$limit}). Persempit periode atau pakai Ekspor Excel.");
    }
}
