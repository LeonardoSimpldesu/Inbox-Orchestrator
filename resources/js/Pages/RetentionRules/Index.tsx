import { Fragment, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Clock, Pause, Pencil, Play, Plus, Trash2, Zap } from 'lucide-react';
import type { RetentionRuleRow, RuleSimulation } from '@/types';
import AppLayout from '@/Layouts/AppLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import RetentionRuleForm from '@/Components/RetentionRuleForm';
import SimulationPanel from '@/Components/SimulationPanel';
import { describeAction, describeFilter, ruleErrorLabel } from '@/lib/rules';
import { formatDate, timeAgo } from '@/lib/gmail';

interface RetentionRulesIndexProps {
    rules: RetentionRuleRow[];
    accounts: { id: number; account_email: string; needs_reauth: boolean }[];
    simulation: RuleSimulation | null;
}

const button = 'inline-flex items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-800 px-2.5 py-1.5 text-xs font-semibold text-slate-200 transition hover:bg-slate-700 cursor-pointer disabled:cursor-not-allowed disabled:opacity-50';

function lastRun(rule: RetentionRuleRow): string {
    const run = rule.logs.find((log) => log.status !== 'simulated');
    if (!rule.last_run_at && !run) return 'nunca';
    if (run?.status === 'failed') return `falhou: ${ruleErrorLabel(run.error ?? '')}`;
    const when = rule.last_run_at ? timeAgo(rule.last_run_at) : '';
    return run ? `${when} · ${run.affected} ${run.affected === 1 ? 'e-mail' : 'e-mails'}` : when;
}

export default function RetentionRulesIndex({ rules, accounts, simulation }: RetentionRulesIndexProps) {
    const [editing, setEditing] = useState<RetentionRuleRow | null | 'new'>(null);
    const [expanded, setExpanded] = useState<number | null>(null);
    const [shownSimulation, setShownSimulation] = useState<RuleSimulation | null>(simulation);
    const simulatedRule = shownSimulation ? rules.find((rule) => rule.id === shownSimulation.rule_id) : undefined;

    useEffect(() => setShownSimulation(simulation), [simulation]);

    const post = (url: string) => router.post(url, {}, { preserveScroll: true });
    const remove = (rule: RetentionRuleRow) => {
        if (window.confirm(`Apagar a regra "${rule.name}"? O histórico dela também será apagado.`)) {
            router.delete(`/retention-rules/${rule.id}`, { preserveScroll: true });
        }
    };

    return (
        <AppLayout title="Regras">
            <div className="flex flex-col lg:flex-row lg:items-end justify-between gap-4 pb-6 border-b border-slate-800">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-white">Regras de expiração</h1>
                    <p className="text-sm text-slate-400 mt-1">Rodam uma vez por dia, depois das 03:00. Regras novas começam pausadas até você simular e ativar.</p>
                </div>
                <div className="flex gap-2">
                    <button type="button" className={button} onClick={() => post('/retention-rules/run')}>
                        <Zap className="w-3.5 h-3.5" /> Executar regras agora
                    </button>
                    <PrimaryButton type="button" className="w-auto text-xs" onClick={() => setEditing('new')} disabled={accounts.length === 0}>
                        <Plus className="w-4 h-4" /> Nova regra
                    </PrimaryButton>
                </div>
            </div>

            {shownSimulation && simulatedRule && <SimulationPanel simulation={shownSimulation} rule={simulatedRule} onClose={() => setShownSimulation(null)} />}

            <div className="mt-6 bg-slate-900/40 border border-slate-800 rounded-2xl overflow-x-auto">
                {rules.length === 0 ? (
                    <div className="text-center py-12 px-4">
                        <Clock className="w-10 h-10 text-slate-600 mx-auto mb-3" />
                        <p className="text-sm text-slate-300">Nenhuma regra ainda.</p>
                        <p className="text-xs text-slate-500 mt-1">Exemplo: e-mails da categoria Promoções com mais de 15 dias devem ser arquivados.</p>
                    </div>
                ) : (
                    <table className="w-full min-w-[820px] text-sm">
                        <thead>
                            <tr className="text-left text-xs uppercase tracking-wider text-slate-500 border-b border-slate-800 whitespace-nowrap">
                                <th className="px-4 py-3 font-medium">Regra</th>
                                <th className="px-4 py-3 font-medium">Filtro</th>
                                <th className="px-4 py-3 font-medium text-right">Dias</th>
                                <th className="px-4 py-3 font-medium">Ação</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Última execução</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800">
                            {rules.map((rule) => (
                                <Fragment key={rule.id}>
                                    <tr className="align-top">
                                        <td className="px-4 py-3 max-w-[16rem]">
                                            <div className="truncate font-medium text-slate-200" title={rule.name}>{rule.name}</div>
                                            {accounts.length > 1 && <div className="truncate text-xs text-slate-500">{rule.account_email}</div>}
                                            <div className="mt-2 flex flex-wrap gap-1.5">
                                                <button type="button" className={button} onClick={() => post(`/retention-rules/${rule.id}/simulate`)}>Simular</button>
                                                {rule.is_active ? (
                                                    <button type="button" className={button} onClick={() => post(`/retention-rules/${rule.id}/pause`)}><Pause className="w-3.5 h-3.5" /> Pausar</button>
                                                ) : (
                                                    <button type="button" className={button} disabled={!rule.can_activate} title={rule.can_activate ? undefined : 'Simule antes de ativar'} onClick={() => post(`/retention-rules/${rule.id}/activate`)}>
                                                        <Play className="w-3.5 h-3.5" /> Ativar
                                                    </button>
                                                )}
                                                <button type="button" className={button} aria-label="Editar" onClick={() => setEditing(rule)}><Pencil className="w-3.5 h-3.5" /></button>
                                                <button type="button" className={button} aria-label="Apagar" onClick={() => remove(rule)}><Trash2 className="w-3.5 h-3.5" /></button>
                                                <button type="button" className={button} aria-expanded={expanded === rule.id} onClick={() => setExpanded(expanded === rule.id ? null : rule.id)}>
                                                    Histórico {expanded === rule.id ? <ChevronUp className="w-3.5 h-3.5" /> : <ChevronDown className="w-3.5 h-3.5" />}
                                                </button>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-slate-300">{describeFilter(rule)}</td>
                                        <td className="px-4 py-3 text-right text-slate-200">{rule.retention_days}</td>
                                        <td className="px-4 py-3 text-xs text-slate-300">{describeAction(rule)}</td>
                                        <td className="px-4 py-3 text-xs whitespace-nowrap">
                                            {rule.is_active ? <span className="text-emerald-300">● Ativa</span> : <span className="text-slate-400">⏸ Pausada</span>}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-slate-400">{lastRun(rule)}</td>
                                    </tr>
                                    {expanded === rule.id && (
                                        <tr>
                                            <td colSpan={6} className="px-4 pb-4">
                                                {rule.logs.length === 0 ? (
                                                    <p className="text-xs text-slate-500">Sem execuções ainda.</p>
                                                ) : (
                                                    <ul className="space-y-1 text-xs">
                                                        {rule.logs.map((log, index) => (
                                                            <li key={index} className="text-slate-400">
                                                                {log.executed_at ? formatDate(log.executed_at) : '—'} ·{' '}
                                                                {log.status === 'simulated' ? `simulação: ${log.affected} e-mails` : log.status === 'success' ? `executada: ${log.affected} e-mails` : <span className="text-rose-300">falhou: {ruleErrorLabel(log.error ?? '')}</span>}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {editing !== null && (
                <RetentionRuleForm accounts={accounts} rule={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />
            )}
        </AppLayout>
    );
}
