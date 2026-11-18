import type { ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { 
    Inbox, 
    MailCheck, 
    Clock, 
    Filter, 
    Settings, 
    LogOut, 
    Layers, 
    ShieldCheck, 
    AlertCircle,
    CheckCircle2
} from 'lucide-react';

interface AppLayoutProps {
    children: ReactNode;
    title?: string;
}

export default function AppLayout({ children }: AppLayoutProps) {
    const { auth, flash } = usePage().props;
    const user = auth?.user;

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans">
            {/* Top Navigation */}
            <header className="border-b border-slate-800 bg-slate-900/60 backdrop-blur sticky top-0 z-50">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-indigo-600/20 border border-indigo-500/30 flex items-center justify-center text-indigo-400">
                            <Layers className="w-5 h-5" />
                        </div>
                        <div>
                            <span className="font-bold text-lg tracking-tight bg-gradient-to-r from-indigo-400 via-sky-300 to-teal-300 bg-clip-text text-transparent">
                                Inbox Orchestrator
                            </span>
                            <span className="text-xs text-slate-400 block font-mono">v1.0 MVP</span>
                        </div>
                    </div>

                    <nav className="flex items-center gap-1 sm:gap-2">
                        <Link 
                            href="/" 
                            className="flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium text-slate-200 hover:bg-slate-800/80 hover:text-white transition"
                        >
                            <Inbox className="w-4 h-4 text-indigo-400" />
                            <span>Visão Geral</span>
                        </Link>
                        <Link 
                            href="/subscriptions" 
                            className="flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium text-slate-400 hover:bg-slate-800/80 hover:text-slate-200 transition"
                        >
                            <MailCheck className="w-4 h-4 text-emerald-400" />
                            <span>Assinaturas</span>
                        </Link>
                        <Link 
                            href="/retention-rules" 
                            className="flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium text-slate-400 hover:bg-slate-800/80 hover:text-slate-200 transition"
                        >
                            <Clock className="w-4 h-4 text-amber-400" />
                            <span>Regras TTL</span>
                        </Link>
                    </nav>

                    <div className="flex items-center gap-3">
                        {user && (
                            <div className="flex items-center gap-2 bg-slate-800/50 border border-slate-700/60 rounded-full px-3 py-1 text-xs">
                                <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                <span className="text-slate-300 font-medium">{user.name}</span>
                                <span className="text-slate-500 hidden md:inline">({user.email})</span>
                            </div>
                        )}
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium text-slate-400 hover:bg-slate-800/80 hover:text-white transition cursor-pointer"
                        >
                            <LogOut className="w-4 h-4" />
                            <span>Sair</span>
                        </Link>
                    </div>
                </div>
            </header>

            {/* Flash notifications */}
            {flash?.success && (
                <div className="max-w-7xl mx-auto px-4 mt-4 w-full">
                    <div className="bg-emerald-950/60 border border-emerald-500/40 rounded-xl p-3 flex items-center gap-2 text-sm text-emerald-300">
                        <CheckCircle2 className="w-4 h-4 shrink-0 text-emerald-400" />
                        <span>{flash.success}</span>
                    </div>
                </div>
            )}
            {flash?.error && (
                <div className="max-w-7xl mx-auto px-4 mt-4 w-full">
                    <div className="bg-rose-950/60 border border-rose-500/40 rounded-xl p-3 flex items-center gap-2 text-sm text-rose-300">
                        <AlertCircle className="w-4 h-4 shrink-0 text-rose-400" />
                        <span>{flash.error}</span>
                    </div>
                </div>
            )}

            {/* Main content */}
            <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
                {children}
            </main>

            {/* Footer */}
            <footer className="border-t border-slate-800/60 bg-slate-900/30 py-4 text-center text-xs text-slate-500">
                Inbox Orchestrator &bull; Suporte para múltiplas contas &bull; Docker Containerizado
            </footer>
        </div>
    );
}
