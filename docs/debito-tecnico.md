# Débito técnico

Itens conhecidos que ficaram para depois. Cada um traz o impacto, onde está e uma sugestão de correção. Ao resolver um item, remova-o daqui no mesmo commit da correção.

| # | Item | Impacto | Prioridade |
|---|------|---------|------------|
| 11 | Novidades do Gmail por polling (5 min) em vez de notificações push | Desempenho / tempo real | Média (quando houver servidor público) |
| 19 | Regras sem busca livre nem filtros combinados | Funcionalidade | Baixa |

---

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
