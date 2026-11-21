import { useState } from 'react';
import { useOpenKey } from '@/hooks/use-open-key';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    open: boolean;
    processing: boolean;
    defaultLabel: string;
    onOpenChange: (open: boolean) => void;
    onHold: (label: string) => void;
};

export function HoldDialog(props: Props) {
    const key = useOpenKey(props.open);

    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            <DialogContent className="sm:max-w-sm">
                <HoldForm key={key} {...props} />
            </DialogContent>
        </Dialog>
    );
}

function HoldForm({ processing, defaultLabel, onHold }: Props) {
    const [label, setLabel] = useState(defaultLabel);

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                onHold(label.trim());
            }}
        >
            <DialogHeader>
                <DialogTitle>Tahan transaksi</DialogTitle>
                <DialogDescription>
                    Keranjang disimpan dan bisa dilanjutkan nanti. Stok belum
                    berkurang.
                </DialogDescription>
            </DialogHeader>
            <div className="grid gap-2">
                <Label htmlFor="hold-label">Nama / keterangan (opsional)</Label>
                <Input
                    id="hold-label"
                    autoFocus
                    maxLength={60}
                    placeholder="mis. Bu Ani, baju merah"
                    value={label}
                    onChange={(e) => setLabel(e.target.value)}
                />
            </div>
            <DialogFooter>
                <Button type="submit" disabled={processing}>
                    Tahan
                </Button>
            </DialogFooter>
        </form>
    );
}
