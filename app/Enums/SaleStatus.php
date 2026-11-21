<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Held = 'held';
    /** QRIS shown to the customer; no stock has moved yet. */
    case PendingPayment = 'pending_payment';
    case Completed = 'completed';
    /** QRIS expired or was cancelled before payment. */
    case Cancelled = 'cancelled';
}
