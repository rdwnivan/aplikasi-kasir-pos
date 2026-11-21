import { router, useForm } from '@inertiajs/react';
import type { RouteDefinition } from '@/wayfinder';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useOpenKey } from '@/hooks/use-open-key';
import { toastFirstError } from '@/lib/errors';

export type MasterItem = {
    id: number;
    name: string;
    usage: number;
    phone?: string | null;
    address?: string | null;
};

type Routes = {
    store: () => RouteDefinition<'post'>;
    update: (id: number) => RouteDefinition<'put' | 'patch'>;
    destroy: (id: number) => RouteDefinition<'delete'>;
};

type Props = {
    title: string;
    description: string;
    noun: string;
    usageLabel: string;
    items: MasterItem[];
    routes: Routes;
    withContact?: boolean;
    placeholder?: string;
};

/**
 * List + add/edit dialog for the small master tables (kategori, satuan, supplier).
 */
export function MasterList(props: Props) {
    const { title, description, noun, usageLabel, items, routes } = props;
    const [editing, setEditing] = useState<MasterItem | 'new' | null>(null);
    const [deleting, setDeleting] = useState<MasterItem | null>(null);

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <PageHeader
                title={title}
                description={description}
                actions={
                    <Button onClick={() => setEditing('new')}>
                        <Plus /> Tambah {noun}
                    </Button>
                }
            />

            <div className="rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Nama</TableHead>
                            {props.withContact && <TableHead>Kontak</TableHead>}
                            <TableHead className="text-right">
                                {usageLabel}
                            </TableHead>
                            <TableHead className="w-0" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {items.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={4}
                                    className="py-10 text-center text-muted-foreground"
                                >
                                    Belum ada {noun}.
                                </TableCell>
                            </TableRow>
                        )}
                        {items.map((item) => (
                            <TableRow key={item.id}>
                                <TableCell className="font-medium">
                                    {item.name}
                                </TableCell>
                                {props.withContact && (
                                    <TableCell className="text-sm text-muted-foreground">
                                        <div>{item.phone ?? '–'}</div>
                                        {item.address && (
                                            <div className="max-w-sm truncate text-xs">
                                                {item.address}
                                            </div>
                                        )}
                                    </TableCell>
                                )}
                                <TableCell className="text-right tabular-nums">
                                    {item.usage}
                                </TableCell>
                                <TableCell>
                                    <div className="flex justify-end gap-1">
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            title="Ubah"
                                            onClick={() => setEditing(item)}
                                        >
                                            <Pencil />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            title="Hapus"
                                            onClick={() => setDeleting(item)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            <EditDialog
                {...props}
                item={editing}
                onClose={() => setEditing(null)}
            />

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Hapus ${noun} ${deleting?.name ?? ''}?`}
                description="Tindakan ini tidak bisa dibatalkan."
                confirmLabel="Hapus"
                destructive
                onConfirm={() => {
                    if (deleting) {
                        router.visit(routes.destroy(deleting.id), {
                            preserveScroll: true,
                            onError: toastFirstError,
                            onFinish: () => setDeleting(null),
                        });
                    }
                }}
            />
        </div>
    );
}

function EditDialog({
    item,
    onClose,
    ...props
}: Props & { item: MasterItem | 'new' | null; onClose: () => void }) {
    const key = useOpenKey(item !== null);

    return (
        <Dialog
            open={item !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                {item !== null && (
                    <EditForm
                        key={key}
                        {...props}
                        item={item === 'new' ? null : item}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function EditForm({
    item,
    noun,
    routes,
    withContact,
    placeholder,
    onDone,
}: Props & { item: MasterItem | null; onDone: () => void }) {
    const form = useForm({
        name: item?.name ?? '',
        phone: item?.phone ?? '',
        address: item?.address ?? '',
    });

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((data) =>
                    withContact ? data : { name: data.name },
                );
                form.submit(item ? routes.update(item.id) : routes.store(), {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <DialogHeader>
                <DialogTitle>
                    {item ? `Ubah ${noun}` : `Tambah ${noun}`}
                </DialogTitle>
            </DialogHeader>
            <div className="grid gap-2">
                <Label htmlFor="master-name">Nama</Label>
                <Input
                    id="master-name"
                    autoFocus
                    placeholder={placeholder}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <InputError message={form.errors.name} />
            </div>
            {withContact && (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="master-phone">Telepon</Label>
                        <Input
                            id="master-phone"
                            inputMode="tel"
                            value={form.data.phone}
                            onChange={(e) =>
                                form.setData('phone', e.target.value)
                            }
                        />
                        <InputError message={form.errors.phone} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="master-address">Alamat</Label>
                        <Textarea
                            id="master-address"
                            value={form.data.address}
                            onChange={(e) =>
                                form.setData('address', e.target.value)
                            }
                        />
                        <InputError message={form.errors.address} />
                    </div>
                </>
            )}
            <DialogFooter>
                <Button type="submit" disabled={form.processing}>
                    Simpan
                </Button>
            </DialogFooter>
        </form>
    );
}
