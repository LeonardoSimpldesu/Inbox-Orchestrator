import { useEffect, useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import type { GmailLabel, RetentionRuleRow, RuleAction, RuleFilterType } from '@/types';
import PrimaryButton from '@/Components/PrimaryButton';
import { ACTION_LABELS, CATEGORY_LABELS, FILTER_TYPE_LABELS } from '@/lib/rules';

interface RetentionRuleFormProps {
    accounts: { id: number; account_email: string }[];
    rule: RetentionRuleRow | null;
    onClose: () => void;
}

interface RuleFormData {
    email_account_id: number;
    name: string;
    filter_type: RuleFilterType;
    filter_value: string;
    retention_days: number;
    action: RuleAction;
    target_label: string;
    archive_after_label: boolean;
    simulate: boolean;
}

function suggestName(data: RuleFormData, labels: GmailLabel[]): string {
    const labelName = (id: string) => labels.find((label) => label.id === id)?.name ?? '';
    const subject = {
        category: `${CATEGORY_LABELS[data.filter_value] ?? 'Categoria'}`,
        domain: `E-mails de ${data.filter_value || 'domínio'}`,
        sender: `E-mails de ${data.filter_value || 'remetente'}`,
        label: `Marcador ${labelName(data.filter_value) || ''}`.trim(),
    }[data.filter_type];
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
        filter_type: rule?.filter_type ?? 'category',
        filter_value: rule?.filter_value ?? 'promotions',
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
    const needsLabels = form.data.filter_type === 'label' || form.data.action === 'add_label';

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
        if (!nameTouched) {
            form.setData('name', suggestName(form.data, labels));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.filter_type, form.data.filter_value, form.data.retention_days, form.data.action, form.data.target_label, labels, nameTouched]);

    const createLabel = async (field: 'filter_value' | 'target_label') => {
        if (newLabel.trim() === '') return;
        try {
            const response = await window.axios.post<{ label: GmailLabel }>(`/accounts/${form.data.email_account_id}/labels`, { name: newLabel });
            setLabels((current) => [...current.filter((l) => l.id !== response.data.label.id), response.data.label].sort((a, b) => a.name.localeCompare(b.name, 'pt-BR')));
            form.setData(field, response.data.label.id);
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

    const labelSelect = (field: 'filter_value' | 'target_label') => (
        <div className="space-y-2">
            <select className={input} value={form.data[field]} onChange={(e) => form.setData(field, e.target.value)}>
                <option value="">Escolha um marcador…</option>
                {labels.map((label) => (
                    <option key={label.id} value={label.id}>{label.name}</option>
                ))}
            </select>
            <div className="flex gap-2">
                <input className={input} placeholder="Novo marcador" value={newLabel} onChange={(e) => setNewLabel(e.target.value)} maxLength={100} />
                <button type="button" onClick={() => createLabel(field)} className="inline-flex items-center gap-1 whitespace-nowrap rounded-lg border border-slate-700 bg-slate-800 px-3 text-xs font-semibold text-slate-200 hover:bg-slate-700 cursor-pointer">
                    <Plus className="w-3.5 h-3.5" /> Criar marcador
                </button>
            </div>
        </div>
    );

    const error = (field: keyof RuleFormData) => form.errors[field] && <p className="mt-1 text-xs text-rose-300">{form.errors[field]}</p>;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4" role="dialog" aria-modal="true" aria-labelledby="rule-form-title">
            <form onSubmit={submit} className="w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl space-y-4">
                <div className="flex items-start justify-between">
                    <h2 id="rule-form-title" className="text-lg font-semibold text-white">{rule ? 'Editar regra' : 'Nova regra'}</h2>
                    <button type="button" onClick={onClose} aria-label="Fechar" className="text-slate-400 hover:text-white cursor-pointer"><X className="w-5 h-5" /></button>
                </div>

                <div>
                    <label className={labelClass} htmlFor="rule-account">Conta</label>
                    <select id="rule-account" className={input} value={form.data.email_account_id} onChange={(e) => form.setData('email_account_id', Number(e.target.value))}>
                        {accounts.map((account) => <option key={account.id} value={account.id}>{account.account_email}</option>)}
                    </select>
                    {error('email_account_id')}
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label className={labelClass} htmlFor="rule-filter-type">Filtro</label>
                        <select
                            id="rule-filter-type"
                            className={input}
                            value={form.data.filter_type}
                            onChange={(e) => {
                                const type = e.target.value as RuleFilterType;
                                form.setData((data) => ({ ...data, filter_type: type, filter_value: type === 'category' ? 'promotions' : '' }));
                            }}
                        >
                            {(Object.keys(FILTER_TYPE_LABELS) as RuleFilterType[]).map((type) => <option key={type} value={type}>{FILTER_TYPE_LABELS[type]}</option>)}
                        </select>
                    </div>
                    <div className="sm:col-span-2">
                        <label className={labelClass}>Valor</label>
                        {form.data.filter_type === 'category' && (
                            <select className={input} value={form.data.filter_value} onChange={(e) => form.setData('filter_value', e.target.value)}>
                                {Object.entries(CATEGORY_LABELS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                            </select>
                        )}
                        {form.data.filter_type === 'domain' && <input className={input} placeholder="amazon.com.br" value={form.data.filter_value} onChange={(e) => form.setData('filter_value', e.target.value)} />}
                        {form.data.filter_type === 'sender' && <input className={input} type="email" placeholder="news@site.com" value={form.data.filter_value} onChange={(e) => form.setData('filter_value', e.target.value)} />}
                        {form.data.filter_type === 'label' && labelSelect('filter_value')}
                        {error('filter_value')}
                    </div>
                </div>

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
                            {(Object.keys(ACTION_LABELS) as RuleAction[]).map((action) => <option key={action} value={action}>{ACTION_LABELS[action]}</option>)}
                        </select>
                        {form.data.action === 'add_label' && (
                            <div className="mt-2 space-y-2">
                                {labelSelect('target_label')}
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
                    <PrimaryButton type="submit" className="w-auto" disabled={form.processing}>{rule ? 'Salvar' : 'Salvar e simular'}</PrimaryButton>
                </div>
            </form>
        </div>
    );
}
