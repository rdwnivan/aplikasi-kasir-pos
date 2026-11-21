<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    // QRIS (Midtrans) arrives with its webhook flow in milestone 4.
}
