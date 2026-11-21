const dateFormat = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'Asia/Jakarta',
});

const dateTimeFormat = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Asia/Jakarta',
});

const qtyFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });

/** "2026-11-21" or an ISO timestamp → "21 Nov 2026" */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '–';
    }

    // A bare date is a calendar day, not midnight UTC.
    const date = /^\d{4}-\d{2}-\d{2}$/.test(value)
        ? new Date(`${value}T00:00:00+07:00`)
        : new Date(value);

    return dateFormat.format(date);
}

/** ISO timestamp → "21 Nov 2026, 14.05" */
export function formatDateTime(value: string | null | undefined): string {
    return value ? dateTimeFormat.format(new Date(value)) : '–';
}

/** 1200 → "1.200" */
export function formatQty(value: number): string {
    return qtyFormat.format(value);
}
