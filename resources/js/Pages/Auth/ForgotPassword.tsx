import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import TextField from '@/Components/TextField';
import PrimaryButton from '@/Components/PrimaryButton';
import StatusMessage from '@/Components/StatusMessage';

interface ForgotPasswordProps {
    status: string | null;
}

export default function ForgotPassword({ status }: ForgotPasswordProps) {
    const form = useForm({ email: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/forgot-password');
    };

    return (
        <GuestLayout
            title="Esqueci minha senha"
            description="Informe seu e-mail e enviaremos um link para você criar uma nova senha."
        >
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

                <PrimaryButton type="submit" disabled={form.processing}>
                    Enviar link
                </PrimaryButton>

                <p className="text-center text-sm">
                    <Link href="/login" className="text-indigo-400 hover:text-indigo-300">
                        Voltar para o login
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
