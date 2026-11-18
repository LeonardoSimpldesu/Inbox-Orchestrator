import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import PasswordField from '@/Components/PasswordField';
import PrimaryButton from '@/Components/PrimaryButton';

interface ResetPasswordProps {
    linkValid: boolean;
    token: string;
    email: string;
}

const INVALID_LINK_MESSAGE = 'Este link de redefinição de senha é inválido ou expirou.';

function InvalidLink({ message }: { message: string }) {
    return (
        <div role="alert" className="mb-4 rounded-xl border border-rose-500/40 bg-rose-950/60 p-3 text-sm text-rose-300">
            {message}
        </div>
    );
}

export default function ResetPassword({ linkValid, token, email }: ResetPasswordProps) {
    // The token only works for the e-mail it was issued to, so the e-mail comes from the link and is never shown.
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    if (!linkValid) {
        return (
            <GuestLayout title="Link inválido">
                <InvalidLink message={INVALID_LINK_MESSAGE} />
                <Link
                    href="/forgot-password"
                    className="w-full inline-flex items-center justify-center bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-lg shadow-indigo-600/20 transition"
                >
                    Pedir um novo link
                </Link>
            </GuestLayout>
        );
    }

    return (
        <GuestLayout title="Criar nova senha" description="Use pelo menos 12 caracteres.">
            {form.errors.email && (
                <>
                    <InvalidLink message={form.errors.email} />
                    <p className="mb-4 text-sm">
                        <Link href="/forgot-password" className="text-indigo-400 hover:text-indigo-300">
                            Pedir um novo link
                        </Link>
                    </p>
                </>
            )}

            <form onSubmit={submit} className="space-y-4" noValidate>
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
