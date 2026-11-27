<?php

namespace App\Enums;

/** Where a pelunasan stands (DESIGN.md §18). Cash payments are completed at once. */
enum CustomerPaymentStatus: string
{
    /** QRIS shown to the customer; the debt has not come down yet. */
    case PendingPayment = 'pending_payment';
    case Completed = 'completed';
    /** QRIS expired, was cancelled, or Midtrans refused the charge. */
    case Cancelled = 'cancelled';
}
