export interface EmailAccountSummary {
    id: number;
    provider: 'gmail' | 'outlook';
    account_email: string;
    last_synced_at: string | null;
    is_active: boolean;
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
