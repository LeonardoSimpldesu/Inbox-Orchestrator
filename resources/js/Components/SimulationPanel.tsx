import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import type { RetentionRuleRow, RuleSimulation } from '@/types';
import PrimaryButton from '@/Components/PrimaryButton';
import { describeAction } from '@/lib/rules';
import { formatDate } from '@/lib/gmail';

interface SimulationPanelProps {
    simulation: RuleSimulation;
    rule: RetentionRuleRow;
    onClose: () => void;
}

function verb(rule: RetentionRuleRow, total: number): string {
    const plural = total === 1 ? '' : 's';
    switch (rule.action) {
        case 'archive':
            return `seria${total === 1 ? '' : 'm'} arquivado${plural}`;
        case 'trash':
            return `iria${total === 1 ? '' : 'm'} para a lixeira`;
        default:
            return `receberia${total === 1 ? '' : 'm'} o marcador`;
    }
}

export default function SimulationPanel({ simulation, rule, onClose }: SimulationPanelProps) {
    const count = simulation.capped ? 'Mais de 10.000' : simulation.total.toLocaleString('pt-BR');

    return (
        <section className="mt-6 rounded-2xl border border-indigo-500/40 bg-indigo-950/30 p-5" aria-label="Resultado da simulação">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h2 className="text-base font-semibold text-white">Simulação: {rule.name}</h2>
                    <p className="mt-1 text-sm text-indigo-200">
                        <strong>{count}</strong> {simulation.total === 1 ? 'e-mail' : 'e-mails'} {verb(rule, simulation.total)} hoje{rule.action === 'add_label' ? ` (${describeAction(rule)})` : ''}.
                    </p>
                </div>
                <button type="button" onClick={onClose} aria-label="Fechar simulação" className="text-slate-400 hover:text-white cursor-pointer"><X className="w-5 h-5" /></button>
            </div>

            {simulation.sample.length > 0 && (
                <div className="mt-4 overflow-x-auto">
                    <p className="mb-2 text-xs text-slate-400">
                        {simulation.capped ? 'Amostra (a execução também alcança e-mails mais antigos que estes):' : 'Amostra dos mais antigos:'}
                    </p>
                    <table className="w-full text-xs">
                        <tbody className="divide-y divide-slate-800">
                            {simulation.sample.map((message, index) => (
                                <tr key={index}>
                                    <td className="py-1.5 pr-3 text-slate-400 whitespace-nowrap">{formatDate(message.date)}</td>
                                    <td className="py-1.5 pr-3 text-slate-300 max-w-[14rem] truncate">{message.sender}</td>
                                    <td className="py-1.5 text-slate-200 max-w-[24rem] truncate">{message.subject ?? '(sem assunto)'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {!rule.is_active && (
                <div className="mt-4 flex justify-end">
                    <PrimaryButton type="button" className="w-auto" onClick={() => router.post(`/retention-rules/${rule.id}/activate`, {}, { preserveScroll: true, onSuccess: onClose })}>
                        Ativar regra
                    </PrimaryButton>
                </div>
            )}
        </section>
    );
}
