import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    DoorClosed,
    History,
    KeyRound,
    MoreHorizontal,
    Pencil,
    Plus,
    ShieldOff,
    Smartphone,
    UserCheck,
    UserX,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import PasswordInput from '@/components/password-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useOpenKey } from '@/hooks/use-open-key';
import { toastFirstError } from '@/lib/errors';
import { formatDate } from '@/lib/format';
import reports from '@/routes/reports';
import users from '@/routes/users';
import type { RoleName } from '@/types';

type Account = {
    id: number;
    name: string;
    email: string;
    role: RoleName | null;
    active: boolean;
    deactivated_at: string | null;
    has_pin: boolean;
    two_factor: boolean;
    has_two_factor_state: boolean;
    passkeys: number;
    must_change_password: boolean;
    has_open_shift: boolean;
    open_shift_id: number | null;
    is_self: boolean;
    is_protected: boolean;
};

type Props = { users: Account[]; passwordRules: string };

const ROLES: { value: RoleName; label: string; hint: string }[] = [
    { value: 'owner', label: 'Owner', hint: 'Semua menu, laporan, pengguna' },
    {
        value: 'gudang',
        label: 'Staf Gudang',
        hint: 'Produk, supplier, PO, penerimaan, opname',
    },
    { value: 'kasir', label: 'Kasir', hint: 'Layar kasir dan shift sendiri' },
];

const roleLabel = (role: RoleName | null) =>
    ROLES.find((r) => r.value === role)?.label ?? '–';

type Confirm = { kind: 'deactivate' | 'reactivate' | 'pin'; user: Account };

export default function Users({ users: accounts, passwordRules }: Props) {
    const [status, setStatus] = useState<'active' | 'inactive' | 'all'>(
        'active',
    );
    const [role, setRole] = useState<RoleName | 'all'>('all');
    const [editing, setEditing] = useState<Account | 'new' | null>(null);
    const [resetting, setResetting] = useState<Account | null>(null);
    const [resettingFactors, setResettingFactors] = useState<Account | null>(
        null,
    );
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [processing, setProcessing] = useState(false);

    const shown = accounts.filter(
        (u) =>
            (status === 'all' || u.active === (status === 'active')) &&
            (role === 'all' || u.role === role),
    );
    const inactiveCount = accounts.filter((u) => !u.active).length;

    const runConfirm = () => {
        if (!confirm) {
            return;
        }

        const route = {
            deactivate: users.deactivate,
            reactivate: users.reactivate,
            pin: users.pin,
        }[confirm.kind](confirm.user.id);

        router.visit(route, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onError: toastFirstError,
            onFinish: () => {
                setProcessing(false);
                setConfirm(null);
            },
        });
    };

    return (
        <>
            <Head title="Pengguna" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Pengguna"
                    description="Akun yang bisa masuk ke aplikasi. Akun tidak dihapus, hanya dinonaktifkan, supaya riwayat transaksi tetap utuh."
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={users.events.index()}>
                                    <History /> Riwayat akun
                                </Link>
                            </Button>
                            <Button onClick={() => setEditing('new')}>
                                <Plus /> Tambah pengguna
                            </Button>
                        </>
                    }
                />

                <div className="flex flex-wrap gap-2">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={status}
                        onValueChange={(v) =>
                            v && setStatus(v as typeof status)
                        }
                    >
                        <ToggleGroupItem value="active" className="px-3">
                            Aktif
                        </ToggleGroupItem>
                        <ToggleGroupItem value="inactive" className="px-3">
                            Nonaktif{inactiveCount > 0 && ` (${inactiveCount})`}
                        </ToggleGroupItem>
                        <ToggleGroupItem value="all" className="px-3">
                            Semua
                        </ToggleGroupItem>
                    </ToggleGroup>
                    <Select
                        value={role}
                        onValueChange={(v) => setRole(v as typeof role)}
                    >
                        <SelectTrigger className="w-44" aria-label="Role">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua role</SelectItem>
                            {ROLES.map((r) => (
                                <SelectItem key={r.value} value={r.value}>
                                    {r.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-0" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shown.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Tidak ada pengguna dengan filter ini.
                                    </TableCell>
                                </TableRow>
                            )}
                            {shown.map((u) => (
                                <TableRow key={u.id}>
                                    <TableCell>
                                        <div className="font-medium">
                                            {u.name}
                                            {u.is_self && (
                                                <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                                                    (Anda)
                                                </span>
                                            )}
                                        </div>
                                        <div className="text-sm break-all text-muted-foreground">
                                            {u.email}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <div>{roleLabel(u.role)}</div>
                                        {u.role === 'owner' && (
                                            <div className="text-xs text-muted-foreground">
                                                {u.has_pin
                                                    ? 'PIN sudah diatur'
                                                    : 'PIN belum diatur'}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap gap-1">
                                            {u.active ? (
                                                <Badge variant="secondary">
                                                    Aktif
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline">
                                                    Nonaktif sejak{' '}
                                                    {formatDate(
                                                        u.deactivated_at,
                                                    )}
                                                </Badge>
                                            )}
                                            {u.open_shift_id !== null &&
                                                (u.is_self ? (
                                                    <Badge variant="outline">
                                                        Shift terbuka
                                                    </Badge>
                                                ) : (
                                                    <Badge
                                                        variant="outline"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={reports.shifts.close.edit(
                                                                u.open_shift_id,
                                                            )}
                                                            title="Tutup shift ini"
                                                        >
                                                            Shift terbuka
                                                        </Link>
                                                    </Badge>
                                                ))}
                                            {u.must_change_password && (
                                                <Badge variant="outline">
                                                    Belum ganti kata sandi
                                                </Badge>
                                            )}
                                            {u.is_protected && (
                                                <Badge variant="outline">
                                                    Akun demo
                                                </Badge>
                                            )}
                                            {u.two_factor && (
                                                <Badge variant="outline">
                                                    2FA
                                                </Badge>
                                            )}
                                            {u.passkeys > 0 && (
                                                <Badge variant="outline">
                                                    {u.passkeys} passkey
                                                </Badge>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <RowActions
                                            user={u}
                                            onEdit={() => setEditing(u)}
                                            onResetPassword={() =>
                                                setResetting(u)
                                            }
                                            onResetFactors={() =>
                                                setResettingFactors(u)
                                            }
                                            onConfirm={(kind) =>
                                                setConfirm({ kind, user: u })
                                            }
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <AccountDialog
                account={editing}
                passwordRules={passwordRules}
                onClose={() => setEditing(null)}
            />
            <PasswordDialog
                account={resetting}
                passwordRules={passwordRules}
                onClose={() => setResetting(null)}
            />
            <SignInFactorsDialog
                account={resettingFactors}
                onClose={() => setResettingFactors(null)}
            />
            <ConfirmDialog
                open={confirm !== null}
                onOpenChange={(open) => !open && setConfirm(null)}
                processing={processing}
                onConfirm={runConfirm}
                {...confirmText(confirm)}
            />
        </>
    );
}

function confirmText(confirm: Confirm | null) {
    const name = confirm?.user.name ?? '';

    switch (confirm?.kind) {
        case 'reactivate':
            return {
                title: `Aktifkan lagi ${name}?`,
                description:
                    'Akun ini bisa masuk lagi dengan kata sandi yang sama.',
                confirmLabel: 'Aktifkan',
            };
        case 'pin':
            return {
                title: `Hapus PIN ${name}?`,
                description:
                    'PIN lama tidak berlaku lagi untuk menyetujui retur. Owner ini perlu mengatur PIN baru di Pengaturan → PIN owner.',
                confirmLabel: 'Hapus PIN',
                destructive: true,
            };
        default:
            return {
                title: `Nonaktifkan ${name}?`,
                description:
                    'Akun ini tidak bisa masuk lagi dan sesinya yang sedang berjalan langsung berakhir. Riwayat transaksi, shift, dan stoknya tetap ada, dan akun bisa diaktifkan lagi kapan saja.',
                confirmLabel: 'Nonaktifkan',
                destructive: true,
            };
    }
}

function RowActions({
    user,
    onEdit,
    onResetPassword,
    onResetFactors,
    onConfirm,
}: {
    user: Account;
    onEdit: () => void;
    onResetPassword: () => void;
    onResetFactors: () => void;
    onConfirm: (kind: Confirm['kind']) => void;
}) {
    const locked = user.is_protected;
    const hasFactors = user.has_two_factor_state || user.passkeys > 0;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    size="icon"
                    variant="ghost"
                    aria-label={`Aksi untuk ${user.name}`}
                >
                    <MoreHorizontal />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={onEdit}>
                    <Pencil /> Ubah
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link
                        href={users.events.index({
                            query: { user: user.id },
                        })}
                    >
                        <History /> Riwayat
                    </Link>
                </DropdownMenuItem>
                {!user.is_self && (
                    <DropdownMenuItem
                        disabled={locked}
                        onSelect={onResetPassword}
                    >
                        <KeyRound /> Reset kata sandi
                    </DropdownMenuItem>
                )}
                {!user.is_self && hasFactors && (
                    <DropdownMenuItem
                        disabled={locked}
                        onSelect={onResetFactors}
                    >
                        <Smartphone /> Reset 2FA & passkey
                    </DropdownMenuItem>
                )}
                {!user.is_self && user.role === 'owner' && user.has_pin && (
                    <DropdownMenuItem
                        disabled={locked}
                        onSelect={() => onConfirm('pin')}
                    >
                        <ShieldOff /> Hapus PIN
                    </DropdownMenuItem>
                )}
                {!user.is_self && (
                    <>
                        <DropdownMenuSeparator />
                        {user.open_shift_id !== null && (
                            <DropdownMenuItem asChild>
                                <Link
                                    href={reports.shifts.close.edit(
                                        user.open_shift_id,
                                    )}
                                >
                                    <DoorClosed /> Tutup shift
                                </Link>
                            </DropdownMenuItem>
                        )}
                        {user.active ? (
                            <DropdownMenuItem
                                variant="destructive"
                                disabled={locked || user.has_open_shift}
                                onSelect={() => onConfirm('deactivate')}
                            >
                                <UserX />
                                {user.has_open_shift
                                    ? 'Nonaktifkan (tutup shift dulu)'
                                    : 'Nonaktifkan'}
                            </DropdownMenuItem>
                        ) : (
                            <DropdownMenuItem
                                onSelect={() => onConfirm('reactivate')}
                            >
                                <UserCheck /> Aktifkan lagi
                            </DropdownMenuItem>
                        )}
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function AccountDialog({
    account,
    passwordRules,
    onClose,
}: {
    account: Account | 'new' | null;
    passwordRules: string;
    onClose: () => void;
}) {
    const key = useOpenKey(account !== null);

    return (
        <Dialog
            open={account !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                {account !== null && (
                    <AccountForm
                        key={key}
                        account={account === 'new' ? null : account}
                        passwordRules={passwordRules}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function AccountForm({
    account,
    passwordRules,
    onDone,
}: {
    account: Account | null;
    passwordRules: string;
    onDone: () => void;
}) {
    const form = useForm({
        name: account?.name ?? '',
        email: account?.email ?? '',
        role: (account?.role ?? 'kasir') as RoleName,
        password: '',
        password_confirmation: '',
    });
    // An owner cannot change their own role; a demo account keeps role and email.
    const roleLocked = !!account && (account.is_self || account.is_protected);
    const roleHint = account?.is_self
        ? 'Role akun sendiri tidak bisa diubah.'
        : account?.has_open_shift
          ? 'Shift masih terbuka, jadi role tanpa akses kasir belum bisa dipilih.'
          : null;

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform(
                    ({ password, password_confirmation, ...rest }) =>
                        account
                            ? rest
                            : { ...rest, password, password_confirmation },
                );
                form.submit(
                    account ? users.update(account.id) : users.store(),
                    { preserveScroll: true, onSuccess: onDone },
                );
            }}
        >
            <DialogHeader>
                <DialogTitle>
                    {account ? `Ubah ${account.name}` : 'Tambah pengguna'}
                </DialogTitle>
                {!account && (
                    <DialogDescription>
                        Berikan kata sandi awal ini ke penggunanya. Ia wajib
                        menggantinya saat pertama kali masuk.
                    </DialogDescription>
                )}
            </DialogHeader>
            <InputError
                message={(form.errors as Record<string, string>).user}
            />
            <div className="grid gap-2">
                <Label htmlFor="user-name">Nama</Label>
                <Input
                    id="user-name"
                    autoFocus
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <InputError message={form.errors.name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="user-email">Email</Label>
                <Input
                    id="user-email"
                    type="email"
                    autoComplete="off"
                    disabled={account?.is_protected}
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                />
                <InputError message={form.errors.email} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="user-role">Role</Label>
                <Select
                    value={form.data.role}
                    disabled={roleLocked}
                    onValueChange={(v) => form.setData('role', v as RoleName)}
                >
                    <SelectTrigger id="user-role" className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {ROLES.map((r) => (
                            <SelectItem
                                key={r.value}
                                value={r.value}
                                disabled={
                                    r.value === 'gudang' &&
                                    !!account?.has_open_shift
                                }
                            >
                                {r.label}
                                <span className="text-xs text-muted-foreground">
                                    {r.hint}
                                </span>
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {roleHint && (
                    <p className="text-xs text-muted-foreground">{roleHint}</p>
                )}
                <InputError message={form.errors.role} />
            </div>
            {!account && (
                <PasswordFields
                    passwordRules={passwordRules}
                    label="Kata sandi awal"
                    value={form.data.password}
                    confirmation={form.data.password_confirmation}
                    error={form.errors.password}
                    onChange={(v) => form.setData('password', v)}
                    onConfirmationChange={(v) =>
                        form.setData('password_confirmation', v)
                    }
                />
            )}
            <DialogFooter>
                <Button type="submit" disabled={form.processing}>
                    {account ? 'Simpan' : 'Buat akun'}
                </Button>
            </DialogFooter>
        </form>
    );
}

function PasswordDialog({
    account,
    passwordRules,
    onClose,
}: {
    account: Account | null;
    passwordRules: string;
    onClose: () => void;
}) {
    const key = useOpenKey(account !== null);

    return (
        <Dialog
            open={account !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                {account !== null && (
                    <PasswordForm
                        key={key}
                        account={account}
                        passwordRules={passwordRules}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function PasswordForm({
    account,
    passwordRules,
    onDone,
}: {
    account: Account;
    passwordRules: string;
    onDone: () => void;
}) {
    const form = useForm({ password: '', password_confirmation: '' });

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.submit(users.password(account.id), {
                    preserveScroll: true,
                    onSuccess: onDone,
                    onError: (errors) => errors.user && toastFirstError(errors),
                });
            }}
        >
            <DialogHeader>
                <DialogTitle>Reset kata sandi {account.name}</DialogTitle>
                <DialogDescription>
                    Sesi {account.name} yang sedang berjalan akan berakhir. Ia
                    masuk dengan kata sandi sementara ini lalu wajib
                    menggantinya.
                </DialogDescription>
            </DialogHeader>
            <PasswordFields
                passwordRules={passwordRules}
                label="Kata sandi sementara"
                value={form.data.password}
                confirmation={form.data.password_confirmation}
                error={form.errors.password}
                onChange={(v) => form.setData('password', v)}
                onConfirmationChange={(v) =>
                    form.setData('password_confirmation', v)
                }
            />
            <DialogFooter>
                <Button type="submit" disabled={form.processing}>
                    Reset kata sandi
                </Button>
            </DialogFooter>
        </form>
    );
}

function SignInFactorsDialog({
    account,
    onClose,
}: {
    account: Account | null;
    onClose: () => void;
}) {
    const key = useOpenKey(account !== null);

    return (
        <Dialog
            open={account !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                {account !== null && (
                    <SignInFactorsForm
                        key={key}
                        account={account}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function SignInFactorsForm({
    account,
    onDone,
}: {
    account: Account;
    onDone: () => void;
}) {
    const form = useForm({ password: '' });
    const removed = [
        account.two_factor
            ? '2FA (aplikasi authenticator dan kode pemulihan)'
            : account.has_two_factor_state
              ? 'Pengaturan 2FA yang belum selesai'
              : null,
        account.passkeys > 0 ? `${account.passkeys} passkey` : null,
    ].filter((item) => item !== null);

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.submit(users.signInFactors(account.id), {
                    preserveScroll: true,
                    onSuccess: onDone,
                    onError: (errors) => errors.user && toastFirstError(errors),
                });
            }}
        >
            <DialogHeader>
                <DialogTitle>Reset 2FA & passkey {account.name}?</DialogTitle>
                <DialogDescription>
                    {account.name} bisa masuk dengan kata sandinya saja, lalu
                    memasang 2FA atau passkey baru di Pengaturan → Keamanan.
                </DialogDescription>
            </DialogHeader>
            <div className="text-sm">
                <p className="font-medium">Yang dihapus:</p>
                <ul className="mt-1 list-disc pl-5 text-muted-foreground">
                    {removed.map((item) => (
                        <li key={item}>{item}</li>
                    ))}
                </ul>
            </div>
            <p className="text-sm text-muted-foreground">
                Sesi {account.name} yang sedang berjalan tidak berakhir. Bila
                perangkatnya dicuri, reset juga kata sandinya agar semua sesinya
                berakhir.
            </p>
            <div className="grid gap-2">
                <Label htmlFor="owner-password">Kata sandi Anda</Label>
                <PasswordInput
                    id="owner-password"
                    autoComplete="current-password"
                    autoFocus
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                />
                <InputError message={form.errors.password} />
            </div>
            <DialogFooter>
                <Button
                    type="submit"
                    variant="destructive"
                    disabled={form.processing || form.data.password === ''}
                >
                    Reset 2FA & passkey
                </Button>
            </DialogFooter>
        </form>
    );
}

function PasswordFields(props: {
    passwordRules: string;
    label: string;
    value: string;
    confirmation: string;
    error?: string;
    onChange: (value: string) => void;
    onConfirmationChange: (value: string) => void;
}) {
    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor="user-password">{props.label}</Label>
                <PasswordInput
                    id="user-password"
                    autoComplete="new-password"
                    passwordrules={props.passwordRules}
                    value={props.value}
                    onChange={(e) => props.onChange(e.target.value)}
                />
                <InputError message={props.error} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="user-password-confirmation">
                    Ulangi kata sandi
                </Label>
                <PasswordInput
                    id="user-password-confirmation"
                    autoComplete="new-password"
                    passwordrules={props.passwordRules}
                    value={props.confirmation}
                    onChange={(e) => props.onConfirmationChange(e.target.value)}
                />
            </div>
        </>
    );
}

Users.layout = {
    breadcrumbs: [{ title: 'Pengguna', href: users.index() }],
};
