import type { EmailAccountSummary } from '@/types';
import { formatDate, timeAgo } from '@/lib/gmail';

export default function AccountSyncProgress({ sync }: { sync: EmailAccountSummary['sync'] }) {
    const synced = sync.last_synced_at ? ` · sincronizado ${timeAgo(sync.last_synced_at)}` : '';

    if (sync.state === 'importing_recent') {
        return <p className="text-xs text-sky-300">Importando e-mails recentes…</p>;
    }

    if (sync.state === 'complete') {
        return <p className="text-xs text-slate-400">Histórico completo{synced}</p>;
    }

    return (
        <p className="text-xs text-slate-400">
            {sync.oldest_synced_at ? `Histórico importado até ${formatDate(sync.oldest_synced_at)}` : 'Importando histórico'}
            {synced}
        </p>
    );
}
