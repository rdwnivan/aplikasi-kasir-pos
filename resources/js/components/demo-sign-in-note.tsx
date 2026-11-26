import { Info } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * Shown instead of the 2FA / passkey buttons on a protected demo account
 * (`signInLocked`); the server rejects those changes anyway.
 */
export default function DemoSignInNote({ children }: { children: ReactNode }) {
    return (
        <p className="flex items-start gap-2 rounded-lg border border-border bg-muted/50 p-3 text-sm text-muted-foreground">
            <Info className="mt-0.5 h-4 w-4 shrink-0" />
            <span>{children}</span>
        </p>
    );
}
