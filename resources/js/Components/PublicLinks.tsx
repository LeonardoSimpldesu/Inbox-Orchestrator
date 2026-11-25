import { Link } from '@inertiajs/react';

export default function PublicLinks() {
    return (
        <nav className="flex items-center justify-center gap-3 text-xs text-slate-500">
            <Link href="/sobre" className="hover:text-slate-300">
                Sobre
            </Link>
            <span aria-hidden="true">&bull;</span>
            <Link href="/privacidade" className="hover:text-slate-300">
                Política de Privacidade
            </Link>
        </nav>
    );
}
