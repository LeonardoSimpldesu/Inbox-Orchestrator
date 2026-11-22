# Débito técnico

Itens conhecidos que ficaram para depois. Cada um traz o impacto, onde está e uma sugestão de correção. Ao resolver um item, remova-o daqui no mesmo commit da correção.

| # | Item | Impacto | Prioridade |
|---|------|---------|------------|
| 7 | Acesso ao Gmail expira a cada 7 dias (app do Google em modo Teste) | UX / operação | Alta antes de uso diário |
| 8 | Contas Gmail desconectadas guardadas para sempre | Privacidade / limpeza | Média |
| 11 | Novidades do Gmail por polling (5 min) em vez de notificações push | Desempenho / tempo real | Média (quando houver servidor público) |
| 16 | Hub de Assinaturas sem ações em massa nem busca | UX | Média |
| 17 | Remetente "Tratado" não pode ser refeito nem desfeito pelo Hub | UX | Baixa |
| 19 | Regras sem busca livre, filtros combinados ou regras para todas as contas | Funcionalidade | Baixa |

---

## 7. Acesso ao Gmail expira a cada 7 dias

**Origem:** conexão Gmail (2026-11-18).

**O que acontece:** o app OAuth do Google é "Externo" e está em modo **Teste**. Nesse modo o Google expira o refresh token 7 dias depois da autorização, e só testadores cadastrados conseguem conectar. O app mostra "Reconecte até dd/mm" e marca a conta como "Precisa reconectar" (`GOOGLE_OAUTH_TESTING=true`), mas cada pessoa precisa reconectar toda semana.

**Onde:** configuração do projeto no Google Cloud Console; `config/services.php` (`google.testing`); `EmailAccount::reconnectBy()`.

**Soluções:**
1. **Publicar o app em Produção sem verificação (recomendada para uso pessoal).** Em "OAuth consent screen", clicar em "Publish app". Os refresh tokens deixam de expirar por semana. Custos: a pessoa vê a tela "O Google não verificou este app" e precisa clicar em "Avançado > Acessar"; apps não verificados com escopos restritos têm limite de 100 usuários. Depois, trocar `GOOGLE_OAUTH_TESTING=false`.
2. **Verificação completa do Google.** Exige política de privacidade, domínio verificado, vídeo de demonstração e, para escopos restritos (`gmail.modify`, `gmail.send`), a avaliação de segurança CASA anual, que é paga. Só compensa se o app for aberto ao público.
3. **Google Workspace com app "Interno".** Sem expiração e sem verificação, mas só aceita contas do próprio domínio Workspace. Não serve para contas @gmail.com.

Mesmo sem a expiração semanal, o refresh token ainda pode ser invalidado (senha trocada, acesso revogado pela pessoa, 6 meses sem uso). Esse caso já é tratado: `reauth_required_at` + "Precisa reconectar".

## 8. Contas Gmail desconectadas guardadas para sempre

**Origem:** conexão Gmail (2026-11-18).

**O que acontece:** desconectar faz soft delete. Remetentes, metadados e regras ficam guardados para quando a pessoa reconectar, sem prazo de exclusão.

**Onde:** `EmailAccountController::destroy`, `email_accounts.deleted_at`.

**Sugestão:** comando agendado (ex.: `inbox:purge-disconnected-accounts`, diário) que faz `forceDelete()` das contas desconectadas há mais de N dias (ex.: 90; configurável), apagando em cascata os dados ligados. Mostrar o prazo na mensagem de desconexão.

## 11. Novidades do Gmail por polling em vez de notificações push

**Origem:** sincronização Gmail (2026-11-18).

**O que acontece:** as novidades são buscadas a cada 5 minutos (`inbox:sync`). O Gmail oferece notificações push, mas elas exigem uma URL pública com HTTPS, e o ambiente atual roda só em `127.0.0.1`.

**Onde:** `routes/console.php`, `EmailSyncService` (modo `Changes`).

**Sugestão:** quando o app estiver num servidor com domínio e HTTPS:
1. Criar um tópico no Google Cloud Pub/Sub e dar permissão de publicação a `gmail-api-push@system.gserviceaccount.com`.
2. Criar uma assinatura push apontando para uma rota nova (ex.: `POST /webhooks/gmail`), com autenticação OIDC.
3. Chamar `users.watch` para cada conta ao conectar, e renovar a cada 7 dias com um comando agendado.
4. Na rota, validar o token OIDC do Pub/Sub, achar a conta pelo `emailAddress` do aviso e despachar `SyncEmailAccountJob` com `SyncMode::Changes`. O polling pode continuar como rede de segurança, com intervalo maior.

## 16. Hub de Assinaturas sem ações em massa nem busca

**Origem:** Hub de Assinaturas (2026-11-19).

**O que acontece:** cada desinscrição é feita remetente a remetente, e não há busca por nome ou domínio. Com 150 assinaturas, tratar tudo exige muitos cliques e paginação.

**Onde:** `SubscriptionController`, `resources/js/Pages/Subscriptions/Index.tsx`.

**Sugestão:** caixa de busca (`name`/`email`/`domain` com `like`, mantendo consultas constantes) e seleção múltipla com "Desinscrever selecionados" despachando um `UnsubscribeSenderJob` por remetente, com o mesmo limite de ações por minuto.

## 17. Remetente "Tratado" não pode ser refeito nem desfeito pelo Hub

**Origem:** Hub de Assinaturas (2026-11-19).

**O que acontece:** depois de "Desinscrição pedida" ou "Regra criada", o remetente vai para a aba Tratados, que não tem botões. Se o remetente continuar mandando ("Ainda mandou N e-mails depois do pedido"), não dá para tentar outro método nem voltar o remetente para Pendentes.

**Onde:** `resources/js/Pages/Subscriptions/Index.tsx`, `SubscriptionController::unsubscribe`.

**Sugestão:** na aba Tratados, botão "Tentar de novo" (abre o mesmo diálogo) e "Voltar para pendentes" (status `none`, sem apagar a regra).

## 19. Regras sem busca livre, filtros combinados ou regras para todas as contas

**Origem:** Regras de expiração (2026-11-22).

**O que acontece:** cada regra tem um único filtro (categoria, domínio, remetente ou marcador), vale para uma conta e não aceita a busca livre do Gmail (`query` do SPEC original). Para "Promoções **e** domínio X" ou a mesma regra nas duas contas, é preciso criar várias regras ou não dá.

**Onde:** `RetentionRuleRequest`, `RuleQuery`, `retention_rules.filter_type`.

**Sugestão:** permitir uma lista de condições (E) e um seletor "todas as contas" que crie uma regra por conta; a busca livre só com pré-visualização obrigatória e validação de operadores perigosos.
