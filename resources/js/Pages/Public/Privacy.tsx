import type { ReactNode } from 'react';
import PublicLayout from '@/Layouts/PublicLayout';
import { formatDate } from '@/lib/dates';
import type { PrivacyPageProps } from '@/types';

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-2">
            <h2 className="text-base font-semibold text-white">{title}</h2>
            {children}
        </section>
    );
}

export default function Privacy({ controllerName, contactEmail, updatedAt, disconnectedRetentionDays }: PrivacyPageProps) {
    return (
        <PublicLayout title="Política de Privacidade">
            <p className="text-xs text-slate-500">Última atualização: {formatDate(updatedAt)}</p>

            <p>
                Esta política explica quais dados o Inbox Orchestrator usa, para quê e por quanto tempo. O sistema é
                privado, de uso pessoal, e só é acessado por pessoas convidadas
                {controllerName ? <> pelo responsável, {controllerName}</> : null}.
            </p>

            <Section title="Dados que guardamos">
                <ul className="list-disc pl-5 space-y-1">
                    <li>Da sua conta no sistema: nome, e-mail, senha (guardada apenas como hash) e, se ativada, a configuração da verificação em duas etapas.</li>
                    <li>Da sessão: endereço IP e navegador, para manter você conectado com segurança.</li>
                    <li>Da conta Google conectada: o endereço do Gmail e os tokens de acesso do Google, guardados criptografados.</li>
                    <li>
                        Das mensagens: apenas metadados, ou seja, remetente, assunto, data, marcadores e os cabeçalhos de
                        descadastro (<code>List-Unsubscribe</code>). A partir deles calculamos estatísticas por remetente.
                    </li>
                    <li>As regras de retenção e categorias que você cria e o histórico das execuções.</li>
                </ul>
                <p>
                    <strong className="text-white">Nunca</strong> lemos nem guardamos o corpo dos e-mails ou anexos.
                </p>
            </Section>

            <Section title="Por que pedimos acesso ao Gmail">
                <ul className="list-disc pl-5 space-y-1">
                    <li>
                        <code>gmail.modify</code>: ler os metadados das mensagens, aplicar marcadores e arquivar ou mover
                        para a lixeira os e-mails que você escolher ou que suas regras indicarem.
                    </li>
                    <li>
                        <code>gmail.send</code>: enviar, em seu nome, o e-mail de cancelamento de inscrição quando a
                        newsletter só aceita descadastro por e-mail.
                    </li>
                </ul>
                <p>
                    O uso e a transferência de informações recebidas das APIs do Google obedecem à{' '}
                    <a
                        href="https://developers.google.com/terms/api-services-user-data-policy"
                        className="text-indigo-400 hover:text-indigo-300"
                        rel="noreferrer"
                        target="_blank"
                    >
                        Política de Dados do Usuário dos Serviços de API do Google
                    </a>
                    , incluindo os requisitos de Uso Limitado.
                </p>
            </Section>

            <Section title="Compartilhamento">
                <p>
                    Não vendemos, não compartilhamos e não usamos seus dados para publicidade ou para treinar modelos
                    de inteligência artificial. Pessoas não leem seus dados, exceto com sua autorização, por motivo de
                    segurança ou por obrigação legal. Os únicos fornecedores envolvidos são a hospedagem do servidor e
                    o serviço de envio de e-mails (Resend), usado apenas para mensagens do próprio sistema, como a
                    redefinição de senha.
                </p>
            </Section>

            <Section title="Retenção e exclusão">
                <ul className="list-disc pl-5 space-y-1">
                    <li>
                        Ao desconectar uma conta Google, o acesso é revogado no Google na hora e os dados dessa conta são
                        apagados em até {disconnectedRetentionDays} dias.
                    </li>
                    <li>Você também pode revogar o acesso a qualquer momento em myaccount.google.com/permissions.</li>
                    <li>A exclusão do seu usuário, com todos os dados associados, pode ser pedida pelo contato abaixo.</li>
                </ul>
            </Section>

            <Section title="Seus direitos">
                <p>
                    Conforme a Lei Geral de Proteção de Dados (LGPD), você pode pedir confirmação do tratamento, acesso,
                    correção, portabilidade e exclusão dos seus dados, além de revogar o consentimento.
                </p>
            </Section>

            {contactEmail && (
                <Section title="Contato">
                    <p>
                        Dúvidas ou pedidos sobre seus dados:{' '}
                        <a href={`mailto:${contactEmail}`} className="text-indigo-400 hover:text-indigo-300">
                            {contactEmail}
                        </a>
                        .
                    </p>
                </Section>
            )}
        </PublicLayout>
    );
}
