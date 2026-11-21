import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ownerPin from '@/routes/owner-pin';

export default function OwnerPin({ hasPin }: { hasPin: boolean }) {
    const form = useForm({
        current_password: '',
        pin: '',
        pin_confirmation: '',
    });

    return (
        <>
            <Head title="PIN owner" />
            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="PIN owner"
                    description={
                        hasPin
                            ? 'PIN sudah diatur. Isi formulir ini untuk menggantinya.'
                            : 'Kasir memerlukan PIN ini untuk memproses retur. PIN belum diatur.'
                    }
                />
                <form
                    className="max-w-sm space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.submit(ownerPin.update(), {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="current_password">
                            Kata sandi akun
                        </Label>
                        <PasswordInput
                            id="current_password"
                            autoComplete="current-password"
                            value={form.data.current_password}
                            onChange={(e) =>
                                form.setData('current_password', e.target.value)
                            }
                        />
                        <InputError message={form.errors.current_password} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="pin">PIN baru (4–6 angka)</Label>
                        <Input
                            id="pin"
                            type="password"
                            inputMode="numeric"
                            autoComplete="off"
                            maxLength={6}
                            value={form.data.pin}
                            onChange={(e) =>
                                form.setData(
                                    'pin',
                                    e.target.value.replace(/\D/g, ''),
                                )
                            }
                        />
                        <InputError message={form.errors.pin} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="pin_confirmation">Ulangi PIN</Label>
                        <Input
                            id="pin_confirmation"
                            type="password"
                            inputMode="numeric"
                            autoComplete="off"
                            maxLength={6}
                            value={form.data.pin_confirmation}
                            onChange={(e) =>
                                form.setData(
                                    'pin_confirmation',
                                    e.target.value.replace(/\D/g, ''),
                                )
                            }
                        />
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        Simpan PIN
                    </Button>
                </form>
            </div>
        </>
    );
}

OwnerPin.layout = {
    breadcrumbs: [{ title: 'PIN owner', href: ownerPin.edit() }],
};
