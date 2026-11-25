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
        manual_import: boolean;
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

export type RuleFilterType = 'category' | 'domain' | 'sender' | 'label';
export type RuleAction = 'archive' | 'trash' | 'add_label';

export interface RuleLog {
    status: 'success' | 'simulated' | 'failed';
    affected: number;
    error: string | null;
    executed_at: string | null;
}

export interface RetentionRuleRow {
    id: number;
    name: string;
    email_account_id: number;
    account_email: string;
    filter_type: RuleFilterType;
    filter_value: string;
    filter_label: string | null;
    retention_days: number;
    action: RuleAction;
    target_label: string | null;
    target_label_name: string | null;
    archive_after_label: boolean;
    is_active: boolean;
    can_activate: boolean;
    last_run_at: string | null;
    logs: RuleLog[];
}

export interface RuleSimulation {
    rule_id: number;
    total: number;
    capped: boolean;
    sample: { sender: string; subject: string | null; date: string }[];
}

export interface GmailLabel {
    id: string;
    name: string;
}

export interface SenderRow {
    id: number;
    name: string | null;
    email: string;
    domain: string;
    account_id: number;
    account_email: string;
    total: number;
    unread: number;
    last_received_at: string | null;
    is_subscription: boolean;
    category: { label_id: string; name: string } | null;
    applying: boolean;
    gmail_url: string;
}

export interface PrivacyPageProps {
    controllerName: string | null;
    contactEmail: string | null;
    updatedAt: string;
    disconnectedRetentionDays: number;
}
