# Design: Regras de Expiração (TTL)

Status: aprovado · Data: 2026-11-22 · SPEC: seções 3.5, 3.6, 4.3 e 6 (Fase 1)

## 1. Intenção

Fechar a Fase 1 com a automação contínua da caixa: regras do tipo *"e-mails de [categoria / domínio / remetente / marcador] com mais de [N] dias devem ser [arquivados / mandados para a lixeira / marcados]"*, que rodam sozinhas todo dia, sem nunca surpreender o usuário com uma ação em massa que ele não viu antes.

## 2. Objetivos, não-objetivos e anti-objetivos

**Objetivos**
- Tela **Regras** (`/retention-rules`, item "Regras TTL" do menu) para criar, editar, simular, ativar/pausar e apagar regras.
- **Simular** mostra o total exato e uma amostra antes de ativar.
- Execução diária automática e botão **"Executar regras agora"**.
- Marcadores do Gmail como **filtro** e como **ação**, com a lista dos marcadores do usuário e **"+ Criar marcador"**.
- Histórico das execuções por regra.
- Regras criadas pelo Hub aparecem aqui e podem ser apagadas (débito técnico 18).

**Não-objetivos**
- Busca livre do Gmail como filtro (poderosa demais para um formulário simples).
- Combinar vários filtros na mesma regra (E/OU).
- Regras que valem para todas as contas de uma vez.
- Desfazer uma execução pelo app (arquivar e lixeira são recuperáveis no próprio Gmail).

**Anti-objetivos**
- Uma regra agir em massa sem o usuário ter visto o número antes.
- O número simulado diferir do que a execução faz.
- Tocar em e-mails com estrela, enviados, rascunhos ou spam.
- Uma regra de um usuário agir na conta de outro.

## 3. Restrições

- Escopo `gmail.modify` (já concedido) cobre listar, modificar e criar marcadores.
- Cota: `messages.list` 5 unidades (500 IDs por página), `batchModify` 50 (até 1.000 IDs), `labels.list` 1, `labels.create` 5, `messages.get` 5; passa pelo controle de cota por conta existente.
- O app roda hoje num Mac que dorme de madrugada; o agendador não recupera execuções perdidas.
- Regras vigentes: consultas a partir do usuário; contas desconectadas, de usuários desativados ou que precisam reconectar não executam; sem N+1 (testes contam consultas); logs sem assuntos, endereços ou consultas com dados pessoais; código em inglês, textos em pt-BR; TDD e 100% de cobertura de `app/`; sem subagentes.

## 4. Abordagens consideradas

| | Abordagem | Prós | Contras |
|---|---|---|---|
| A | Encontrar os e-mails no banco do app (metadados importados) | Simulação instantânea, sem cota | Só enxerga o que já foi importado; depende da sincronização estar em dia |
| **B (escolhida)** | Encontrar os e-mails pela **busca do Gmail** e aplicar com `batchModify` | Enxerga a caixa inteira; simulação e execução usam a mesma consulta (número simulado = número executado); marcadores e categorias pelo ID interno | Cada simulação consulta o Gmail (1–2 s) |
| C | Simular no banco, executar no Gmail | Simulação rápida | Números podem divergir — derruba o propósito da simulação |

## 5. Decisões do dono

1. Regra nova nasce **pausada**; **simular é obrigatório** antes de ativar.
2. Marcadores do Gmail como **filtro e como ação**, puxando os marcadores do usuário e permitindo criar novos pelo app.
3. Abordagem **B** (busca do Gmail).
4. Aceitas as propostas: tipos categoria/domínio/remetente (+ marcador), ações arquivar/lixeira (+ marcador), nunca tocar estrela/enviados/rascunhos/spam, regra por conta, execução diária "na primeira oportunidade depois das 03:00", tela conforme esboço.

## 6. Design

### 6.1 Banco de dados

`retention_rules` (migration):
- `filter_type`: `category` | `domain` | `sender` | `label` (`query` sai).
- `filter_value`: `promotions`|`updates`|`social`|`forums` (categoria); domínio em minúsculas; e-mail do remetente em minúsculas; **ID** do marcador (ex.: `Label_123`).
- `action`: `archive` | `trash` | `add_label`; `target_label` = ID do marcador quando `add_label`.
- `+ archive_after_label` (bool, default false): na ação marcador, também arquiva.
- `+ simulated_at` (timestamp, nullable): última simulação desde a última alteração. Ativar exige `simulated_at` preenchido; **editar** zera `simulated_at` e pausa a regra.
- `is_active`: default passa a `false`.
- `last_run_at` (já existe).
- Índice `(email_account_id, is_active)`.

`rule_execution_logs`: sem mudanças de estrutura; `status` = `success` | `simulated` | `failed`; `error_message` guarda só o código (`rate_limited`, `reauth_required`, `http_*`, `label_missing`, `account_unavailable`).

Regras do Hub (`sender`, 0 dias) continuam sendo criadas **ativas**: o usuário confirmou a ação na hora, para um remetente específico, vendo o total de e-mails dele. (Decisão minha; registrar.)

### 6.2 Consulta ao Gmail (`RuleQuery`)

Cada regra vira `(q, labelIds)` para `messages.list`:
- **Sempre**: `-is:starred -in:sent -in:drafts` (spam e lixeira já ficam de fora da listagem padrão).
- **Idade**: `older_than:{N}d` quando N > 0.
- **Filtro**: categoria → `labelIds = [CATEGORY_PROMOTIONS|UPDATES|SOCIAL|FORUMS]`; marcador → `labelIds = [ID]`; domínio → `from:@{domínio}`; remetente → `from:{email}`.
- **Ação**: arquivar → `in:inbox`; lixeira → nada a mais; marcador → exclui quem já tem o marcador (`-label:"{nome atual}"`, nome obtido do ID na hora da execução; se o marcador não existir mais → falha `label_missing` e a regra é pausada).
- Mudanças a aplicar: arquivar = remover `INBOX`; lixeira = adicionar `TRASH`; marcador = adicionar `{ID}` (+ remover `INBOX` se `archive_after_label`).
- Montagem pura e testada; valores vêm de campos validados (domínio/e-mail), nunca de texto livre.
- **Verificar com o Gmail real durante a implementação** que `from:@dominio` pega subdomínios e só o domínio, e que `-label:"nome"` funciona com espaços, acentos e marcadores aninhados (`Pai/Filho`). Se algum não funcionar, a alternativa é filtrar pelo ID no próprio retorno (listar com e sem o marcador e subtrair).

### 6.3 Provedor (amplia `EmailProviderInterface`)

- `listAllMessageIds(EmailAccount, string $query, array $labelIds = [], ?int $limit = null): list<string>` (novo parâmetro `labelIds`; `limit` para a simulação).
- `listLabels(EmailAccount): list<array{id: string, name: string}>` — só marcadores do usuário (`type = user`), ordenados por nome. Cache de 10 min por conta (invalidado ao criar).
- `createLabel(EmailAccount, string $name): array{id, name}` — nome validado (1–100 caracteres, sem `/` no início/fim); nome já existente → devolve o existente.

### 6.4 Simulação

`POST /retention-rules/{rule}/simulate` (síncrono):
- Lista até **10.001** IDs (para exibir "10.000+" sem varrer caixas enormes); busca metadados dos **20 mais antigos** (últimos da listagem) para a amostra: remetente, assunto, data.
- Grava `simulated_at = now` e um log `simulated` com o total.
- Resposta como prop parcial (`simulation`: `{rule_id, total, capped, sample[]}`), exibida num painel na tela.
- Erros do Gmail → mensagem "Não foi possível simular agora. Tente de novo em alguns minutos." / "Reconecte a conta…".
- No formulário de criação, "Salvar e simular" salva a regra (pausada) e já simula.

### 6.5 Execução (`ExecuteRetentionRuleJob(int $ruleId, bool $manual)`)

- Fila `default`; trava `WithoutOverlapping("retention-rule-{id}")->dontRelease()`; `$tries = 1`; `failed()` grava log `failed`.
- Carrega a regra com a conta e o usuário; desiste (log `failed` com `account_unavailable`/`reauth_required`) se a conta não estiver sincronizável; ignora regra pausada.
- Lista **todos** os IDs da consulta, aplica `batchModify` em blocos de 1.000, atualiza o banco local com `MessageStore::applyLabelChange` (consultas por bloco, não por e-mail), grava `last_run_at`, log `success` com o total (0 também é registrado).
- Erros de cota/servidor/reautorização → log `failed` com o código; a próxima execução tenta de novo.

### 6.6 Agendamento

- Comando `inbox:apply-retention`, agendado **de hora em hora**: despacha as regras ativas, de contas sincronizáveis, cujo `last_run_at` é anterior às **03:00 de hoje** no fuso `config('app.schedule_timezone')` (novo, padrão `America/Sao_Paulo`), desde que já tenha passado das 03:00. Assim roda uma vez por dia, na primeira hora em que a máquina estiver ligada depois das 03:00.
- Botão **"Executar regras agora"** (`POST /retention-rules/run`): despacha todas as regras ativas das contas sincronizáveis do usuário, ignorando o horário. Flash "Execução das regras iniciada." / "Nenhuma regra ativa para executar.".
- Uma regra recém-ativada roda na próxima janela (ou pelo botão).

### 6.7 Tela

`resources/js/Pages/RetentionRules/Index.tsx`:
```
Regras de expiração                                    [+ Nova regra] [Executar regras agora]
Nome                               Conta        Filtro                 Dias  Ação      Status    Última execução
Promoções expiram em 15 dias       voce@gm…     Categoria: Promoções   15    Arquivar  ● Ativa   hoje 03:10 · 37 e-mails
Lixeira para promo@lojax.com.br    outra@gm…    Remetente              0     Lixeira   ⏸ Pausada nunca
                                                         [Simular] [Ativar/Pausar] [Editar] [Apagar] [▾ Histórico]
```
- **Ativar** desabilitado (com dica "Simule antes de ativar") enquanto `simulated_at` for nulo.
- **Formulário** (modal): conta; filtro (tipo + valor: select de categoria, campo de domínio, campo de e-mail ou select de marcadores com "+ Criar marcador"); dias (0–3650; dica "0 = qualquer idade"); ação (arquivar, lixeira, aplicar marcador + "também arquivar"); nome sugerido automaticamente e editável. Aviso fixo: "Nunca são tocados: e-mails com estrela, enviados, rascunhos e spam."
- **Painel de simulação**: "2.422 e-mails seriam arquivados hoje" (ou "mais de 10.000"), tabela da amostra, botão "Ativar regra".
- **Histórico** (expandido): últimas 10 execuções (data, tipo, quantidade, status/erro).
- **Apagar** (confirmação): remove a regra e os logs; se for regra de remetente e o remetente do Hub estiver como `blocked`, ele volta para `none` (débito 18).
- Listagem e histórico com número constante de consultas (eager loading).

### 6.8 Rotas (grupo autenticado)

| Método | URL | Ação |
|---|---|---|
| GET | `/retention-rules` | lista (+ `labels` por conta sob demanda: `GET /accounts/{account}/labels` JSON) |
| POST | `/retention-rules` | cria (pausada); `simulate=1` simula em seguida |
| PUT | `/retention-rules/{rule}` | edita (pausa e zera `simulated_at`) |
| DELETE | `/retention-rules/{rule}` | apaga |
| POST | `/retention-rules/{rule}/simulate` | simula |
| POST | `/retention-rules/{rule}/activate` · `/pause` | ativa (exige simulação) / pausa |
| POST | `/retention-rules/run` | executa agora |
| GET | `/accounts/{account}/labels` | marcadores do usuário (JSON) |
| POST | `/accounts/{account}/labels` | cria marcador |

Todas a partir de `$user->retentionRules()` / `$user->emailAccounts()` (404 para alheias). `throttle:30,1` em simular, executar e criar marcador.

### 6.9 Documentos

SPEC 3.5/3.6/4.3/6 (✅ fecha a Fase 1), README (regras, horário, proteções), CLAUDE.md (camada de regras, consulta ao Gmail), débito técnico (remover 18; registrar busca livre, filtros combinados e regras para todas as contas).

## 7. Testes esperados

- **RuleQuery**: cada tipo de filtro × ação, idade 0 e N, exclusões fixas, `-label:` com nomes com espaço/acentos, marcador inexistente.
- **Provedor**: `labelIds` e `limit` na listagem; `listLabels` só `user` e ordenado; `createLabel` e nome duplicado.
- **Simulação**: total, "10.000+", amostra dos mais antigos, grava `simulated_at` e log; erros do Gmail; regra alheia 404.
- **Ativação**: bloqueada sem simulação; editar pausa e exige nova simulação.
- **Job**: aplica as mudanças certas por ação, blocos de 1.000, atualiza o banco local, `last_run_at`, log `success` (inclusive 0), regra pausada ignorada, conta indisponível, erros → log `failed`, `failed()`, consultas constantes.
- **Agendamento**: hora em hora; janela das 03:00 no fuso configurado (antes das 03:00 não roda; já rodou hoje não roda; máquina ligando às 10:00 roda); só contas sincronizáveis; consultas constantes.
- **Tela/rotas**: CRUD, validação (domínio, e-mail, dias, marcador da própria conta), apagar regra do Hub libera o remetente, executar agora, isolamento entre usuários, listagem sem N+1.

## 8. Deixado para quem implementa

Nomes internos, layout fino do formulário e do painel, texto exato das dicas, formato do nome sugerido, como cachear os marcadores (cache do Laravel por conta).
