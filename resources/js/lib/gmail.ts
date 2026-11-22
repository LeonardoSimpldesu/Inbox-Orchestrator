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

export { formatDate, timeAgo } from './dates';
