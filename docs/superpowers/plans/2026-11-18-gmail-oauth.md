# Conexão Gmail (OAuth 2.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **This project forbids subagents: execute inline with superpowers:executing-plans.**

**Goal:** Users connect, reconnect and disconnect personal Gmail accounts through OAuth 2.0, with encrypted tokens, visible reconnect status and an initial sync job dispatched.

**Architecture:** `GmailProviderService` wraps `Google\Client` behind `EmailProviderInterface` (bound in the container, faked in feature tests). `EmailAccountController` handles the redirect/callback/disconnect flow; account state is derived on `EmailAccount`. Disconnect is a soft delete after revoking at Google.

**Tech Stack:** Laravel 13, google/apiclient 2.20 (`Google\Client`, `Google\Service\Gmail`), Guzzle `MockHandler` for provider tests, Inertia 3 + React 19 + TypeScript strict.

**Spec:** `docs/superpowers/specs/2026-11-18-gmail-oauth-design.md`

## Global Constraints

- Scopes exactly: `https://www.googleapis.com/auth/gmail.modify`, `https://www.googleapis.com/auth/gmail.send`. Auth URL params: `access_type=offline`, `prompt=consent`, `include_granted_scopes=false`.
- Config: `services.google.{client_id, client_secret, redirect, testing}`; env `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` (default `${APP_URL}/accounts/google/callback`), `GOOGLE_OAUTH_TESTING` (default `true`).
- Testing-mode window: 7 days from `authorized_at`; `expiring_soon` when ≤ 2 days remain.
- Tokens: `encrypted` cast + `$hidden`; never in props, logs (including the OAuth `code`) or queue payloads.
- One Gmail active (not soft-deleted) per system; rejection paths discard tokens **without** revoking.
- All routes inside `['auth','auth.session','active']`. Account lookups via `$request->user()->emailAccounts()`.
- Code in English, few comments; user-facing copy in pt-BR exactly as in the spec. TDD; `app/` coverage 100%. PHP via `docker exec inbox_app ...`, npm on host.

## Review Focus

1. Replayed or second use of the same `state` (back button, double callback) → rejected with the validation error, never connects.
2. `code` / tokens leaking into `storage/logs` on a Google failure → log entries carry only the error type.
3. User A's disconnected account data reachable by user B after B connects the same Gmail → B gets a fresh row; A's senders/rules not visible to B.
4. `DELETE /accounts/{id}` for another user's account or an already disconnected one → 404, nothing revoked.
5. Google returning a new `refresh_token` on refresh → stored; returning none → old one kept.

Each is pinned by a named test in Tasks 2 and 4.

---

### Task 1: Schema, model state and config

**Files:**
- Create: `database/migrations/2026_11_19_000001_add_connection_state_to_email_accounts_table.php`, `database/factories/EmailAccountFactory.php`
- Modify: `app/Models/EmailAccount.php`, `config/services.php`, `.env.example`, `.env.production.example`, `app/Http/Controllers/DashboardController.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `tests/Feature/DashboardTest.php`, `tests/Unit/Models/EmailAccountTest.php`, `resources/js/types/index.d.ts`, `resources/js/Pages/Dashboard.tsx`

**Interfaces:**
- Produces:
  - Columns `deleted_at`, `authorized_at` (timestamp, nullable for old rows), `reauth_required_at` (nullable); `is_active` dropped from `email_accounts` only.
  - `EmailAccount` uses `SoftDeletes`, `HasFactory`; casts `authorized_at`, `reauth_required_at` as datetime.
  - `EmailAccount::needsReauth(): bool`, `EmailAccount::reconnectBy(): ?CarbonImmutable` (null when `services.google.testing` false or `authorized_at` null), `EmailAccount::expiringSoon(): bool`, `EmailAccount::summary(): array{id:int, provider:string, account_email:string, last_synced_at:?string, status:'connected'|'needs_reauth', reconnect_by:?string (Y-m-d), expiring_soon:bool}`.
  - `EmailAccountFactory` default: gmail, unique `account_email`, tokens, `authorized_at = now()`; states `needsReauth()`, `disconnected()`.
  - TS: `EmailAccountSummary` matches `summary()`.

- [ ] **Step 1: Failing tests** in `tests/Unit/Models/EmailAccountTest.php`: `test_connected_account_status`, `test_reauth_required_at_means_needs_reauth`, `test_testing_mode_window_expired_means_needs_reauth` (authorized 8 days ago), `test_no_deadline_when_testing_mode_is_off` (`reconnectBy()` null, status connected even if authorized 30 days ago), `test_expiring_soon_within_two_days` (authorized 5 days + 1 h ago → true; 4 days ago → false), `test_summary_has_only_public_fields` (keys exactly as above; no tokens, `history_id`, `user_id`). In `DashboardTest`: replace `is_active` usage; `test_disconnected_accounts_and_their_data_are_hidden` (soft-deleted account not in `accounts`, its senders not counted, its rules not listed, not in `auth.user.accounts`).
- [ ] **Step 2:** `docker exec inbox_app php artisan test --filter='EmailAccountTest|DashboardTest'` → FAIL (missing columns/methods).
- [ ] **Step 3:** Migration, model, factory, config (`services.google`), `.env.example`/`.env.production.example` (four vars + short Google Cloud how-to: Web application client, redirect URI, consent screen External/Testing with testers, Gmail API enabled). Dashboard and shared props use `->get()->map->summary()`. Update TS types and `Dashboard.tsx` (drop `is_active`).
- [ ] **Step 4:** Same filter → PASS; `npm run typecheck` → ok.
- [ ] **Step 5: Tell the user the `.env` variables now exist** (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI=http://localhost:8000/accounts/google/callback`, `GOOGLE_OAUTH_TESTING=true`) so they can fill `.env` while work continues. Do not wait.
- [ ] **Step 6:** Commit `feat: add connection state to email accounts`.

### Task 2: Provider layer

**Files:**
- Create: `app/Contracts/EmailProviderInterface.php`, `app/Services/Providers/OAuthGrant.php`, `app/Services/Providers/GmailProviderService.php`, `app/Exceptions/ReauthorizationRequired.php`, `app/Exceptions/OAuthExchangeFailed.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind interface → `GmailProviderService`)
- Test: `tests/Unit/Services/GmailProviderServiceTest.php`

**Interfaces:**
- Produces:
  - `EmailProviderInterface::authorizationUrl(string $state, ?string $loginHint = null): string`
  - `::exchangeCode(string $code): OAuthGrant` — throws `OAuthExchangeFailed` (message = Google error type only).
  - `::refreshAccessToken(EmailAccount $account): string` — no-op when `token_expires_at` > now + 60 s; on `invalid_grant` sets `reauth_required_at`, saves, throws `ReauthorizationRequired`; other errors throw `OAuthExchangeFailed`.
  - `::revoke(EmailAccount $account): bool`
  - `OAuthGrant` (final readonly): `string $accessToken, ?string $refreshToken, CarbonImmutable $expiresAt, array $grantedScopes, string $email` (lowercased); `hasScopes(array $required): bool`.
  - `GmailProviderService::SCOPES` const; constructor `__construct(?\GuzzleHttp\ClientInterface $http = null)` so tests inject a MockHandler client.

- [ ] **Step 1: Failing tests**: `test_authorization_url_contains_scopes_state_and_offline_consent`, `test_authorization_url_includes_login_hint`, `test_exchange_code_returns_grant_with_profile_email` (mock token response then `users/me/profile` returning `Foo@Gmail.com` → `foo@gmail.com`), `test_exchange_code_error_throws_without_leaking_code`, `test_refresh_skipped_when_token_still_valid` (no HTTP request made), `test_refresh_stores_new_access_token_and_expiry`, `test_refresh_stores_rotated_refresh_token`, `test_refresh_keeps_refresh_token_when_none_returned`, `test_invalid_grant_marks_account_and_throws`, `test_revoke_posts_refresh_token`, `test_revoke_failure_returns_false`.
- [ ] **Step 2:** `--filter=GmailProviderServiceTest` → FAIL (classes missing).
- [ ] **Step 3:** Implement. `Google\Client` with `setHttpClient`; `fetchAccessTokenWithAuthCode` / `fetchAccessTokenWithRefreshToken` return `['error' => ...]` instead of throwing — map that. Email via `(new Google\Service\Gmail($client))->users->getProfile('me')->getEmailAddress()`.
- [ ] **Step 4:** Filter → PASS.
- [ ] **Step 5:** Commit `feat: add Gmail provider service`.

### Task 3: Sync job skeleton

**Files:** Create `app/Jobs/SyncEmailAccountJob.php`; Test `tests/Unit/Jobs/SyncEmailAccountJobTest.php`

**Interfaces:**
- Produces: `SyncEmailAccountJob(int $accountId)` implements `ShouldQueue`; `handle(): void`; public `shouldRun(): bool` (false when account missing/soft-deleted, user disabled, or `needsReauth()`). Sync logic left for the next stage.

- [ ] **Step 1:** Tests `test_runs_for_connected_account`, `test_skips_disconnected_account`, `test_skips_disabled_user`, `test_skips_account_needing_reauth`, `test_skips_missing_account`, `test_payload_has_no_tokens` (serialize job, assert no token strings).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS. **Step 5:** Commit `feat: add sync job skeleton for email accounts`.

### Task 4: Accounts controller and routes

**Files:**
- Create: `app/Http/Controllers/EmailAccountController.php`, `tests/Fakes/FakeEmailProvider.php`, `tests/Feature/Accounts/ConnectAccountTest.php`, `tests/Feature/Accounts/DisconnectAccountTest.php`, `tests/Feature/Accounts/AccountsPageTest.php`
- Modify: `routes/web.php`, `lang/pt_BR.json` (if copy goes through `__()`)

**Interfaces:**
- Consumes: Task 1 `summary()`, factory; Task 2 interface, `OAuthGrant`, exceptions; Task 3 job.
- Produces routes: `accounts.index` GET `/accounts`, `accounts.google.redirect` POST `/accounts/google/redirect` (optional `account` id), `accounts.google.callback` GET `/accounts/google/callback`, `accounts.destroy` DELETE `/accounts/{account}`. Inertia page `Accounts/Index` with props `accounts: EmailAccountSummary[]`, `testingMode: bool`. Session key `google_oauth_state`. Flash `success`/`error`.
- `FakeEmailProvider` (test double): configurable `grant`/`exception`, records `revoked` accounts and last `authorizationUrl` args; returns `https://accounts.google.test/auth?state=...`.

- [ ] **Step 1: Failing tests** (copy from spec 6.6/6.7 exactly):
  - Page: `test_lists_only_own_non_disconnected_accounts`, `test_page_never_contains_tokens`, `test_guest_redirected_to_login`.
  - Redirect: `test_redirect_stores_state_and_goes_to_google` (409 Inertia location / redirect to fake URL), `test_redirect_uses_login_hint_for_own_account`, `test_redirect_ignores_other_users_account_hint`.
  - Callback: `test_missing_or_wrong_state_is_rejected`, `test_state_cannot_be_reused` (Review Focus 1), `test_access_denied_shows_cancelled`, `test_exchange_failure_shows_generic_error_and_logs_no_secrets` (Log::spy; Review Focus 2), `test_missing_scopes_rejected_without_revoking`, `test_missing_refresh_token_rejected`, `test_gmail_active_for_other_user_rejected_without_revoking`, `test_connect_creates_account_and_dispatches_job` (Queue::fake), `test_reconnect_restores_disconnected_account_and_clears_reauth`, `test_gmail_released_by_other_user_creates_fresh_account` (Review Focus 3: B sees no A senders/rules).
  - Disconnect: `test_disconnect_revokes_and_soft_deletes`, `test_disconnect_still_happens_when_revoke_fails` (warning logged, no tokens), `test_cannot_disconnect_other_users_or_disconnected_account` (404, nothing revoked; Review Focus 4).
- [ ] **Step 2:** `--filter=Accounts` → FAIL.
- [ ] **Step 3:** Implement controller + routes; bind `FakeEmailProvider` in tests via `$this->app->instance(EmailProviderInterface::class, ...)`. Callback uses a DB transaction for the uniqueness check + upsert (`withTrashed()->firstOrNew`).
- [ ] **Step 4:** `--filter=Accounts` → PASS; full `--exclude-group=audit` → PASS.
- [ ] **Step 5:** Commit `feat: connect, reconnect and disconnect Gmail accounts`.

### Task 5: Frontend

**Files:**
- Create: `resources/js/Pages/Accounts/Index.tsx`
- Modify: `resources/js/Layouts/AppLayout.tsx` (menu "Contas", `Mail` icon, before "Segurança"), `resources/js/Pages/Dashboard.tsx` (alert linking to `/accounts` when any account `needs_reauth` or `expiring_soon`)

- [ ] **Step 1:** Build the page per spec 6.9 sketch: list with status ("Conectada · reconecte até dd/mm" / "Precisa reconectar"), "Conectar conta Gmail" (`router.post('/accounts/google/redirect')`), "Reconectar" (same with `{ account: id }`), "Desconectar" (`confirm()` then `router.delete`), empty state, testing-mode note, flash messages.
- [ ] **Step 2:** `npm run typecheck && npm run build` → ok.
- [ ] **Step 3:** Browser check with Playwright at `http://localhost:8000/accounts` (dev user): empty state, flash on cancelled callback (`/accounts/google/callback?error=access_denied&state=x` → validation error), menu link, dashboard alert using a factory-made `needs_reauth` account. If the user has filled `.env`, run the real Google flow together with them (they log in at Google).
- [ ] **Step 4:** Commit `feat: add accounts page and reconnect alerts`.

### Task 6: Docs

**Files:** `SPEC.md` (3.2, 4.1, 6 ✅), `docs/debito-tecnico.md` (items: 7-day expiry with the three solutions from spec 6.10; purge disconnected accounts after N days), `README.md` ("Conectar o Gmail"), `CLAUDE.md` (provider layer, soft-delete/one-Gmail rule, no-revoke on rejection).

- [ ] **Step 1:** Edit docs. **Step 2:** Full checks: `docker exec inbox_app php artisan test`, `--coverage` (app/ 100%), `npm run typecheck`, `npm run build`, `npm run test:security`, `vendor/bin/pint --test`. **Step 3:** Commit `docs: document Gmail connection`.

Then: final self-review of the branch (no subagents), hand over test steps, wait for the user's validation before merge (validate-before-commit applies to the final merge/push).
