# Débito técnico

Itens conhecidos que ficaram para depois. Cada um traz o impacto, onde está e uma sugestão de correção. Ao resolver um item, remova-o daqui no mesmo commit da correção.

| # | Item | Impacto | Prioridade |
|---|------|---------|------------|
| 11 | Novidades do Gmail por polling (5 min) em vez de notificações push | Desempenho / tempo real | Média (quando houver servidor público) |
| 21 | Busca livre do Gmail nas regras (opção futura) | Funcionalidade | Baixa |

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

## 21. Busca livre do Gmail nas regras (opção futura)

**Origem:** Regras com condições combinadas (2026-11-25). O dono escolheu só as condições estruturadas (caminho A) e deixou a busca livre registrada como opção.

**O que acontece:** as regras aceitam categoria, marcador, domínio, remetente e assunto, combinados com E (e OU dentro do mesmo tipo). Não há como usar outros operadores do Gmail (anexo, tamanho, palavra no corpo, `has:`, `larger:`).

**Onde:** `RetentionRuleRequest`, `RuleQuery`, `RetentionRule::CONDITION_TYPES`.

**Sugestão (caminho B):** um tipo de condição novo, "Busca avançada (Gmail)", que se combina com E com as outras condições:
1. O texto vai entre parênteses na busca, e as exclusões fixas (`-is:starred -in:sent -in:drafts`) continuam fora deles.
2. Recusar operadores que furam a proteção ou mudam o escopo: `in:anywhere`, `in:sent`, `is:starred`, `in:trash`, `in:spam`, `label:` (marcador é sempre por id), e parênteses, chaves ou aspas desbalanceados.
3. A simulação obrigatória antes de ativar já serve de pré-visualização.
