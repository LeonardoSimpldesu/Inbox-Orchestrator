# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Inbox Orchestrator: a private (owner + a few close people) Laravel + Inertia app to automate inbox hygiene (unsubscribe, TTL/retention rules, sender triage). Product spec and phases: `SPEC.md`. Approved designs and plans: `docs/superpowers/specs/`, `docs/superpowers/plans/`. Known debt: `docs/debito-tecnico.md`.

## Conventions

- **Code in English** (identifiers, comments, commit messages, test names). **User-facing text in pt-BR** (UI, validation/auth messages, e-mails, Artisan command output). Translations live in `lang/pt_BR/*.php` and `lang/pt_BR.json`; the default locale is `pt_BR` in `config/app.php`, so tests don't depend on `.env`.
- **Few comments.** Only add one when it is relevant: a business rule or security decision that the code doesn't make obvious. Don't narrate what the code does.
- Frontend is **TypeScript strict** (`.tsx`/`.ts` only). Import with the `@/` alias (`resources/js`).
- PHP style: Laravel Pint.
- TDD: write the failing test first, watch it fail, then implement. `app/` coverage must stay at **100%**.

## Commands

PHP/Composer run **inside the container** (no Composer on the host). Node runs on the host (Node 26).

```bash
docker compose up -d                                   # app, web (:8000), queue, scheduler, vite (:5173)
docker exec inbox_app php artisan test                 # full PHPUnit suite (includes dependency audit, needs internet)
docker exec inbox_app php artisan test --exclude-group=audit
docker exec inbox_app php artisan test --filter=LoginTest              # single class
docker exec inbox_app php artisan test --filter=test_recovery_code_works_only_once
docker exec inbox_app php artisan test --coverage
docker exec inbox_app vendor/bin/pint --test <paths>
docker exec inbox_app php artisan migrate
docker exec inbox_app php artisan optimize:clear       # needed after Inertia/Blade upgrades (stale compiled views)
npm run typecheck                                      # tsc --noEmit
npm run build
npm run test:security                                  # node --test tests/js: Vite must not serve sensitive files
```

**Before any merge into `main` or opening a PR, run all of the above checks** (full PHPUnit incl. audit, typecheck, build, test:security), again on the merged result. After merging into `main`, push `main`.

User management (no public registration): `php artisan user:create|user:disable|user:enable|user:delete|user:reset-two-factor` (see README).

## Architecture

### Authentication (Laravel Fortify, headless)
- Fortify provides routes and logic; `app/Providers/FortifyServiceProvider.php` registers every Fortify view as an **Inertia page** (`Auth/*`), the rate limiters, and `Fortify::authenticateUsing` (rejects disabled users with the same generic error as a wrong password, before the 2FA challenge).
- `config/fortify.php` enables **only** `resetPasswords` and `twoFactorAuthentication` (confirm + confirmPassword). Registration, email verification, profile/password update and passkeys are intentionally off; `tests/Feature/Auth/LoginTest` asserts those routes 404.
- Anti-enumeration: login always fails with `Credenciais inválidas.`; `App\Http\Responses\PasswordResetLinkRequestedResponse` is bound to both the success and failure reset-link contracts so the endpoint always answers the same.
- App routes (`routes/web.php`) use `['auth', 'auth.session', 'active']`: `auth.session` ends other sessions when the password changes; `active` (`EnsureUserIsActive`) logs out disabled users. `/settings/security` also requires `password.confirm`.
- Disabling a user (`users.disabled_at`) = no login, no reset e-mail (`User::sendPasswordResetNotification`), sessions deleted and `remember_token` rotated (the remember-me cookie bypasses `authenticateUsing`).
- 2FA secrets/recovery codes are hidden on `User`; the Security page fetches QR/secret/codes from Fortify's JSON endpoints on demand and never receives them as props.

### Data isolation
Every query starts from the authenticated user: `User::emailAccounts()`, `senders()` and `retentionRules()` (`hasManyThrough` via `EmailAccount`). Shared Inertia props (`HandleInertiaRequests::share`) expose only `id, name, email, two_factor_enabled, accounts`. OAuth tokens on `EmailAccount` are `encrypted` casts **and** `$hidden` (casts decrypt on serialization). Future sync/retention jobs must skip accounts of disabled users.

### Gmail connection (OAuth)
- `App\Contracts\EmailProviderInterface` is implemented by `App\Services\Providers\GmailProviderService` (wraps `Google\Client`, bound in `AppServiceProvider`). Feature tests swap it for `tests/Fakes/FakeEmailProvider`; the real service is tested with a Guzzle `MockHandler`. Google errors become `OAuthExchangeFailed` (message = error type only, safe to log) or `ReauthorizationRequired` (`invalid_grant`, sets `reauth_required_at`).
- `EmailAccountController`: `/accounts` page, `POST /accounts/google/redirect` (state in session `google_oauth_state`), callback, `DELETE /accounts/{id}`. Never log the OAuth `code` or tokens.
- Disconnect = revoke at Google + **soft delete** (`deleted_at`); reconnecting the same Gmail by the same user restores the row. A Gmail can be active for only one user (checked in the callback; soft-deleted rows don't count). Rejected grants are discarded **without revoking**: the Google grant is shared, so revoking would cut off the user who has it connected.
- Account state is derived on `EmailAccount` (`needsReauth()`, `reconnectBy()`, `expiringSoon()`, `summary()` = the only shape sent to the frontend). `services.google.testing` (`GOOGLE_OAUTH_TESTING`) enables the 7-day reconnect window of Google's "Testing" status.
- The callback is a cross-site top-level GET from Google, so the session cookie must stay `SameSite=lax` (a `strict` cookie would drop the session and the `state`).
- `SyncEmailAccountJob` takes the account id (no tokens in queue payloads) and skips disconnected accounts, disabled users and accounts needing reauth.

### Gmail metadata sync
- `EmailSyncService::run($account, SyncMode)` runs one budgeted mode per job: `Recent` (90 days, 5,000 msgs), `Changes` (history.list since `history_id`; falls back to `Recent` on `HistoryExpired`), `History` (500 msgs, `before:` `oldest_synced_at`), `HistoryNow` (20 min). `SyncEmailAccountJob` (queue `sync`) holds a per-account `WithoutOverlapping` lock; scheduled modes are dropped when busy, manual ones retried.
- `MessageStore` writes each batch with a **constant number of queries** (bulk upserts keyed by `(email_account_id, provider_message_id)` / `(email_account_id, email)`, one correlated `UPDATE` to recount senders). Sender totals are always recomputed, never incremented, so re-reads are idempotent. Only date-walking imports move `oldest_synced_at` (`extendsCoverage: false` for `Changes`). Tests assert query counts; keep them constant.
- `GmailProviderService` throttles each account to `services.google.quota_units_per_minute` (RateLimiter, cost per Gmail method), batches 25 metadata reads and retries rate-limited items (429 / 403 `rateLimitExceeded`) with backoff. Errors surface as `ProviderUnavailable` (logged with account id + error type only), `HistoryExpired` or `ReauthorizationRequired`.
- Queue workers keep code in memory: run `php artisan queue:restart` after PHP changes.

### Subscription Hub
- `EmailSender::subscriptions()` (`pendingSubscriptions`/`handledSubscriptions`) defines what the Hub lists; `SubscriptionController` paginates from `$user->senders()` with a constant number of queries (tests compare 1 vs 150 rows).
- Unsubscribe runs in `UnsubscribeSenderJob`: `OneClickUnsubscriber` (RFC 8058 POST, **SSRF guard**: https only, every resolved IP must be global, connection pinned with `CURLOPT_RESOLVE`, no redirects; resolver behind `HostResolver`), `MailtoUnsubscriber` (`MailtoParser` rejects CR/LF outside the body), then `SenderMailCleaner` (Gmail `from:` listing + `batchModify` in 1,000-id blocks + `MessageStore::applyLabelChange`).
- `unsubscribe_url`/`unsubscribe_mailto` never go into Inertia props or logs; the sender page is reached through `GET /subscriptions/{id}/page` (redirect in the user's browser). Logs carry `sender_id` + error code only.

### Frontend
Inertia pages in `resources/js/Pages`, layouts `AppLayout` (authenticated) and `GuestLayout` (auth screens), shared inputs in `Components/`. Shared props are typed via module augmentation of `@inertiajs/core` (`InertiaConfig.sharedPageProps`) in `resources/js/types/inertia.d.ts`; domain types in `resources/js/types/index.d.ts`.

### Security hardening (keep these intact)
- Docker publishes ports on `127.0.0.1` only; `vite.config.js` restricts `server.fs.allow` to `resources/` and `node_modules/` (the dev server otherwise serves the SQLite DB, logs and source). Guarded by `tests/Security/EnvironmentHardeningTest` and `tests/js/vite-fs-exposure.test.mjs`.
- `SecurityHeaders` middleware (Referrer-Policy, Permissions-Policy, HSTS only on HTTPS in production). `.env.production.example` is the hardened production template.
- `tests/Security/DependencyAuditTest` (group `audit`) fails on any `composer audit`/`npm audit` advisory not in `audit-allowlist.json`; entries need a reason and an expiry date, and production npm deps can never be allowlisted.

### Docker gotchas
The `vite` container has its own `node_modules` volume (Linux native binaries for rolldown/lightningcss) and runs `npm ci` on start: restart it after changing `package-lock.json`. The host `node_modules` is macOS-only.
