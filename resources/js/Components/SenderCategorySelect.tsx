import type { GmailLabel } from '@/types';

export const NEW_LABEL = '__new__';
export const NO_CATEGORY = '';

interface SenderCategorySelectProps {
    value: string | null;
    currentName: string | null;
    labels: GmailLabel[] | undefined;
    disabled?: boolean;
    ariaLabel: string;
    onOpen: () => void;
    onChoose: (labelId: string | null) => void;
    onCreate: () => void;
}

/**
 * Labels load on first focus (they belong to the sender's account); until then
 * only the current category is listed.
 */
export default function SenderCategorySelect({ value, currentName, labels, disabled, ariaLabel, onOpen, onChoose, onCreate }: SenderCategorySelectProps) {
    const options = labels ?? (value && currentName ? [{ id: value, name: currentName }] : []);

    return (
        <select
            aria-label={ariaLabel}
            className="w-36 rounded-lg border border-slate-700 bg-slate-950 px-2 py-1.5 text-xs text-slate-200 disabled:opacity-60"
            value={value ?? NO_CATEGORY}
            disabled={disabled}
            onFocus={onOpen}
            onMouseDown={onOpen}
            onChange={(event) => {
                if (event.target.value === NEW_LABEL) {
                    onCreate();
                    return;
                }
                onChoose(event.target.value === NO_CATEGORY ? null : event.target.value);
            }}
        >
            <option value={NO_CATEGORY}>— sem categoria —</option>
            {options.map((label) => <option key={label.id} value={label.id}>{label.name}</option>)}
            <option value={NEW_LABEL}>+ Criar marcador…</option>
        </select>
    );
}
