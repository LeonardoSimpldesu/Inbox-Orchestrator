import type { ButtonHTMLAttributes } from 'react';
import { twMerge } from 'tailwind-merge';

export default function PrimaryButton({ className = '', children, ...button }: ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button
            className={twMerge(
                'w-full inline-flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-lg shadow-indigo-600/20 transition cursor-pointer',
                className,
            )}
            {...button}
        >
            {children}
        </button>
    );
}
