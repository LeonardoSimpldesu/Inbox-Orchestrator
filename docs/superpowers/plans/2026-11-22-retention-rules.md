# Regras de Expiração (TTL) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **This project forbids subagents: execute inline with superpowers:executing-plans.**

**Goal:** A `/retention-rules` page to create, simulate, activate and run per-account retention rules (category/domain/sender/label → archive/trash/add label) that run daily via Gmail search + batchModify.

**Architecture:** `RuleQuery` turns a rule into `(q, labelIds)` plus label changes. `RuleSimulator` lists (capped) and samples; `ExecuteRetentionRuleJob` lists all, `batchModify`s and mirrors locally via `MessageStore::applyLabelChange`. `inbox:apply-retention` runs hourly and dispatches rules not yet run since today's 03:00 (schedule timezone). Controllers expose CRUD, simulate, activate/pause, run now and labels.

**Tech Stack:** Laravel 13, google/apiclient (`users.labels`), Inertia 3 + React 19 + TS strict.

**Spec:** `docs/superpowers/specs/2026-11-22-retention-rules-design.md`

## Global Constraints

- `filter_type`: `category`|`domain`|`sender`|`label`. Categories: `promotions`→`CATEGORY_PROMOTIONS`, `updates`→`CATEGORY_UPDATES`, `social`→`CATEGORY_SOCIAL`, `forums`→`CATEGORY_FORUMS`. `action`: `archive`|`trash`|`add_label` (+`target_label` id, `archive_after_label`).
- Fixed exclusions in every query: `-is:starred -in:sent -in:drafts`. Age: `older_than:{N}d` when N > 0. Archive adds `in:inbox`.
- Days 0–3650; name 1–100 chars; domain lowercased hostname; sender lowercased valid e-mail; label must belong to the rule's account.
- New UI rules: `is_active = false`, `simulated_at = null`. Activate requires `simulated_at`. Edit → paused + `simulated_at = null`. Hub-created rules stay active.
- Simulation: list up to 10,001 ids (`capped` when > 10,000), sample = 20 oldest with sender/subject/date; log `simulated`.
- Execution logs: `success` (count, 0 included), `simulated`, `failed` (`error_message` = code only: `rate_limited`, `reauth_required`, `http_*`, `label_missing`, `account_unavailable`, `request_failed`). `label_missing` also pauses the rule.
- Schedule: `inbox:apply-retention` hourly, `withoutOverlapping()`; window starts 03:00 in `config('app.schedule_timezone')` (env `APP_SCHEDULE_TIMEZONE`, default `America/Sao_Paulo`).
- `throttle:30,1` on simulate, run now, create label. All lookups from `$user->retentionRules()` / `$user->emailAccounts()` (404 otherwise).
- Copy (pt-BR exact): "Regra criada.", "Regra atualizada. Simule de novo antes de ativar.", "Regra ativada.", "Regra pausada.", "Regra apagada.", "Simule a regra antes de ativá-la.", "Execução das regras iniciada.", "Nenhuma regra ativa para executar.", "Não foi possível simular agora. Tente de novo em alguns minutos.", "Reconecte a conta antes de usar as regras.", "Nunca são tocados: e-mails com estrela, enviados, rascunhos e spam."
- No N+1: list page, history, command and job use constant queries (tests compare small vs large). TDD, `app/` coverage 100%, code in English; `queue:restart` after PHP changes before browser tests.

## Review Focus

1. Rule edited after simulation and then activated with stale numbers → activation refused until re-simulated (Task 6 `test_editing_pauses_and_requires_new_simulation`).
2. Machine asleep at 03:00, wakes at 10:00 → rules run once that day, not twice (Task 5 `test_runs_once_after_window_even_if_late`).
3. Target label deleted in Gmail → run fails with `label_missing`, rule paused, nothing modified (Task 4).
4. Label id from another account posted in the form → rejected (Task 6 `test_label_must_belong_to_account`).
5. Huge result (> 10,000) in simulation → "mais de 10.000", listing stops at 10,001 ids (Task 4 `test_simulation_caps_at_10000`).

---

### Task 1: Schema and model

**Files:** migration `2026_11_22_000001_update_retention_rules_for_ttl_engine.php`; `app/Models/RetentionRule.php`; `config/app.php` (`schedule_timezone`); `.env.example` / `.env.production.example`; Tests `tests/Unit/Models/RetentionRuleTest.php`.

**Interfaces (produces):** columns `archive_after_label` (bool default false), `simulated_at` (datetime null), `is_active` default false, index `(email_account_id, is_active)`; `RetentionRule::FILTER_TYPES`, `CATEGORIES` (key → Gmail label id), `ACTIONS`; `canActivate(): bool`; `scopeActive`; `latestLogs` relation helper not needed (controller eager-loads `executionLogs` limited in PHP).

- [ ] Tests: `test_defaults_to_paused_and_unsimulated`, `test_can_activate_only_after_simulation`, `test_category_map`, `test_casts`. FAIL → implement → PASS → commit `feat: prepare retention rules schema`.

### Task 2: Provider — labels and filtered listing

**Files:** `EmailProviderInterface`, `GmailProviderService`, `FakeEmailProvider`, `GmailProviderServiceTest`.

**Interfaces:** `listAllMessageIds(EmailAccount, string $query, array $labelIds = [], ?int $limit = null): list<string>`; `listLabels(EmailAccount): list<array{id: string, name: string}>` (type user, sorted by name, cached 10 min under `gmail-labels:{account_id}`); `createLabel(EmailAccount, string $name): array{id: string, name: string}` (existing name → existing label; clears cache). Costs: labels.list 1, labels.create 5. Fake: `labels` array, `labelIds` filter on mailbox `labelIds`, `limit`.

- [ ] Tests: `test_list_all_message_ids_sends_label_ids_and_stops_at_limit`, `test_list_labels_returns_user_labels_sorted_and_cached`, `test_create_label_posts_name_and_clears_cache`, `test_create_label_returns_existing_on_conflict` (409 → list → match). Commit `feat: add Gmail labels and filtered listing`.

### Task 3: RuleQuery

**Files:** `app/Services/Retention/RuleQuery.php` (+ `LabelChange` DTO); Test `tests/Unit/Services/Retention/RuleQueryTest.php`.

**Interfaces:** `RuleQuery::for(RetentionRule $rule, ?string $targetLabelName = null): self` with `public string $query`, `public array $labelIds`, `public array $add`, `public array $remove`. Throws `LabelMissing` when action is `add_label` and name is null.

- [ ] Tests: `test_category_archive` (`-is:starred -in:sent -in:drafts older_than:15d in:inbox`, labelIds `[CATEGORY_PROMOTIONS]`, remove `[INBOX]`), `test_domain_trash_any_age` (`from:@amazon.com.br`, add `[TRASH]`, no `older_than`), `test_sender_filter`, `test_label_filter_uses_label_id`, `test_add_label_excludes_already_labeled` (`-label:"Notas Fiscais"`, quotes escaped), `test_add_label_and_archive`, `test_add_label_without_name_throws`. Commit `feat: translate retention rules into Gmail queries`.

### Task 4: Simulation and execution

**Files:** `app/Services/Retention/RuleSimulator.php` (+ `SimulationResult`), `app/Services/Retention/RuleExecutor.php`, `app/Jobs/ExecuteRetentionRuleJob.php`, `app/Exceptions/LabelMissing.php`; Tests `tests/Unit/Services/Retention/RuleSimulatorTest.php`, `RuleExecutorTest.php`, `tests/Unit/Jobs/ExecuteRetentionRuleJobTest.php`.

**Interfaces:** `RuleSimulator::simulate(RetentionRule): SimulationResult{total:int, capped:bool, sample: list<array{sender:string, subject:?string, date:string}>}` (writes `simulated_at` + log `simulated`); `RuleExecutor::execute(RetentionRule): int` (count; writes `last_run_at` + log `success`; on `LabelMissing` → log failed `label_missing`, pause, rethrow-free); job `ExecuteRetentionRuleJob(int $ruleId)` queue default, `$tries = 1`, `WithoutOverlapping("retention-rule-{id}")->dontRelease()`, `failed()` logs `request_failed`; skips paused rules; unsyncable account → log `account_unavailable`/`reauth_required`.

- [ ] Tests: simulator `test_simulation_counts_and_samples_oldest`, `test_simulation_caps_at_10000`, `test_simulation_marks_rule_and_logs`; executor `test_archive_rule_modifies_and_mirrors_locally`, `test_trash_and_label_actions`, `test_zero_matches_still_logged`, `test_label_missing_pauses_rule`, `test_execution_uses_constant_queries` (10 vs 600 local messages); job `test_skips_paused_rules`, `test_unsyncable_accounts_log_failure`, `test_provider_errors_log_codes`, `test_failed_hook_logs_request_failed`, `test_lock_and_queue`. Commit `feat: simulate and execute retention rules`.

### Task 5: Daily scheduling

**Files:** `app/Console/Commands/ApplyRetentionRulesCommand.php`; `routes/console.php`; Test `tests/Feature/Console/ApplyRetentionRulesCommandTest.php`.

**Interfaces:** `inbox:apply-retention` dispatches `ExecuteRetentionRuleJob` for active rules of syncable accounts with `last_run_at` null or before today's 03:00 (schedule tz), only when now ≥ 03:00 (schedule tz).

- [ ] Tests (travel time, tz `America/Sao_Paulo`): `test_does_nothing_before_three_am`, `test_runs_once_after_window_even_if_late`, `test_skips_paused_and_unsyncable`, `test_command_uses_constant_queries`, `test_scheduled_hourly_without_overlapping`. Commit `feat: run retention rules daily after 03:00`.

### Task 6: Controllers and routes

**Files:** `app/Http/Controllers/RetentionRuleController.php`, `app/Http/Controllers/AccountLabelController.php`, `app/Http/Requests/RetentionRuleRequest.php`; `routes/web.php`; Tests `tests/Feature/RetentionRules/*`.

**Interfaces:** routes per spec 6.8. Index props: `rules` (id, name, account_id, account_email, filter_type, filter_value, filter_label (display), retention_days, action, target_label, archive_after_label, is_active, can_activate, last_run_at, logs: last 10 {status, affected, error, executed_at}), `accounts` (id, account_email, needs_reauth), `simulation` (flash-like, only after simulate). Label names for display resolved via cached `listLabels` per account involved (one Gmail call per account, cached).

- [ ] Tests: `test_lists_only_own_rules_with_history_without_n_plus_one`, `test_create_rule_starts_paused`, `test_create_and_simulate`, `test_validation_rules` (days, domain, email, category, action/label), `test_label_must_belong_to_account`, `test_editing_pauses_and_requires_new_simulation`, `test_activate_requires_simulation`, `test_pause`, `test_delete_frees_hub_sender`, `test_simulate_endpoint_and_provider_errors`, `test_run_now_dispatches_active_rules_of_user`, `test_run_now_without_rules`, `test_foreign_rules_and_accounts_404`, `test_labels_endpoints`, `test_throttled`, `test_needs_reauth_account_refused`. Commit `feat: add retention rules routes`.

### Task 7: Frontend

**Files:** `resources/js/Pages/RetentionRules/Index.tsx`, `resources/js/Components/RetentionRuleForm.tsx`, `resources/js/Components/SimulationPanel.tsx`; types in `resources/js/types/index.d.ts`.

- [ ] Implement per spec 6.7 (table, actions, history toggle, modal form with label select loaded via `fetch('/accounts/{id}/labels')` + "+ Criar marcador", simulation panel with "Ativar regra"). `npm run typecheck && npm run build`. Playwright with the real accounts: create a rule, simulate (verify `from:@domain` and `-label:"…"` against real Gmail counts), activate/pause, history. **Do not run a real execution without the user's go-ahead.** Commit `feat: add retention rules page`.

### Task 8: Docs and checks

SPEC 3.5/3.6/4.3/6, README, CLAUDE.md, débito técnico (remove 18; add free query, combined filters, all-account rules). Full checks; commit `docs: document retention rules`.

Then: self-review, hand over test steps, wait for validation before merge.
