import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { formatDate, formatQty } from '@/lib/format';
import type { BatchInput, BatchStatus } from '@/types';

const statusLabel: Record<BatchStatus, string> = {
    expired: 'Kedaluwarsa',
    soon: 'Hampir kedaluwarsa',
    ok: 'Aman',
    undated: 'Tanpa tanggal',
};

/** Expiry status of a batch (DESIGN.md §19): expired, within the warning window, fine, or undated. */
export function BatchStatusBadge({
    status,
    className,
}: {
    status: BatchStatus;
    className?: string;
}) {
    return (
        <Badge
            variant="outline"
            className={cn(
                status === 'expired' &&
                    'border-red-500/50 text-red-600 dark:text-red-400',
                status === 'soon' &&
                    'border-amber-500/50 text-amber-600 dark:text-amber-400',
                status === 'ok' &&
                    'border-emerald-500/50 text-emerald-600 dark:text-emerald-400',
                className,
            )}
        >
            {statusLabel[status]}
        </Badge>
    );
}

/** "12 Des 2026 · L42", or "Tanpa tanggal". */
export function batchLabel(expiresOn: string | null, code: string | null) {
    const date = expiresOn ? formatDate(expiresOn) : 'Tanpa tanggal';

    return code ? `${date} · ${code}` : date;
}

/** "3 hari lagi", "hari ini", "lewat 2 hari". */
export function daysLabel(days: number | null) {
    if (days === null) {
        return 'tanpa tanggal';
    }

    if (days === 0) {
        return 'hari ini';
    }

    return days > 0 ? `${days} hari lagi` : `lewat ${-days} hari`;
}

/**
 * Expiry dates for one goods receipt line of a product that tracks expiry, counted in the
 * line's unit; they must add up to the line (the server checks again).
 */
export function ExpiryBatchesInput({
    batches,
    lineQty,
    unit,
    minDate,
    error,
    onChange,
}: {
    batches: BatchInput[];
    lineQty: number;
    unit: string;
    minDate: string;
    error?: string;
    onChange: (batches: BatchInput[]) => void;
}) {
    const sum = batches.reduce((s, b) => s + (b.qty || 0), 0);
    const set = (index: number, patch: Partial<BatchInput>) =>
        onChange(batches.map((b, i) => (i === index ? { ...b, ...patch } : b)));

    return (
        <div className="space-y-2 rounded-md bg-muted/40 p-2">
            <p className="text-xs font-medium text-muted-foreground">
                Tanggal kedaluwarsa
            </p>
            {batches.map((b, i) => (
                <div
                    key={i}
                    className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_5.5rem_auto] gap-2"
                >
                    <Input
                        type="date"
                        min={minDate}
                        aria-label="Tanggal kedaluwarsa"
                        value={b.expires_on}
                        onChange={(e) => set(i, { expires_on: e.target.value })}
                        required
                    />
                    <Input
                        aria-label="Kode batch"
                        placeholder="Kode batch (opsional)"
                        value={b.code}
                        maxLength={50}
                        onChange={(e) => set(i, { code: e.target.value })}
                    />
                    <Input
                        type="number"
                        min={1}
                        aria-label={`Jumlah (${unit})`}
                        value={b.qty}
                        onChange={(e) =>
                            set(i, { qty: Number(e.target.value) || 0 })
                        }
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        title="Hapus tanggal"
                        disabled={batches.length === 1}
                        onClick={() =>
                            onChange(batches.filter((_, j) => j !== i))
                        }
                    >
                        <Trash2 />
                    </Button>
                </div>
            ))}
            <div className="flex items-center justify-between gap-2 text-xs">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        onChange([
                            ...batches,
                            {
                                expires_on: '',
                                code: '',
                                qty: Math.max(0, lineQty - sum),
                            },
                        ])
                    }
                >
                    <Plus /> Tanggal lain
                </Button>
                <span
                    className={cn(
                        'tabular-nums',
                        sum === lineQty
                            ? 'text-muted-foreground'
                            : 'font-medium text-amber-600 dark:text-amber-400',
                    )}
                >
                    {formatQty(sum)} dari {formatQty(lineQty)} {unit}
                </span>
            </div>
            <InputError message={error} />
        </div>
    );
}
