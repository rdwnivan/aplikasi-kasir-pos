import { cn } from '@/lib/utils';

export function Kbd({
    children,
    className,
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <kbd
            className={cn(
                'pointer-events-none inline-flex h-5 min-w-5 items-center justify-center rounded border bg-muted px-1 font-mono text-[10px] font-medium text-muted-foreground',
                className,
            )}
        >
            {children}
        </kbd>
    );
}
