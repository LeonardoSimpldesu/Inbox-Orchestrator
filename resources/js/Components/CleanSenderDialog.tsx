import { useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import PrimaryButton from '@/Components/PrimaryButton';

interface CleanSenderDialogProps {
    ids: number[];
    description: string;
    onClose: () => void;
    onDone?: () => void;
}

export default function CleanSenderDialog({ ids, description, onClose, onDone }: CleanSenderDialogProps) {
    const [cleanup, setCleanup] = useState<'archive' | 'trash'>('archive');
    const [future, setFuture] = useState(false);
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setProcessing(true);
        router.post('/senders/clean', { ids, cleanup, future }, {
            preserveScroll: true,
            onSuccess: () => {
                onDone?.();
                onClose();
            },
            onFinish: () => setProcessing(false),
        });
    };

    const radio = 'h-4 w-4 accent-indigo-500';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4" role="dialog" aria-modal="true" aria-labelledby="clean-title">
            <form onSubmit={submit} className="w-full max-w-md rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl space-y-4">
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <h2 id="clean-title" className="text-lg font-semibold text-white">Limpar e-mails</h2>
                        <p className="mt-1 truncate text-sm text-slate-400">{description}</p>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Fechar" className="text-slate-400 hover:text-white cursor-pointer"><X className="w-5 h-5" /></button>
                </div>

                <fieldset className="space-y-2">
                    <legend className="text-xs font-semibold uppercase tracking-wider text-slate-400">E-mails existentes</legend>
                    <label className="flex items-center gap-2 text-sm text-slate-200">
                        <input type="radio" name="cleanup" className={radio} checked={cleanup === 'archive'} onChange={() => setCleanup('archive')} />
                        Arquivar
                    </label>
                    <label className="flex items-center gap-2 text-sm text-slate-200">
                        <input type="radio" name="cleanup" className={radio} checked={cleanup === 'trash'} onChange={() => setCleanup('trash')} />
                        Mandar para a lixeira (recuperáveis por 30 dias no Gmail)
                    </label>
                    <p className="text-xs text-slate-500">E-mails com estrela nunca são tocados.</p>
                </fieldset>

                <label className="flex items-center gap-2 text-sm text-slate-200">
                    <input type="checkbox" className={radio} checked={future} onChange={(event) => setFuture(event.target.checked)} />
                    Fazer o mesmo com os próximos e-mails (cria uma regra)
                </label>

                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-slate-300 hover:text-white cursor-pointer">Cancelar</button>
                    <PrimaryButton type="submit" className="w-auto" disabled={processing}>Limpar</PrimaryButton>
                </div>
            </form>
        </div>
    );
}
