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
- `unsubscribe_status` (Enum: `none`, `requested`, `unsubscribed`, `failed`)
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
- `filter_type` (Enum: `category`, `domain`, `sender`, `query`)
- `filter_value` (String - ex: `CATEGORY_PROMOTIONS`, `amazon.com.br`, `uber.com`)
- `retention_days` (Integer - ex: 15, 30)
- `action` (Enum: `archive`, `trash`, `add_label`)
- `target_label` (String, Nullable - usado se action == `add_label`)
- `is_active` (Boolean, Default true)
- `last_run_at` (Timestamp, Nullable)
- `created_at`, `updated_at`

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
7. Com o app do Google em modo Teste, o acesso expira em 7 dias: a tela mostra "Reconecte até dd/mm" e o painel avisa quando falta pouco ou já expirou (ver `docs/debito-tecnico.md`, item 7). Design: `docs/superpowers/specs/2026-11-18-gmail-oauth-design.md`.

### 4.1.1. Sincronização de metadados
Só metadados (remetente, assunto, data, labels, cabeçalhos de desinscrição); nunca corpo ou anexos. Spam e lixeira não são importados.
1. **Recentes:** ao conectar, os últimos 3 meses, até 5.000 mensagens (ou 20 min).
2. **Histórico:** a cada 10 min (`inbox:import-history`), até 500 mensagens por conta, indo para trás no tempo até o fim. Botão **"Importar histórico agora"** roda por até 20 min.
3. **Novidades:** a cada 5 min (`inbox:sync`) e pelo botão **"Sincronizar"** do painel, via histórico de mudanças do Gmail. Se a conta ficou parada mais de ~1 semana, relê os 3 meses recentes.
4. Cada conta respeita a cota do Gmail por minuto (`GMAIL_QUOTA_UNITS_PER_MINUTE`); lotes de 25 mensagens, com nova tentativa quando o Google limita.
5. A tela Contas mostra "Importando e-mails recentes…", "Histórico importado até dd/mm/aaaa" ou "Histórico completo". Design: `docs/superpowers/specs/2026-11-18-gmail-sync-design.md`.

### 4.2. Hub de Assinaturas (Unsubscribe Manager)
1. **Listagem Agregada:** Apresenta lista de remetentes com nome, domínio, total de e-mails, quantos estão não lidos e data do último recebido.
2. **Deep-Link Direto para o Gmail:**
   - Cada remetente ou mensagem possui um link direto:  
     `https://mail.google.com/mail/u/?authuser={account_email}#search/from:{sender_email}`
   - Para abrir uma mensagem/thread específica:  
     `https://mail.google.com/mail/u/?authuser={account_email}#all/{provider_thread_id}`
   - O e-mail abre diretamente na aba oficial do Gmail, com segurança, sem necessidade de baixar o corpo para o banco.
3. **Ação "Desinscrever":**
   - **Cenário A (HTTP One-Click):** Envia requisição POST/GET segura ao endpoint do remetente.
   - **Cenário B (Mailto):** Envia e-mail de desinscrição padrão através da API do Gmail em nome do usuário.
   - **Cenário C (Sem cabeçalho padrão):** Permite criar com 1 clique uma regra para arquivar ou enviar direto para a lixeira qualquer futuro e-mail daquele remetente.

### 4.3. Motor de Expiração e Retenção (TTL Engine)
1. **Criação de Regras:**
   - Interface visual simples: *"E-mails da categoria [Promocional] com mais de [15] dias devem ser [Arquivados]"*.
2. **Botão "Simular / Ver impacto" (Dry-Run):**
   - Consulta o banco de metadados e mostra quantos e-mails seriam afetados hoje antes de aplicar a ação.
3. **Execução:**
   - **Automática:** Diária às 03:00 via agendador do Laravel (`$schedule->command('inbox:apply-retention')->dailyAt('03:00')`).
   - **Manual:** Botão **"Executar regras agora"** no painel que despacha o job para a fila.
4. **Chamada na API do Gmail:**
   - Agrupa em chamadas em lote (`batchModify`) para remover a label `INBOX` (arquivar) ou aplicar `TRASH` (lixeira).

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
  - Tela do Hub de Assinaturas com Unsubscribe e links diretos para o Gmail.
  - Tela de Regras de Expiração (TTL) com Dry-Run, execução diária e botão manual.

* **Fase 2 (Follow-ups e Rotinas):**
  - Monitoramento de e-mails sem resposta (Inbound/Outbound).
  - Lembretes e geração de rascunhos automáticos recorrentes.
  - Categorização refinada por domínio.

* **Fase 3 (Multi-Provedor):**
  - Suporte completo ao Outlook via Microsoft Graph API.
