import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { formatDate, formatDateTime } from '@/lib/format';
import users from '@/routes/users';
import type { Option, Paginated } from '@/types';

type AccountEventRow = {
    id: number;
    at: string;
    type: string;
    label: string;
    user: { id: number; name: string | null };
    actor: { id: number; name: string } | null;
    by_self: boolean;
    changes: string[];
};

type Props = {
    events: Paginated<AccountEventRow>;
    filters: { user: number | null; type: string | null };
    users: Option[];
    types: { value: string; label: string }[];
    since: string | null;
};

const ALL = 'all';

export default function AccountEvents({
    events,
    filters,
    users: accounts,
    types,
    since,
}: Props) {
    const filter = (next: Partial<Props['filters']>) =>
        router.get(
            users.events.index.url(),
            {
                user:
                    (next.user !== undefined ? next.user : filters.user) ??
                    undefined,
                type:
                    (next.type !== undefined ? next.type : filters.type) ??
                    undefined,
            },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Riwayat akun" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Riwayat akun"
                    description={
                        since
                            ? `Siapa mengubah akun siapa, terbaru di atas. Dicatat sejak ${formatDate(since)}. Kata sandi, PIN, dan kunci 2FA/passkey tidak pernah disimpan di sini.`
                            : 'Siapa mengubah akun siapa, terbaru di atas. Kata sandi, PIN, dan kunci 2FA/passkey tidak pernah disimpan di sini.'
                    }
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={users.index()}>
                                <ArrowLeft /> Pengguna
                            </Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap gap-2">
                    <Select
                        value={
                            filters.user === null ? ALL : String(filters.user)
                        }
                        onValueChange={(v) =>
                            filter({ user: v === ALL ? null : Number(v) })
                        }
                    >
                        <SelectTrigger className="w-52" aria-label="Akun">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Semua akun</SelectItem>
                            {accounts.map((u) => (
                                <SelectItem key={u.id} value={String(u.id)}>
                                    {u.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.type ?? ALL}
                        onValueChange={(v) =>
                            filter({ type: v === ALL ? null : v })
                        }
                    >
                        <SelectTrigger className="w-64" aria-label="Kejadian">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Semua kejadian</SelectItem>
                            {types.map((t) => (
                                <SelectItem key={t.value} value={t.value}>
                                    {t.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Waktu</TableHead>
                                <TableHead>Akun</TableHead>
                                <TableHead>Kejadian</TableHead>
                                <TableHead>Oleh</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {events.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Belum ada perubahan akun yang tercatat.
                                    </TableCell>
                                </TableRow>
                            )}
                            {events.data.map((e) => (
                                <TableRow key={e.id}>
                                    <TableCell className="whitespace-nowrap text-muted-foreground">
                                        {formatDateTime(e.at)}
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {e.user.name ?? '–'}
                                    </TableCell>
                                    <TableCell>
                                        <div>{e.label}</div>
                                        {e.changes.map((line) => (
                                            <div
                                                key={line}
                                                className="text-xs text-muted-foreground"
                                            >
                                                {line}
                                            </div>
                                        ))}
                                    </TableCell>
                                    <TableCell>
                                        {e.actor === null ? (
                                            <Badge variant="outline">
                                                Sistem
                                            </Badge>
                                        ) : e.by_self ? (
                                            <span className="text-muted-foreground">
                                                Sendiri
                                            </span>
                                        ) : (
                                            e.actor.name
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination page={events} />
            </div>
        </>
    );
}

AccountEvents.layout = {
    breadcrumbs: [
        { title: 'Pengguna', href: users.index() },
        { title: 'Riwayat akun', href: users.events.index() },
    ],
};
