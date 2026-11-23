import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { PurchaseOrderStatus, StatusOption } from '@/types';

const tone: Record<PurchaseOrderStatus, string> = {
    draft: 'border-amber-500/50 text-amber-600 dark:text-amber-400',
    ordered: 'border-sky-500/50 text-sky-600 dark:text-sky-400',
    partial: 'border-violet-500/50 text-violet-600 dark:text-violet-400',
    received: 'border-emerald-500/50 text-emerald-600 dark:text-emerald-400',
    closed: 'text-muted-foreground',
    cancelled: 'text-muted-foreground line-through',
};

export function PoStatus({
    status,
    statuses,
}: {
    status: PurchaseOrderStatus;
    statuses: StatusOption[];
}) {
    return (
        <Badge variant="outline" className={cn(tone[status])}>
            {statuses.find((s) => s.value === status)?.label ?? status}
        </Badge>
    );
}

export function LateBadge() {
    return (
        <Badge
            variant="outline"
            className="border-destructive/50 text-destructive"
        >
            Terlambat
        </Badge>
    );
}
