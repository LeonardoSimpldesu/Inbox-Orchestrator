export interface EmailAccountSummary {
    id: number;
    provider: 'gmail' | 'outlook';
    account_email: string;
    last_synced_at: string | null;
    status: 'connected' | 'needs_reauth';
    reconnect_by: string | null;
    expiring_soon: boolean;
    sync: {
        state: 'importing_recent' | 'importing_history' | 'complete';
        oldest_synced_at: string | null;
        last_synced_at: string | null;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    two_factor_enabled: boolean;
    accounts: EmailAccountSummary[];
}

export interface RetentionRuleSummary {
    id: number;
    name: string;
    filter_type: 'category' | 'domain' | 'sender' | 'query';
    filter_value: string;
    retention_days: number;
    action: 'archive' | 'trash' | 'add_label';
    is_active: boolean;
}

export interface Flash {
    success: string | null;
    error: string | null;
}

export type SharedProps = {
    auth: { user: User | null };
    flash: Flash;
    status?: string | null;
};

export type UnsubscribeMethod = 'one_click' | 'mailto' | 'page' | 'rule';

export interface SubscriptionRow {
    id: number;
    name: string | null;
    email: string;
    domain: string;
    account_email: string;
    total: number;
    unread: number;
    last_received_at: string | null;
    status: 'none' | 'processing' | 'requested' | 'blocked' | 'failed';
    method: UnsubscribeMethod | null;
    error: string | null;
    requested_at: string | null;
    emails_after_request: number;
    options: { one_click: boolean; mailto: boolean; page: boolean };
    gmail_url: string;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
}
