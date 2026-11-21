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
    expectedCash: number;
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
};
