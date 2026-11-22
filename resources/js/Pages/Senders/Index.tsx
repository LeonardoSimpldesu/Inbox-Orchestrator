import { useCallback, useEffect, useState } from 'react';
import { Link, router, usePoll } from '@inertiajs/react';
import { ExternalLink, Users } from 'lucide-react';
import type { GmailLabel, Paginated, SenderRow } from '@/types';
import AppLayout from '@/Layouts/AppLayout';
import CleanSenderDialog from '@/Components/CleanSenderDialog';
import SenderCategorySelect from '@/Components/SenderCategorySelect';
import { timeAgo } from '@/lib/gmail';

interface SendersIndexProps {
    senders: Paginated<SenderRow>;
    accounts: { id: number; account_email: string }[];
    filters: { account: number | null; category: string | null; q: string; all: boolean };
    labels: GmailLabel[];
}

type Filters = SendersIndexProps['filters'];

function paginationLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»').replace('Previous', 'Anterior').replace('Next', 'Próxima');
}

const control = 'rounded-lg border border-slate-700 bg-slate-900 px-2 py-1.5 text-xs text-slate-200';
const smallButton = 'inline-flex items-center gap-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold px-3 py-2 rounded-lg border border-slate-700 transition cursor-pointer whitespace-nowrap';

export default function SendersIndex({ senders, accounts, filters, labels }: SendersIndexProps) {
    const [labelsByAccount, setLabelsByAccount] = useState<Record<number, GmailLabel[]>>({});
    const [labelError, setLabelError] = useState<string | null>(null);
    const [checked, setChecked] = useState<number[]>([]);
    const [cleaning, setCleaning] = useState<{ ids: number[]; description: string } | null>(null);
    const [search, setSearch] = useState(filters.q);

    const rows = senders.data;
    const selectedRows = rows.filter((row) => checked.includes(row.id));
    const selectedAccounts = [...new Set(selectedRows.map((row) => row.account_id))];
    const bulkAccount = selectedAccounts.length === 1 ? selectedAccounts[0] : undefined;
    const allChecked = rows.length > 0 && rows.every((row) => checked.includes(row.id));

    const { start, stop } = usePoll(5_000, { only: ['senders'] }, { autoStart: false });
    const applying = rows.some((row) => row.applying);
    useEffect(() => {
        if (applying) start(); else stop();
        return stop;
    }, [applying, start, stop]);

    useEffect(() => setChecked([]), [senders.current_page, filters.account, filters.category, filters.q, filters.all]);

    const applyFilters = useCallback((next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get('/senders', {
            account: merged.account ?? undefined,
            category: merged.account ? merged.category ?? undefined : undefined,
            q: merged.q || undefined,
            all: merged.all ? 1 : undefined,
        }, { preserveState: true, preserveScroll: true });
    }, [filters]);

    useEffect(() => {
        if (search === filters.q) return;
        const timer = setTimeout(() => applyFilters({ q: search }), 400);
        return () => clearTimeout(timer);
    }, [search, filters.q, applyFilters]);

    const loadLabels = (accountId: number) => {
        if (labelsByAccount[accountId]) return;
        window.axios
            .get<{ labels: GmailLabel[] }>(`/accounts/${accountId}/labels`)
            .then((response) => setLabelsByAccount((current) => ({ ...current, [accountId]: response.data.labels })))
            .catch(() => setLabelError('Não foi possível carregar os marcadores do Gmail. Tente de novo.'));
    };

    const categorize = (ids: number[], labelId: string | null) => {
        router.post('/senders/categorize', { ids, label_id: labelId }, { preserveScroll: true, onSuccess: () => setChecked([]) });
    };

    const createAndCategorize = async (accountId: number, ids: number[]) => {
        const name = window.prompt('Nome do novo marcador no Gmail:')?.trim();
        if (!name) return;
        try {
            const response = await window.axios.post<{ label: GmailLabel }>(`/accounts/${accountId}/labels`, { name });
            const label = response.data.label;
            setLabelsByAccount((current) => ({
                ...current,
                [accountId]: [...(current[accountId] ?? []).filter((l) => l.id !== label.id), label].sort((a, b) => a.name.localeCompare(b.name, 'pt-BR')),
            }));
            categorize(ids, label.id);
        } catch {
            setLabelError('Não foi possível criar o marcador no Gmail. Tente de novo.');
        }
    };

    const toggle = (id: number) => setChecked((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));

    return (
        <AppLayout title="Remetentes">
            <div className="flex flex-col gap-4 pb-6 border-b border-slate-800">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-white">Remetentes</h1>
                    <p className="text-sm text-slate-400 mt-1">
                        Pessoas, bancos e serviços que escrevem para você. Escolha a categoria (marcador do Gmail) de cada um e decida o que limpar. As newsletters ficam no{' '}
                        <Link href="/subscriptions" className="text-indigo-300 hover:text-indigo-200">Hub de Assinaturas</Link>.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <select aria-label="Conta" className={control} value={filters.account ?? ''} onChange={(e) => applyFilters({ account: e.target.value === '' ? null : Number(e.target.value), category: null })}>
                        <option value="">Todas as contas</option>
                        {accounts.map((account) => <option key={account.id} value={account.id}>{account.account_email}</option>)}
                    </select>
                    {filters.account !== null && (
                        <select
                            aria-label="Categoria"
                            className={control}
                            value={filters.category ?? ''}
                            onChange={(e) => applyFilters({ category: e.target.value === '' ? null : e.target.value })}
                        >
                            <option value="">Todas as categorias</option>
                            <option value="none">Sem categoria</option>
                            {labels.map((label) => <option key={label.id} value={label.id}>{label.name}</option>)}
                        </select>
                    )}
                    <input type="search" aria-label="Buscar remetente" placeholder="Buscar nome, e-mail ou domínio" maxLength={100} className={`${control} w-56`} value={search} onChange={(e) => setSearch(e.target.value)} />
                    <label className="flex items-center gap-2 text-xs text-slate-300">
                        <input type="checkbox" className="h-4 w-4 accent-indigo-500" checked={filters.all} onChange={(e) => applyFilters({ all: e.target.checked })} />
                        Mostrar assinaturas
                    </label>
                </div>
            </div>

            {labelError && (
                <div role="alert" className="mt-4 rounded-xl border border-rose-500/40 bg-rose-950/60 p-3 text-sm text-rose-300">{labelError}</div>
            )}

            {checked.length > 0 && (
                <div className="mt-6 flex flex-wrap items-center gap-3 rounded-xl border border-indigo-500/40 bg-indigo-950/40 p-3 text-sm text-indigo-100">
                    <span>{checked.length} selecionado(s)</span>
                    {bulkAccount !== undefined ? (
                        <SenderCategorySelect
                            value={null}
                            currentName={null}
                            labels={labelsByAccount[bulkAccount]}
                            ariaLabel="Categoria dos selecionados"
                            onOpen={() => loadLabels(bulkAccount)}
                            onChoose={(labelId) => categorize(checked, labelId)}
                            onCreate={() => void createAndCategorize(bulkAccount, checked)}
                        />
                    ) : (
                        <span className="text-xs text-indigo-200">Para categorizar, selecione remetentes de uma mesma conta.</span>
                    )}
                    <button type="button" className={smallButton} onClick={() => setCleaning({ ids: checked, description: `${checked.length} remetente(s) selecionado(s)` })}>
                        Limpar…
                    </button>
                    <button type="button" onClick={() => setChecked([])} className="text-xs text-indigo-200 underline underline-offset-2 cursor-pointer">Limpar seleção</button>
                </div>
            )}

            <div className="mt-6 bg-slate-900/40 border border-slate-800 rounded-2xl overflow-x-auto">
                {rows.length === 0 ? (
                    <div className="text-center py-12 px-4">
                        <Users className="w-10 h-10 text-slate-600 mx-auto mb-3" />
                        <p className="text-sm text-slate-300">{filters.q || filters.category ? 'Nenhum remetente encontrado com esses filtros.' : 'Nenhum remetente ainda.'}</p>
                    </div>
                ) : (
                    <table className="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr className="text-left text-xs uppercase tracking-wider text-slate-500 border-b border-slate-800 whitespace-nowrap">
                                <th className="pl-4 py-3 w-8">
                                    <input type="checkbox" aria-label="Selecionar todos da página" className="h-4 w-4 accent-indigo-500" checked={allChecked} onChange={() => setChecked(allChecked ? [] : rows.map((row) => row.id))} />
                                </th>
                                <th className="px-4 py-3 font-medium">Remetente</th>
                                <th className="px-4 py-3 font-medium text-right">E-mails</th>
                                <th className="px-4 py-3 font-medium">Último</th>
                                <th className="px-4 py-3 font-medium">Categoria</th>
                                <th className="px-4 py-3 font-medium">Ações</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800">
                            {rows.map((row) => (
                                <tr key={row.id} className="align-top">
                                    <td className="pl-4 py-3">
                                        <input type="checkbox" aria-label={`Selecionar ${row.email}`} className="h-4 w-4 accent-indigo-500" checked={checked.includes(row.id)} onChange={() => toggle(row.id)} />
                                    </td>
                                    <td className="px-4 py-3 max-w-[22rem]">
                                        <div className="flex items-center gap-2">
                                            <span className="truncate font-medium text-slate-200">{row.name ?? row.email}</span>
                                            {row.is_subscription && (
                                                <Link href="/subscriptions" className="shrink-0 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-[10px] text-emerald-300">Assinatura</Link>
                                            )}
                                        </div>
                                        <div className="truncate text-xs text-slate-500">{row.email}</div>
                                        {filters.account === null && accounts.length > 1 && <div className="truncate text-xs text-slate-600">em {row.account_email}</div>}
                                    </td>
                                    <td className="px-4 py-3 text-right text-slate-200">{row.total}</td>
                                    <td className="px-4 py-3 text-xs text-slate-400 whitespace-nowrap">{row.last_received_at ? timeAgo(row.last_received_at) : '—'}</td>
                                    <td className="px-4 py-3">
                                        {row.applying ? (
                                            <span className="text-xs text-sky-300">Aplicando…</span>
                                        ) : (
                                            <SenderCategorySelect
                                                value={row.category?.label_id ?? null}
                                                currentName={row.category?.name ?? null}
                                                labels={labelsByAccount[row.account_id]}
                                                ariaLabel={`Categoria de ${row.email}`}
                                                onOpen={() => loadLabels(row.account_id)}
                                                onChoose={(labelId) => categorize([row.id], labelId)}
                                                onCreate={() => void createAndCategorize(row.account_id, [row.id])}
                                            />
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex gap-2">
                                            <a href={row.gmail_url} target="_blank" rel="noopener noreferrer" title="Abrir os e-mails deste remetente no Gmail" className={smallButton}>
                                                <ExternalLink className="w-3.5 h-3.5" /> Gmail
                                            </a>
                                            <button type="button" className={smallButton} onClick={() => setCleaning({ ids: [row.id], description: row.name ? `${row.name} <${row.email}>` : row.email })}>
                                                Limpar…
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {senders.last_page > 1 && (
                <nav className="mt-4 flex flex-wrap items-center justify-center gap-1" aria-label="Paginação">
                    {senders.links.map((link, index) =>
                        link.url ? (
                            <Link key={index} href={link.url} preserveScroll className={`px-3 py-1.5 rounded-lg text-xs ${link.active ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'}`}>
                                {paginationLabel(link.label)}
                            </Link>
                        ) : (
                            <span key={index} className="px-3 py-1.5 text-xs text-slate-600">{paginationLabel(link.label)}</span>
                        ),
                    )}
                </nav>
            )}

            {cleaning && <CleanSenderDialog ids={cleaning.ids} description={cleaning.description} onClose={() => setCleaning(null)} onDone={() => setChecked([])} />}
        </AppLayout>
    );
}
