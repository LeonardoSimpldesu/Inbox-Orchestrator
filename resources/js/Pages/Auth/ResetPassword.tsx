import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import PasswordField from '@/Components/PasswordField';
import PrimaryButton from '@/Components/PrimaryButton';

interface ResetPasswordProps {
    token: string;
    email: string;
}

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    // The token only works for the e-mail it was issued to, so the e-mail comes from the link and isn't editable.
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <GuestLayout title="Criar nova senha" description="Use pelo menos 12 caracteres.">
            {form.errors.email && (
                <div role="alert" className="mb-4 rounded-xl border border-rose-500/40 bg-rose-950/60 p-3 text-sm text-rose-300">
                    {form.errors.email}{' '}
                    <Link href="/forgot-password" className="underline hover:text-rose-200">
                        Pedir um novo link
                    </Link>
                </div>
            )}

            <form onSubmit={submit} className="space-y-4" noValidate>
                <p className="text-sm text-slate-400">
                    Conta: <span className="font-medium text-slate-200">{email}</span>
                </p>

                <input type="hidden" name="email" value={email} autoComplete="username" readOnly />

                <PasswordField
                    id="password"
                    label="Nova senha"
                    autoComplete="new-password"
                    autoFocus
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                />

                <PasswordField
                    id="password_confirmation"
                    label="Confirme a nova senha"
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
