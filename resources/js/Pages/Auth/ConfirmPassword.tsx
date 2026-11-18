import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import TextField from '@/Components/TextField';
import PrimaryButton from '@/Components/PrimaryButton';

export default function ConfirmPassword() {
    const form = useForm({ password: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/user/confirm-password', { onFinish: () => form.reset('password') });
    };

    return (
        <GuestLayout title="Confirme sua senha" description="Esta é uma área protegida. Confirme sua senha para continuar.">
            <form onSubmit={submit} className="space-y-4" noValidate>
                <TextField
                    id="password"
                    label="Senha"
                    type="password"
                    autoComplete="current-password"
                    autoFocus
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                />

                <PrimaryButton type="submit" disabled={form.processing}>
                    Confirmar
                </PrimaryButton>
            </form>
        </GuestLayout>
    );
}
