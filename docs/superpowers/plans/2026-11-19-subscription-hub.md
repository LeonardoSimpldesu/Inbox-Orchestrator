# Hub de Assinaturas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **This project forbids subagents: execute inline with superpowers:executing-plans.**

**Goal:** A `/subscriptions` page listing only subscription senders (100 per page) with Gmail deep links and queued unsubscribe (one-click, mailto, sender page, rule) plus optional archive/trash of existing mail.

**Architecture:** `EmailSender` gains subscription scopes and status fields; `MessageStore` recounts promotional mail. Unsubscribe runs in `UnsubscribeSenderJob` using `OneClickUnsubscriber` (SSRF-guarded HTTP) or `MailtoUnsubscriber` (Gmail send), then `SenderMailCleaner` (Gmail list + batchModify + bulk local update). `SubscriptionController` serves the list, actions and the sender-page redirect.

**Tech Stack:** Laravel 13 (Http client, queues, `throttle`), google/apiclient (`users.messages.send`, `batchModify`), Inertia 3 + React 19 + TS strict.

**Spec:** `docs/superpowers/specs/2026-11-19-subscription-hub-design.md`

## Global Constraints

- Subscription = `total_messages_received > 0` and (`unsubscribe_url` or `unsubscribe_mailto` not null or `total_messages_promotional > 0`); only non-disconnected accounts of the user.
- Statuses: `none`, `processing`, `requested`, `blocked`, `failed`. Methods: `one_click`, `mailto`, `page`, `rule`. Cleanup: `keep`, `archive`, `trash`. Tabs: `pending` (none/processing/failed, default), `handled` (requested/blocked).
- Error codes: `http_{status}`, `timeout`, `blocked_address`, `invalid_mailto`, `rate_limited`, `reauth_required`, `request_failed`.
- Gmail costs: send 100, batchModify 50 (≤1,000 ids), list 5 (maxResults 500). Archive = remove `INBOX`; trash = add `TRASH`.
- One-click: POST `List-Unsubscribe=One-Click` form body, https only, port 443/absent, no userinfo, all resolved IPs public, `CURLOPT_RESOLVE` pin, no redirects, connect 5 s / total 10 s, success = 2xx or 3xx.
- `gmail_url` = `https://mail.google.com/mail/u/?authuser={rawurlencode(account)}#search/from:{rawurlencode(sender)}`.
- Never expose `unsubscribe_url`/`unsubscribe_mailto` in props; never log URLs, addresses or subjects (log `sender_id` + code).
- No N+1: list, actions and cleanup run a constant number of queries (tests compare 1 vs many).
- Copy (pt-BR exact): "Desinscrição iniciada.", "Regra criada.", "Marcado como desinscrito.", "Reconecte a conta antes de se desinscrever.", rule names "Arquivar e-mails de {nome}" / "Mandar para a lixeira e-mails de {nome}" ({nome} = name or email).
- TDD, `app/` coverage 100%, code in English; PHP via `docker exec inbox_app`; `php artisan queue:restart` after PHP changes before browser tests.

## Review Focus

1. Unsubscribe URL resolving to a private/metadata IP, or DNS rebinding between check and connect → blocked, no request (Task 3 `test_blocks_host_with_any_private_ip`, `test_pins_connection_to_validated_ip`).
2. Double click / two tabs on "Desinscrever" → one job, one email sent (Task 5 `test_processing_sender_is_not_queued_twice`).
3. Cleanup over thousands of messages → paginated list + 1,000-id blocks, constant DB queries (Task 4 `test_cleanup_uses_constant_queries`).
4. Sender of another user / disconnected account via id guessing → 404 on list filter, actions and page redirect (Task 5).
5. Mailto with header injection (`%0d%0a` in subject/body/address) → rejected or sanitized, never extra headers (Task 3 `test_mailto_rejects_header_injection`).

---

### Task 1: Schema, subscription scope, promotional count

**Files:** Create migration `2026_11_19_000004_add_subscription_columns_to_email_senders.php`; Modify `app/Models/EmailSender.php`, `app/Services/Sync/MessageStore.php`, `app/Http/Controllers/DashboardController.php`, `docs/debito-tecnico.md` (drop item 13); Tests `tests/Unit/Models/EmailSenderTest.php`, `tests/Unit/Services/Sync/MessageStoreTest.php`, `tests/Feature/DashboardTest.php`.

**Interfaces (produces):**
- Columns `unsubscribe_method`, `unsubscribe_requested_at` (datetime), `unsubscribe_error`, `total_messages_promotional` (int default 0); index `(email_account_id, unsubscribe_status)`.
- `EmailSender` constants `STATUS_NONE|PROCESSING|REQUESTED|BLOCKED|FAILED`; `scopeSubscriptions(Builder)`; `scopePendingSubscriptions`, `scopeHandledSubscriptions`; `unsubscribeOptions(): array{one_click: bool, mailto: bool, page: bool}` (`one_click` = flag + url; `mailto` = mailto present; `page` = url present).
- MessageStore recount also sets `total_messages_promotional`.
- Dashboard `stats.totalSenders` → pending subscriptions count.

- [ ] Step 1 tests: `test_subscription_scope_criteria` (header / promo only / none / zero totals), `test_pending_and_handled_scopes`, `test_unsubscribe_options`; MessageStore `test_promotional_messages_are_counted` (trashed promo excluded), existing constant-query test still equal; Dashboard `test_dashboard_counts_pending_subscriptions_only`.
- [ ] Step 2 FAIL → Step 3 implement → Step 4 PASS (+ `--exclude-group=audit`) → Step 5 commit `feat: add subscription fields and scopes to senders`.

### Task 2: Provider — send, list all, batch modify

**Files:** Modify `app/Contracts/EmailProviderInterface.php`, `app/Services/Providers/GmailProviderService.php`, `tests/Fakes/FakeEmailProvider.php`, `tests/Unit/Services/GmailProviderServiceTest.php`.

**Interfaces:** `sendMessage(EmailAccount, string $to, string $subject, string $body): void`; `listAllMessageIds(EmailAccount, string $query): list<string>`; `batchModify(EmailAccount, list<string> $ids, list<string> $add, list<string> $remove): void`. Fake: `sent: list<array{account_id,to,subject,body}>`, `modified: list<array{ids,add,remove}>`, `listAllMessageIds` uses the same mailbox filter plus `from:` support.

- [ ] Step 1 tests: `test_send_message_posts_raw_rfc2822` (decode base64url `raw`: `From: <account>`, `To`, `Subject: =?UTF-8?B?…?=`, `Content-Type: text/plain; charset=UTF-8`, body), `test_list_all_message_ids_follows_pages` (500 per page, 2 pages), `test_batch_modify_splits_in_blocks_of_1000` (2,500 ids → 3 requests with add/remove), `test_batch_modify_with_no_ids_makes_no_request`, `test_send_charges_100_quota_units` (limit 150: two sends → one sleep).
- [ ] Steps 2–5; commit `feat: add Gmail send, full listing and batch modify`.

### Task 3: Unsubscribers (one-click with SSRF guard, mailto)

**Files:** Create `app/Services/Unsubscribe/HostResolver.php` (interface `resolve(string $host): list<string>`), `DnsHostResolver.php` (`dns_get_record` A+AAAA), `UnsubscribeFailed.php` (exception, message = code), `OneClickUnsubscriber.php`, `MailtoParser.php` (+ `ParsedMailto` DTO), `MailtoUnsubscriber.php`; bind resolver in `AppServiceProvider`; Tests `tests/Unit/Services/Unsubscribe/*`.

**Interfaces:** `OneClickUnsubscriber::unsubscribe(string $url): void` throws `UnsubscribeFailed`; `OneClickUnsubscriber::guard(string $url): array{host: string, ip: string}` (public for tests); `MailtoParser::parse(string $mailto): ParsedMailto(to, subject, body)` throws `UnsubscribeFailed('invalid_mailto')`; `MailtoUnsubscriber::unsubscribe(EmailAccount, string $mailto): void`.

- [ ] Step 1 tests (Http::fake + fake resolver): `test_posts_one_click_body`, `test_3xx_counts_as_success_and_is_not_followed`, `test_4xx_5xx_fail_with_status_code`, `test_timeout_fails_with_timeout`, `test_rejects_http_userinfo_and_odd_ports`, `test_blocks_private_loopback_linklocal_and_metadata_ips` (10.x, 127.0.0.1, 169.254.169.254, 192.168.x, ::1, fc00::1, fe80::1, ::ffff:127.0.0.1), `test_blocks_host_with_any_private_ip`, `test_blocks_unresolvable_host`, `test_pins_connection_to_validated_ip` (options contain `CURLOPT_RESOLVE => ['host:443:ip']`, `allow_redirects => false`, timeouts), `test_mailto_defaults_and_params`, `test_mailto_rejects_header_injection`, `test_mailto_invalid_address`, `test_mailto_unsubscriber_sends_via_provider`.
- [ ] Steps 2–5; commit `feat: add SSRF-safe one-click and mailto unsubscribers`.

### Task 4: Cleanup and job

**Files:** Create `app/Services/Unsubscribe/SenderMailCleaner.php`, `app/Jobs/UnsubscribeSenderJob.php`; Modify `app/Services/Sync/MessageStore.php` (add `applyLabelChange(EmailAccount, list<string> $providerIds, list<string> $add, list<string> $remove): void` bulk local update + recount); Tests `tests/Unit/Services/Unsubscribe/SenderMailCleanerTest.php`, `tests/Unit/Jobs/UnsubscribeSenderJobTest.php`.

**Interfaces:** `SenderMailCleaner::clean(EmailSender $sender, string $cleanup): void` (`keep` no-op); job `UnsubscribeSenderJob(int $senderId, string $method, string $cleanup)` queue `default`, `$tries = 1`, `WithoutOverlapping("unsubscribe-sender-{id}")->dontRelease()`.

- [ ] Step 1 tests: cleaner `test_archive_removes_inbox_for_all_sender_mail`, `test_trash_adds_trash_label`, `test_keep_does_nothing`, `test_updates_local_flags_and_totals`, `test_cleanup_uses_constant_queries` (10 vs 300 local messages); job `test_one_click_success_marks_requested_and_cleans`, `test_mailto_success_marks_requested`, `test_failure_marks_failed_and_skips_cleanup`, `test_page_and_rule_methods_only_clean`, `test_rate_limit_and_reauth_map_to_codes`, `test_skips_unsyncable_accounts`, `test_logs_only_sender_id_and_code`.
- [ ] Steps 2–5; commit `feat: run unsubscribe and mail cleanup in a queued job`.

### Task 5: Controller and routes

**Files:** Create `app/Http/Controllers/SubscriptionController.php`, `app/Http/Requests/UnsubscribeRequest.php`; Modify `routes/web.php`; Tests `tests/Feature/Subscriptions/SubscriptionListTest.php`, `SubscriptionActionsTest.php`.

**Interfaces:** `GET /subscriptions` (`subscriptions.index`) → Inertia `Subscriptions/Index` props `subscriptions` (paginator: data rows per spec 6.3, links/meta), `accounts` (id, account_email), `filters` {account, status}, `counts` {pending, handled}; `POST /subscriptions/{sender}/unsubscribe` (`throttle:30,1`); `GET /subscriptions/{sender}/page`.

- [ ] Step 1 tests: list `test_lists_only_subscriptions_of_user`, `test_paginates_by_100_ordered_by_volume`, `test_account_filter_ignores_foreign_account`, `test_tabs_and_counts`, `test_emails_after_request_count`, `test_props_never_contain_unsubscribe_targets`, `test_gmail_url_is_encoded`, `test_list_uses_constant_queries` (1 vs 150 senders); actions `test_one_click_marks_processing_and_queues_job`, `test_mailto_queues_job`, `test_page_marks_requested_and_queues_cleanup_only_when_asked`, `test_rule_creates_retention_rule_and_blocks`, `test_unavailable_method_is_422`, `test_foreign_or_disconnected_sender_is_404`, `test_needs_reauth_account_is_refused`, `test_processing_sender_is_not_queued_twice`, `test_actions_are_throttled`, `test_page_redirects_to_sender_url_only_for_owner`, `test_guests_are_redirected`.
- [ ] Steps 2–5; commit `feat: add subscription hub routes`.

### Task 6: Frontend

**Files:** Create `resources/js/Pages/Subscriptions/Index.tsx`, `resources/js/Components/UnsubscribeDialog.tsx`; Modify `resources/js/types/index.d.ts` (`SubscriptionRow`, `Paginated<T>`), `resources/js/Layouts/AppLayout.tsx` (no change if link already `/subscriptions`).

- [ ] Step 1: implement per spec 6.3/6.4/6.10 (account select + tabs via `router.get` with `preserveState`, table, pagination links, dialog with method explanation and cleanup radios / rule checkbox, `usePoll(5000, {only:['subscriptions']}, {autoStart:false})` while any row `processing`, page link `target="_blank" rel="noopener noreferrer"` to `/subscriptions/{id}/page`).
- [ ] Step 2: `npm run typecheck && npm run build`.
- [ ] Step 3: Playwright with the real accounts: list, filters, open in Gmail link, dialog; perform one real one-click unsubscribe **only with the user's go-ahead** (it acts on their mailbox) — otherwise verify with a sender whose method is `page` (no external effect) and the rule path with cleanup `keep`.
- [ ] Step 4: commit `feat: add subscription hub page`.

### Task 7: Docs and checks

SPEC 3.3/4.2/6, README, CLAUDE.md, débito técnico (remove 13, add bulk actions/search). Full checks (PHPUnit incl. audit, coverage 100%, typecheck, build, test:security, pint). Commit `docs: document subscription hub`.

Then: self-review, hand over test steps, wait for validation before merge.
