import { router } from '@inertiajs/react';

export function connectGmail(accountId?: number): void {
    router.post('/accounts/google/redirect', accountId === undefined ? {} : { account: accountId });
}

export function formatDate(isoDate: string): string {
    const [year, month, day] = isoDate.split('-');
    return `${day}/${month}/${year}`;
}
