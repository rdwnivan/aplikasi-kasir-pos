import { router } from '@inertiajs/react';
import { Loader2, QrCode, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatRupiah } from '@/lib/money';

type Status = 'pending_payment' | 'completed' | 'cancelled';

/** What any QR on screen needs: a sale (DESIGN.md §9.3) or a pelunasan (§18). */
export type QrisTarget = {
    id: number;
    number: string;
    /** What the QR charges. */
    amount: number;
    qrUrl: string | null;
    expiresAt: string | null;
};

type Props = {
    payment: QrisTarget | null;
    onPaid: () => void;
    /** Polling endpoint (JSON with `status`) and the cancel action for this kind of QR. */
    statusUrl: (id: number) => string;
    cancelUrl: (id: number) => string;
    /** Inertia prop to reload once the QR is gone, which closes the dialog. */
    reloadOnly: string;
    /** Under the amount, e.g. the cash part of a mixed sale or the customer's name. */
    note?: ReactNode;
    /** Shown when the QR expired or was cancelled. */
    cancelledNote: ReactNode;
};

const POLL_MS = 3000;

function remaining(expiresAt: string | null): number | null {
    if (!expiresAt) {
        return null;
    }

    return Math.max(
        0,
        Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000),
    );
}

/**
 * Shows the Midtrans QR and polls the server until the payment settles, expires or is
 * cancelled. The server asks Midtrans directly on each poll, so this works without the
 * webhook reaching the machine (handy on a laptop).
 */
export function QrisDialog({
    payment,
    onPaid,
    statusUrl,
    cancelUrl,
    reloadOnly,
    note,
    cancelledNote,
}: Props) {
    const [status, setStatus] = useState<Status>('pending_payment');
    const [seconds, setSeconds] = useState<number | null>(null);
    const [cancelling, setCancelling] = useState(false);
    const id = payment?.id;
    const expiresAt = payment?.expiresAt ?? null;

    useEffect(() => {
        if (id === undefined) {
            return;
        }

        setStatus('pending_payment');
        let stopped = false;

        const poll = async () => {
            try {
                const response = await fetch(statusUrl(id), {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok || stopped) {
                    return;
                }

                const data = (await response.json()) as { status: Status };
                setStatus(data.status);

                if (data.status !== 'pending_payment') {
                    stopped = true;
                }

                if (data.status === 'completed') {
                    onPaid();
                }
            } catch {
                // Network hiccup: try again on the next tick.
            }
        };

        const timer = window.setInterval(() => {
            if (!stopped) {
                void poll();
            }
        }, POLL_MS);
        void poll();

        return () => {
            stopped = true;
            window.clearInterval(timer);
        };
    }, [id, onPaid, statusUrl]);

    useEffect(() => {
        setSeconds(remaining(expiresAt));
        const timer = window.setInterval(
            () => setSeconds(remaining(expiresAt)),
            1000,
        );

        return () => window.clearInterval(timer);
    }, [expiresAt]);

    const cancel = () =>
        router.post(
            cancelUrl(id ?? 0),
            {},
            {
                preserveScroll: true,
                onStart: () => setCancelling(true),
                onFinish: () => setCancelling(false),
            },
        );

    const closeAfterFailure = () => router.reload({ only: [reloadOnly] });

    return (
        <Dialog open={payment !== null}>
            <DialogContent
                className="sm:max-w-sm [&>button:last-child]:hidden"
                onEscapeKeyDown={(e) => e.preventDefault()}
                onInteractOutside={(e) => e.preventDefault()}
            >
                {payment && (
                    <>
                        <DialogHeader className="items-center text-center">
                            <DialogTitle className="flex items-center gap-2">
                                <QrCode className="size-5" /> Scan QRIS
                            </DialogTitle>
                            <DialogDescription>
                                {payment.number}
                            </DialogDescription>
                            <div className="text-3xl font-bold tracking-tight tabular-nums">
                                {formatRupiah(payment.amount)}
                            </div>
                            {note && (
                                <p className="text-sm text-muted-foreground">
                                    {note}
                                </p>
                            )}
                        </DialogHeader>

                        {status === 'cancelled' ? (
                            <div className="flex flex-col items-center gap-2 py-6 text-center text-sm">
                                <XCircle className="size-10 text-destructive" />
                                {cancelledNote}
                            </div>
                        ) : (
                            <>
                                <div className="mx-auto aspect-square w-64 overflow-hidden rounded-lg bg-white p-2">
                                    {payment.qrUrl ? (
                                        <img
                                            src={payment.qrUrl}
                                            alt={`QRIS ${payment.number}`}
                                            className="size-full object-contain"
                                        />
                                    ) : (
                                        <p className="p-4 text-sm text-black">
                                            Gambar QR tidak tersedia.
                                        </p>
                                    )}
                                </div>
                                <p className="flex items-center justify-center gap-2 text-sm text-muted-foreground">
                                    <Loader2 className="size-4 animate-spin" />
                                    Menunggu pembayaran
                                    {seconds !== null &&
                                        ` · ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`}
                                </p>
                            </>
                        )}

                        <DialogFooter>
                            {status === 'cancelled' ? (
                                <Button
                                    className="w-full"
                                    onClick={closeAfterFailure}
                                >
                                    Tutup
                                </Button>
                            ) : (
                                <Button
                                    variant="outline"
                                    className="w-full"
                                    disabled={cancelling}
                                    onClick={cancel}
                                >
                                    Batalkan QRIS
                                </Button>
                            )}
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
