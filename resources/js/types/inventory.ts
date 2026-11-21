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
};
