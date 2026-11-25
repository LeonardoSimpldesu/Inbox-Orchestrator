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
docker exec inbox_app vendor/bin/phpunit --exclude-group=audit --coverage-text --only-summary-for-coverage-text   # coverage (app/ must be 100%)
docker exec inbox_app vendor/bin/pint --test <paths>
docker exec inbox_app php artisan migrate
docker exec inbox_app php artisan optimize:clear       # needed after Inertia/Blade upgrades (stale compiled views)
npm run typecheck                                      # tsc --noEmit
npm run build
npm run test:security                                  # node --test tests/js/*.test.mjs: Vite must not serve sensitive files
npm run test:js                                        # node --test tests/js/*.test.ts: pure frontend helpers (resources/js/lib/dates.ts)
docker run --rm -v "$PWD/deploy:/d:ro" koalaman/shellcheck:stable /d/provision.sh /d/deploy.sh /d/backup.sh
```

**CI** (`.github/workflows/ci.yml`, GitHub Actions on pushes and PRs to `main`) runs the same checks in three jobs: PHP tests with a 100% line-coverage gate + Pint, the dependency audit (separate so an external advisory doesn't look like a broken test), and the frontend (typecheck, build, `test:security`, `test:js`). Tests run without compiled assets (`withoutVite()` in `tests/TestCase.php`) and without real credentials. Note: `php artisan test --coverage --min` silently skips the check in this setup; use PHPUnit's summary.

**Before any merge into `main` or opening a PR, run all of the above checks** (full PHPUnit incl. audit, typecheck, build, test:security, test:js), again on the merged result. `main` is protected (GitHub ruleset): no direct pushes; changes land through a branch + PR with the three CI jobs green and the branch up to date, merged by rebase only (`gh pr merge <n> --rebase --delete-branch`). Afterwards `git checkout main && git pull --ff-only` and check that the CI run on `main` is green. Production is updated only when the owner pushes a release tag `vX.Y.Z` (never create or push tags yourself).

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
- Account state is derived on `EmailAccount` (`needsReauth()`, `reconnectBy()`, `expiringSoon()`, `summary()` = the only shape sent to the frontend). The Google app is published in Production without verification (100-user cap, "unverified app" screen), so refresh tokens don't expire weekly; `services.google.testing` (`GOOGLE_OAUTH_TESTING`, `false` in the env templates) only re-enables the 7-day reconnect window if the app goes back to "Testing".
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

### Retention rules (TTL)
- `RuleQuery::for($rule)` turns a rule into a Gmail search (`q` + `labelIds`) and the label changes; it always adds `-is:starred -in:sent -in:drafts`. For "add label" it also describes an exclusion listing (by label **id**, never by name) that `RuleMatcher` subtracts. `RuleMatcher` checks the rule's labels still exist (`LabelMissing` → the executor pauses the rule) and is shared by `RuleSimulator` (count capped at 10,000 + sample) and `RuleExecutor` (list → `batchModify` → `MessageStore::applyLabelChange`), so the simulated count is what runs.
- Rules start paused; `canActivate()` requires `simulated_at`, and any edit clears it. `inbox:apply-retention` runs hourly and dispatches `ExecuteRetentionRuleJob` for active rules not run since today's 03:00 in `config('app.schedule_timezone')`. Every simulation/run/failure goes to `rule_execution_logs` (error = code only); after 3 failures in a day a rule waits for the next day. `ExecuteRetentionRuleJob` also takes the account's `sync-account-{id}` lock so a run and a sync never write labels at the same time.
- Controllers look rules up with `whereIn(email_account_id, user's accounts)` (not the `hasManyThrough`, whose `laravel_through_key` must not reach saved models). Label ids in forms are validated against the account's Gmail labels.

### Senders page
- A sender's category **is** its retention rule (`filter_type=sender`, `action=add_label`); `SenderCategories` reads it for a page in one query and writes changes in bulk. No category column exists, so the Senders and Rules pages can't diverge.
- `ApplySenderLabelJob` (new label on, old label off for the sender's existing mail) and `CleanSenderMailJob` share `Jobs\Concerns\RunsPerSender`: per-sender lock plus the account's `sync-account-{id}` lock, `label_applying_at` drives the "Aplicando…" state.
- `EmailSender::scopeNonSubscriptions()` is the complement of `subscriptions()`; `scopeSearch()` is shared with the Hub.

### Frontend
Inertia pages in `resources/js/Pages`, layouts `AppLayout` (authenticated) and `GuestLayout` (auth screens), shared inputs in `Components/`. Shared props are typed via module augmentation of `@inertiajs/core` (`InertiaConfig.sharedPageProps`) in `resources/js/types/inertia.d.ts`; domain types in `resources/js/types/index.d.ts`.

### Security hardening (keep these intact)
- Docker publishes ports on `127.0.0.1` only; `vite.config.js` restricts `server.fs.allow` to `resources/` and `node_modules/` (the dev server otherwise serves the SQLite DB, logs and source). Guarded by `tests/Security/EnvironmentHardeningTest` and `tests/js/vite-fs-exposure.test.mjs`.
- `SecurityHeaders` middleware (Referrer-Policy, Permissions-Policy, HSTS only on HTTPS in production). `.env.production.example` is the hardened production template.
- `tests/Security/DependencyAuditTest` (group `audit`) fails on any `composer audit`/`npm audit` advisory not in `audit-allowlist.json`; entries need a reason and an expiry date, and production npm deps can never be allowlisted.

### Production (VPS)
- Native install on a dedicated Ubuntu 24.04 VPS (nginx + PHP-FPM 8.5 + Supervisor + cron, Let's Encrypt); no Docker in production. `deploy/provision.sh` (root, idempotent: never replaces `.env` or a set `APP_KEY`, only disables SSH passwords when a key is authorized), `deploy/deploy.sh [tag]` (as `inbox`; publishes a `vX.Y.Z` tag on `main`, the newest when no argument, refusing tags older than tag deploys; validates everything and takes a `flock` before `artisan down`; refuses missing `.env` keys and prints names only; body inside `main()` because `git checkout` rewrites the running file). `.github/workflows/deploy.yml` runs on a tag push (or *Run workflow* on a tag): reuses `ci.yml` via `workflow_call`, checks the tag is on `main`, then SSHes as `inbox` with a key whose `authorized_keys` forced command is `deploy/remote-deploy.sh` (accepts only a tag name). Host, key and host key are secrets of the `production` environment (tags `v*` only); Actions logs are public, so print nothing from the server beyond the deploy summary, `deploy/backup.sh` (`sqlite3 .backup`, 14 days local; when `/etc/inbox/backup.env` is filled, an `age`-encrypted copy goes to Backblaze B2 via `rclone` with a write-only key, and a healthchecks.io URL gets a success ping or `/fail`; without config it stays local-only and sends no success ping). Templates use `__APP_DOMAIN__`/`__APP_DIR__`/`__PHP_VERSION__`. Steps in README "Produção".
- **The repository is public**: no IP, real domain, real e-mail, key or secret in committed files, commits or PR text. `tests/Security/DeployFilesTest` scans `deploy/`, the env templates and README.
- Public pages for Google's OAuth review: `/sobre` and `/privacidade` (`PrivacyPolicyController`, contact from `config/privacy.php` / `.env`). `/` stays the protected Dashboard. Every response carries `X-Robots-Tag: noindex, nofollow`.
- SQLite runs in WAL with `busy_timeout` 5000 (`config/database.php`), since the web and the queue write at the same time.

### Docker gotchas
The `vite` container has its own `node_modules` volume (Linux native binaries for rolldown/lightningcss) and runs `npm ci` on start: restart it after changing `package-lock.json`. The host `node_modules` is macOS-only.
