import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';

export default function About() {
    return (
        <PublicLayout title="Sobre o Inbox Orchestrator">
            <p>
                O Inbox Orchestrator ajuda a manter a caixa de entrada do Gmail organizada. Ele lê apenas os
                metadados das mensagens (remetente, assunto, data e marcadores) para mostrar de onde vêm seus
                e-mails e automatizar a limpeza.
            </p>

            <section className="space-y-2">
                <h2 className="text-base font-semibold text-white">O que ele faz</h2>
                <ul className="list-disc pl-5 space-y-1">
                    <li>Lista as newsletters e e-mails promocionais e cancela a inscrição com um clique.</li>
                    <li>Aplica regras de retenção: arquiva ou move para a lixeira e-mails mais antigos que um prazo definido.</li>
                    <li>Organiza remetentes em categorias usando os marcadores do próprio Gmail.</li>
                </ul>
            </section>

            <section className="space-y-2">
                <h2 className="text-base font-semibold text-white">Quem pode usar</h2>
                <p>
                    É um sistema privado, de uso pessoal, com acesso apenas por convite. Não há cadastro público.
                </p>
            </section>

            <p>
                Saiba como os dados são tratados na{' '}
                <Link href="/privacidade" className="text-indigo-400 hover:text-indigo-300">
                    Política de Privacidade
                </Link>
                .
            </p>
        </PublicLayout>
    );
}
