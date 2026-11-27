import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, ChevronDown, Clock, Inbox, Layers, LogOut, Mail, MailCheck, ShieldCheck, UserRound, Users, type LucideIcon } from 'lucide-react';
import PublicLinks from '@/Components/PublicLinks';

interface AppLayoutProps {
    children: ReactNode;
    title?: string;
}

interface NavItem {
    href: string;
    label: string;
    icon: LucideIcon;
    color: string;
}

const NAV: NavItem[] = [
    { href: '/', label: 'Visão Geral', icon: Inbox, color: 'text-indigo-400' },
    { href: '/subscriptions', label: 'Assinaturas', icon: MailCheck, color: 'text-emerald-400' },
    { href: '/senders', label: 'Remetentes', icon: Users, color: 'text-sky-400' },
    { href: '/retention-rules', label: 'Regras', icon: Clock, color: 'text-amber-400' },
];

function isActive(url: string, href: string): boolean {
    const path = url.split('?')[0] ?? url;
    return href === '/' ? path === '/' : path === href || path.startsWith(`${href}/`);
}

function ProfileMenu({ name, email }: { name: string; email: string }) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const close = (event: MouseEvent | KeyboardEvent) => {
            if (event instanceof KeyboardEvent ? event.key === 'Escape' : !ref.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', close);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', close);
        };
    }, [open]);

    const item = 'flex items-center gap-2 w-full px-3 py-2.5 rounded-lg text-sm text-slate-300 hover:bg-slate-800 hover:text-white transition cursor-pointer';

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                aria-haspopup="menu"
                aria-label="Menu da conta"
                className="flex items-center gap-1.5 min-h-11 px-2.5 rounded-lg text-slate-300 hover:bg-slate-800/80 hover:text-white transition cursor-pointer"
            >
                <UserRound className="w-5 h-5" />
                <span className="hidden xl:inline max-w-40 truncate text-sm">{name}</span>
                <ChevronDown className="w-4 h-4" />
            </button>
            {open && (
                <div role="menu" className="absolute right-0 mt-2 w-64 max-w-[calc(100vw-2rem)] rounded-xl border border-slate-700 bg-slate-900 p-2 shadow-2xl">
                    <div className="px-3 py-2 border-b border-slate-800 mb-1">
                        <div className="truncate text-sm font-medium text-slate-200">{name}</div>
                        <div className="truncate text-xs text-slate-500">{email}</div>
                    </div>
                    <Link href="/accounts" role="menuitem" className={item} onClick={() => setOpen(false)}>
                        <Mail className="w-4 h-4" /> Contas
                    </Link>
                    <Link href="/settings/security" role="menuitem" className={item} onClick={() => setOpen(false)}>
                        <ShieldCheck className="w-4 h-4" /> Segurança
                    </Link>
                    <Link href="/logout" method="post" as="button" role="menuitem" className={item}>
                        <LogOut className="w-4 h-4" /> Sair
                    </Link>
                </div>
            )}
        </div>
    );
}

export default function AppLayout({ children, title }: AppLayoutProps) {
    const { auth, flash } = usePage().props;
    const url = usePage().url;
    const user = auth?.user;

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans">
            <Head title={title} />
            <header className="border-b border-slate-800 bg-slate-900/60 backdrop-blur sticky top-0 z-40">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-14 lg:h-16 flex items-center justify-between gap-4">
                    <Link href="/" className="flex items-center gap-2.5 min-w-0">
                        <div className="w-9 h-9 shrink-0 rounded-xl bg-indigo-600/20 border border-indigo-500/30 flex items-center justify-center text-indigo-400">
                            <Layers className="w-5 h-5" />
                        </div>
                        <span className="truncate font-bold text-lg tracking-tight bg-gradient-to-r from-indigo-400 via-sky-300 to-teal-300 bg-clip-text text-transparent">
                            Inbox Orchestrator
                        </span>
                    </Link>

                    <nav className="hidden lg:flex items-center gap-1" aria-label="Principal">
                        {NAV.map(({ href, label, icon: Icon, color }) => (
                            <Link
                                key={href}
                                href={href}
                                aria-current={isActive(url, href) ? 'page' : undefined}
                                className={`flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition ${isActive(url, href) ? 'bg-slate-800 text-white' : 'text-slate-400 hover:bg-slate-800/80 hover:text-slate-200'}`}
                            >
                                <Icon className={`w-4 h-4 ${color}`} />
                                <span className="whitespace-nowrap">{label}</span>
                            </Link>
                        ))}
                    </nav>

                    {user && <ProfileMenu name={user.name} email={user.email} />}
                </div>
            </header>

            {flash?.success && (
                <div className="max-w-7xl mx-auto px-4 mt-4 w-full">
                    <div className="bg-emerald-950/60 border border-emerald-500/40 rounded-xl p-3 flex items-center gap-2 text-sm text-emerald-300">
                        <CheckCircle2 className="w-4 h-4 shrink-0 text-emerald-400" />
                        <span className="min-w-0 break-words">{flash.success}</span>
                    </div>
                </div>
            )}
            {flash?.error && (
                <div className="max-w-7xl mx-auto px-4 mt-4 w-full">
                    <div className="bg-rose-950/60 border border-rose-500/40 rounded-xl p-3 flex items-center gap-2 text-sm text-rose-300">
                        <AlertCircle className="w-4 h-4 shrink-0 text-rose-400" />
                        <span className="min-w-0 break-words">{flash.error}</span>
                    </div>
                </div>
            )}

            <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
                {children}
            </main>

            {/* The bottom bar is fixed, so the footer keeps room for it on small screens. */}
            <footer className="border-t border-slate-800/60 bg-slate-900/30 py-4 pb-[calc(5rem+env(safe-area-inset-bottom))] lg:pb-4">
                <PublicLinks />
            </footer>

            <nav
                aria-label="Principal"
                className="lg:hidden fixed bottom-0 inset-x-0 z-40 border-t border-slate-800 bg-slate-900/95 backdrop-blur pb-[env(safe-area-inset-bottom)]"
            >
                <div className="grid grid-cols-4">
                    {NAV.map(({ href, label, icon: Icon, color }) => (
                        <Link
                            key={href}
                            href={href}
                            aria-current={isActive(url, href) ? 'page' : undefined}
                            className={`flex flex-col items-center justify-center gap-1 min-h-16 px-1 text-[11px] font-medium transition ${isActive(url, href) ? 'text-white' : 'text-slate-400 hover:text-slate-200'}`}
                        >
                            <Icon className={`w-5 h-5 ${isActive(url, href) ? color : ''}`} />
                            <span className="truncate max-w-full">{label}</span>
                        </Link>
                    ))}
                </div>
            </nav>
        </div>
    );
}
