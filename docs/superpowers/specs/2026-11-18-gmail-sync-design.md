# Design: Sincronização de metadados do Gmail

Status: aprovado · Data: 2026-11-18 · SPEC: seções 3.3, 3.4, 4.2 e 6 (Fase 1)

## 1. Intenção

Com as contas Gmail conectadas, o app precisa conhecer as mensagens (só metadados) para que o Hub de Assinaturas e as regras de TTL tenham com o que trabalhar. Esta etapa importa e mantém atualizados os metadados e a lista agregada de remetentes, **sem pesar o sistema**: primeiro o recente, rápido; o histórico antigo aos poucos; e as novidades quase em tempo real.

## 2. Objetivos, não-objetivos e anti-objetivos

**Objetivos**
- Importar ao conectar os e-mails dos **últimos 3 meses** (teto de **5.000**), para a pessoa já organizar o recente.
- Importar o **histórico antigo aos poucos**, indo para trás no tempo, até o e-mail mais antigo.
- Manter tudo atualizado com as **novidades a cada 5 minutos** e sob demanda.
- Mostrar em cada conta até onde o app já enxerga.

**Não-objetivos (nesta etapa)**
- Telas do Hub de Assinaturas e de regras de TTL (paginação de 100 em 100 fica no Hub).
- Executar desinscrições ou ações no Gmail.
- Notificações push (webhook) do Gmail: vão para o débito técnico.

**Anti-objetivos**
- Duplicar mensagens ou inflar totais de remetentes em releituras.
- Baixar corpo ou anexos de e-mails.
- Travar a fila ou o servidor com importações longas.
- Importar spam ou lixeira.

## 3. Restrições

- Gmail API: a listagem devolve só IDs (até 500 por página); metadados exigem uma chamada por mensagem, agrupável em **lotes de 100** (HTTP batch). Cota de ~250 unidades/s por conta Gmail (≈ 50 mensagens/s). `history.list` (novidades) só alcança cerca de **uma semana** para trás; depois disso devolve 404.
- `messages.list` sem `includeSpamTrash` já exclui spam e lixeira.
- Fila `database` com **um** worker (`queue:work --timeout=120`, `retry_after` 90 s) e um container `scheduler`. Precisam acomodar jobs de até 20 minutos.
- **Sem N+1 (pedido do dono):** cada lote de 100 mensagens grava com um número **constante** de consultas (upserts em massa, uma consulta agregada para os totais), nunca uma consulta por mensagem ou por remetente. Comandos carregam contas com o usuário (`with('user')`) e `summary()` não consulta o banco. Testes medem o número de consultas por lote.
- Regras vigentes: consultas a partir do usuário; jobs ignoram contas desconectadas, de usuários desativados e que precisam reconectar; tokens nunca em logs ou filas; código em inglês, textos em pt-BR; TDD e 100% de cobertura de `app/`; sem subagentes.

## 4. Abordagens consideradas

| | Abordagem | Prós | Contras |
|---|---|---|---|
| **A (escolhida)** | Um job por tarefa e conta, com orçamento; salva onde parou e a próxima execução continua | Simples de testar e entender; progresso direto ("importado até dd/mm"); falha no meio não perde lotes salvos | Um job ocupa o worker até bater o orçamento (curto com os limites definidos) |
| B | Corrente de jobs pequenos (uma página por job) | Trabalho bem fatiado na fila | Milhares de jobs; lógica de parada espalhada; testes mais trabalhosos |
| C | `Bus::batch` com um job por página | Progresso nativo | Exige saber o total de páginas antes (o Gmail não informa de forma confiável); não combina com "parar no limite e continuar depois" |

Para o histórico, também foi considerada uma janela de madrugada; o dono escolheu um **worker contínuo e leve durante o dia + botão "Importar histórico agora"**, porque o app roda hoje num Mac que dorme de madrugada (o agendador não recupera execuções perdidas). Para as novidades, o webhook via Pub/Sub foi descartado por ora: exige URL pública com HTTPS, o que expõe o ambiente de desenvolvimento.

## 5. Decisões do dono

1. Ler tudo menos spam e lixeira, em etapas para não pesar o sistema.
2. **Recentes:** últimos 3 meses, teto de 5.000 e-mails, ao conectar.
3. **Histórico:** automático a cada **10 minutos**, até **500 mensagens por conta** por execução; botão **"Importar histórico agora"** com orçamento de **20 minutos**.
4. **Novidades:** a cada **5 minutos** e pelo botão "Sincronizar" do painel.
5. Dados entram lote a lote (100 em 100) e já ficam visíveis.
6. Releituras (inclusive após mais de uma semana parada) **nunca duplicam**: mensagens são atualizadas e os totais dos remetentes recalculados.
7. Abordagem A. Webhook do Gmail fica no débito técnico, para quando houver servidor público.

## 6. Design

### 6.1 Banco de dados

`email_accounts` (nova migration):
- `history_id` (já existe): cursor das novidades.
- `+ oldest_synced_at` (timestamp, nullable): data da mensagem mais antiga já importada; o histórico continua a partir dela.
- `+ history_completed_at` (timestamp, nullable): o histórico chegou ao fim.
- `last_synced_at` (já existe): última execução bem-sucedida de qualquer tipo.

`email_senders`:
- `+ unsubscribe_one_click` (boolean, default false): o remetente aceita desinscrição de um clique (`List-Unsubscribe-Post: List-Unsubscribe=One-Click`, RFC 8058). Necessário para o Hub (cenário A).

`email_messages_meta`: sem mudanças; a unique `(email_account_id, provider_message_id)` já existe e é a base do "nunca duplicar".

### 6.2 Provedor (amplia `EmailProviderInterface`)

- `currentHistoryId(EmailAccount $account): string` (de `users.getProfile`).
- `listMessageIds(EmailAccount $account, string $query, ?string $pageToken = null, int $maxResults = 100): MessagePage` → `ids: list<string>`, `nextPageToken: ?string`. Ordem do Gmail: mais recentes primeiro.
- `fetchMetadata(EmailAccount $account, array $ids): list<MessageMetadata>` → via batch HTTP de até 100; `format=metadata`, `metadataHeaders`: `From`, `Subject`, `List-Unsubscribe`, `List-Unsubscribe-Post`. IDs que o Gmail diz não existirem mais (404 dentro do lote) são omitidos.
- `listChanges(EmailAccount $account, string $startHistoryId, ?string $pageToken = null): ChangePage` → `changedIds` (mensagens adicionadas ou com labels alteradas), `deletedIds`, `historyId`, `nextPageToken`. Lança `HistoryExpired` quando o Gmail responde 404.
- Todos usam `refreshAccessToken()` antes. Erro de cota ou do servidor do Google (429/5xx) lança `ProviderUnavailable` (mensagem = só o tipo).
- DTO `MessageMetadata`: `id`, `threadId`, `labelIds`, `internalDate` (CarbonImmutable), `from` (cabeçalho bruto), `subject`, `listUnsubscribe`, `listUnsubscribePost` (nullable).

### 6.3 Interpretação dos cabeçalhos (`App\Services\Sync\HeaderParser`, puro)

- `From`: `"Nome" <email@dominio>` ou `email@dominio` → `email` (minúsculo), `domain` (depois do último `@`), `name` (sem aspas, nullable). Sem endereço válido: `sender_email = ''`, `sender_domain = ''`, sem remetente associado.
- `List-Unsubscribe`: lista de `<...>`. Primeiro `mailto:` → `unsubscribe_mailto`; primeiro `https://` → `unsubscribe_url` (`http://` é ignorado). `unsubscribe_one_click` = `List-Unsubscribe-Post` contém `List-Unsubscribe=One-Click` **e** há URL https.
- `subject` truncado em 255 caracteres.

### 6.4 Gravação (`App\Services\Sync\MessageStore`)

Para cada lote de metadados:
1. Upsert de `email_messages_meta` por `(email_account_id, provider_message_id)`: `provider_thread_id`, `sender_email`, `sender_domain`, `subject`, `labels`, `received_at = internalDate`, `is_archived = !labels∋INBOX`, `is_deleted = labels∋TRASH ou SPAM`.
2. Upsert de `email_senders` por `(email_account_id, email)` para cada remetente do lote: `name`, `domain`; dados de desinscrição vêm da **mensagem mais recente** desse remetente (só sobrescreve se a mensagem do lote for mais nova que `last_email_received_at`). `unsubscribe_status` não é tocado.
3. Liga `email_sender_id` nas mensagens do lote.
4. **Recalcula** os totais dos remetentes afetados a partir das mensagens guardadas: `total_messages_received` = mensagens não deletadas; `total_messages_unread` = não deletadas com `UNREAD`; `last_email_received_at` = maior `received_at`. Nunca soma incremental.
5. Atualiza `oldest_synced_at` da conta = menor entre o atual e o menor `received_at` do lote.

Tudo de um lote numa transação. Mensagens removidas de vez (`deletedIds`) são apagadas de `email_messages_meta` e os remetentes afetados recalculados.

### 6.5 Modos de sincronização (`App\Services\EmailSyncService` + `SyncEmailAccountJob`)

`SyncEmailAccountJob(int $accountId, SyncMode $mode)`; `SyncMode`: `Recent`, `Changes`, `History`, `HistoryNow`.

| Modo | Quando | Consulta | Orçamento |
|---|---|---|---|
| `Recent` | Ao conectar/reconectar (já despachado) e quando `HistoryExpired` | `after:{agora − 90 dias, epoch}` | 5.000 mensagens ou 20 min |
| `Changes` | Agendado a cada 5 min; botão "Sincronizar" | `history.list` desde `history_id` | Sem limite de quantidade; 20 min |
| `History` | Agendado a cada 10 min, se `history_completed_at` nulo | `before:{oldest_synced_at, epoch}` | 500 mensagens |
| `HistoryNow` | Botão "Importar histórico agora" | idem | 20 min |

- **Recent:** captura `currentHistoryId()` **antes** de listar (para não perder mensagens que chegarem durante a importação), importa página a página (listagem de 100 → `fetchMetadata` → `MessageStore`) e, ao terminar, grava `history_id`. Se a janela tiver menos mensagens que o teto, o histórico continua normalmente a partir de `oldest_synced_at`. Sem nenhuma mensagem nos 3 meses: `oldest_synced_at = agora − 90 dias`.
- **Changes:** sem `history_id` (conta nunca importada) → executa `Recent`. Com `HistoryExpired` → executa `Recent` (relê os 3 meses com upsert; o histórico antigo já importado continua). Ao terminar, grava o novo `history_id`.
- **History/HistoryNow:** se `oldest_synced_at` nulo, não faz nada (o `Recent` ainda não rodou). Página vazia → `history_completed_at = agora`. Paginação por `pageToken` dentro da execução; entre execuções, recomeça por `before:` (tokens do Gmail expiram). Mensagens na fronteira lidas duas vezes são inofensivas (upsert).
- **Orçamento:** checado entre lotes; o lote corrente sempre termina e é salvo.
- **Guardas:** `shouldRun()` (já existe) no início e entre páginas (conta desconectada no meio → para). `ReauthorizationRequired` → para em silêncio (a conta já fica marcada). `ProviderUnavailable` → para, salva o progresso, `warning` no log com `account_id` e o tipo do erro; a próxima execução continua.
- **Trava por conta:** `WithoutOverlapping("sync-account-{id}")` para todos os modos. Agendados (`Changes`, `History`) são descartados se a conta estiver ocupada (`dontRelease`; o próximo ciclo cobre). `Recent` e `HistoryNow` voltam para a fila após 60 s.
- `last_synced_at = agora` ao final de qualquer execução bem-sucedida.

### 6.6 Agendamento e fila

- Comandos `inbox:sync` (despacha `Changes`) e `inbox:import-history` (despacha `History` só para contas com `history_completed_at` nulo), para todas as contas elegíveis (não desconectadas, usuário ativo, sem `needs_reauth`).
- `routes/console.php`: `inbox:sync` a cada 5 min e `inbox:import-history` a cada 10 min, ambos `withoutOverlapping()`.
- Jobs de sync numa fila própria `sync`, para não segurar outros jobs atrás de um histórico de 20 min. `docker-compose.yml`: o worker passa a atender `--queue=default,sync` com `--timeout=1500`, e `DB_QUEUE_RETRY_AFTER=1600` (o `retry_after` precisa ser maior que o job mais longo, senão o job seria entregue de novo no meio). `SyncEmailAccountJob`: `$timeout = 1500`, `$tries = 1` (a próxima execução agendada é o retry natural).
- Documentar no README e no `.env.example`.

### 6.7 Frontend

- `summary()` da conta ganha `sync`: `{ state: 'importing_recent' | 'importing_history' | 'complete', oldest_synced_at: ?string (Y-m-d), last_synced_at }`. `importing_recent` = `history_id` nulo.
- Tela **Contas**, em cada conta, abaixo do status:
  ```
  voce@gmail.com   ● Conectada · reconecte até 25/11
                   Importando e-mails recentes…            (importing_recent)
                   Histórico importado até 12/03/2024 · sincronizado há 3 min
                   [Importar histórico agora] [Reconectar] [Desconectar]
                   Histórico completo · sincronizado há 3 min    (complete)
  ```
  "Importar histórico agora" só aparece em `importing_history`. A página se atualiza sozinha a cada 15 s (`usePoll`) enquanto alguma conta estiver em `importing_recent`.
- `POST /accounts/{account}/import-history` (conta do usuário, 404 senão; se `needs_reauth`, não despacha e mostra "Reconecte a conta antes de importar o histórico."): despacha `HistoryNow`, flash "Importação do histórico iniciada. Pode levar até 20 minutos."
- `POST /accounts/sync`: despacha `Changes` para as contas elegíveis do usuário; flash "Sincronização iniciada." (sem contas elegíveis: "Nenhuma conta conectada para sincronizar."). Liga o botão "Sincronizar" do painel (hoje um placeholder).
- Painel: "Última sinc." passa a mostrar data/hora formatada.

### 6.8 Documentos

- SPEC 3.2/3.3 (colunas novas), 4.2/6 (modos, agendamento, ✅ do item "Worker de sincronização").
- `docs/debito-tecnico.md`:
  - **Notificações push do Gmail** (Pub/Sub + `users.watch` renovado a cada 7 dias + validação do token OIDC do push), quando houver servidor público com HTTPS; o endpoint só despacha `Changes`.
  - **Conta parada mais de uma semana:** a releitura cobre só os últimos 3 meses; mudanças de labels ou exclusões em mensagens mais antigas feitas nesse intervalo não são vistas até uma reimportação completa.
- README (agendamentos, fila `sync`) e CLAUDE.md (camada de sync, invariantes de upsert/recálculo).

## 7. Testes esperados

- **HeaderParser:** formatos de `From` (com/sem nome, aspas, sem endereço, maiúsculas), `List-Unsubscribe` (mailto, https, http ignorado, ambos, vazio), one-click só com https, assunto longo.
- **MessageStore:** importar o mesmo lote duas vezes não duplica nem altera totais; totais e não lidos corretos; mensagem que vai para a lixeira sai dos totais; exclusão definitiva remove e recalcula; desinscrição vem da mensagem mais recente, mensagem antiga não sobrescreve; `unsubscribe_status` preservado; `oldest_synced_at` só diminui; remetentes isolados por conta.
- **Provedor (MockHandler):** paginação de `listMessageIds` com a query; batch de `fetchMetadata` (resposta multipart) incluindo item 404 omitido; `listChanges` com adicionadas/alteradas/excluídas; 404 → `HistoryExpired`; 429/5xx → `ProviderUnavailable`.
- **Serviço/job (fake do provedor):** `Recent` respeita teto e janela, grava `history_id` capturado antes; `Changes` aplica mudanças e avança `history_id`; `HistoryExpired` → relê recentes sem duplicar; `History` respeita 500 e marca completo em página vazia; orçamento de tempo (com relógio controlado); conta desconectada no meio para; `ReauthorizationRequired` e `ProviderUnavailable` param salvando o progresso e sem segredos no log; trava descarta agendados.
- **Comandos/agendamento:** só contas elegíveis; `import-history` ignora completas; agendamentos registrados nos intervalos certos.
- **Rotas:** importar histórico e sincronizar só para contas próprias; props com `sync` e sem campos internos.

## 8. Deixado para quem implementa

Nomes internos auxiliares, organização dos DTOs, formatação de "há X min" no frontend, se o batch HTTP usa o suporte do `google/apiclient` (`setUseBatch`) ou montagem própria, e o tamanho de página da listagem (100 sugerido, para casar com o lote).
