import { toast } from 'sonner';

/** Show the first validation message as a toast, for actions that have no form to show it in. */
export function toastFirstError(errors: Record<string, string>): void {
    const first = Object.values(errors)[0];

    if (first) {
        toast.error(first);
    }
}
