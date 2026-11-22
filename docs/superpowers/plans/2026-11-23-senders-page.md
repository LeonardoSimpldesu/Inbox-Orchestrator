# Página "Remetentes" — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **This project forbids subagents: execute inline with superpowers:executing-plans.**

**Goal:** A `/senders` page to categorize non-subscription senders with Gmail labels (existing + future mail via a sender add_label rule) and to clean their mail, one by one or in bulk.

**Architecture:** The category of a sender *is* its `retention_rules` row (`filter_type=sender`, `action=add_label`). `SenderCategories` reads/writes those rules in bulk; `ApplySenderLabelJob` relabels existing mail; `CleanSenderMailJob` wraps `SenderMailCleaner`. `SenderController` serves list, categorize and clean.

**Tech Stack:** Laravel 13, Inertia 3 + React 19 + TS strict, existing provider/fake.

**Spec:** `docs/superpowers/specs/2026-11-23-senders-page-design.md`

## Global Constraints

- List: `$user->senders()`, `total_messages_received > 0`; default `nonSubscriptions()`; `all=1` includes all; filters `account`, `category` (label id | `none`), `q`; order `total desc, email asc`; `paginate(100)`; constant queries.
- Category rule: `filter_type=sender`, `filter_value=email`, `action=add_label`, `target_label=<id>`, `retention_days=0`, `is_active=true`, `simulated_at=now`, name `"Categoria {label}: {name|email}"` (max 100). At most one per sender/account.
- Clean future rule: `action=archive|trash`, 0 days, active, simulated, names `"Arquivar e-mails de {x}"` / `"Mandar para a lixeira e-mails de {x}"`; never duplicated.
- Copy (pt-BR): "Categoria aplicada a N remetente(s).", "Categoria removida de N remetente(s).", "Selecione remetentes de uma mesma conta para escolher a categoria.", "Escolha um marcador desta conta.", "Reconecte a conta antes de organizar os remetentes.", "Limpeza iniciada para N remetente(s).", " Regra criada para os próximos e-mails.", "Nenhum remetente selecionado pode ser limpo agora."
- Jobs: queue default; locks `sender-label-{id}` / `sender-clean-{id}` (dontRelease, expireAfter 1600) + `sync-account-{accountId}` (releaseAfter 60, expireAfter 1600); `tries=10`, `maxExceptions=1`, `timeout=1500`; logs `sender_id` + code only.
- `throttle:30,1` on POST routes. TDD, 100% `app/`, `queue:restart` before browser tests.

## Review Focus

1. Category change while a previous apply is running for the same sender → second job waits (lock), final labels = newest category (Task 2 `test_lock_and_account_lock`).
2. Bulk categorize mixing accounts → refused, nothing changed (Task 3 `test_categorize_requires_single_account`).
3. Rule deleted on the Rules page → sender shows "sem categoria" (Task 3 `test_category_follows_the_rule`).
4. Sender ids from another user in bulk → ignored (Task 3/4 isolation tests).
5. Clean with `future` twice → one rule only (Task 4 `test_future_rule_is_not_duplicated`).

---

### Task 1: Model support and category reader/writer

**Files:** migration `2026_11_23_000002_add_label_applying_at_to_email_senders.php`; `app/Models/EmailSender.php` (`scopeNonSubscriptions`, cast); `app/Services/Senders/SenderCategories.php`; Test `tests/Unit/Services/Senders/SenderCategoriesTest.php`, `tests/Unit/Models/EmailSenderTest.php`.

**Interfaces:** `SenderCategories::forSenders(Collection<EmailSender>): array<int senderId, ?string labelId>` (one query); `SenderCategories::assign(EmailAccount, Collection<EmailSender>, ?string $labelId, ?string $labelName): array<int senderId, array{old: ?string, new: ?string}>` (bulk insert/update/delete; only changed senders returned; constant queries); `EmailSender::scopeNonSubscriptions`.

- [ ] Tests: `test_non_subscriptions_scope`, `test_reads_categories_in_one_query`, `test_assign_creates_updates_and_deletes_rules`, `test_assign_skips_unchanged`, `test_assign_uses_constant_queries`. FAIL → implement → PASS → commit `feat: read and write sender categories as label rules`.

### Task 2: Jobs

**Files:** `app/Jobs/ApplySenderLabelJob.php`, `app/Jobs/CleanSenderMailJob.php`; Tests `tests/Unit/Jobs/ApplySenderLabelJobTest.php`, `CleanSenderMailJobTest.php`.

**Interfaces:** `ApplySenderLabelJob(int $senderId, int $accountId, ?string $newLabel, ?string $oldLabel)`; `CleanSenderMailJob(int $senderId, int $accountId, string $cleanup)`.

- [ ] Tests: apply `test_adds_new_and_removes_old_label_on_all_sender_mail`, `test_removing_category_only_removes`, `test_clears_applying_flag_on_success_error_and_failed`, `test_skips_unsyncable_accounts`, `test_lock_and_account_lock`; clean `test_cleans_with_sender_mail_cleaner`, `test_errors_are_logged_with_codes`, `test_lock_and_account_lock`. Commit `feat: apply sender labels and clean sender mail in jobs`.

### Task 3: List and categorize routes

**Files:** `app/Http/Controllers/SenderController.php`; `routes/web.php`; Tests `tests/Feature/Senders/SenderListTest.php`, `SenderCategorizeTest.php`. Extract the Hub's `search()` into a reusable `EmailSender::scopeSearch(Builder, string)` and use it in both controllers.

- [ ] Tests: list `test_lists_non_subscriptions_by_default`, `test_all_includes_subscriptions_with_flag`, `test_filters_account_category_and_search`, `test_category_names_and_fallback`, `test_list_uses_constant_queries`, `test_isolated_per_user`; categorize `test_categorize_creates_rule_and_queues_job`, `test_change_and_remove_category`, `test_same_label_queues_nothing`, `test_categorize_requires_single_account`, `test_label_must_belong_to_account`, `test_needs_reauth_refused`, `test_category_follows_the_rule`, `test_validation_and_throttle`, `test_categorize_uses_constant_queries`. Commit `feat: list and categorize senders`.

### Task 4: Clean route

**Files:** `SenderController::clean`; Test `tests/Feature/Senders/SenderCleanTest.php`.

- [ ] Tests: `test_clean_queues_jobs_across_accounts`, `test_future_creates_rule`, `test_future_rule_is_not_duplicated`, `test_needs_reauth_accounts_skipped`, `test_nothing_cleanable`, `test_validation_and_isolation`. Commit `feat: clean sender mail from the senders page`.

### Task 5: Frontend

**Files:** `resources/js/Pages/Senders/Index.tsx`, `resources/js/Components/SenderCategorySelect.tsx`, `resources/js/Components/CleanSenderDialog.tsx`, types, `AppLayout.tsx` (menu "Remetentes" between "Assinaturas" and "Regras TTL").

- [ ] Implement per spec 6.5; typecheck/build; Playwright with real accounts (list, filters, category select loads labels; **no real categorize/clean without the user's go-ahead**). Commit `feat: add senders page`.

### Task 6: Docs and checks

SPEC (4.4), README, CLAUDE.md; full checks (PHPUnit incl. audit, coverage, typecheck, build, test:security, test:js, pint). Commit `docs: document senders page`.
