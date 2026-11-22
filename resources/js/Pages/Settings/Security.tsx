import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { KeyRound, ShieldCheck, ShieldOff } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import TextField from '@/Components/TextField';
import PrimaryButton from '@/Components/PrimaryButton';
import StatusMessage from '@/Components/StatusMessage';

interface SecurityProps {
    twoFactorEnabled: boolean;
    twoFactorPending: boolean;
    status: string | null;
}

interface SetupData {
    svg: string;
    secretKey: string;
}

const STATUS_MESSAGES: Record<string, string> = {
    'two-factor-authentication-enabled': 'Escaneie o QR code e confirme com um código para concluir a ativação.',
    'two-factor-authentication-confirmed': 'Verificação em duas etapas ativada.',
    'two-factor-authentication-disabled': 'Verificação em duas etapas desativada.',
    'recovery-codes-generated': 'Novos códigos de recuperação gerados. Os anteriores deixaram de valer.',
};

const secondaryButton =
    'inline-flex items-center gap-2 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold px-3 py-2.5 rounded-xl border border-slate-700 transition cursor-pointer disabled:opacity-60';

export default function Security({ twoFactorEnabled, twoFactorPending, status }: SecurityProps) {
    const [setup, setSetup] = useState<SetupData | null>(null);
    const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [loadError, setLoadError] = useState<{ message: string; retry: () => void } | null>(null);
    const confirmForm = useForm({ code: '' });

    // Fortify answers 423 once the 3-hour password confirmation expires: ask for the password again.
    const handleLoadFailure = useCallback((error: unknown, retry: () => void) => {
        if (window.axios.isAxiosError(error) && error.response?.status === 423) {
            router.visit('/user/confirm-password');
            return;
        }
        setLoadError({ message: 'Não foi possível carregar os dados da verificação em duas etapas.', retry });
    }, []);

    const loadSetup = useCallback(async () => {
        setLoadError(null);
        try {
            const [qr, key] = await Promise.all([
                window.axios.get<{ svg: string }>('/user/two-factor-qr-code'),
                window.axios.get<{ secretKey: string }>('/user/two-factor-secret-key'),
            ]);
            setSetup({ svg: qr.data.svg, secretKey: key.data.secretKey });
        } catch (error) {
            handleLoadFailure(error, () => void loadSetup());
        }
    }, [handleLoadFailure]);

    const loadRecoveryCodes = useCallback(async () => {
        setLoadError(null);
        try {
            const response = await window.axios.get<string[]>('/user/two-factor-recovery-codes');
            setRecoveryCodes(response.data);
        } catch (error) {
            handleLoadFailure(error, () => void loadRecoveryCodes());
        }
    }, [handleLoadFailure]);

    useEffect(() => {
        if (twoFactorPending) {
            void loadSetup();
        } else {
            setSetup(null);
        }
    }, [twoFactorPending, loadSetup]);

    const enable = () => {
        setBusy(true);
        router.post('/user/two-factor-authentication', {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    const confirm = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        confirmForm.post('/user/confirmed-two-factor-authentication', {
            errorBag: 'confirmTwoFactorAuthentication',
            preserveScroll: true,
            onSuccess: () => {
                confirmForm.reset();
                void loadRecoveryCodes();
            },
        });
    };

    const regenerate = () => {
        setBusy(true);
        router.post('/user/two-factor-recovery-codes', {}, {
            preserveScroll: true,
            onSuccess: () => void loadRecoveryCodes(),
            onFinish: () => setBusy(false),
        });
    };

    const disable = () => {
        if (!window.confirm('Desativar a verificação em duas etapas?')) {
            return;
        }

        setBusy(true);
        router.delete('/user/two-factor-authentication', {
            preserveScroll: true,
            onSuccess: () => setRecoveryCodes([]),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <AppLayout title="Segurança">
            <div className="max-w-2xl">
                <h1 className="text-2xl font-bold tracking-tight text-white">Segurança</h1>
                <p className="text-sm text-slate-400 mt-1">Proteja sua conta com a verificação em duas etapas.</p>

                <div className="mt-6">
                    <StatusMessage message={status ? STATUS_MESSAGES[status] : null} />
                    {loadError && (
                        <div role="alert" className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-rose-500/40 bg-rose-950/60 p-3 text-sm text-rose-300">
                            <span>{loadError.message}</span>
                            <button type="button" onClick={loadError.retry} className="text-xs font-semibold underline underline-offset-2 hover:text-rose-200 cursor-pointer">
                                Tentar de novo
                            </button>
                        </div>
                    )}
                </div>

                <section className="bg-slate-900/50 border border-slate-800 rounded-2xl p-6 mt-2">
                    <div className="flex items-start gap-3">
                        <div
                            className={`w-9 h-9 rounded-lg flex items-center justify-center ${
                                twoFactorEnabled ? 'bg-emerald-500/10 text-emerald-400' : 'bg-slate-800 text-slate-400'
                            }`}
                        >
                            {twoFactorEnabled ? <ShieldCheck className="w-5 h-5" /> : <ShieldOff className="w-5 h-5" />}
                        </div>
                        <div>
                            <h2 className="text-base font-semibold text-white">Verificação em duas etapas</h2>
                            <p className="text-sm text-slate-400 mt-0.5">
                                {twoFactorEnabled
                                    ? 'Ativada. Ao entrar, pediremos um código do seu app autenticador.'
                                    : twoFactorPending
                                      ? 'Quase lá: confirme com um código para concluir a ativação.'
                                      : 'Desativada. Use um app como Google Authenticator, 1Password ou Authy.'}
                            </p>
                        </div>
                    </div>

                    {!twoFactorEnabled && !twoFactorPending && (
                        <div className="mt-5 max-w-xs">
                            <PrimaryButton type="button" onClick={enable} disabled={busy}>
                                Ativar
                            </PrimaryButton>
                        </div>
                    )}

                    {twoFactorPending && setup && (
                        <div className="mt-5 space-y-4">
                            <div
                                className="inline-block rounded-xl bg-white p-3"
                                aria-label="QR code para o app autenticador"
                                // SVG generated server-side by Fortify (bacon-qr-code), not user input.
                                dangerouslySetInnerHTML={{ __html: setup.svg }}
                            />
                            <p className="text-sm text-slate-400">
                                Ou digite a chave manualmente:{' '}
                                <code className="font-mono text-slate-200 break-all">{setup.secretKey}</code>
                            </p>
                            <form onSubmit={confirm} className="flex items-start gap-3 max-w-sm" noValidate>
                                <TextField
                                    id="code"
                                    label="Código do app"
                                    className="flex-1"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={6}
                                    required
                                    value={confirmForm.data.code}
                                    onChange={(e) => confirmForm.setData('code', e.target.value.replace(/\D/g, ''))}
                                    error={confirmForm.errors.code}
                                />
                                <PrimaryButton type="submit" className="w-auto mt-6" disabled={confirmForm.processing}>
                                    Confirmar
                                </PrimaryButton>
                            </form>
                        </div>
                    )}

                    {twoFactorEnabled && (
                        <div className="mt-5 flex flex-wrap gap-2">
                            <button type="button" className={secondaryButton} onClick={() => void loadRecoveryCodes()} disabled={busy}>
                                <KeyRound className="w-4 h-4" />
                                Ver códigos de recuperação
                            </button>
                            <button type="button" className={secondaryButton} onClick={regenerate} disabled={busy}>
                                Gerar novos códigos
                            </button>
                            <button
                                type="button"
                                className={`${secondaryButton} text-rose-300 hover:text-rose-200`}
                                onClick={disable}
                                disabled={busy}
                            >
                                Desativar
                            </button>
                        </div>
                    )}

                    {twoFactorEnabled && recoveryCodes.length > 0 && (
                        <div className="mt-5 rounded-xl border border-amber-500/30 bg-amber-950/30 p-4">
                            <p className="text-sm text-amber-200">
                                Guarde estes códigos em um lugar seguro. Cada um funciona uma única vez se você perder acesso ao app.
                            </p>
                            <ul className="mt-3 grid grid-cols-2 gap-1 font-mono text-sm text-slate-200">
                                {recoveryCodes.map((code) => (
                                    <li key={code}>{code}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
