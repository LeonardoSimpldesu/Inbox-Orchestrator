# Design: Conexão de contas Gmail (OAuth 2.0)

Status: aprovado · Data: 2026-11-18 · SPEC: seções 3.2, 4.1 e 6 (Fase 1)

## 1. Intenção

Cada usuário do Inbox Orchestrator precisa ligar uma ou mais contas Gmail pessoais ao app para que as próximas etapas da Fase 1 (sync de metadados, Hub de Assinaturas, regras TTL) tenham onde trabalhar. Esta etapa entrega a conexão: autorizar no Google, guardar os tokens com segurança, mostrar o estado de cada conta, reconectar e desconectar.

## 2. Objetivos, não-objetivos e anti-objetivos

**Objetivos**
- Tela "Contas" para conectar, reconectar e desconectar contas Gmail.
- Tokens criptografados, nunca enviados ao frontend, a logs ou a filas.
- Deixar visível quando uma conta precisa ser reconectada (o app no Google ficará em modo Teste, onde o acesso expira a cada 7 dias).
- Disparar o job de sincronização inicial ao conectar (o job existe, mas a lógica de sync é a próxima etapa).

**Não-objetivos (nesta etapa)**
- Sincronizar metadados, remetentes ou aplicar regras.
- Outlook (Fase 3).
- Excluir de vez contas desconectadas após um prazo (vai para o débito técnico).

**Anti-objetivos (seria falha mesmo funcionando)**
- Um usuário ver dados de um Gmail conectado por outro usuário.
- Salvar uma conta sem as permissões necessárias, que falharia depois em silêncio.
- Desconectar uma conta e o app continuar com acesso ao Gmail.

## 3. Restrições

- Contas Gmail pessoais (@gmail.com). App OAuth do Google do tipo "Externo", em modo **Teste**: só e-mails cadastrados como testadores conseguem autorizar, e o refresh token **expira em 7 dias**.
- Escopos: `https://www.googleapis.com/auth/gmail.modify` e `https://www.googleapis.com/auth/gmail.send`. O `gmail.readonly` sai, porque o `modify` já permite leitura (decisão do dono). O SPEC 4.1 é atualizado.
- `access_type=offline`, `prompt=consent`, `include_granted_scopes=false`.
- Regras já vigentes: consultas partem do usuário autenticado; jobs ignoram contas de usuários desativados; tokens são `encrypted` + `$hidden`; código em inglês, textos em pt-BR; TDD com 100% de cobertura de `app/`; sem subagentes.

## 4. Abordagens consideradas

| | Abordagem | Prós | Contras |
|---|---|---|---|
| **A (escolhida)** | Só `google/apiclient` (já instalado) | Uma biblioteca para OAuth, renovação de token e, nas próximas etapas, chamadas ao Gmail; é a indicada no SPEC | API antiga e verbosa; fica escondida atrás de uma classe nossa |
| B | Laravel Socialite + `google/apiclient` | Redirect/callback mais enxutos | Duas bibliotecas cuidando dos mesmos tokens; dependência extra |
| C | HTTP direto com o client do Laravel | Sem dependência, fácil de testar | Reimplementar renovação, lotes e formatos do Gmail |

Escolhida **A** pelo dono: é a que já está no projeto e a única que serve também às etapas seguintes.

## 5. Decisões do dono

1. Remover `gmail.readonly`.
2. Contas Gmail pessoais; mostrar o aviso de expiração semanal na interface; registrar no débito técnico as soluções definitivas para a expiração de 7 dias.
3. **Desconectar = desativar com soft delete**: o acesso é revogado no Google, mas a conta, os remetentes e as regras ficam guardados. Reconectar a mesma conta pelo mesmo usuário traz tudo de volta. Exclusão definitiva após N dias desconectada (ex.: 90) fica para o futuro (débito técnico).
4. **Um Gmail pertence a um único usuário** por vez. O usuário pode desvincular a conta dele.
5. Aprovadas as propostas: tela própria "Contas" no menu; status e "Reconecte até dd/mm" visíveis, ligados por `GOOGLE_OAUTH_TESTING`; conta marcada como "Precisa reconectar" quando o Google recusar a renovação, com aviso no dashboard; recusar conexão com permissões incompletas; e-mail lido pela API do Gmail; `state` na sessão; reconectar atualiza a mesma conta.

## 6. Design

### 6.1 Configuração

- `config/services.php` → chave `google`: `client_id`, `client_secret`, `redirect` (`GOOGLE_REDIRECT_URI`, padrão `${APP_URL}/accounts/google/callback`), `testing` (`GOOGLE_OAUTH_TESTING`, bool, padrão `true`).
- `.env.example` e `.env.production.example`: as quatro variáveis, com um passo a passo curto de como criar o OAuth Client no Google Cloud (Web application, URI de redirecionamento, tela de consentimento em Teste com os testadores, escopos acima).
- README: seção "Conectar o Gmail" com o mesmo passo a passo.

### 6.2 Banco de dados

Nova migration em `email_accounts`:
- `+ deleted_at` (soft delete = desconectada).
- `+ authorized_at` (timestamp): quando o Google emitiu o refresh token atual (conexão ou reconexão). Base para "Reconecte até".
- `+ reauth_required_at` (timestamp, nullable): preenchido quando o Google recusa a renovação (`invalid_grant`). Zerado ao reconectar.
- `- is_active`: sai. O estado da conta passa a ser derivado (6.4).
- A unique `(user_id, account_email)` continua. A regra "um Gmail ativo por vez em todo o sistema" é checada na aplicação (desconectadas não contam), dentro de uma transação.

`EmailAccount` ganha `SoftDeletes` e os casts novos. Como `hasManyThrough` respeita o soft delete do modelo intermediário, `User::senders()` e `retentionRules()` passam a ignorar contas desconectadas sem mudanças.

### 6.3 Camada do provedor

- `App\Contracts\EmailProviderInterface` (só o que esta etapa usa):
  - `authorizationUrl(string $state, ?string $loginHint = null): string`
  - `exchangeCode(string $code): OAuthGrant`
  - `refreshAccessToken(EmailAccount $account): string`: renova se `token_expires_at` passou (com folga de 60 s), salva e devolve o access token. Em `invalid_grant`, preenche `reauth_required_at` e lança `ReauthorizationRequired`.
  - `revoke(EmailAccount $account): void`
- `App\Services\Providers\OAuthGrant` (readonly DTO): `accessToken`, `refreshToken` (nullable), `expiresAt`, `grantedScopes` (array), `email` (lido de `users.getProfile` do Gmail com o access token recém-emitido).
- `App\Services\Providers\GmailProviderService` implementa a interface com `Google\Client`. É ligado no container, para os testes trocarem por um fake. A classe real é testada com o HTTP client do Google apontado para um `MockHandler` do Guzzle.
- `App\Exceptions\ReauthorizationRequired`.

### 6.4 Estado da conta

Calculado no modelo (`EmailAccount::status()`), exposto ao frontend:
- `needs_reauth`: `reauth_required_at` preenchido, **ou** (`services.google.testing` e `authorized_at + 7 dias` já passou).
- `connected`: caso contrário.
- `reconnect_by` (data, só quando `testing`): `authorized_at + 7 dias`.
- `expiring_soon`: `testing`, conectada e faltam ≤ 2 dias para `reconnect_by`.

### 6.5 Rotas (grupo `['auth','auth.session','active']`)

| Método | URL | Ação |
|---|---|---|
| GET | `/accounts` | `EmailAccountController@index`: página `Accounts/Index` |
| POST | `/accounts/google/redirect` | gera `state` (aleatório, 40 chars) na sessão e responde `Inertia::location()` para o Google; aceita `account` (id de uma conta do usuário) para usar o e-mail dela como `login_hint` |
| GET | `/accounts/google/callback` | fluxo 6.6 |
| DELETE | `/accounts/{account}` | desconecta; a conta é buscada em `$request->user()->emailAccounts()` (404 para conta de outro usuário) |

### 6.6 Callback

1. `state` ausente ou diferente do guardado na sessão (comparação em tempo constante; sempre removido da sessão) → redireciona para `/accounts` com erro "Não foi possível validar a resposta do Google. Tente novamente."
2. `error=access_denied` → "Conexão cancelada."; outro `error` → mensagem genérica de falha.
3. `exchangeCode`. Falha do Google → mensagem genérica; o detalhe vai para o log **sem tokens nem o `code`**.
4. Escopos concedidos sem `gmail.modify` ou `gmail.send` → "Para funcionar, o Inbox Orchestrator precisa de todas as permissões pedidas. Conecte de novo e marque todas as opções."
5. Sem `refresh_token` → mesma mensagem genérica de falha.
6. E-mail (minúsculo) já **ativo** em outro usuário → "Esta conta Gmail já está conectada por outro usuário." Os tokens recebidos são descartados **sem revogar**: a revogação derrubaria o acesso do outro usuário, porque o grant é o mesmo (mesmo cliente OAuth, mesma conta Google).
7. Caso contrário, em transação: procura `(user_id, account_email)` **incluindo desconectadas**. Se existir, restaura e atualiza tokens, `authorized_at = now`, `reauth_required_at = null`. Se não, cria. Despacha `SyncEmailAccountJob` e volta para `/accounts` com sucesso "Conta {email} conectada." (ou "reconectada").

Nos passos 4 e 5 os tokens também são descartados sem revogar, pela mesma razão do passo 6.

"Reconectar" é o mesmo fluxo com `login_hint`. Se a pessoa escolher outra conta Google na tela do Google, essa outra conta é conectada normalmente, e a original continua como estava. (Decisão minha: é o comportamento mais previsível e não precisa de tratamento especial.) Se o Google devolver um refresh token novo em qualquer renovação, ele substitui o guardado.

### 6.7 Desconectar

`DELETE /accounts/{account}`: chama `revoke` (falha de rede ou token já inválido não impede a desconexão; vira um `warning` no log, sem tokens), soft delete, sucesso "Conta {email} desconectada. Os dados ficam guardados se você reconectar." O frontend pede confirmação antes.

### 6.8 Job

`App\Jobs\SyncEmailAccountJob(int $accountId)`: carrega a conta (sem as desconectadas) e retorna sem fazer nada se ela não existir, se estiver desconectada, se o usuário estiver desativado ou se a conta estiver `needs_reauth`. A sincronização em si é a próxima etapa. Recebe o id, não o modelo, para não serializar a conta na fila.

### 6.9 Frontend

- Menu do `AppLayout`: "Contas" (ícone `Mail`), antes de "Segurança".
- `Accounts/Index.tsx`:
  ```
  Contas Gmail                                   [Conectar conta Gmail]
  ┌─────────────────────────────────────────────────────────────────┐
  │ voce@gmail.com   ● Conectada · reconecte até 25/11             │
  │                                  [Reconectar] [Desconectar]    │
  │ outra@gmail.com  ▲ Precisa reconectar                          │
  │                                  [Reconectar] [Desconectar]    │
  └─────────────────────────────────────────────────────────────────┘
  ```
  Estado vazio com explicação e o botão de conectar. Com `testing`, uma nota explica por que o Google pede reconexão semanal. Mensagens de sucesso e erro via `flash`.
- Dashboard: um aviso com link para "Contas" se alguma conta estiver `needs_reauth` ou `expiring_soon`.
- Props: `{ id, provider, account_email, last_synced_at, status, reconnect_by, expiring_soon }`. Nunca tokens, `history_id`, `user_id` ou timestamps internos. Os tipos ficam em `resources/js/types/index.d.ts`.
- Shared props (`auth.user.accounts`) e `DashboardController` deixam de usar `is_active`, listam só contas não desconectadas e usam o mesmo formato acima.

### 6.10 Documentos

- SPEC: 3.2 (`deleted_at`, `authorized_at`, `reauth_required_at`, sem `is_active`), 4.1 (escopos, desconectar = soft delete, um Gmail por usuário), 6 (item marcado ✅).
- `docs/debito-tecnico.md`:
  - expiração de 7 dias, com as soluções: publicar o app em Produção sem verificação (tela "app não verificado", limite de 100 usuários; o refresh token deixa de expirar por semana); verificação completa do Google com avaliação de segurança CASA para escopos restritos (cara, só faz sentido se o app abrir para o público); Workspace com app "Interno" (não serve para @gmail.com);
  - exclusão definitiva de contas desconectadas após N dias (ex.: 90), com comando agendado.
- README e CLAUDE.md: nova seção e notas de arquitetura.

## 7. Testes esperados

- **Redirect**: monta a URL com os dois escopos, `offline`, `consent` e o `state` da sessão; `login_hint` só para conta do próprio usuário; visitante vai para o login.
- **Callback**: `state` ausente, errado e reaproveitado; `access_denied`; erro na troca do `code`; escopos faltando; sem refresh token; Gmail ativo em outro usuário (sem revogar); criação; reconexão restaura conta desconectada e zera `reauth_required_at`; Gmail desconectado por A e conectado por B gera conta nova para B sem os dados de A; job despachado; e-mail normalizado para minúsculas.
- **Desconectar**: revoga e faz soft delete; falha na revogação ainda desconecta; conta de outro usuário dá 404; remetentes e regras somem do dashboard.
- **Estado**: `connected`, `needs_reauth` por `reauth_required_at`, `needs_reauth` por prazo vencido em modo teste, nada de prazo com `testing=false`, `expiring_soon`.
- **Provedor real** (Guzzle `MockHandler`): URL de autorização, troca do `code` e leitura do e-mail, renovação quando expirado, sem renovação quando válido, `invalid_grant` marca `reauth_required_at` e lança, `revoke`.
- **Job**: ignora conta desconectada, usuário desativado e conta `needs_reauth`.
- **Vazamento**: `/accounts`, dashboard e shared props nunca contêm tokens, e os logs de falha não contêm `code` nem tokens.

## 8. Deixado para quem implementa

Nomes internos, componentes React auxiliares, o texto exato das notas explicativas, o ícone de status e a folga de renovação (60 s é a sugestão).
