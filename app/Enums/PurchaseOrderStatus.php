<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    /** Being written; editable, no number yet. */
    case Draft = 'draft';
    /** Sent to the supplier; nothing received yet. */
    case Ordered = 'ordered';
    case Partial = 'partial';
    case Received = 'received';
    /** Partly received; the rest will not come. */
    case Closed = 'closed';
    /** Called off before anything arrived. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ordered => 'Dipesan',
            self::Partial => 'Diterima sebagian',
            self::Received => 'Diterima',
            self::Closed => 'Ditutup',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** Still waiting for goods. */
    public function isOpen(): bool
    {
        return $this === self::Ordered || $this === self::Partial;
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::Ordered, self::Partial];
    }
}
