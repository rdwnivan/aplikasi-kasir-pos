export type PurchaseOrderStatus =
    | 'draft'
    | 'ordered'
    | 'partial'
    | 'received'
    | 'closed'
    | 'cancelled';

/** Status values with their labels, from App\Enums\PurchaseOrderStatus. */
export type StatusOption = { value: PurchaseOrderStatus; label: string };

/** A purchase order line as the form sends it. */
export type PurchaseOrderLine = {
    product_id: number;
    unit_id: number;
    qty: number;
    unit_price: number;
};
