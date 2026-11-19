import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { ExternalLink, X } from 'lucide-react';
import type { SubscriptionRow, UnsubscribeMethod } from '@/types';
import PrimaryButton from '@/Components/PrimaryButton';

interface UnsubscribeDialogProps {
    subscription: SubscriptionRow;
    onClose: () => void;
}

type Cleanup = 'keep' | 'archive' | 'trash';

const METHOD_LABELS: Record<UnsubscribeMethod, string> = {
    one_click: 'Desinscrição de um clique',
    mailto: 'E-mail de desinscrição',
    page: 'Página de desinscrição do remetente',
    rule: 'Regra para os próximos e-mails',
};

export function availableMethods(subscription: SubscriptionRow): UnsubscribeMethod[] {
    const methods: UnsubscribeMethod[] = [];
    if (subscription.options.one_click) methods.push('one_click');
    if (subscription.options.mailto) methods.push('mailto');
    if (subscription.options.page) methods.push('page');
    methods.push('rule');
    return methods;
}

function senderLabel(subscription: SubscriptionRow): string {
    return subscription.name ? `${subscription.name} <${subscription.email}>` : subscription.email;
}

export default function UnsubscribeDialog({ subscription, onClose }: UnsubscribeDialogProps) {
    const methods = availableMethods(subscription);
    const form = useForm<{ method: UnsubscribeMethod; cleanup: Cleanup; rule_action: 'archive' | 'trash' }>({
        method: methods[0] ?? 'rule',
        cleanup: 'keep',
        rule_action: 'archive',
    });
    const isRule = form.data.method === 'rule';

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(`/subscriptions/${subscription.id}/unsubscribe`, { preserveScroll: true, onSuccess: onClose });
    };

    const description: Record<UnsubscribeMethod, string> = {
        one_click: `O Inbox Orchestrator pede a desinscrição diretamente a ${subscription.domain}.`,
        mailto: `O Inbox Orchestrator envia um e-mail de desinscrição pela sua conta ${subscription.account_email} para ${subscription.domain}.`,
        page: 'Abra a página do remetente, conclua a desinscrição lá e depois confirme aqui.',
        rule: `${methods.length === 1 ? 'Este remetente não oferece desinscrição. ' : ''}Crie uma regra para os próximos e-mails dele (passa a valer quando as regras automáticas estiverem ativas).`,
    };

    const radio = 'h-4 w-4 accent-indigo-500';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4" role="dialog" aria-modal="true" aria-labelledby="unsubscribe-title">
            <form onSubmit={submit} className="w-full max-w-lg rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl">
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <h2 id="unsubscribe-title" className="text-lg font-semibold text-white">
                            {isRule ? 'Criar regra' : 'Desinscrever'}
                        </h2>
                        <p className="mt-1 truncate text-sm text-slate-400">{senderLabel(subscription)}</p>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Fechar" className="text-slate-400 hover:text-white cursor-pointer">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {methods.length > 1 && (
                    <fieldset className="mt-5 space-y-2">
                        <legend className="text-xs font-semibold uppercase tracking-wider text-slate-400">Como</legend>
                        {methods.map((method) => (
                            <label key={method} className="flex items-center gap-2 text-sm text-slate-200">
                                <input type="radio" name="method" className={radio} checked={form.data.method === method} onChange={() => form.setData('method', method)} />
                                {METHOD_LABELS[method]}
                            </label>
                        ))}
                    </fieldset>
                )}

                <p className="mt-4 rounded-xl border border-slate-700 bg-slate-950/60 p-3 text-sm text-slate-300">{description[form.data.method]}</p>
                {form.errors.method && <p className="mt-2 text-sm text-rose-300">{form.errors.method}</p>}

                {form.data.method === 'page' && (
                    <a
                        href={`/subscriptions/${subscription.id}/page`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-300 hover:text-indigo-200"
                    >
                        <ExternalLink className="w-4 h-4" />
                        Abrir página de desinscrição
                    </a>
                )}

                {isRule ? (
                    <fieldset className="mt-5 space-y-2">
                        <legend className="text-xs font-semibold uppercase tracking-wider text-slate-400">Próximos e-mails</legend>
                        {(['archive', 'trash'] as const).map((action) => (
                            <label key={action} className="flex items-center gap-2 text-sm text-slate-200">
                                <input
                                    type="radio"
                                    name="rule_action"
                                    className={radio}
                                    checked={form.data.rule_action === action}
                                    onChange={() => form.setData((data) => ({ ...data, rule_action: action, cleanup: data.cleanup === 'keep' ? 'keep' : action }))}
                                />
                                {action === 'archive' ? 'Arquivar' : 'Mandar para a lixeira'}
                            </label>
                        ))}
                        <label className="mt-3 flex items-center gap-2 text-sm text-slate-200">
                            <input
                                type="checkbox"
                                className={radio}
                                checked={form.data.cleanup !== 'keep'}
                                onChange={(event) => form.setData('cleanup', event.target.checked ? form.data.rule_action : 'keep')}
                            />
                            Aplicar também aos {subscription.total} e-mails existentes
                        </label>
                    </fieldset>
                ) : (
                    <fieldset className="mt-5 space-y-2">
                        <legend className="text-xs font-semibold uppercase tracking-wider text-slate-400">E-mails existentes</legend>
                        {(
                            [
                                ['keep', 'Manter'],
                                ['archive', `Arquivar os ${subscription.total} e-mails`],
                                ['trash', 'Mandar para a lixeira (recuperáveis por 30 dias no Gmail)'],
                            ] as const
                        ).map(([value, label]) => (
                            <label key={value} className="flex items-center gap-2 text-sm text-slate-200">
                                <input type="radio" name="cleanup" className={radio} checked={form.data.cleanup === value} onChange={() => form.setData('cleanup', value)} />
                                {label}
                            </label>
                        ))}
                    </fieldset>
                )}

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-slate-300 hover:text-white cursor-pointer">
                        Cancelar
                    </button>
                    <PrimaryButton type="submit" className="w-auto" disabled={form.processing}>
                        {form.data.method === 'page' ? 'Já me desinscrevi' : isRule ? 'Criar regra' : 'Desinscrever'}
                    </PrimaryButton>
                </div>
            </form>
        </div>
    );
}
