import { router } from '@inertiajs/react';

export function connectGmail(accountId?: number): void {
    router.post('/accounts/google/redirect', accountId === undefined ? {} : { account: accountId });
}

export function importHistory(accountId: number): void {
    router.post(`/accounts/${accountId}/import-history`, {}, { preserveScroll: true });
}

export function syncAllAccounts(): void {
    router.post('/accounts/sync', {}, { preserveScroll: true });
}

export function formatDate(isoDate: string): string {
    const [year, month, day] = isoDate.slice(0, 10).split('-');
    return `${day}/${month}/${year}`;
}

export function timeAgo(isoDateTime: string, now: Date = new Date()): string {
    const minutes = Math.floor((now.getTime() - new Date(isoDateTime).getTime()) / 60_000);

    if (minutes < 1) return 'agora';
    if (minutes < 60) return `há ${minutes} min`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `há ${hours} h`;

    const days = Math.floor(hours / 24);
    return days === 1 ? 'há 1 dia' : `há ${days} dias`;
}
