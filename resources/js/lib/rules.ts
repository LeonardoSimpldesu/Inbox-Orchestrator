import type { RetentionRuleRow, RuleAction, RuleFilterType } from '@/types';

export const CATEGORY_LABELS: Record<string, string> = {
    promotions: 'Promoções',
    updates: 'Atualizações',
    social: 'Social',
    forums: 'Fóruns',
};

export const FILTER_TYPE_LABELS: Record<RuleFilterType, string> = {
    category: 'Categoria',
    domain: 'Domínio',
    sender: 'Remetente',
    label: 'Marcador',
};

export const ACTION_LABELS: Record<RuleAction, string> = {
    archive: 'Arquivar',
    trash: 'Mandar para a lixeira',
    add_label: 'Aplicar marcador',
};

export const RULE_ERROR_LABELS: Record<string, string> = {
    rate_limited: 'limite do Gmail atingido',
    reauth_required: 'reconecte a conta',
    label_missing: 'o marcador não existe mais (regra pausada)',
    account_unavailable: 'conta indisponível',
    request_failed: 'a execução foi interrompida',
};

export function ruleErrorLabel(code: string): string {
    return RULE_ERROR_LABELS[code] ?? (code.startsWith('http_') ? `o Gmail respondeu com erro ${code.slice(5)}` : code);
}

export function describeFilter(rule: Pick<RetentionRuleRow, 'filter_type' | 'filter_value' | 'filter_label'>): string {
    switch (rule.filter_type) {
        case 'category':
            return `Categoria: ${CATEGORY_LABELS[rule.filter_value] ?? rule.filter_value}`;
        case 'label':
            return `Marcador: ${rule.filter_label ?? rule.filter_value}`;
        case 'domain':
            return `Domínio: ${rule.filter_value}`;
        default:
            return `Remetente: ${rule.filter_value}`;
    }
}

export function describeAction(rule: Pick<RetentionRuleRow, 'action' | 'target_label_name' | 'target_label' | 'archive_after_label'>): string {
    if (rule.action !== 'add_label') {
        return ACTION_LABELS[rule.action];
    }

    const label = `Marcar "${rule.target_label_name ?? rule.target_label ?? ''}"`;
    return rule.archive_after_label ? `${label} e arquivar` : label;
}
