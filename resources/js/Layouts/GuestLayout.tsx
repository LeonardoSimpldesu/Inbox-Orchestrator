import type { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { Layers } from 'lucide-react';
import PublicLinks from '@/Components/PublicLinks';

interface GuestLayoutProps {
    title: string;
    description?: string;
    children: ReactNode;
}

export default function GuestLayout({ title, description, children }: GuestLayoutProps) {
    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col items-center justify-center px-4 py-12 font-sans">
            <Head title={title} />

            <div className="flex items-center gap-3 mb-8">
                <div className="w-10 h-10 rounded-xl bg-indigo-600/20 border border-indigo-500/30 flex items-center justify-center text-indigo-400">
                    <Layers className="w-5 h-5" />
                </div>
                <span className="font-bold text-lg tracking-tight bg-gradient-to-r from-indigo-400 via-sky-300 to-teal-300 bg-clip-text text-transparent">
                    Inbox Orchestrator
                </span>
            </div>

            <div className="w-full max-w-sm bg-slate-900/60 border border-slate-800 rounded-2xl p-6 shadow-xl shadow-black/20">
                <h1 className="text-lg font-semibold text-white">{title}</h1>
                {description && <p className="text-sm text-slate-400 mt-1">{description}</p>}
                <div className="mt-6">{children}</div>
            </div>

            <footer className="mt-8">
                <PublicLinks />
            </footer>
        </div>
    );
}
