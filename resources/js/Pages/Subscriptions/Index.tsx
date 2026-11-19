import { useEffect, useState } from 'react';
import { Link, router, usePoll } from '@inertiajs/react';
import { AlertTriangle, ExternalLink, MailX } from 'lucide-react';
import type { Paginated, SubscriptionRow } from '@/types';
import AppLayout from '@/Layouts/AppLayout';
import UnsubscribeDialog, { availableMethods } from '@/Components/UnsubscribeDialog';
import { formatDate, timeAgo } from '@/lib/gmail';

interface SubscriptionsIndexProps {
    subscriptions: Paginated<SubscriptionRow>;
    accounts: { id: number; account_email: string }[];
    filters: { account: number | null; status: 'pending' | 'handled' };
    counts: { pending: number; handled: number };
}

const ERROR_LABELS: Record<string, string> = {
    timeout: 'o site do remetente não respondeu',
    blocked_address: 'endereço de desinscrição recusado por segurança',
    invalid_mailto: 'endereço de e-mail de desinscrição inválido',
    rate_limited: 'limite do Gmail atingido, tente de novo em alguns minutos',
    reauth_required: 'reconecte a conta',
    account_unavailable: 'conta indisponível',
    request_failed: 'não foi possível conectar ao site do remetente',
};

function errorLabel(code: string): string {
    const cleanup = code.startsWith('cleanup_');
    const base = cleanup ? code.slice('cleanup_'.length) : code;
    const label = ERROR_LABELS[base] ?? (base.startsWith('http_') ? `o remetente respondeu com erro ${base.slice(5)}` : base);
    return cleanup ? `a limpeza dos e-mails falhou (${label})` : label;
}

function StatusCell({ row }: { row: SubscriptionRow }) {
    switch (row.status) {
        case 'processing':
            return <span className="text-xs text-sky-300">Desinscrevendo…</span>;
        case 'failed':
            return <span className="text-xs text-rose-300">Falhou: {row.error ? errorLabel(row.error) : 'erro desconhecido'}</span>;
        case 'blocked':
            return <span className="text-xs text-slate-300">Regra criada</span>;
        case 'requested':
            return (
                <span className="flex flex-col gap-0.5 text-xs">
                    <span className="text-emerald-300">Desinscrição pedida{row.requested_at ? ` em ${formatDate(row.requested_at)}` : ''}</span>
                    {row.emails_after_request > 0 && (
                        <span className="inline-flex items-center gap-1 text-amber-300">
                            <AlertTriangle className="w-3 h-3" />
                            Ainda mandou {row.emails_after_request} {row.emails_after_request === 1 ? 'e-mail' : 'e-mails'} depois do pedido
                        </span>
                    )}
                    {row.error && <span className="text-amber-300">Atenção: {errorLabel(row.error)}</span>}
                </span>
            );
        default:
            return null;
    }
}

function paginationLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»').replace('Previous', 'Anterior').replace('Next', 'Próxima');
}

export default function SubscriptionsIndex({ subscriptions, accounts, filters, counts }: SubscriptionsIndexProps) {
    const [selected, setSelected] = useState<SubscriptionRow | null>(null);
    const processing = subscriptions.data.some((row) => row.status === 'processing');
    const { start, stop } = usePoll(5_000, { only: ['subscriptions', 'counts'] }, { autoStart: false });

    useEffect(() => {
        if (processing) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [processing, start, stop]);

    const applyFilters = (next: Partial<SubscriptionsIndexProps['filters']>) => {
        const merged = { ...filters, ...next };
        router.get('/subscriptions', { account: merged.account ?? undefined, status: merged.status }, { preserveState: true, preserveScroll: true });
    };

    const tab = (status: 'pending' | 'handled', label: string, count: number) => (
        <button
            type="button"
            onClick={() => applyFilters({ status })}
            className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer ${
                filters.status === status ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
            }`}
        >
            {label} {count}
        </button>
    );

    return (
        <AppLayout title="Assinaturas">
            <div className="flex flex-col lg:flex-row lg:items-end justify-between gap-4 pb-6 border-b border-slate-800">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-white">Hub de Assinaturas</h1>
                    <p className="text-sm text-slate-400 mt-1">Newsletters e e-mails promocionais das suas contas, dos que mais mandam para os que menos mandam.</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <label className="text-xs text-slate-400" htmlFor="account-filter">Conta</label>
                    <select
                        id="account-filter"
                        value={filters.account ?? ''}
                        onChange={(event) => applyFilters({ account: event.target.value === '' ? null : Number(event.target.value) })}
                        className="rounded-lg border border-slate-700 bg-slate-900 px-2 py-1.5 text-xs text-slate-200"
                    >
                        <option value="">Todas</option>
                        {accounts.map((account) => (
                            <option key={account.id} value={account.id}>{account.account_email}</option>
                        ))}
                    </select>
                    {tab('pending', 'Pendentes', counts.pending)}
                    {tab('handled', 'Tratados', counts.handled)}
                </div>
            </div>

            <div className="mt-6 bg-slate-900/40 border border-slate-800 rounded-2xl overflow-x-auto">
                {subscriptions.data.length === 0 ? (
                    <div className="text-center py-12 px-4">
                        <MailX className="w-10 h-10 text-slate-600 mx-auto mb-3" />
                        <p className="text-sm text-slate-300">
                            {filters.status === 'pending' ? 'Nenhuma assinatura pendente.' : 'Nenhuma assinatura tratada ainda.'}
                        </p>
                    </div>
                ) : (
                    <table className="w-full min-w-[680px] text-sm">
                        <thead>
                            <tr className="text-left text-xs uppercase tracking-wider text-slate-500 border-b border-slate-800 whitespace-nowrap">
                                <th className="px-4 py-3 font-medium">Remetente</th>
                                <th className="px-4 py-3 font-medium text-right">E-mails</th>
                                <th className="px-4 py-3 font-medium text-right">Não lidos</th>
                                <th className="px-4 py-3 font-medium">Último</th>
                                <th className="px-4 py-3 font-medium">Ações</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800">
                            {subscriptions.data.map((row) => (
                                <tr key={row.id} className="align-top">
                                    <td className="px-4 py-3 max-w-[22rem]">
                                        <div className="truncate font-medium text-slate-200">{row.name ?? row.email}</div>
                                        <div className="truncate text-xs text-slate-500">{row.email}</div>
                                        {filters.account === null && accounts.length > 1 && (
                                            <div className="truncate text-xs text-slate-600">em {row.account_email}</div>
                                        )}
                                        <div className="mt-1"><StatusCell row={row} /></div>
                                    </td>
                                    <td className="px-4 py-3 text-right text-slate-200">{row.total}</td>
                                    <td className="px-4 py-3 text-right text-slate-200">{row.unread}</td>
                                    <td className="px-4 py-3 text-xs text-slate-400 whitespace-nowrap">{row.last_received_at ? timeAgo(row.last_received_at) : '—'}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex gap-2">
                                            <a
                                                href={row.gmail_url}
                                                title="Abrir os e-mails deste remetente no Gmail"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold px-3 py-2 rounded-lg border border-slate-700 transition whitespace-nowrap"
                                            >
                                                <ExternalLink className="w-3.5 h-3.5" />
                                                Gmail
                                            </a>
                                            {filters.status === 'pending' && row.status !== 'processing' && (
                                                <button
                                                    type="button"
                                                    onClick={() => setSelected(row)}
                                                    className="inline-flex items-center gap-1.5 bg-rose-600/90 hover:bg-rose-600 text-white text-xs font-semibold px-3 py-2 rounded-lg transition cursor-pointer whitespace-nowrap"
                                                >
                                                    {availableMethods(row)[0] === 'rule' ? 'Criar regra' : 'Desinscrever'}
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {subscriptions.last_page > 1 && (
                <nav className="mt-4 flex flex-wrap items-center justify-center gap-1" aria-label="Paginação">
                    {subscriptions.links.map((link, index) =>
                        link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                preserveScroll
                                className={`px-3 py-1.5 rounded-lg text-xs ${link.active ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'}`}
                            >
                                {paginationLabel(link.label)}
                            </Link>
                        ) : (
                            <span key={index} className="px-3 py-1.5 text-xs text-slate-600">{paginationLabel(link.label)}</span>
                        ),
                    )}
                </nav>
            )}

            {selected && <UnsubscribeDialog subscription={selected} onClose={() => setSelected(null)} />}
        </AppLayout>
    );
}
