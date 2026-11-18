# Sincronização de metadados do Gmail — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **This project forbids subagents: execute inline with superpowers:executing-plans.**

**Goal:** Import Gmail message metadata and aggregated senders per connected account in budgeted stages (recent, history, changes), without duplicates or N+1 queries.

**Architecture:** `EmailProviderInterface` grows list/metadata/history methods (implemented in `GmailProviderService` with HTTP batch). `HeaderParser` (pure) and `MessageStore` (bulk upserts + aggregate recount) persist batches. `EmailSyncService` runs one `SyncMode` per job with a budget; `SyncEmailAccountJob` adds the per-account lock. Commands + scheduler drive `Changes` (5 min) and `History` (10 min); routes expose "Importar histórico agora" and "Sincronizar".

**Tech Stack:** Laravel 13 (Eloquent `upsert`, `WithoutOverlapping`, `Schedule`), google/apiclient 2.20 (`Google\Http\Batch`, `Google\Service\Gmail`), Guzzle `MockHandler`, Inertia 3 + React 19 + TS strict (`usePoll`).

**Spec:** `docs/superpowers/specs/2026-11-18-gmail-sync-design.md`

## Global Constraints

- Recent: `after:{now-90d epoch}`, cap **5.000** messages or **20 min**. History (scheduled): **500** messages per account per run, every **10 min**. HistoryNow: **20 min**. Changes: every **5 min**, 20 min budget.
- Gmail list excludes spam/trash (no `includeSpamTrash`). Metadata headers: `From`, `Subject`, `List-Unsubscribe`, `List-Unsubscribe-Post`. Batch size 100, list page size 100.
- **No N+1:** a 100-message batch is stored with a constant number of queries independent of batch size (tests assert the count is equal for 1 and 100 messages). Commands eager-load `user`. `summary()` does no queries.
- Upsert key `(email_account_id, provider_message_id)`; sender totals always **recomputed** from stored messages, never incremented.
- Never log tokens, subjects or addresses: logs carry `account_id` + error type only.
- Queue: jobs on queue `sync`; worker `--queue=default,sync --timeout=1500`; `DB_QUEUE_RETRY_AFTER=1600`; job `$timeout = 1500`, `$tries = 1`.
- Copy (pt-BR, exact): "Importando e-mails recentes…", "Histórico importado até dd/mm/aaaa", "Histórico completo", "sincronizado há …", "Importar histórico agora", "Importação do histórico iniciada. Pode levar até 20 minutos.", "Reconecte a conta antes de importar o histórico.", "Sincronização iniciada.", "Nenhuma conta conectada para sincronizar."
- TDD, `app/` coverage 100%, code in English, PHP via `docker exec inbox_app`, npm on host.

## Review Focus

1. Same batch stored twice / overlapping `before:` boundary → no duplicate rows, totals unchanged (Task 3 `test_storing_same_batch_twice_is_idempotent`).
2. Query count grows with batch size (N+1) → constant (Task 3 `test_batch_uses_constant_number_of_queries`).
3. Messages arriving during Recent are lost → `history_id` captured **before** listing (Task 4 `test_recent_captures_history_id_before_listing`).
4. Account disconnected or token revoked mid-run → stops, keeps saved batches, no exception escapes (Task 4).
5. 20-minute job re-delivered by the queue mid-run → `retry_after` > timeout (Task 5 `test_queue_retry_after_exceeds_sync_job_timeout`).

---

### Task 1: Schema, header parser, account sync summary

**Files:**
- Create: `database/migrations/2026_11_19_000002_add_sync_progress_columns.php`, `app/Services/Sync/HeaderParser.php`, `app/Services/Sync/ParsedSender.php`, `app/Services/Sync/ParsedUnsubscribe.php`, `tests/Unit/Services/Sync/HeaderParserTest.php`
- Modify: `app/Models/EmailAccount.php`, `app/Models/EmailSender.php`, `resources/js/types/index.d.ts`, `tests/Unit/Models/EmailAccountTest.php`

**Interfaces:**
- Produces: columns `email_accounts.oldest_synced_at`, `email_accounts.history_completed_at` (datetime casts, fillable); `email_senders.unsubscribe_one_click` (bool, default false, cast).
- `EmailAccount::syncState(): 'importing_recent'|'importing_history'|'complete'` (`history_id` null → importing_recent; `history_completed_at` set → complete). `summary()` adds `sync: {state, oldest_synced_at: ?Y-m-d, last_synced_at: ?ISO8601}` and keeps `last_synced_at`.
- `HeaderParser::sender(?string $from): ?ParsedSender` (`email`, `domain`, `name`); `HeaderParser::unsubscribe(?string $listUnsubscribe, ?string $listUnsubscribePost): ParsedUnsubscribe` (`mailto`, `url`, `oneClick`); `HeaderParser::subject(?string): ?string` (max 255).

- [ ] **Step 1: Failing tests.** HeaderParser: `test_sender_with_quoted_name`, `test_sender_without_name`, `test_sender_lowercases_and_takes_last_at_domain`, `test_sender_without_address_returns_null` (`''`, `null`, `Mailer-Daemon`), `test_unsubscribe_mailto_and_https`, `test_unsubscribe_ignores_http`, `test_one_click_requires_https_and_post_header`, `test_empty_unsubscribe`, `test_subject_is_truncated_to_255`. EmailAccount: `test_sync_state_transitions`, `test_summary_includes_sync_progress_without_queries` (assert `DB::getQueryLog()` empty while calling `summary()` on a loaded model; keys of `sync`).
- [ ] **Step 2:** `--filter='HeaderParserTest|EmailAccountTest'` → FAIL.
- [ ] **Step 3:** Implement migration, casts, parser (RFC-ish: `/<([^>]+)>/` for angle form, else bare address; validate with `filter_var(FILTER_VALIDATE_EMAIL)`), summary; TS type `EmailAccountSummary.sync`.
- [ ] **Step 4:** PASS; `npm run typecheck` ok. **Step 5:** Commit `feat: add sync progress columns and header parser`.

### Task 2: Provider — list, metadata batch, history

**Files:**
- Create: `app/Services/Providers/MessagePage.php`, `ChangePage.php`, `MessageMetadata.php`, `app/Exceptions/HistoryExpired.php`, `app/Exceptions/ProviderUnavailable.php`
- Modify: `app/Contracts/EmailProviderInterface.php`, `app/Services/Providers/GmailProviderService.php`, `tests/Fakes/FakeEmailProvider.php`, `tests/Unit/Services/GmailProviderServiceTest.php`

**Interfaces:**
- `currentHistoryId(EmailAccount): string`
- `listMessageIds(EmailAccount, string $query, ?string $pageToken = null, int $maxResults = 100): MessagePage` — `MessagePage(list<string> $ids, ?string $nextPageToken)`
- `fetchMetadata(EmailAccount, list<string> $ids): list<MessageMetadata>` — `MessageMetadata(string $id, string $threadId, list<string> $labelIds, CarbonImmutable $internalDate, ?string $from, ?string $subject, ?string $listUnsubscribe, ?string $listUnsubscribePost)`; 404 items omitted; header names case-insensitive.
- `listChanges(EmailAccount, string $startHistoryId, ?string $pageToken = null): ChangePage` — `ChangePage(list<string> $changedIds, list<string> $deletedIds, string $historyId, ?string $nextPageToken)`; history types `messageAdded, messageDeleted, labelAdded, labelRemoved`; an id both changed and deleted counts as deleted only.
- Errors: 404 on history → `HistoryExpired`; 429/5xx or network → `ProviderUnavailable` (message = error type); all call `refreshAccessToken()` first.
- `FakeEmailProvider`: in-memory mailbox (`messages: array<id, MessageMetadata>`, `historyId`, scripted `changes` pages, `listCalls` log, optional exceptions per method); `listMessageIds` honours `after:`/`before:` epoch filters on `internalDate`, newest first, paginated.

- [ ] **Step 1: Failing tests** (MockHandler recorder from the existing test): `test_current_history_id_reads_profile`, `test_list_message_ids_sends_query_and_paginates`, `test_list_message_ids_empty_page`, `test_fetch_metadata_uses_one_batch_request_per_100_ids` (150 ids → 2 HTTP requests; parse a `multipart/mixed` fixture), `test_fetch_metadata_omits_not_found_items`, `test_list_changes_collects_changed_and_deleted_ids`, `test_history_404_throws_history_expired`, `test_rate_limit_throws_provider_unavailable`, `test_refreshes_expired_token_before_listing`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement with `Google\Service\Gmail` (`users_messages->listUsersMessages`, `users_history->listUsersHistory`) and `$client->setUseBatch(true)` + `new Google\Http\Batch($client)`; map `Google\Service\Exception` codes. **Step 4:** PASS. **Step 5:** Commit `feat: add Gmail listing, metadata batch and history to provider`.

### Task 3: MessageStore (bulk, no N+1)

**Files:** Create `app/Services/Sync/MessageStore.php`, `tests/Unit/Services/Sync/MessageStoreTest.php`, `database/factories/EmailMessageMetaFactory.php` (if useful)

**Interfaces:**
- `MessageStore::store(EmailAccount $account, list<MessageMetadata> $batch): void` — transaction: senders upsert (`email_account_id,email`), select their ids (one `whereIn`), messages upsert with `email_sender_id`, `labels` JSON-encoded, flags; recount affected senders in **one** aggregate query (`GROUP BY email_sender_id`) and write totals with **one** upsert; unsubscribe fields only from the newest message per sender and only when newer than stored `last_email_received_at`; `oldest_synced_at = min(current, batch min)`.
- `MessageStore::delete(EmailAccount $account, list<string> $providerIds): void` — delete rows, recount affected senders (same bulk path).

- [ ] **Step 1: Failing tests:** `test_stores_messages_and_senders`, `test_storing_same_batch_twice_is_idempotent`, `test_totals_and_unread_are_recomputed`, `test_trashed_or_spam_message_leaves_totals`, `test_archived_flag_from_inbox_label`, `test_delete_removes_rows_and_recounts`, `test_unsubscribe_comes_from_newest_message`, `test_older_message_does_not_overwrite_unsubscribe`, `test_unsubscribe_status_is_preserved`, `test_message_without_sender_has_no_sender_row`, `test_oldest_synced_at_only_moves_back`, `test_senders_are_isolated_per_account`, `test_batch_uses_constant_number_of_queries` (count queries for a 1-message and a 100-message batch with distinct senders → equal).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS. **Step 5:** Commit `feat: store message metadata and sender totals in bulk`.

### Task 4: Sync service, modes and job

**Files:**
- Create: `app/Services/Sync/SyncMode.php` (enum `Recent`, `Changes`, `History`, `HistoryNow`), `app/Services/EmailSyncService.php`, `tests/Unit/Services/EmailSyncServiceTest.php`
- Modify: `app/Jobs/SyncEmailAccountJob.php` (+ `SyncMode $mode = SyncMode::Recent`, queue `sync`, `$timeout`, `$tries`, `middleware()`), `app/Http/Controllers/EmailAccountController.php` (dispatch keeps Recent), `tests/Unit/Jobs/SyncEmailAccountJobTest.php`

**Interfaces:**
- `EmailSyncService::run(EmailAccount $account, SyncMode $mode): void` — budgets per Global Constraints via `SyncMode::messageLimit(): ?int` and `SyncMode::timeLimitSeconds(): int`; time read from `now()` so tests use `travel`.
- Job `middleware()`: `WithoutOverlapping("sync-account-{$id}")` → `dontRelease()` for `Changes`/`History`, `releaseAfter(60)` for `Recent`/`HistoryNow`; `expireAfter(1600)`.

- [ ] **Step 1: Failing tests** (FakeEmailProvider): `test_recent_imports_last_90_days_only`, `test_recent_stops_at_5000_messages`, `test_recent_captures_history_id_before_listing`, `test_recent_with_empty_window_sets_oldest_to_window_start`, `test_changes_applies_changes_and_advances_history_id`, `test_changes_without_history_id_runs_recent`, `test_expired_history_reimports_recent_without_duplicates`, `test_history_continues_before_oldest_and_stops_at_500`, `test_history_marks_complete_on_empty_page`, `test_history_does_nothing_before_recent`, `test_time_budget_stops_between_batches`, `test_stops_when_account_disconnected_mid_run`, `test_reauthorization_required_stops_quietly`, `test_provider_unavailable_stops_and_logs_without_secrets`, `test_last_synced_at_updated_on_success`; job: `test_job_runs_service_with_mode`, `test_job_uses_sync_queue_and_lock`, existing guard tests kept.
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS. **Step 5:** Commit `feat: run budgeted Gmail sync modes`.

### Task 5: Commands, schedule, queue config

**Files:** Create `app/Console/Commands/SyncAccountsCommand.php` (`inbox:sync`), `app/Console/Commands/ImportHistoryCommand.php` (`inbox:import-history`), `tests/Feature/Console/SyncCommandsTest.php`; Modify `routes/console.php`, `docker-compose.yml`, `.env.example`, `.env.production.example`, `tests/Security/EnvironmentHardeningTest.php` (or new `tests/Feature/QueueConfigTest.php`)

**Interfaces:** `EmailAccount::scopeSyncable(Builder)` — not trashed, user not disabled, `reauth_required_at` null (testing-window expiry filtered in PHP via `needsReauth()` after eager loading `user`).

- [ ] **Step 1: Failing tests:** `test_sync_dispatches_changes_for_eligible_accounts_only`, `test_import_history_skips_complete_and_recent_pending`, `test_commands_use_constant_queries` (3 vs 30 accounts), `test_schedule_registers_sync_every_five_and_history_every_ten_minutes` (inspect `Schedule::events()`), `test_queue_retry_after_exceeds_sync_job_timeout` (`config('queue.connections.database.retry_after') > (new SyncEmailAccountJob(1))->timeout` with the `.env.example` value), `test_docker_queue_worker_listens_to_sync_queue_with_long_timeout`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement; `config/queue.php` default `DB_QUEUE_RETRY_AFTER` → 1600. **Step 4:** PASS. **Step 5:** Commit `feat: schedule Gmail sync and history import`.

### Task 6: Routes and frontend

**Files:** Modify `routes/web.php`, `app/Http/Controllers/EmailAccountController.php` (`importHistory`, `syncAll`), `resources/js/Pages/Accounts/Index.tsx`, `resources/js/Pages/Dashboard.tsx`, `resources/js/lib/gmail.ts`; Test `tests/Feature/Accounts/SyncRoutesTest.php`

**Interfaces:** `POST /accounts/{account}/import-history` (`accounts.import-history`, `whereNumber`), `POST /accounts/sync` (`accounts.sync`).

- [ ] **Step 1: Failing tests:** `test_import_history_dispatches_history_now_for_own_account`, `test_import_history_404_for_other_users_account`, `test_import_history_refused_when_needs_reauth`, `test_sync_all_dispatches_changes_for_own_eligible_accounts`, `test_sync_all_without_accounts`, `test_accounts_page_exposes_sync_progress`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement controller + routes; frontend per spec 6.7 (`usePoll(15000)` only while some account is `importing_recent`; relative time helper in `lib/gmail.ts`); wire dashboard "Sincronizar" and format "Última sinc.". **Step 4:** PASS; `npm run typecheck && npm run build`. **Step 5:** Playwright check with the real connected Gmail (user's account already connected): run `docker exec inbox_app php artisan queue:work --queue=sync --once` style or rely on the worker; see recent import complete, history progress line, buttons. **Step 6:** Commit `feat: show sync progress and add history import and sync buttons`.

### Task 7: Docs and full checks

**Files:** `SPEC.md`, `docs/debito-tecnico.md` (push notifications; >1 week stale outside the 3-month window), `README.md` (schedules, `sync` queue, `inbox:sync`/`inbox:import-history`), `CLAUDE.md` (sync layer, upsert/recount and no-N+1 invariants).

- [ ] **Step 1:** Edit docs. **Step 2:** Full checks: `php artisan test` (incl. audit), coverage 100%, `npm run typecheck`, `npm run build`, `npm run test:security`, `pint --test`. **Step 3:** Commit `docs: document Gmail sync`.

Then: self-review of the branch, hand over test steps, wait for the user's validation before merging.
