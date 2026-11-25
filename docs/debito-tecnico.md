# Débito técnico

Itens conhecidos que ficaram para depois. Cada um traz o impacto, onde está e uma sugestão de correção. Ao resolver um item, remova-o daqui no mesmo commit da correção.

| # | Item | Impacto | Prioridade |
|---|------|---------|------------|
| 7 | Acesso ao Gmail expira a cada 7 dias (app do Google em modo Teste) | UX / operação | Alta antes de uso diário |
| 11 | Novidades do Gmail por polling (5 min) em vez de notificações push | Desempenho / tempo real | Média (quando houver servidor público) |
| 19 | Regras sem busca livre nem filtros combinados | Funcionalidade | Baixa |
| 20 | Backup do banco só dentro da própria VPS | Operação / recuperação | Média |

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

## 11. Novidades do Gmail por polling em vez de notificações push

**Origem:** sincronização Gmail (2026-11-18).

**O que acontece:** as novidades são buscadas a cada 5 minutos (`inbox:sync`). O Gmail oferece notificações push, mas elas exigem uma URL pública com HTTPS, e o ambiente atual roda só em `127.0.0.1`.

**Onde:** `routes/console.php`, `EmailSyncService` (modo `Changes`).

**Sugestão:** quando o app estiver num servidor com domínio e HTTPS:
1. Criar um tópico no Google Cloud Pub/Sub e dar permissão de publicação a `gmail-api-push@system.gserviceaccount.com`.
2. Criar uma assinatura push apontando para uma rota nova (ex.: `POST /webhooks/gmail`), com autenticação OIDC.
3. Chamar `users.watch` para cada conta ao conectar, e renovar a cada 7 dias com um comando agendado.
4. Na rota, validar o token OIDC do Pub/Sub, achar a conta pelo `emailAddress` do aviso e despachar `SyncEmailAccountJob` com `SyncMode::Changes`. O polling pode continuar como rede de segurança, com intervalo maior.

## 19. Regras sem busca livre nem filtros combinados

**Origem:** Regras de expiração (2026-11-22). "Todas as contas" foi resolvido em 2026-11-23 (cria uma cópia pausada por conta).

**O que acontece:** cada regra tem um único filtro (categoria, domínio, remetente ou marcador) e não aceita a busca livre do Gmail (`query` do SPEC original). Para "Promoções **e** domínio X" não há como.

**Onde:** `RetentionRuleRequest`, `RuleQuery`, `retention_rules.filter_type`.

**Sugestão:** permitir uma lista de condições (E); a busca livre só com pré-visualização obrigatória e validação de operadores perigosos.

## 20. Backup do banco só dentro da própria VPS

**Origem:** publicação em produção (2026-11-25).

**O que acontece:** `deploy/backup.sh` guarda 14 dias de backups do SQLite em `/var/backups/inbox`, no mesmo disco do app. Se a VPS for perdida, os backups vão junto.

**Impacto:** o Gmail continua sendo a fonte da verdade. Perder o banco custa reconectar as contas, esperar a nova sincronização e recriar regras e categorias (as categorias são marcadores do Gmail e sobrevivem; as regras que as aplicam, não).

**Onde:** `deploy/backup.sh`, `deploy/cron.template`.

**Soluções:**
1. Snapshot automático da VPS, se a hospedagem oferecer.
2. Enviar o backup já criptografado (ex.: `age`) para um armazenamento externo (S3, Backblaze B2) com uma chave que não fique na VPS.

