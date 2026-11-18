import { router } from '@inertiajs/react';
import { Info, Mail, Plus, RefreshCw, Unlink } from 'lucide-react';
import type { EmailAccountSummary } from '@/types';
import AppLayout from '@/Layouts/AppLayout';
import AccountStatusBadge from '@/Components/AccountStatusBadge';
import PrimaryButton from '@/Components/PrimaryButton';
import { connectGmail } from '@/lib/gmail';

interface AccountsIndexProps {
    accounts: EmailAccountSummary[];
    testingMode: boolean;
}

function disconnect(account: EmailAccountSummary): void {
    const confirmed = window.confirm(
        `Desconectar ${account.account_email}? O app perde o acesso ao Gmail, mas os remetentes e as regras ficam guardados se você reconectar.`,
    );

    if (confirmed) {
        router.delete(`/accounts/${account.id}`, { preserveScroll: true });
    }
}

export default function AccountsIndex({ accounts, testingMode }: AccountsIndexProps) {
    return (
        <AppLayout title="Contas">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-800">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-white">Contas Gmail</h1>
                    <p className="text-sm text-slate-400 mt-1">Conecte as caixas de entrada que o Inbox Orchestrator vai organizar.</p>
                </div>
                <PrimaryButton type="button" className="w-auto text-xs" onClick={() => connectGmail()}>
                    <Plus className="w-4 h-4" />
                    Conectar conta Gmail
                </PrimaryButton>
            </div>

            {testingMode && (
                <div className="mt-6 flex gap-3 rounded-xl border border-sky-500/30 bg-sky-950/40 p-4 text-sm text-sky-200">
                    <Info className="w-4 h-4 shrink-0 mt-0.5 text-sky-400" />
                    <p>
                        Enquanto o app estiver em modo de teste no Google, o acesso de cada conta expira 7 dias depois da conexão.
                        Antes disso, use <strong>Reconectar</strong> para renovar por mais 7 dias.
                    </p>
                </div>
            )}

            <div className="mt-6 bg-slate-900/40 border border-slate-800 rounded-2xl">
                {accounts.length === 0 ? (
                    <div className="text-center py-12 px-4">
                        <Mail className="w-10 h-10 text-slate-600 mx-auto mb-3" />
                        <h2 className="text-sm font-medium text-slate-300">Nenhuma conta conectada</h2>
                        <p className="text-xs text-slate-500 max-w-sm mx-auto mt-1">
                            Ao conectar, você autoriza o app no Google a ler, organizar e enviar e-mails de desinscrição pela sua conta.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-slate-800">
                        {accounts.map((account) => (
                            <li key={account.id} className="p-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
                                <div className="flex items-center gap-3 min-w-0">
                                    <div className="w-9 h-9 rounded-full bg-slate-800 flex items-center justify-center text-xs font-bold text-slate-300 shrink-0">
                                        G
                                    </div>
                                    <div className="min-w-0">
                                        <div className="text-sm font-medium text-slate-200 truncate">{account.account_email}</div>
                                        <div className="mt-1">
                                            <AccountStatusBadge account={account} />
                                        </div>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => connectGmail(account.id)}
                                        className="inline-flex items-center gap-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold px-3 py-2 rounded-lg border border-slate-700 transition cursor-pointer"
                                    >
                                        <RefreshCw className="w-3.5 h-3.5" />
                                        Reconectar
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => disconnect(account)}
                                        className="inline-flex items-center gap-1.5 text-rose-300 hover:bg-rose-500/10 text-xs font-semibold px-3 py-2 rounded-lg border border-rose-500/30 transition cursor-pointer"
                                    >
                                        <Unlink className="w-3.5 h-3.5" />
                                        Desconectar
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
