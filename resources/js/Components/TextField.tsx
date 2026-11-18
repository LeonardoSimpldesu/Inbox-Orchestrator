import type { InputHTMLAttributes, ReactNode } from 'react';

interface TextFieldProps extends InputHTMLAttributes<HTMLInputElement> {
    id: string;
    label: string;
    error?: string | undefined;
    trailing?: ReactNode;
}

export default function TextField({ id, label, error, trailing, className = '', ...input }: TextFieldProps) {
    return (
        <div className={className}>
            <label htmlFor={id} className="block text-xs font-medium text-slate-300 mb-1.5">
                {label}
            </label>
            <div className="relative">
                <input
                    id={id}
                    name={id}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? `${id}-error` : undefined}
                    className={`w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2.5 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/60 focus:border-indigo-500 aria-[invalid]:border-rose-500/70 ${trailing ? 'pr-11' : ''}`}
                    {...input}
                />
                {trailing && <div className="absolute inset-y-0 right-0 flex items-center pr-1.5">{trailing}</div>}
            </div>
            {error && (
                <p id={`${id}-error`} className="mt-1.5 text-xs text-rose-400">
                    {error}
                </p>
            )}
        </div>
    );
}
