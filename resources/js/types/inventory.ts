export type ProductOption = {
    id: number;
    sku: string;
    name: string;
    category: string | null;
    categoryId: number | null;
    baseUnit: string;
    stock: number;
    avgCost: number;
    units: {
        unitId: number;
        name: string;
        conversion: number;
        barcode: string | null;
    }[];
    /** Batch & expiry tracking (DESIGN.md §19). */
    tracksExpiry: boolean;
    /** Open batches, first expired first; empty unless tracksExpiry. */
    batches: BatchOption[];
};

export type BatchStatus = 'expired' | 'soon' | 'ok' | 'undated';

export type BatchOption = {
    id: number;
    expiresOn: string | null;
    code: string | null;
    qty: number;
    status: BatchStatus;
};

/** One expiry date on a goods receipt line, counted in the line's unit. */
export type BatchInput = {
    expires_on: string;
    code: string;
    qty: number;
};
