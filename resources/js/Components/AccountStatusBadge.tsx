import type { EmailAccountSummary } from '@/types';
import { formatDate } from '@/lib/gmail';

export default function AccountStatusBadge({ account }: { account: EmailAccountSummary }) {
    if (account.status === 'needs_reauth') {
        return (
            <span className="text-xs text-rose-300 bg-rose-500/10 px-2 py-0.5 rounded-full border border-rose-500/30 whitespace-nowrap">
                Precisa reconectar
            </span>
        );
    }

    const tone = account.expiring_soon
        ? 'text-amber-300 bg-amber-500/10 border-amber-500/30'
        : 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20';

    return (
        <span className={`text-xs px-2 py-0.5 rounded-full border whitespace-nowrap ${tone}`}>
            Conectada
            {account.reconnect_by && <> &middot; reconecte até {formatDate(account.reconnect_by)}</>}
        </span>
    );
}
