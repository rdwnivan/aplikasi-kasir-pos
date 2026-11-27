export type DiscountType = 'amount' | 'percent';

export type CatalogItem = {
    id: number; // product_unit_id
    productId: number;
    sku: string;
    name: string;
    category: string | null;
    unit: string;
    conversion: number;
    price: number;
    barcode: string | null;
    stock: number; // base units
};

export type CartItem = {
    productUnitId: number;
    qty: number;
    discountType: DiscountType | null;
    discountValue: number;
};

export type HeldSale = {
    id: number;
    label: string | null;
    total: number;
    heldAt: string | null;
    discountType: DiscountType | null;
    discountValue: number;
    items: CartItem[];
};

export type ShiftSummary = {
    id: number;
    openedAt: string;
    openingCash: number;
    salesCount: number;
    cashSalesTotal: number;
    refundsCount: number;
    refundsTotal: number;
    /** Kasbon given in this shift (the part left owing). */
    creditSalesTotal: number;
    /** Kasbon paid off in cash into this drawer. */
    paymentsCount: number;
    paymentsTotal: number;
    expectedCash: number;
};

/** A customer in the kasbon picker (DESIGN.md §17). */
export type CreditCustomer = {
    id: number;
    name: string;
    phone: string | null;
    balance: number;
    creditLimit: number;
    /** How much more kasbon fits under the limit. */
    available: number;
};

export type TaxConfig = {
    enabled: boolean;
    rate: number;
    dppNumerator: number;
    dppDenominator: number;
    pricesIncludeTax: boolean;
};

export type LastSale = {
    id: number;
    number: string;
    total: number;
    paid: number;
    change: number;
    method: 'cash' | 'qris' | 'split' | 'credit' | null;
    cashAmount: number;
    qrisAmount: number;
    creditAmount: number;
    /** For kasbon: who owes it, and what they owed right after this sale. */
    customer: { name: string; balance: number } | null;
};

export type PendingQris = {
    id: number;
    number: string;
    total: number;
    /** What the QR charges: the whole total, or the rest after a cash part. */
    amount: number;
    /** Cash already taken for a mixed payment; 0 for plain QRIS. */
    cashAmount: number;
    qrUrl: string | null;
    qrString: string | null;
    expiresAt: string | null;
};
