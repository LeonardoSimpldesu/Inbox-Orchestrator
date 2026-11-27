import { useEffect, useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import type { GmailLabel, RetentionRuleRow, RuleAction, RuleCondition, RuleConditionType } from '@/types';
import PrimaryButton from '@/Components/PrimaryButton';
import { ACTION_LABELS, CATEGORY_LABELS, CONDITION_TYPE_LABELS, MANAGED_BY, describeConditions } from '@/lib/rules';

interface RetentionRuleFormProps {
    accounts: { id: number; account_email: string }[];
    rule: RetentionRuleRow | null;
    onClose: () => void;
}

interface RuleFormData {
    email_account_id: number | 'all';
    name: string;
    conditions: RuleCondition[];
    retention_days: number;
    action: RuleAction;
    target_label: string;
    archive_after_label: boolean;
    simulate: boolean;
}

const MAX_CONDITIONS = 20;

function blankCondition(type: RuleConditionType): RuleCondition {
    return { type, value: type === 'category' ? 'promotions' : '' };
}

function suggestName(data: RuleFormData, labels: GmailLabel[]): string {
    const labelName = (id: string) => labels.find((label) => label.id === id)?.name ?? '';
    const filled = data.conditions
        .filter((condition) => condition.value.trim() !== '')
        .map((condition) => (condition.type === 'label' ? { ...condition, label: labelName(condition.value) || null } : condition));
    const subject = filled.length > 0 ? describeConditions(filled) : 'Nova regra';
    const when = data.retention_days > 0 ? ` com mais de ${data.retention_days} dias` : '';
    const action = data.action === 'add_label' ? `: marcar ${labelName(data.target_label)}` : data.action === 'trash' ? ': lixeira' : ': arquivar';
    return `${subject}${when}${action}`.slice(0, 100);
}

const input = 'w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 focus:border-indigo-500 focus:outline-none';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1';

export default function RetentionRuleForm({ accounts, rule, onClose }: RetentionRuleFormProps) {
    const form = useForm<RuleFormData>({
        email_account_id: rule?.email_account_id ?? accounts[0]?.id ?? 0,
        name: rule?.name ?? '',
        conditions: rule?.conditions.map(({ type, value }) => ({ type, value })) ?? [blankCondition('category')],
        retention_days: rule?.retention_days ?? 15,
        action: rule?.action ?? 'archive',
        target_label: rule?.target_label ?? '',
        archive_after_label: rule?.archive_after_label ?? false,
        simulate: true,
    });
    const [labels, setLabels] = useState<GmailLabel[]>([]);
    const [labelsError, setLabelsError] = useState<string | null>(null);
    const [nameTouched, setNameTouched] = useState(rule !== null);
    const [newLabel, setNewLabel] = useState('');
    const allAccounts = form.data.email_account_id === 'all';
    const managedBy = rule ? MANAGED_BY[rule.kind] : undefined;
    const hasLabelCondition = form.data.conditions.some((condition) => condition.type === 'label');
    const needsLabels = !allAccounts && (hasLabelCondition || form.data.action === 'add_label');
    const errors = form.errors as Record<string, string | undefined>;
    const conditionsKey = JSON.stringify(form.data.conditions);
    const allowedActions = (Object.keys(ACTION_LABELS) as RuleAction[]).filter((action) => {
        if (allAccounts && action === 'add_label') return false;
        if (rule?.kind === 'sender_category') return action === 'add_label';
        if (managedBy) return action !== 'add_label';
        return true;
    });

    useEffect(() => {
        if (!needsLabels || !form.data.email_account_id) {
            return;
        }

        setLabelsError(null);
        window.axios
            .get<{ labels: GmailLabel[] }>(`/accounts/${form.data.email_account_id}/labels`)
            .then((response) => setLabels(response.data.labels))
            .catch(() => setLabelsError('Não foi possível carregar os marcadores do Gmail. Tente de novo.'));
    }, [needsLabels, form.data.email_account_id]);

    useEffect(() => {
        const suggestion = suggestName(form.data, labels);
        if (!nameTouched && suggestion !== form.data.name) {
            form.setData('name', suggestion);
        }
        // setData hands back a new conditions array, so the effect keys on its content.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [conditionsKey, form.data.retention_days, form.data.action, form.data.target_label, labels, nameTouched]);

    const setCondition = (index: number, condition: RuleCondition) =>
        form.setData((data) => ({ ...data, conditions: data.conditions.map((current, i) => (i === index ? condition : current)) }));
    const addCondition = () =>
        form.setData((data) => ({ ...data, conditions: [...data.conditions, blankCondition('subject')] }));
    const removeCondition = (index: number) =>
        form.setData((data) => ({ ...data, conditions: data.conditions.filter((_, i) => i !== index) }));

    const createLabel = async (pick: (id: string) => void) => {
        if (newLabel.trim() === '') return;
        try {
            const response = await window.axios.post<{ label: GmailLabel }>(`/accounts/${form.data.email_account_id}/labels`, { name: newLabel });
            setLabels((current) => [...current.filter((l) => l.id !== response.data.label.id), response.data.label].sort((a, b) => a.name.localeCompare(b.name, 'pt-BR')));
            pick(response.data.label.id);
            setNewLabel('');
        } catch {
            setLabelsError('Não foi possível criar o marcador no Gmail. Tente de novo.');
        }
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (rule) {
            form.put(`/retention-rules/${rule.id}`, options);
        } else {
            form.post('/retention-rules', options);
        }
    };

    const labelSelect = (value: string, pick: (id: string) => void) => (
        <div className="space-y-2">
            <select className={input} value={value} onChange={(e) => pick(e.target.value)}>
                <option value="">Escolha um marcador…</option>
                {labels.map((label) => (
                    <option key={label.id} value={label.id}>{label.name}</option>
                ))}
            </select>
            <div className="flex gap-2">
                <input className={input} placeholder="Novo marcador" value={newLabel} onChange={(e) => setNewLabel(e.target.value)} maxLength={100} />
                <button type="button" onClick={() => createLabel(pick)} className="inline-flex items-center gap-1 whitespace-nowrap rounded-lg border border-slate-700 bg-slate-800 px-3 text-xs font-semibold text-slate-200 hover:bg-slate-700 cursor-pointer">
                    <Plus className="w-3.5 h-3.5" /> Criar marcador
                </button>
            </div>
        </div>
    );

    const error = (field: string) => errors[field] && <p className="mt-1 text-xs text-rose-300">{errors[field]}</p>;

    const conditionValue = (condition: RuleCondition, index: number) => {
        const set = (value: string) => setCondition(index, { ...condition, value });
        switch (condition.type) {
            case 'category':
                return (
                    <select className={input} aria-label="Categoria" value={condition.value} onChange={(e) => set(e.target.value)}>
                        {Object.entries(CATEGORY_LABELS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                    </select>
                );
            case 'label':
                return labelSelect(condition.value, set);
            case 'domain':
                return <input className={input} aria-label="Domínio" placeholder="amazon.com.br" value={condition.value} onChange={(e) => set(e.target.value)} />;
            case 'sender':
                return <input className={input} aria-label="Remetente" type="email" placeholder="news@site.com" value={condition.value} onChange={(e) => set(e.target.value)} />;
            case 'subject':
                return <input className={input} aria-label="Assunto" placeholder="Bem-vindo" maxLength={100} value={condition.value} onChange={(e) => set(e.target.value)} />;
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4" role="dialog" aria-modal="true" aria-labelledby="rule-form-title">
            <form onSubmit={submit} className="w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl space-y-4">
                <div className="flex items-start justify-between">
                    <h2 id="rule-form-title" className="text-lg font-semibold text-white">{rule ? 'Editar regra' : 'Nova regra'}</h2>
                    <button type="button" onClick={onClose} aria-label="Fechar" className="text-slate-400 hover:text-white cursor-pointer"><X className="w-5 h-5" /></button>
                </div>

                <div>
                    <label className={labelClass} htmlFor="rule-account">Conta</label>
                    <select
                        id="rule-account"
                        className={input}
                        disabled={managedBy !== undefined}
                        value={form.data.email_account_id}
                        onChange={(e) => {
                            // Labels belong to one account: drop the old list and any picked label.
                            setLabels([]);
                            form.setData((data) => {
                                const all = e.target.value === 'all';
                                const conditions = data.conditions
                                    .filter((condition) => !all || condition.type !== 'label')
                                    .map((condition) => (condition.type === 'label' ? { ...condition, value: '' } : condition));
                                return {
                                    ...data,
                                    email_account_id: all ? 'all' : Number(e.target.value),
                                    conditions: conditions.length > 0 ? conditions : [blankCondition('category')],
                                    action: all && data.action === 'add_label' ? 'archive' : data.action,
                                    target_label: '',
                                };
                            });
                        }}
                    >
                        {accounts.map((account) => <option key={account.id} value={account.id}>{account.account_email}</option>)}
                        {!rule && accounts.length > 1 && <option value="all">Todas as contas (uma cópia por conta)</option>}
                    </select>
                    {allAccounts && <p className="mt-1 text-xs text-slate-500">Cria uma regra pausada em cada conta. Marcadores não estão disponíveis porque são de cada conta.</p>}
                    {error('email_account_id')}
                </div>

                <fieldset className="space-y-2">
                    <legend className={labelClass}>Condições</legend>
                    {managedBy ? (
                        <p className="rounded-lg border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-300">
                            {describeConditions(rule?.conditions ?? [])}
                            <span className="mt-1 block text-xs text-slate-500">{managedBy}: as condições não podem ser alteradas aqui.</span>
                        </p>
                    ) : (
                        <>
                            <p className="text-xs text-slate-500">Todas as condições precisam valer. Repita um tipo para aceitar alternativas (ex.: dois assuntos = um ou outro).</p>
                            {form.data.conditions.map((condition, index) => (
                                <div key={index} className="grid grid-cols-[1fr_auto] sm:grid-cols-[10rem_1fr_auto] gap-2 items-start">
                                    <select
                                        className={`${input} col-span-2 sm:col-span-1`}
                                        aria-label={`Tipo da condição ${index + 1}`}
                                        value={condition.type}
                                        onChange={(e) => setCondition(index, blankCondition(e.target.value as RuleConditionType))}
                                    >
                                        {(Object.keys(CONDITION_TYPE_LABELS) as RuleConditionType[])
                                            .filter((type) => type !== 'label' || condition.type === 'label' || (!allAccounts && !hasLabelCondition))
                                            .map((type) => <option key={type} value={type}>{CONDITION_TYPE_LABELS[type]}</option>)}
                                    </select>
                                    <div>
                                        {conditionValue(condition, index)}
                                        {condition.type === 'subject' && <p className="mt-1 text-xs text-slate-500">O Gmail diferencia acentos: escreva como aparece no e-mail.</p>}
                                        {error(`conditions.${index}.value`)}
                                        {error(`conditions.${index}.type`)}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => removeCondition(index)}
                                        disabled={form.data.conditions.length === 1}
                                        aria-label={`Remover condição ${index + 1}`}
                                        className="mt-2 text-slate-400 hover:text-white disabled:opacity-30 cursor-pointer disabled:cursor-default"
                                    >
                                        <X className="w-4 h-4" />
                                    </button>
                                </div>
                            ))}
                            {form.data.conditions.length < MAX_CONDITIONS && (
                                <button type="button" onClick={addCondition} className="inline-flex items-center gap-1 rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs font-semibold text-slate-200 hover:bg-slate-700 cursor-pointer">
                                    <Plus className="w-3.5 h-3.5" /> Adicionar condição
                                </button>
                            )}
                        </>
                    )}
                    {error('conditions')}
                </fieldset>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label className={labelClass} htmlFor="rule-days">Mais de (dias)</label>
                        <input id="rule-days" type="number" min={0} max={3650} className={input} value={form.data.retention_days} onChange={(e) => form.setData('retention_days', Number(e.target.value))} />
                        <p className="mt-1 text-xs text-slate-500">0 = qualquer idade</p>
                        {error('retention_days')}
                    </div>
                    <div className="sm:col-span-2">
                        <label className={labelClass} htmlFor="rule-action">Ação</label>
                        <select id="rule-action" className={input} value={form.data.action} onChange={(e) => form.setData('action', e.target.value as RuleAction)}>
                            {allowedActions.map((action) => <option key={action} value={action}>{ACTION_LABELS[action]}</option>)}
                        </select>
                        {form.data.action === 'add_label' && (
                            <div className="mt-2 space-y-2">
                                {labelSelect(form.data.target_label, (id) => form.setData('target_label', id))}
                                <label className="flex items-center gap-2 text-sm text-slate-200">
                                    <input type="checkbox" className="h-4 w-4 accent-indigo-500" checked={form.data.archive_after_label} onChange={(e) => form.setData('archive_after_label', e.target.checked)} />
                                    Também arquivar
                                </label>
                            </div>
                        )}
                        {error('action')}
                        {error('target_label')}
                    </div>
                </div>

                {labelsError && needsLabels && <p className="text-xs text-rose-300">{labelsError}</p>}

                <div>
                    <label className={labelClass} htmlFor="rule-name">Nome</label>
                    <input id="rule-name" className={input} value={form.data.name} maxLength={100} onChange={(e) => { setNameTouched(true); form.setData('name', e.target.value); }} />
                    {error('name')}
                </div>

                <p className="rounded-xl border border-slate-700 bg-slate-950/60 p-3 text-xs text-slate-400">
                    Nunca são tocados: e-mails com estrela, enviados, rascunhos e spam.
                    {rule && ' Ao salvar, a regra fica pausada até ser simulada de novo.'}
                </p>

                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-slate-300 hover:text-white cursor-pointer">Cancelar</button>
                    <PrimaryButton type="submit" className="w-auto" disabled={form.processing}>{rule ? 'Salvar' : allAccounts ? 'Criar nas contas' : 'Salvar e simular'}</PrimaryButton>
                </div>
            </form>
        </div>
    );
}
