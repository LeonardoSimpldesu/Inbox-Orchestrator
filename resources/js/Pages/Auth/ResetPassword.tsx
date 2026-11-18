import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import TextField from '@/Components/TextField';
import PrimaryButton from '@/Components/PrimaryButton';

interface ResetPasswordProps {
    token: string;
    email: string;
}

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <GuestLayout title="Criar nova senha" description="Use pelo menos 12 caracteres.">
            <form onSubmit={submit} className="space-y-4" noValidate>
                <TextField
                    id="email"
                    label="E-mail"
                    type="email"
                    autoComplete="username"
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    error={form.errors.email}
                />

                <TextField
                    id="password"
                    label="Nova senha"
                    type="password"
                    autoComplete="new-password"
                    autoFocus
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                />

                <TextField
                    id="password_confirmation"
                    label="Confirme a nova senha"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={form.data.password_confirmation}
                    onChange={(e) => form.setData('password_confirmation', e.target.value)}
                    error={form.errors.password_confirmation}
                />

                <PrimaryButton type="submit" disabled={form.processing}>
                    Redefinir senha
                </PrimaryButton>
            </form>
        </GuestLayout>
    );
}
