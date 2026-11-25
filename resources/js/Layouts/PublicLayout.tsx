import type { ReactNode } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { Layers } from 'lucide-react';
import PublicLinks from '@/Components/PublicLinks';

interface PublicLayoutProps {
    title: string;
    children: ReactNode;
}

export default function PublicLayout({ title, children }: PublicLayoutProps) {
    const { auth } = usePage().props;

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans">
            <Head title={title} />

            <header className="border-b border-slate-800 bg-slate-900/60">
                <div className="max-w-3xl mx-auto px-4 h-16 flex items-center justify-between">
                    <Link href="/sobre" className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-xl bg-indigo-600/20 border border-indigo-500/30 flex items-center justify-center text-indigo-400">
                            <Layers className="w-4 h-4" />
                        </div>
                        <span className="font-bold tracking-tight bg-gradient-to-r from-indigo-400 via-sky-300 to-teal-300 bg-clip-text text-transparent">
                            Inbox Orchestrator
                        </span>
                    </Link>
                    <Link
                        href={auth.user ? '/' : '/login'}
                        className="px-3 py-1.5 rounded-lg text-sm font-medium bg-indigo-600 hover:bg-indigo-500 text-white transition"
                    >
                        {auth.user ? 'Ir para o app' : 'Entrar'}
                    </Link>
                </div>
            </header>

            <main className="flex-1 max-w-3xl w-full mx-auto px-4 py-10">
                <h1 className="text-2xl font-semibold text-white">{title}</h1>
                <div className="mt-6 space-y-6 text-sm leading-relaxed text-slate-300">{children}</div>
            </main>

            <footer className="border-t border-slate-800/60 py-4">
                <PublicLinks />
            </footer>
        </div>
    );
}
