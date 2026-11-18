import { useState, type ComponentProps } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import TextField from '@/Components/TextField';

type PasswordFieldProps = Omit<ComponentProps<typeof TextField>, 'type' | 'trailing'>;

export default function PasswordField(props: PasswordFieldProps) {
    const [visible, setVisible] = useState(false);
    const Icon = visible ? EyeOff : Eye;

    return (
        <TextField
            {...props}
            type={visible ? 'text' : 'password'}
            trailing={
                <button
                    type="button"
                    onClick={() => setVisible((current) => !current)}
                    aria-label={visible ? 'Ocultar senha' : 'Mostrar senha'}
                    aria-pressed={visible}
                    aria-controls={props.id}
                    title={visible ? 'Ocultar senha' : 'Mostrar senha'}
                    className="p-2 rounded-lg text-slate-400 hover:text-slate-200 hover:bg-slate-800/80 transition cursor-pointer"
                >
                    <Icon className="w-4 h-4" />
                </button>
            }
        />
    );
}
