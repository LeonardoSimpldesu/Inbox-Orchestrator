import type { EmailAccountSummary, RetentionRuleSummary } from '@/types';
import { Link } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import AccountStatusBadge from '@/Components/AccountStatusBadge';
import { connectGmail } from '@/lib/gmail';
import { 
    Mail, 
    Trash2, 
    Clock, 
    ExternalLink, 
    Play, 
    Plus, 
    RefreshCw, 
    ShieldCheck, 
    AtSign, 
    Zap,
    AlertTriangle
} from 'lucide-react';

interface DashboardProps {
    accounts: EmailAccountSummary[];
    stats: { totalSenders: number; archivedCount: number };
    recentRules: RetentionRuleSummary[];
}

export default function Dashboard({ accounts, stats, recentRules }: DashboardProps) {
    const needsAttention = accounts.some((account) => account.status === 'needs_reauth' || account.expiring_soon);

    return (
        <AppLayout title="Cockpit de Automação">
            {/* Header */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-6 border-b border-slate-800">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
                        Painel de Controle
                        <span className="text-xs font-normal py-0.5 px-2 rounded-full bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                            Ambiente Docker
                        </span>
                    </h1>
                    <p className="text-sm text-slate-400 mt-1">
                        Gerencie a saúde da sua caixa de entrada, desinscrições em lote e políticas de expiração.
                    </p>
                </div>

                <div className="flex items-center gap-2">
                    <button 
                        type="button"
                        onClick={() => connectGmail()}
                        className="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold px-4 py-2.5 rounded-xl shadow-lg shadow-indigo-600/20 transition cursor-pointer"
                    >
                        <Plus className="w-4 h-4" />
                        <span className="whitespace-nowrap">Conectar Conta Gmail</span>
                    </button>

                    <button 
                        type="button"
                        onClick={() => alert('Disparando sincronização em background...')}
                        className="inline-flex items-center gap-2 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold px-3 py-2.5 rounded-xl border border-slate-700 transition cursor-pointer"
                        title="Sincronizar metadados agora"
                    >
                        <RefreshCw className="w-4 h-4" />
                        <span>Sincronizar</span>
                    </button>
                </div>
            </div>

            {needsAttention && (
                <div role="alert" className="mt-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-xl border border-amber-500/40 bg-amber-950/40 p-4 text-sm text-amber-200">
                    <div className="flex items-center gap-2">
                        <AlertTriangle className="w-4 h-4 shrink-0 text-amber-400" />
                        <span>Uma ou mais contas Gmail precisam ser reconectadas para continuar funcionando.</span>
                    </div>
                    <Link href="/accounts" className="text-xs font-semibold text-amber-100 underline underline-offset-2 hover:text-white">
                        Ver contas
                    </Link>
                </div>
            )}

            {/* Quick Metrics */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
                <div className="bg-slate-900/50 border border-slate-800 rounded-2xl p-5 hover:border-slate-700 transition">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-slate-400 uppercase tracking-wider">Contas Conectadas</span>
                        <div className="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-400 flex items-center justify-center">
                            <AtSign className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="mt-3 flex items-baseline gap-2">
                        <span className="text-2xl font-bold text-white">{accounts.length}</span>
                        <span className="text-xs text-slate-500">contas ativas</span>
                    </div>
                </div>

                <div className="bg-slate-900/50 border border-slate-800 rounded-2xl p-5 hover:border-slate-700 transition">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-slate-400 uppercase tracking-wider">Assinaturas Detectadas</span>
                        <div className="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                            <Mail className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="mt-3 flex items-baseline gap-2">
                        <span className="text-2xl font-bold text-white">{stats.totalSenders || 0}</span>
                        <span className="text-xs text-emerald-400">newsletters rastreadas</span>
                    </div>
                </div>

                <div className="bg-slate-900/50 border border-slate-800 rounded-2xl p-5 hover:border-slate-700 transition">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-slate-400 uppercase tracking-wider">Regras de TTL</span>
                        <div className="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-400 flex items-center justify-center">
                            <Clock className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="mt-3 flex items-baseline gap-2">
                        <span className="text-2xl font-bold text-white">{recentRules.length || 0}</span>
                        <span className="text-xs text-slate-500">políticas ativas</span>
                    </div>
                </div>

                <div className="bg-slate-900/50 border border-slate-800 rounded-2xl p-5 hover:border-slate-700 transition">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-slate-400 uppercase tracking-wider">E-mails Higienizados</span>
                        <div className="w-8 h-8 rounded-lg bg-sky-500/10 text-sky-400 flex items-center justify-center">
                            <Zap className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="mt-3 flex items-baseline gap-2">
                        <span className="text-2xl font-bold text-white">{stats.archivedCount || 0}</span>
                        <span className="text-xs text-sky-400">arquivados/apagados</span>
                    </div>
                </div>
            </div>

            {/* Main Sections */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-8">
                {/* Left 2 Cols: Accounts & Status */}
                <div className="lg:col-span-2 space-y-6">
                    <div className="bg-slate-900/40 border border-slate-800 rounded-2xl p-6">
                        <div className="flex items-center justify-between mb-4">
                            <h2 className="text-base font-semibold text-white">Contas de E-mail Conectadas</h2>
                            <span className="text-xs text-slate-400">Gmail &bull; Outlook</span>
                        </div>

                        {accounts.length === 0 ? (
                            <div className="text-center py-10 px-4 border border-dashed border-slate-800 rounded-xl bg-slate-950/40">
                                <Mail className="w-10 h-10 text-slate-600 mx-auto mb-3" />
                                <h3 className="text-sm font-medium text-slate-300">Nenhuma conta conectada ainda</h3>
                                <p className="text-xs text-slate-500 max-w-sm mx-auto mt-1 mb-4">
                                    Conecte sua primeira conta Google para começar a rastrear newsletters e automatizar a faxina de e-mails.
                                </p>
                                <button
                                    type="button"
                                    onClick={() => connectGmail()}
                                    className="inline-flex items-center gap-2 bg-indigo-600/90 hover:bg-indigo-600 text-white text-xs font-semibold px-4 py-2 rounded-lg transition"
                                >
                                    <Plus className="w-3.5 h-3.5" />
                                    <span>Conectar Conta</span>
                                </button>
                            </div>
                        ) : (
                            <div className="divide-y divide-slate-800">
                                {accounts.map((acc) => (
                                    <div key={acc.id} className="py-3 flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-full bg-slate-800 flex items-center justify-center text-xs font-bold text-slate-300">
                                                {acc.provider === 'gmail' ? 'G' : 'O'}
                                            </div>
                                            <div>
                                                <div className="text-sm font-medium text-slate-200">{acc.account_email}</div>
                                                <div className="text-xs text-slate-500">Última sinc: {acc.last_synced_at || 'Nunca'}</div>
                                            </div>
                                        </div>
                                        <AccountStatusBadge account={acc} />
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* How It Works banner */}
                    <div className="bg-gradient-to-r from-indigo-950/40 via-slate-900 to-slate-900 border border-indigo-900/30 rounded-2xl p-6">
                        <div className="flex items-start gap-4">
                            <div className="w-10 h-10 rounded-xl bg-indigo-500/20 border border-indigo-500/30 flex items-center justify-center text-indigo-400 shrink-0">
                                <ShieldCheck className="w-5 h-5" />
                            </div>
                            <div>
                                <h3 className="text-sm font-semibold text-indigo-200">Privacidade & Arquitetura Segura</h3>
                                <p className="text-xs text-slate-400 mt-1 leading-relaxed">
                                    O Inbox Orchestrator sincroniza apenas os <strong>metadados</strong> (remetente, domínio, data e cabeçalhos de desinscrição). O corpo das mensagens e anexos nunca são armazenados no banco de dados. Qualquer e-mail pode ser aberto diretamente no Gmail via deep-link oficial.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Right Col: Quick Actions & TTL Rules Preview */}
                <div className="space-y-6">
                    <div className="bg-slate-900/40 border border-slate-800 rounded-2xl p-6">
                        <h2 className="text-base font-semibold text-white mb-4">Ações Rápidas</h2>

                        <div className="space-y-3">
                            <button 
                                type="button"
                                onClick={() => alert('Executando motor de retenção em modo dry-run...')}
                                className="w-full flex items-center justify-between p-3 rounded-xl bg-slate-800/60 hover:bg-slate-800 border border-slate-700/60 text-left transition text-xs group"
                            >
                                <div className="flex items-center gap-3">
                                    <div className="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-400 flex items-center justify-center group-hover:scale-105 transition">
                                        <Play className="w-4 h-4" />
                                    </div>
                                    <div>
                                        <div className="font-semibold text-slate-200">Executar Regras Agora</div>
                                        <div className="text-slate-400 text-[11px]">Aplica as regras de TTL cadastradas</div>
                                    </div>
                                </div>
                            </button>

                            <button 
                                type="button"
                                onClick={() => alert('Abrindo assistente de nova regra...')}
                                className="w-full flex items-center justify-between p-3 rounded-xl bg-slate-800/60 hover:bg-slate-800 border border-slate-700/60 text-left transition text-xs group"
                            >
                                <div className="flex items-center gap-3">
                                    <div className="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-400 flex items-center justify-center group-hover:scale-105 transition">
                                        <Clock className="w-4 h-4" />
                                    </div>
                                    <div>
                                        <div className="font-semibold text-slate-200">Nova Regra de Retenção</div>
                                        <div className="text-slate-400 text-[11px]">Ex: e-mails de compra duram 30 dias</div>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>

                    <div className="bg-slate-900/40 border border-slate-800 rounded-2xl p-6">
                        <h2 className="text-base font-semibold text-white mb-2">Rotina do Agendador (Cron)</h2>
                        <p className="text-xs text-slate-400 leading-relaxed mb-3">
                            O container <code className="text-indigo-300">inbox_scheduler</code> executa as regras automaticamente todos os dias às <strong>03:00</strong>.
                        </p>
                        <div className="flex items-center gap-2 text-xs text-emerald-400">
                            <span className="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                            <span>Worker em execução na VPS</span>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
