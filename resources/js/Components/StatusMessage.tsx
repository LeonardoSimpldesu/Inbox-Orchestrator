interface StatusMessageProps {
    message: string | null | undefined;
}

export default function StatusMessage({ message }: StatusMessageProps) {
    if (!message) {
        return null;
    }

    return (
        <div role="status" className="mb-4 rounded-xl border border-emerald-500/40 bg-emerald-950/60 p-3 text-sm text-emerald-300">
            {message}
        </div>
    );
}
