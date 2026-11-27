<?php

namespace App\Support;

use Illuminate\Support\Number;

/** "Rp 15.000" for messages and receipts written on the server. */
final class Rupiah
{
    public static function format(int $amount): string
    {
        return ($amount < 0 ? '-' : '').'Rp '.Number::format(abs($amount), locale: 'id');
    }
}
