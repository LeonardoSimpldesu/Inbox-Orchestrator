import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import TextField from '@/Components/TextField';
import PrimaryButton from '@/Components/PrimaryButton';

export default function TwoFactorChallenge() {
    const [useRecoveryCode, setUseRecoveryCode] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((data) => (useRecoveryCode ? { recovery_code: data.recovery_code } : { code: data.code }));
        form.post('/two-factor-challenge', { onFinish: () => form.reset() });
    };

    const toggle = () => {
        setUseRecoveryCode((current) => !current);
        form.clearErrors();
        form.reset();
    };

    return (
        <GuestLayout
            title="Verificação em duas etapas"
            description={
                useRecoveryCode
                    ? 'Informe um dos seus códigos de recuperação.'
                    : 'Informe o código de 6 dígitos do seu app autenticador.'
            }
        >
            <form onSubmit={submit} className="space-y-4" noValidate>
                {useRecoveryCode ? (
                    <TextField
                        key="recovery_code"
                        id="recovery_code"
                        label="Código de recuperação"
                        type="text"
                        autoComplete="one-time-code"
                        autoFocus
                        required
                        value={form.data.recovery_code}
                        onChange={(e) => form.setData('recovery_code', e.target.value)}
                        error={form.errors.recovery_code}
                    />
                ) : (
                    <TextField
                        key="code"
                        id="code"
                        label="Código"
                        type="text"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        maxLength={6}
                        autoFocus
                        required
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, ''))}
                        error={form.errors.code}
                    />
                )}

                <PrimaryButton type="submit" disabled={form.processing}>
                    Verificar
                </PrimaryButton>

                <button type="button" onClick={toggle} className="w-full text-center text-sm text-indigo-400 hover:text-indigo-300 cursor-pointer">
                    {useRecoveryCode ? 'Usar código do app autenticador' : 'Usar um código de recuperação'}
                </button>
            </form>
        </GuestLayout>
    );
}
