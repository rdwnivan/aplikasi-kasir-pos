import { Badge } from '@/components/ui/badge';

export function OpnameStatus({ status }: { status: 'draft' | 'posted' }) {
    return status === 'posted' ? (
        <Badge variant="secondary">Diposting</Badge>
    ) : (
        <Badge
            variant="outline"
            className="border-amber-500/50 text-amber-600 dark:text-amber-400"
        >
            Draft
        </Badge>
    );
}
