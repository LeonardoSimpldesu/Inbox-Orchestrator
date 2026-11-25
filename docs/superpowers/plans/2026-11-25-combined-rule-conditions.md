# Regras com condições combinadas: plano de implementação

> Execução: inline nesta sessão (sem subagentes), tarefa por tarefa, com TDD. Steps em checkbox (`- [ ]`).

**Objetivo:** cada regra de expiração passa a ter uma lista de condições: tipos diferentes valem com E, e valores do mesmo tipo valem com OU. Entra o tipo novo `subject`, e cada regra ganha um papel explícito (`kind`).

**Arquitetura:**
- Tabela `retention_rule_conditions` com `RetentionRule hasMany conditions`.
- `RuleQuery` monta a busca a partir das condições agrupadas por tipo.
- Remetentes e Hub acham as suas regras por `kind`.
- A migração acontece em duas etapas: primeiro cria a tabela e copia os dados mantendo as colunas antigas (Tarefa 1); depois remove `filter_type`/`filter_value` (Tarefa 6). Assim cada tarefa termina com a suíte verde.

**Stack:** Laravel 13 (SQLite), Inertia v3 + React/TS, PHPUnit, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-25-combined-rule-conditions-design.md`

## Restrições globais
- Código em inglês e textos para o usuário em pt-BR. Poucos comentários.
- Cobertura de `app/` em 100%. TDD: primeiro o teste vermelho.
- Caminhos em lote mantêm um número constante de consultas, com teste que compara 1 × N.
- As exclusões fixas `-is:starred -in:sent -in:drafts` estão em toda busca. A busca nunca contém nome de marcador, só id em `labelIds`.
- Branch `feat/combined-rule-conditions`, PR próprio, sem tag (a tag sai só depois dos três trabalhos).

## Pontos de atenção na revisão
1. **Termo de assunto que tenta escapar das aspas:** `Oi") OR (in:anywhere` precisa ser recusado com 422. A busca não pode mudar.
2. **Regra manual idêntica a uma gerenciada:** uma regra manual "remetente X → marcador" não vira categoria. Uma regra manual de remetente + assunto não impede a página Remetentes de criar a sua regra de limpeza.
3. **Editar regra gerenciada pela página Regras:** enviar condições diferentes, ou trocar a ação para uma de outra família (categoria só aceita `add_label`; limpeza e bloqueio só aceitam `archive`/`trash`), retorna 422.
4. **Gravação em lote (todas as contas, categorias, limpeza futura):** cada regra recebe exatamente as suas condições, com consultas constantes.
5. **Migração com dados reais:** cada regra antiga vira uma condição, recebe o `kind` certo e produz a mesma busca de antes.

## Decisões tomadas no plano (fora do design)
- O Dashboard só mostra a contagem de regras ativas. `recentRules` deixa de enviar `filter_type`/`filter_value` e não ganha condições (YAGNI).
- A família de ação das regras gerenciadas fica travada (ponto 3). Sem isso, mudar a ação de uma regra de categoria a deixaria sem sentido.

---

### Tarefa 1: tabela de condições, `kind` e cópia dos dados
**Arquivos:**
- Criar: `database/migrations/2026_11_25_000001_create_retention_rule_conditions_table.php`
- Criar: `app/Models/RetentionRuleCondition.php`, `database/factories/RetentionRuleFactory.php`, `database/factories/RetentionRuleConditionFactory.php`
- Modificar: `app/Models/RetentionRule.php`
- Teste: `tests/Unit/Models/RetentionRuleTest.php`, `tests/Feature/Migrations/RetentionRuleConditionsMigrationTest.php`

**Interfaces produzidas:**
- `RetentionRule::CONDITION_TYPES = ['category', 'label', 'domain', 'sender', 'subject']` (a ordem é a da busca).
- `RetentionRule::KINDS = ['manual', 'sender_category', 'sender_cleanup', 'hub_block']` e `RetentionRule::MANAGED_KINDS` (os três últimos).
- `RetentionRule::conditions(): HasMany`, ordenado por `id`.
- `RetentionRule::replaceConditions(array $conditions): void`. Recebe `list<array{type: string, value: string}>`, apaga as condições atuais e grava as novas com um insert.
- `RetentionRule::insertWithConditions(array $rules, array $conditions): void`. Recebe uma lista de linhas de regra e, na mesma ordem, a lista de condições de cada uma. Roda numa transação e faz 3 consultas: insere as regras; lê os ids das regras **sem condições** (`doesntHave('conditions')`) do mesmo `kind` e das mesmas contas, em `orderBy('id')`; insere as condições.
  - Isso funciona porque toda regra tem ao menos uma condição e o SQLite serializa quem escreve, então as regras sem condição são exatamente as recém-inseridas.
  - O motivo vai num comentário curto.
- `RetentionRule::isManaged(): bool`.
- Factory: `RetentionRule::factory()->withConditions([['sender', 'a@b.com'], ...])`.

**Passos:**
- [ ] Teste da migration (classe nova, `RefreshDatabase`). Faz `down()` da migration nova pelo arquivo (`require`), insere regras no formato antigo, faz `up()` e confere:
  - `sender` + `add_label` → `kind=sender_category`;
  - `sender` + `trash` com o remetente `blocked` na mesma conta → `hub_block`;
  - `sender` + `archive` com 0 dias e o remetente não bloqueado → `sender_cleanup`;
  - `sender` + `archive` com 30 dias → `manual`;
  - `category`/`domain`/`label` → `manual`;
  - cada regra tem uma condição `{filter_type, filter_value}`.
- [ ] Testes do modelo:
  - `replaceConditions` troca as condições;
  - `insertWithConditions` com 1 e com 50 regras faz o mesmo número de consultas e associa cada lista à regra certa;
  - apagar a regra apaga as condições (FK com cascade; confirme que `foreign_keys` está ativo no SQLite de teste).
- [ ] Rodar: `docker exec inbox_app php artisan test --filter='RetentionRuleConditionsMigrationTest|RetentionRuleTest'`. Esperado: FAIL (tabela ou classes inexistentes).
- [ ] Implementar:
  - a migration: tabela com `retention_rule_id` FK `cascadeOnDelete`, `type` string(20), `value` string(255), timestamps e índice `(type, value)`;
  - `kind` string(20) com default `manual` em `retention_rules`;
  - o backfill em SQL com `insert … select` e `update … where exists`, sem laço por regra;
  - o `down()`, que remove a tabela e o `kind`;
  - o modelo, `$fillable` e as factories.
- [ ] Rodar o mesmo filtro. Esperado: PASS. Rodar a suíte sem audit: verde, porque nada ainda lê as condições.
- [ ] Commit: `feat: store rule conditions in their own table with an explicit rule kind`.

### Tarefa 2: busca e marcadores a partir das condições
**Arquivos:**
- Modificar: `app/Services/Retention/RuleQuery.php`, `app/Services/Retention/RuleMatcher.php`
- Teste: `tests/Unit/Services/Retention/RuleQueryTest.php`, `tests/Unit/Services/Retention/RuleExecutorTest.php` (ou `RuleSimulatorTest`), `tests/Unit/Services/Retention/RetentionTestCase.php`

**Interfaces consumidas:** `RetentionRule::conditions` (Tarefa 1). `RuleQuery` lê `$rule->conditions`. Nos testes unitários sem banco, a relação vem de `setRelation('conditions', collect([new RetentionRuleCondition([...])]))`.

**Passos:**
- [ ] Testes de `RuleQuery`. Os casos atuais passam a montar as regras com condições e as strings esperadas não mudam. Casos novos (prefixo `-is:starred -in:sent -in:drafts`, depois `older_than`, depois as condições na ordem de `CONDITION_TYPES`, depois `in:inbox`):
  - `subject` único `Bem-vindo` → `subject:"Bem-vindo"`;
  - `subject` `Bem-vindo` + `Welcome` → `subject:("Bem-vindo" OR "Welcome")`;
  - `sender` `no-reply@accounts.google.com` + `subject` `Alerta de segurança` com `archive` → `… from:no-reply@accounts.google.com subject:"Alerta de segurança" in:inbox`;
  - dois domínios → `{from:@a.com from:@b.com}`;
  - duas categorias `promotions` + `updates` → termo `{category:promotions category:updates}` e `labelIds` vazio;
  - uma categoria + um marcador → `labelIds` `['CATEGORY_PROMOTIONS', 'Label_1']`;
  - `add_label` com marcador condicional → `excludeLabelIds` inclui o marcador da condição e o alvo.
- [ ] Teste do matcher: uma regra `domain` + `label Label_9` com `Label_9` apagado no Gmail gera `LabelMissing` (a regra é pausada pelo executor, como hoje).
- [ ] Rodar: `docker exec inbox_app php artisan test --filter='RuleQueryTest|RuleExecutorTest|RuleSimulatorTest'`. Esperado: FAIL nos casos novos.
- [ ] Implementar:
  - em `RuleQuery::for`, agrupar as condições por tipo;
  - `category` com um valor vai em `labelIds`; com mais de um vira grupo `{category:x …}`;
  - `label` vai em `labelIds`;
  - `domain`/`sender` com um valor geram o termo, com mais de um viram grupo `{…}`;
  - `subject` usa sempre aspas;
  - em `RuleMatcher::ensureLabelsExist`, olhar todas as condições `label` e o `target_label`.
- [ ] Rodar o filtro e depois a suíte sem audit. Esperado: PASS.
- [ ] Commit: `feat: build the Gmail search from combined rule conditions`.

### Tarefa 3: formulário de regras no backend (validação, criação, edição, listagem)
**Arquivos:**
- Modificar: `app/Http/Requests/RetentionRuleRequest.php`, `app/Http/Controllers/RetentionRuleController.php`, `app/Http/Controllers/DashboardController.php`
- Teste: `tests/Feature/RetentionRules/RetentionRuleCrudTest.php`, `RetentionRulesTestCase.php`, `tests/Feature/DashboardTest.php`

**Interfaces:**
- O payload passa a ter `conditions: list<{type, value}>` no lugar de `filter_type`/`filter_value`.
- `RetentionRuleRequest::ruleConditions(): list<array{type, value}>`, já normalizada: trim em tudo e minúsculas em `domain`/`sender`.
- `ruleAttributes()` deixa de ter os campos de filtro. Na criação, `kind` é sempre `manual` e nunca vem do request.
- Cada regra na prop `rules` traz:
  - `kind`;
  - `conditions: list<{type, value, label: string|null}>`, onde `label` é o nome do marcador quando `type=label`.

**Passos:**
- [ ] Testes de validação (422 com erro em `conditions` ou `conditions.N.value`):
  - lista vazia; 21 itens;
  - tipo desconhecido; par `{type, value}` repetido; dois `label`;
  - domínio inválido; e-mail inválido; categoria desconhecida; marcador de outra conta;
  - `subject` vazio após o trim, com 101 caracteres, ou contendo qualquer um de `" ( ) { }` (o caso `Oi") OR (in:anywhere` incluído);
  - "todas as contas" com condição `label`.
- [ ] Testes de sucesso:
  - criar a regra com `sender` + `subject` grava as duas condições e `kind=manual`;
  - "todas as contas" com `subject` `Bem-vindo` + `Welcome` cria uma cópia por conta, cada uma com as duas condições, em consultas constantes (1 × 3 contas);
  - editar troca as condições e zera `simulated_at`.
- [ ] Testes das regras gerenciadas:
  - editar uma `sender_category` com outras condições → 422 com "As condições desta regra são gerenciadas pela página Remetentes.";
  - editar uma `hub_block` com outras condições → 422 com "… pelo Hub.";
  - nome e dias mudam normalmente;
  - ação de outra família → 422;
  - `sender_cleanup` de `archive` para `trash` é aceito.
- [ ] Teste da listagem: `rules` traz `kind` e `conditions` com o nome do marcador, e faz uma única chamada a `listLabels` por conta.
- [ ] Teste do Dashboard: `recentRules` sem campos de filtro.
- [ ] Rodar: `docker exec inbox_app php artisan test --filter='RetentionRule|DashboardTest'`. Esperado: FAIL.
- [ ] Implementar:
  - a validação: as regras por tipo de cada `conditions.N.value` ficam em `Rule::forEach` ou no `after()`, a critério de quem implementa, desde que as mensagens caiam no índice certo;
  - a comparação das condições de regra gerenciada como conjunto de pares;
  - `store` com `insertWithConditions` em "todas as contas";
  - `update` com `replaceConditions`;
  - `labelNames` olhando as condições;
  - eager load de `conditions`.
- [ ] Rodar o filtro e depois a suíte sem audit. Esperado: PASS. Os testes antigos de CRUD foram migrados para o payload novo.
- [ ] Commit: `feat: accept combined conditions in the rules form`.

### Tarefa 4: Remetentes e Hub pelo `kind`
**Arquivos:**
- Modificar: `app/Services/Senders/SenderCategories.php`, `app/Http/Controllers/SenderController.php`, `app/Http/Controllers/SubscriptionController.php`, `app/Http/Controllers/RetentionRuleController.php` (remove `releaseHubSender`)
- Teste: `tests/Unit/Services/Senders/SenderCategoriesTest.php`, `tests/Feature/Senders/*`, `tests/Feature/Subscriptions/SubscriptionActionsTest.php`, `tests/Feature/RetentionRules/RetentionRuleCrudTest.php`

**Passos:**
- [ ] Testes:
  - uma regra `manual` "remetente X → marcador L" **não** aparece como categoria de X (antes aparecia);
  - uma regra `manual` de remetente X + assunto com `trash` não impede "limpar e manter limpo" de criar a `sender_cleanup` de X;
  - atribuir uma categoria a 1 e a 50 remetentes cria `sender_category`, cada uma com a condição do seu remetente, em consultas constantes;
  - o filtro "com categoria/sem categoria" e o filtro por marcador da página Remetentes continuam com consultas constantes;
  - o bloqueio no Hub cria `hub_block` com uma condição `sender`;
  - apagar uma `hub_block` devolve o remetente `blocked` para Pendentes;
  - apagar uma `manual` de remetente não mexe no status do Hub;
  - "Voltar para Pendentes" num remetente `blocked` apaga a `hub_block` dele (só dele e só daquela conta).
- [ ] Rodar: `docker exec inbox_app php artisan test --filter='Sender|Subscription|RetentionRuleCrudTest'`. Esperado: FAIL nos casos novos.
- [ ] Implementar:
  - as buscas de Remetentes e Hub filtram por `kind`, e a ligação com o e-mail usa `whereExists` em `retention_rule_conditions` (`type='sender'`, `value = email_senders.email`);
  - as criações em lote usam `insertWithConditions`;
  - `destroy` libera o remetente quando `kind=hub_block`;
  - `reset` apaga a `hub_block`.
- [ ] Rodar o filtro e depois a suíte sem audit. Esperado: PASS.
- [ ] Commit: `feat: find sender categories, cleanups and Hub blocks by rule kind`.

### Tarefa 5: frontend
**Arquivos:**
- Modificar: `resources/js/types/index.d.ts`, `resources/js/lib/rules.ts`, `resources/js/Components/RetentionRuleForm.tsx`, `resources/js/Pages/RetentionRules/Index.tsx`
- Criar: `tests/js/rules.test.ts`

**Interfaces:**
- Tipos: `RuleConditionType = 'category' | 'label' | 'domain' | 'sender' | 'subject'`, `RuleCondition { type; value; label?: string | null }` e `RuleKind`.
- `RetentionRuleRow` troca os campos de filtro por `kind` e `conditions`.
- `describeConditions(conditions: RuleCondition[]): string` em `lib/rules.ts`.

**Passos:**
- [ ] Teste `tests/js/rules.test.ts`:
  - `[{sender a@b.com}, {subject Alerta de segurança}]` → `Remetente: a@b.com · Assunto: "Alerta de segurança"`;
  - `[{subject Bem-vindo}, {subject Welcome}]` → `Assunto: "Bem-vindo" ou "Welcome"`;
  - duas categorias → `Categoria: Promoções ou Atualizações`;
  - marcador → usa `label` (o nome) e, sem ele, o id.
- [ ] Rodar: `npm run test:js`. Esperado: FAIL (função inexistente).
- [ ] Implementar:
  - `describeConditions`, agrupando pela ordem de `CONDITION_TYPES` e com `FILTER_TYPE_LABELS` + `subject: 'Assunto'`;
  - o formulário: uma linha por condição (seletor de tipo + valor), "Adicionar condição" e remover (mínimo de 1);
  - a opção `label` sumindo quando já houver um marcador ou em "todas as contas";
  - em regra gerenciada, as condições somente leitura com o aviso "Gerenciada pela página Remetentes" / "Gerenciada pelo Hub" e a ação limitada à sua família;
  - os erros por índice em `conditions.N.value`;
  - o nome sugerido a partir de `describeConditions`;
  - a tabela usando `describeConditions`.
- [ ] Rodar: `npm run typecheck && npm run build && npm run test:js`. Esperado: tudo verde.
- [ ] Conferência manual no app local (`docker compose up -d`): criar os dois exemplos do dono, simular e ver a amostra; editar uma categoria vinda de Remetentes e ver as condições travadas.
- [ ] Commit: `feat: rules form with multiple conditions and a subject filter`.

### Tarefa 6: remover as colunas antigas e atualizar a documentação
**Arquivos:**
- Criar: `database/migrations/2026_11_25_000002_drop_filter_columns_from_retention_rules.php`
- Modificar: `app/Models/RetentionRule.php` (`FILTER_TYPES`/fillable), `docs/debito-tecnico.md`, `CLAUDE.md`, `SPEC.md` (se citar `filter_type`)
- Teste: `tests/Feature/Migrations/RetentionRuleConditionsMigrationTest.php`

**Passos:**
- [ ] Teste: depois de todas as migrations, `retention_rules` não tem `filter_type`/`filter_value`. Depois do `down()` da nova, as colunas voltam preenchidas com a primeira condição de cada regra.
- [ ] Rodar o teste. Esperado: FAIL.
- [ ] Implementar a migration. Remover as referências restantes: `grep -rn "filter_type\|filter_value" app resources database/factories tests` não pode encontrar nada fora das migrations e do teste de migration.
- [ ] Documentação:
  - em `docs/debito-tecnico.md`, remover o #19 e adicionar o #21 "Busca livre do Gmail nas regras (opção futura)", com impacto Funcionalidade, prioridade Baixa e o texto do caminho B: os parênteses, os operadores recusados (`in:anywhere`, `in:sent`, `is:starred`, `in:trash`, `in:spam`, `label:`), a simulação obrigatória e a combinação com E com as condições;
  - no `CLAUDE.md`, atualizar "Retention rules" e "Senders page" (condições, `kind`, regras gerenciadas, Hub).
- [ ] Verificação completa:
  - `docker exec inbox_app php artisan test` (com audit);
  - cobertura: `vendor/bin/phpunit --exclude-group=audit --coverage-text --only-summary-for-coverage-text` com `app/` em 100%;
  - `vendor/bin/pint --test`;
  - `npm run typecheck`, `npm run build`, `npm run test:security`, `npm run test:js`.
- [ ] Commit: `refactor: drop the single-filter columns; docs for combined conditions`.

### Final
- [ ] Autorrevisão do branch inteiro contra os "Pontos de atenção" (sem subagente, por regra do projeto).
- [ ] Mostrar ao dono para testar no app local antes do PR (regra "validar antes de commitar" do projeto: os commits ficam no branch, e o PR só abre com o ok dele).
- [ ] PR → CI verde → `gh pr merge <n> --rebase --delete-branch` → `git pull --ff-only` → CI da `main` verde. Sem tag.
