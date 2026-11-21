import { Head, useForm } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import InputError from '@/components/input-error';
import { RupiahInput } from '@/components/pos/rupiah-input';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import pos from '@/routes/pos';

const QUICK_FLOATS = [100000, 200000, 300000, 500000];

export default function OpenShift() {
    const form = useForm({ opening_cash: 200000, note: '' });

    return (
        <>
            <Head title="Buka shift" />
            <div className="flex flex-1 items-start justify-center p-4 md:items-center">
                <Card className="w-full max-w-md">
                    <CardHeader>
                        <div className="mb-2 flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Wallet className="size-5" />
                        </div>
                        <CardTitle>Buka shift kasir</CardTitle>
                        <CardDescription>
                            Hitung uang modal di laci sebelum mulai berjualan.
                            Saat tutup shift, jumlah ini dipakai untuk
                            mencocokkan kas.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="space-y-5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.submit(pos.shift.store());
                            }}
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="opening_cash">
                                    Modal awal laci
                                </Label>
                                <RupiahInput
                                    id="opening_cash"
                                    autoFocus
                                    className="h-11 text-lg"
                                    value={form.data.opening_cash}
                                    onValueChange={(v) =>
                                        form.setData('opening_cash', v)
                                    }
                                />
                                <div className="flex flex-wrap gap-2">
                                    {QUICK_FLOATS.map((amount) => (
                                        <Button
                                            key={amount}
                                            type="button"
                                            size="sm"
                                            variant={
                                                form.data.opening_cash ===
                                                amount
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() =>
                                                form.setData(
                                                    'opening_cash',
                                                    amount,
                                                )
                                            }
                                        >
                                            {amount / 1000}rb
                                        </Button>
                                    ))}
                                </div>
                                <InputError
                                    message={
                                        form.errors.opening_cash ??
                                        (form.errors as Record<string, string>)
                                            .shift
                                    }
                                />
                            </div>

                            <Button
                                type="submit"
                                size="lg"
                                className="w-full"
                                disabled={form.processing}
                            >
                                Buka shift
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

OpenShift.layout = { breadcrumbs: [{ title: 'Kasir', href: pos.index() }] };
