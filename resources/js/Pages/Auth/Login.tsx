import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import TextField from '@/Components/TextField';
import PrimaryButton from '@/Components/PrimaryButton';
import StatusMessage from '@/Components/StatusMessage';

interface LoginProps {
    status: string | null;
    canResetPassword: boolean;
}

export default function Login({ status, canResetPassword }: LoginProps) {
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <GuestLayout title="Entrar">
            <StatusMessage message={status} />

            <form onSubmit={submit} className="space-y-4" noValidate>
                <TextField
                    id="email"
                    label="E-mail"
                    type="email"
                    autoComplete="username"
                    autoFocus
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    error={form.errors.email}
                />

                <TextField
                    id="password"
                    label="Senha"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                />

                <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-sm text-slate-300">
                        <input
                            type="checkbox"
                            name="remember"
                            className="rounded border-slate-600 bg-slate-950 text-indigo-500 focus:ring-indigo-500/60"
                            checked={form.data.remember}
                            onChange={(e) => form.setData('remember', e.target.checked)}
                        />
                        Lembrar-me
                    </label>

                    {canResetPassword && (
                        <Link href="/forgot-password" className="text-sm text-indigo-400 hover:text-indigo-300">
                            Esqueci minha senha
                        </Link>
                    )}
                </div>

                <PrimaryButton type="submit" disabled={form.processing}>
                    Entrar
                </PrimaryButton>
            </form>
        </GuestLayout>
    );
}
