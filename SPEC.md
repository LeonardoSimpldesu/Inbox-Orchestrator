# Especificação Funcional e Técnica: Inbox Orchestrator

**Status:** Aprovado para Desenvolvimento (MVP)  
**Stack Principal:** Laravel 13 (PHP 8.5) + Inertia.js 3 (React 19 + Vite 8) + Tailwind CSS 4 — Node 26 LTS  
**Banco de Dados:** MySQL / PostgreSQL / SQLite  
**Driver de Fila:** Database (`php artisan queue:work`)  
**Hospedagem Alvo:** VPS (Ubuntu/Debian com Nginx + PHP-FPM + Supervisor/Cron)

---

## 1. Visão Geral do Produto

O **Inbox Orchestrator** é um cockpit de automação e higienização contínua de caixas de entrada. O objetivo é dar ao usuário controle programável sobre seu e-mail:
- Desinscrição rápida de newsletters e e-mails promocionais.
- Políticas de retenção e expiração (*Time-To-Live / TTL*) para manter o inbox limpo.
- Categorização por domínio de remetente.
- Abertura direta do e-mail no provedor original (Gmail) sem armazenar corpos de e-mail ou anexos.
- Arquitetura preparada para suportar **Gmail** (MVP) e **Outlook / Microsoft 365** (Fase 2).

---

## 2. Arquitetura do Sistema

```
┌─────────────────────────────────────────────────────────────┐
│               Frontend: React (Vite) + Inertia.js           │
│        (Tailwind CSS, Lucide Icons, Shadcn-like UI)         │
└──────────────────────────────┬──────────────────────────────┘
                               │ (Inertia HTTP / JSON Props)
┌──────────────────────────────▼──────────────────────────────┐
│                  Backend: Laravel 13 App                    │
│  - Autenticação e Gestão de Usuários (Fortify)             │
│  - Controllers & Inertia Page Handlers                      │
│  - Camada de Serviços e Regras de Negócio                   │
└──────────────┬───────────────────────────────┬──────────────┘
               │                               │
┌──────────────▼─────────────┐   ┌─────────────▼──────────────┐
│     Banco de Dados         │   │   Queue & Scheduler        │
│  - users                   │   │   - php artisan schedule   │
│  - email_accounts          │   │   - SyncAccountMetadataJob │
│  - email_messages_meta     │   │   - ApplyRetentionRulesJob │
│  - email_senders           │   └────────────────────────────┘
│  - retention_rules         │
└────────────────────────────┘
               │
┌──────────────▼──────────────────────────────────────────────┐
│               Camada de Abstração de Provedores             │
│                 interface EmailProviderInterface            │
├──────────────────────────────┬──────────────────────────────┤
│    GmailService (Ativo)      │   OutlookService (Fase 2)    │
│    (Google Client API v2)    │   (Microsoft Graph API)      │
└──────────────────────────────┴──────────────────────────────┘
```

---

## 3. Modelo de Dados (Database Schema)

### 3.1. `users`
Usuários do sistema web.
- `id` (PK, BigInt)
- `name` (String)
- `email` (String, Unique)
- `password` (String, Hashed)
- `created_at`, `updated_at`

### 3.2. `email_accounts`
Contas de e-mail conectadas por cada usuário (suporte a múltiplas contas).
- `id` (PK, BigInt)
- `user_id` (FK -> users.id, Cascade)
- `provider` (Enum: `gmail`, `outlook`)
- `account_email` (String - ex: `usuario@gmail.com`)
- `access_token` (Text, Encrypted com `Crypt::encryptString`)
- `refresh_token` (Text, Encrypted)
- `token_expires_at` (Timestamp)
- `authorized_at` (Timestamp, Nullable - quando o Google emitiu o refresh token atual; base do prazo de reconexão em modo Teste)
- `reauth_required_at` (Timestamp, Nullable - o Google recusou a renovação; a conta precisa ser reconectada)
- `history_id` / `sync_token` (String, Nullable - para sync incremental)
- `last_synced_at` (Timestamp, Nullable)
- `oldest_synced_at` (Timestamp, Nullable - mensagem mais antiga já importada; a importação do histórico continua a partir dela)
- `history_completed_at` (Timestamp, Nullable - o histórico chegou ao fim)
- `deleted_at` (Timestamp, Nullable - soft delete: conta desconectada, dados guardados para reconexão)
- `created_at`, `updated_at`
- Um mesmo Gmail só pode estar conectado (não desconectado) por um usuário por vez.

### 3.3. `email_senders`
Indexação agregada de remetentes para triagem e desinscrição.
- `id` (PK, BigInt)
- `email_account_id` (FK -> email_accounts.id, Cascade)
- `email` (String - ex: `newsletter@exemplo.com`)
- `name` (String, Nullable)
- `domain` (String - ex: `exemplo.com`)
- `total_messages_received` (Integer, Default 0 - recalculado a partir das mensagens não deletadas, nunca somado)
- `total_messages_unread` (Integer, Default 0)
- `unsubscribe_mailto` (String, Nullable)
- `unsubscribe_url` (String, Nullable - só `https`)
- `unsubscribe_one_click` (Boolean, Default false - aceita desinscrição de um clique, RFC 8058)
- `total_messages_promotional` (Integer, Default 0 - e-mails não deletados na aba Promoções)
- `unsubscribe_method` (String, Nullable - `one_click`, `mailto`, `page`, `rule`)
- `unsubscribe_requested_at` (Timestamp, Nullable)
- `unsubscribe_error` (String, Nullable - código do último erro)
- `unsubscribe_status` (Enum: `none`, `processing`, `requested`, `blocked` (regra criada), `failed`; não existe "desinscrito" porque não há como confirmar)
- `last_email_received_at` (Timestamp, Nullable)
- `created_at`, `updated_at`
- *Unique:* `[email_account_id, email]`

### 3.4. `email_messages_meta`
Metadados mínimos de e-mails para processamento rápido de regras.
- `id` (PK, BigInt)
- `email_account_id` (FK -> email_accounts.id, Cascade)
- `provider_message_id` (String - ID original no Gmail)
- `provider_thread_id` (String - Thread ID para gerar link direto)
- `email_sender_id` (FK -> email_senders.id, Set Null)
- `sender_email` (String)
- `sender_domain` (String)
- `subject` (String, Nullable)
- `labels` (JSON - ex: `["INBOX", "CATEGORY_PROMOTIONS", "UNREAD"]`)
- `received_at` (Timestamp)
- `is_archived` (Boolean, Default false)
- `is_deleted` (Boolean, Default false - está na lixeira ou no spam)
- `created_at`, `updated_at`
- *Unique:* `[email_account_id, provider_message_id]` (releituras atualizam, nunca duplicam)
- *Index:* `[email_account_id, received_at]`, `[email_account_id, sender_domain]`, `[email_sender_id]`

### 3.5. `retention_rules`
Políticas de expiração configuradas pelo usuário.
- `id` (PK, BigInt)
- `email_account_id` (FK -> email_accounts.id, Cascade)
- `name` (String - ex: "E-mails promocionais expiram em 15 dias")
- `kind` (Enum: `manual`, `sender_category`, `sender_cleanup`, `hub_block` - quem criou a regra; as três últimas são gerenciadas pela página Remetentes ou pelo Hub e têm as condições travadas na página Regras)
- `retention_days` (Integer - 0 a 3650; 0 = qualquer idade)
- `action` (Enum: `archive`, `trash`, `add_label`)
- `target_label` (String, Nullable - ID do marcador do Gmail, usado se action == `add_label`)
- `archive_after_label` (Boolean, Default false - na ação marcador, também arquiva)
- `is_active` (Boolean, Default false - regra nasce pausada; as criadas pelo Hub nascem ativas)
- `simulated_at` (Timestamp, Nullable - ativar exige simulação; editar zera e pausa)
- `last_run_at` (Timestamp, Nullable)
- `created_at`, `updated_at`

### 3.5.1. `retention_rule_conditions`
Condições de uma regra. Tipos diferentes valem juntos (E); valores do mesmo tipo são alternativas (OU). Design: `docs/superpowers/specs/2026-11-25-combined-rule-conditions-design.md`.
- `id` (PK, BigInt)
- `retention_rule_id` (FK -> retention_rules.id, Cascade)
- `type` (Enum: `category`, `label`, `domain`, `sender`, `subject`)
- `value` (String - `promotions`/`updates`/`social`/`forums`, ID do marcador do Gmail (no máximo um por regra), domínio (inclui subdomínios), e-mail do remetente ou termo do assunto (sem `" ( ) { }`))
- `created_at`, `updated_at`
- *Index:* `[type, value]`

### 3.6. `rule_execution_logs`
Histórico de auditoria das execuções de regras.
- `id` (PK, BigInt)
- `retention_rule_id` (FK -> retention_rules.id, Cascade)
- `affected_messages_count` (Integer)
- `status` (Enum: `success`, `simulated`, `failed`)
- `error_message` (Text, Nullable)
- `executed_at` (Timestamp)

---

## 4. Especificação de Funcionalidades do MVP

### 4.1. Conexão de Contas (OAuth 2.0)
1. O usuário acessa a tela **Contas** (`/accounts`).
2. Clica em **"Conectar conta Gmail"**.
3. Redirecionamento para tela de consentimento Google com escopos (o `gmail.modify` já inclui leitura):
   - `https://www.googleapis.com/auth/gmail.modify`
   - `https://www.googleapis.com/auth/gmail.send`
4. Callback valida o `state`, recebe o `code` e troca por `access_token` e `refresh_token` (`access_type=offline`, `prompt=consent`). Conexões sem todas as permissões ou de um Gmail já conectado por outro usuário são recusadas.
5. Dispara imediatamente o job inicial de sincronização de metadados em segundo plano.
6. **Reconectar** renova o acesso da mesma conta. **Desconectar** revoga o acesso no Google e faz soft delete; reconectar depois restaura remetentes e regras.
7. O app do Google está publicado em Produção sem verificação (tela "app não verificado", limite de 100 usuários), então o acesso não expira por semana. Se voltar ao modo Teste, `GOOGLE_OAUTH_TESTING=true` faz a tela mostrar "Reconecte até dd/mm" e o painel avisar quando falta pouco ou já expirou. Design: `docs/superpowers/specs/2026-11-18-gmail-oauth-design.md`.

### 4.1.1. Sincronização de metadados
Só metadados (remetente, assunto, data, labels, cabeçalhos de desinscrição); nunca corpo ou anexos. Spam e lixeira não são importados.
1. **Recentes:** ao conectar, os últimos 3 meses, até 5.000 mensagens (ou 20 min).
2. **Histórico:** a cada 10 min (`inbox:import-history`), até 500 mensagens por conta, indo para trás no tempo até o fim. Botão **"Importar histórico agora"** roda por até 20 min.
3. **Novidades:** a cada 5 min (`inbox:sync`) e pelo botão **"Sincronizar"** do painel, via histórico de mudanças do Gmail. Se a conta ficou parada mais de ~1 semana, relê os 3 meses recentes.
4. Cada conta respeita a cota do Gmail por minuto (`GMAIL_QUOTA_UNITS_PER_MINUTE`); lotes de 25 mensagens, com nova tentativa quando o Google limita.
5. A tela Contas mostra "Importando e-mails recentes…", "Histórico importado até dd/mm/aaaa" ou "Histórico completo". Design: `docs/superpowers/specs/2026-11-18-gmail-sync-design.md`.

### 4.2. Hub de Assinaturas (Unsubscribe Manager)
Página `/subscriptions`. Design: `docs/superpowers/specs/2026-11-19-subscription-hub-design.md`.
1. **Só assinaturas:** remetentes com e-mails ativos e com cabeçalho de desinscrição **ou** com e-mails na aba Promoções. Os demais remetentes terão outra página (futuro).
2. **Listagem:** 100 por página, do que mais manda para o que menos manda; filtro de conta; abas **Pendentes** (inclui falhas) e **Tratados** (com o aviso "Ainda mandou N e-mails depois do pedido").
3. **Deep-link para o Gmail:** `https://mail.google.com/mail/u/?authuser={account_email}#search/from:{sender_email}` (valores codificados).
4. **Desinscrever** (em fila, a tela mostra "Desinscrevendo…"):
   - **Um clique (RFC 8058):** `POST` pelo servidor, só para hosts `https` públicos, com IP validado e fixado, sem redirecionamentos (proteção contra SSRF).
   - **E-mail:** enviado pela conta via Gmail API (`gmail.send`), com o assunto e corpo do `mailto`.
   - **Página do remetente:** aberta no navegador do usuário por uma rota do app (a URL nunca vai para a tela); a pessoa confirma "Já me desinscrevi".
   - **Regra:** sem mecanismo, cria uma regra (`kind = hub_block`, uma condição de remetente, `retention_days = 0`) para arquivar ou apagar os próximos e-mails; age quando o motor de TTL existir.
5. **E-mails existentes:** na confirmação, manter (padrão), arquivar ou mandar para a lixeira todos os e-mails do remetente no Gmail (inclusive não importados), em lotes de 1.000.

### 4.3. Motor de Expiração e Retenção (TTL Engine)
Página `/retention-rules`. Design: `docs/superpowers/specs/2026-11-22-retention-rules-design.md`.
1. **Criação de Regras:** *"E-mails de [categoria / domínio / remetente / marcador] com mais de [N] dias devem ser [arquivados / mandados para a lixeira / marcados]"*, por conta. Marcadores do Gmail podem ser escolhidos ou criados no formulário.
2. **Proteções fixas:** nunca tocam e-mails com estrela, enviados, rascunhos ou spam.
3. **Simular (obrigatório):** a regra nasce pausada; "Simular" mostra o total exato (até "mais de 10.000") e uma amostra dos 20 mais antigos. Só então ela pode ser ativada. Editar pausa a regra e exige nova simulação.
4. **Busca no próprio Gmail:** simulação e execução usam a mesma consulta (`messages.list` com `q`/`labelIds`), então enxergam a caixa inteira, inclusive o que ainda não foi importado, e o número simulado é o executado.
5. **Execução:**
   - **Automática:** `inbox:apply-retention` roda de hora em hora e executa uma vez por dia cada regra ativa, na primeira hora depois das 03:00 (`APP_SCHEDULE_TIMEZONE`, padrão `America/Sao_Paulo`) — funciona mesmo se a máquina estava desligada às 03:00.
   - **Manual:** botão **"Executar regras agora"**.
6. **Chamada na API do Gmail:** `batchModify` em blocos de 1.000 (remover `INBOX`, aplicar `TRASH` ou o marcador); os metadados locais são atualizados em seguida. Cada execução e simulação fica no histórico (`rule_execution_logs`).

### 4.4. Remetentes (gestão dos não-assinantes)
Página `/senders`. Design: `docs/superpowers/specs/2026-11-23-senders-page-design.md`.
1. Lista os remetentes que **não são assinaturas** (pessoas, bancos, serviços); "Mostrar assinaturas" inclui todos. Busca, filtros por conta e categoria, 100 por página.
2. **Categoria = marcador do Gmail**: escolher (ou criar) o marcador aplica-o aos e-mails existentes do remetente e cria a regra "remetente → aplicar marcador, 0 dias" para os próximos. A categoria **é** essa regra (apagá-la na tela de Regras deixa o remetente sem categoria). Trocar tira o marcador antigo e aplica o novo; uma categoria por remetente.
3. **Limpar**: arquivar ou mandar para a lixeira os e-mails existentes, com a opção de criar a regra para os próximos.
4. Seleção múltipla: limpar vários de uma vez; categorizar vários de uma mesma conta (marcadores são de cada conta).

---

## 5. Arquitetura de Classes do Backend (PHP / Laravel)

```
app/
├── Contracts/
│   └── EmailProviderInterface.php
├── Services/
│   ├── Providers/
│   │   ├── GmailProviderService.php
│   │   └── OutlookProviderService.php (stub/Fase 2)
│   ├── EmailSyncService.php
│   ├── UnsubscribeService.php
│   └── RetentionRuleEngine.php
├── Jobs/
│   ├── SyncEmailAccountJob.php
│   ├── ProcessUnsubscribeJob.php
│   └── ExecuteRetentionRuleJob.php
├── Console/Commands/
│   ├── SyncAccountsCommand.php
│   └── ApplyRetentionRulesCommand.php
└── Http/Controllers/
    ├── DashboardController.php
    ├── EmailAccountController.php
    ├── SubscriptionController.php
    └── RetentionRuleController.php
```

---

## 6. Fases e Cronograma de Entrega

* **Fase 1 (MVP Atual):**
  - ✅ Setup Laravel 13 + Inertia + React (TypeScript) + Tailwind + Database Queue.
  - ✅ Autenticação e gestão de múltiplos usuários: login com Fortify, 2FA opcional, reset de senha, sem cadastro público; usuários gerenciados por `php artisan user:create|disable|enable|delete|reset-two-factor`. Dados isolados por usuário. Detalhes em `docs/superpowers/specs/2026-11-18-authentication-design.md`.
  - Requisito para as próximas etapas: jobs de sincronização e regras TTL devem ignorar contas de usuários desativados (`users.disabled_at`), e as consultas devem partir do usuário (`$user->emailAccounts()`, `senders()`, `retentionRules()`).
  - ✅ Conexão OAuth 2.0 com Gmail e armazenamento criptografado de tokens: tela Contas, reconectar, desconectar (soft delete), um Gmail por usuário, aviso de expiração do modo Teste.
  - ✅ Worker de sincronização de metadados e remetentes: recentes (3 meses), histórico em fatias, novidades a cada 5 min, sem duplicação e respeitando a cota do Gmail.
  - ✅ Tela do Hub de Assinaturas com Unsubscribe e links diretos para o Gmail.
  - ✅ Tela de Regras de Expiração (TTL) com simulação obrigatória, execução diária e botão manual.
  - ✅ Página Remetentes: categorias por marcador do Gmail e limpeza dos não-assinantes.

* **Fase 2 (Follow-ups e Rotinas):**
  - Monitoramento de e-mails sem resposta (Inbound/Outbound).
  - Lembretes e geração de rascunhos automáticos recorrentes.
  - Categorização refinada por domínio.

* **Fase 3 (Multi-Provedor):**
  - Suporte completo ao Outlook via Microsoft Graph API.
