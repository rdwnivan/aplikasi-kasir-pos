import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { RupiahInput } from '@/components/pos/rupiah-input';
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
import { Textarea } from '@/components/ui/textarea';
import { useOpenKey } from '@/hooks/use-open-key';
import customers from '@/routes/customers';

export type EditableCustomer = {
    id: number;
    name: string;
    phone: string | null;
    address: string | null;
    note: string | null;
    creditLimit: number;
};

type Props = {
    /** null = closed, 'new' = add, otherwise the customer to change. Owner only. */
    customer: EditableCustomer | 'new' | null;
    defaultLimit: number;
    onClose: () => void;
};

export function CustomerFormDialog({ customer, defaultLimit, onClose }: Props) {
    const key = useOpenKey(customer !== null);

    return (
        <Dialog
            open={customer !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                {customer !== null && (
                    <CustomerForm
                        key={key}
                        customer={customer === 'new' ? null : customer}
                        defaultLimit={defaultLimit}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function CustomerForm({
    customer,
    defaultLimit,
    onDone,
}: {
    customer: EditableCustomer | null;
    defaultLimit: number;
    onDone: () => void;
}) {
    const form = useForm({
        name: customer?.name ?? '',
        phone: customer?.phone ?? '',
        address: customer?.address ?? '',
        note: customer?.note ?? '',
        credit_limit: customer?.creditLimit ?? defaultLimit,
    });

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.submit(
                    customer
                        ? customers.update(customer.id)
                        : customers.store(),
                    { preserveScroll: true, onSuccess: onDone },
                );
            }}
        >
            <DialogHeader>
                <DialogTitle>
                    {customer ? `Ubah ${customer.name}` : 'Tambah pelanggan'}
                </DialogTitle>
                <DialogDescription>
                    Pelanggan bisa belanja kasbon sampai batasnya. Batas 0 =
                    tidak boleh kasbon.
                </DialogDescription>
            </DialogHeader>
            <div className="grid gap-2">
                <Label htmlFor="customer-name">Nama</Label>
                <Input
                    id="customer-name"
                    autoFocus
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <InputError message={form.errors.name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="customer-phone">Nomor HP</Label>
                <Input
                    id="customer-phone"
                    inputMode="tel"
                    value={form.data.phone}
                    onChange={(e) => form.setData('phone', e.target.value)}
                />
                <InputError message={form.errors.phone} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="customer-limit">Batas kasbon</Label>
                <RupiahInput
                    id="customer-limit"
                    value={form.data.credit_limit}
                    onValueChange={(v) => form.setData('credit_limit', v)}
                />
                <InputError message={form.errors.credit_limit} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="customer-address">Alamat</Label>
                <Input
                    id="customer-address"
                    value={form.data.address}
                    onChange={(e) => form.setData('address', e.target.value)}
                />
                <InputError message={form.errors.address} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="customer-note">Catatan</Label>
                <Textarea
                    id="customer-note"
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                />
                <InputError message={form.errors.note} />
            </div>
            <DialogFooter>
                <Button type="submit" disabled={form.processing}>
                    Simpan
                </Button>
            </DialogFooter>
        </form>
    );
}
