import type { RetentionRuleRow, RuleAction, RuleCondition, RuleConditionType, RuleKind } from '@/types';

export const CATEGORY_LABELS: Record<string, string> = {
    promotions: 'Promoções',
    updates: 'Atualizações',
    social: 'Social',
    forums: 'Fóruns',
};

/** Same order as the backend's CONDITION_TYPES. */
export const CONDITION_TYPE_LABELS: Record<RuleConditionType, string> = {
    category: 'Categoria',
    label: 'Marcador',
    domain: 'Domínio',
    sender: 'Remetente',
    subject: 'Assunto',
};

export const MANAGED_BY: Partial<Record<RuleKind, string>> = {
    sender_category: 'Gerenciada pela página Remetentes',
    sender_cleanup: 'Gerenciada pela página Remetentes',
    hub_block: 'Gerenciada pelo Hub',
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

function conditionText(condition: RuleCondition): string {
    switch (condition.type) {
        case 'category':
            return CATEGORY_LABELS[condition.value] ?? condition.value;
        case 'label':
            return condition.label ?? condition.value;
        case 'subject':
            return `"${condition.value}"`;
        default:
            return condition.value;
    }
}

/** Types are all required (·); values of one type are alternatives (ou). */
export function describeConditions(conditions: RuleCondition[]): string {
    return (Object.keys(CONDITION_TYPE_LABELS) as RuleConditionType[])
        .map((type) => ({ type, group: conditions.filter((condition) => condition.type === type) }))
        .filter(({ group }) => group.length > 0)
        .map(({ type, group }) => `${CONDITION_TYPE_LABELS[type]}: ${group.map(conditionText).join(' ou ')}`)
        .join(' · ');
}

export function describeAction(rule: Pick<RetentionRuleRow, 'action' | 'target_label_name' | 'target_label' | 'archive_after_label'>): string {
    if (rule.action !== 'add_label') {
        return ACTION_LABELS[rule.action];
    }

    const label = `Marcar "${rule.target_label_name ?? rule.target_label ?? ''}"`;
    return rule.archive_after_label ? `${label} e arquivar` : label;
}
