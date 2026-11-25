# Production Deploy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task (this project does not use subagents). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run the app on a dedicated Ubuntu 24.04 VPS at `https://<APP_DOMAIN>` with public About/Privacy pages and Resend e-mail, with nothing server-specific committed.

**Architecture:** Native install (nginx + PHP-FPM 8.5 + Supervisor + cron) driven by versioned, placeholder-only files in `deploy/`: `provision.sh` (idempotent machine setup, run as root) and `deploy.sh` (per-release update, run as the `inbox` user). App changes: SQLite WAL defaults, two public Inertia pages, `X-Robots-Tag`, Resend transport. Real values (domain, e-mails, keys) live only in the server's `.env` and environment variables.

**Tech Stack:** Laravel 13, Inertia + React/TS, PHP 8.5 (ondrej PPA), nginx, Supervisor, certbot (webroot), Node 24 (build only), shellcheck.

**Spec:** `docs/superpowers/specs/2026-11-25-production-deploy-design.md`

## Global Constraints

- The repository is **public**: no IP, real domain, real e-mail, SSH user, key, token, `APP_KEY` or Resend key in any committed file, commit message or PR text. Placeholders: `__APP_DOMAIN__`, `__APP_DIR__`, `__PHP_VERSION__`, `example.com`, `your-domain.com`.
- Code/comments/commits in English; every user-facing text (pages, script output, README) in pt-BR.
- `app/` line coverage stays 100%; Pint clean; TDD for app code.
- RAM budget 2 GB: PHP-FPM `pm = ondemand`, `pm.max_children = 4`; one queue worker; 2 GB swap.
- Queue worker: `queue:work database --queue=default,sync --sleep=3 --tries=3 --timeout=1500 --max-time=3600 --memory=256`; Supervisor `stopwaitsecs=1600` (matches `DB_QUEUE_RETRY_AFTER=1600`).
- Session cookie stays `SameSite=lax`.
- `/` stays the protected Dashboard; the public home page is `/sobre`.
- Work on branch `deploy/production`; PR → green CI → `gh pr merge --rebase` only with the user's go-ahead. Never force-push.

## Review Focus

- Re-running `provision.sh` on a provisioned machine: must not duplicate swap/fstab/cron, overwrite `.env`, re-clone, or regenerate `APP_KEY` (would make every stored OAuth token undecryptable). → Task 6 guards + Task 8 re-run check.
- Disabling SSH password login when root has no authorized key would lock the owner out. → Task 6 guard + Task 8 check.
- Deploying with an incomplete `.env` (empty `RESEND_KEY`, `PRIVACY_CONTACT_EMAIL`, Google keys) silently ships a broken app. → Task 7 `deploy.sh` refuses to start, listing missing keys (names only, never values).
- `APP_URL` behind nginx not seen as HTTPS → no HSTS, wrong links in e-mails. → Task 5 nginx template passes `HTTPS on`; Task 8 checks the header.
- Backup taken mid-write in WAL mode. → Task 7 uses `sqlite3 .backup`, Task 8 restores one to verify.

---

### Task 1: SQLite WAL defaults

**Files:**
- Modify: `config/database.php:41-43`
- Test: `tests/Unit/DatabaseConfigTest.php` (create)

**Interfaces:** Produces env keys `DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`, `DB_SYNCHRONOUS` (used in Task 4's `.env.production.example`).

- [ ] **Step 1: Write the failing test** `test_sqlite_defaults_to_wal_with_busy_timeout`: asserts `config('database.connections.sqlite.journal_mode') === 'wal'`, `busy_timeout === 5000`, `synchronous === 'normal'`.
- [ ] **Step 2: Run** `docker exec inbox_app php artisan test --filter=DatabaseConfigTest` → FAIL (null).
- [ ] **Step 3: Implement** `'busy_timeout' => (int) env('DB_BUSY_TIMEOUT', 5000)`, `'journal_mode' => env('DB_JOURNAL_MODE', 'wal')`, `'synchronous' => env('DB_SYNCHRONOUS', 'normal')`.
- [ ] **Step 4: Run** the filter (PASS) and the full suite `--exclude-group=audit` (in-memory DB must still work: SQLite answers `memory` to the WAL pragma).
- [ ] **Step 5: Commit** `feat: default SQLite to WAL with a busy timeout`.

### Task 2: Public About and Privacy pages

**Files:**
- Create: `config/privacy.php`, `app/Http/Controllers/PrivacyPolicyController.php`, `resources/js/Layouts/PublicLayout.tsx`, `resources/js/Pages/Public/About.tsx`, `resources/js/Pages/Public/Privacy.tsx`
- Modify: `routes/web.php` (public routes outside the auth group), `resources/js/Layouts/GuestLayout.tsx` (footer links), `resources/js/Layouts/AppLayout.tsx` (footer link "Privacidade"), `resources/js/types/index.d.ts` (`PrivacyPageProps`)
- Test: `tests/Feature/PublicPagesTest.php` (create)

**Interfaces:**
- `config/privacy.php`: `['controller_name' => env('PRIVACY_CONTROLLER_NAME'), 'contact_email' => env('PRIVACY_CONTACT_EMAIL'), 'updated_at' => '2026-11-25']`.
- Routes: `Route::inertia('/sobre', 'Public/About')->name('about')`; `Route::get('/privacidade', PrivacyPolicyController::class)->name('privacy')`.
- `PrivacyPolicyController::__invoke(): Inertia\Response` renders `Public/Privacy` with props `controllerName: ?string`, `contactEmail: ?string`, `updatedAt: string`, `disconnectedRetentionDays: int` (from `config('app.disconnected_account_retention_days')`).
- `PublicLayout({ title, children })`: wide reading layout, header with app name and "Entrar" (guest) or "Ir para o app" (when shared `auth.user` is set), footer links Sobre · Privacidade.

- [ ] **Step 1: Write failing tests** in `PublicPagesTest`:
  - `test_guest_can_open_about_page`: `get('/sobre')` OK, component `Public/About`.
  - `test_guest_can_open_privacy_page_with_configured_contact`: set `privacy.controller_name`/`privacy.contact_email` to `Responsável Exemplo`/`contato@example.com`, `app.disconnected_account_retention_days` 90; assert component `Public/Privacy` and those four props.
  - `test_root_still_requires_login`: `get('/')` redirects to `/login`.
  - `test_logged_in_user_can_open_public_pages`: `actingAs` → both 200.
- [ ] **Step 2: Run** `--filter=PublicPagesTest` → FAIL (404).
- [ ] **Step 3: Implement** config, routes, controller.
- [ ] **Step 4: Write the pages.** About: what the app does (descadastro, regras de retenção, categorias por remetente), "acesso apenas por convite, sem cadastro público", link to Privacy. Privacy must cover every bullet of spec §5.2 (data stored; never bodies/attachments; why `gmail.modify` and `gmail.send`; the Limited Use sentence verbatim; no sale/sharing/ads; processors = hosting + Resend; disconnect revokes and data deleted after `disconnectedRetentionDays` days; deletion on request; LGPD rights; contact). Hide the contact line if `contactEmail` is null. Links in Guest/App layouts.
- [ ] **Step 5: Run** the filter (PASS), `npm run typecheck`, `npm run build`, then check both pages in the browser (dev at :8000) as guest and logged in.
- [ ] **Step 6: Commit** `feat: add public about and privacy policy pages`.

### Task 3: noindex header

**Files:** Modify `app/Http/Middleware/SecurityHeaders.php`; Test `tests/Feature/SecurityHeadersTest.php`.

- [ ] **Step 1: Failing test** `test_responses_ask_search_engines_not_to_index`: `get('/sobre')` has `X-Robots-Tag: noindex, nofollow`.
- [ ] **Step 2: Run** → FAIL. **Step 3:** set the header in `handle()`. **Step 4:** Run → PASS.
- [ ] **Step 5: Commit** `feat: keep the private app out of search engines`.

### Task 4: Resend transport and production env template

**Files:**
- Modify: `composer.json`/`composer.lock` (`resend/resend-php`), `.env.production.example`, `.env.example` (`APP_NAME="Inbox Orchestrator"`)
- Test: `tests/Feature/MailTransportTest.php` (create), `tests/Security/EnvironmentHardeningTest.php`

- [ ] **Step 1: Failing tests:**
  - `MailTransportTest::test_resend_mailer_is_available`: with `services.resend.key` = `'re_test'`, `app('mail.manager')->mailer('resend')->getSymfonyTransport()` is `Illuminate\Mail\Transport\ResendTransport`.
  - `EnvironmentHardeningTest::productionSettings()` add: `'resend mailer' => ['MAIL_MAILER', 'resend']`, `'app name' => ['APP_NAME', 'Inbox Orchestrator']`, `'oauth published' => ['GOOGLE_OAUTH_TESTING', 'false']`, `'sqlite wal' => ['DB_JOURNAL_MODE', 'wal']`.
  - `test_production_env_template_lists_required_keys`: keys `RESEND_KEY`, `PRIVACY_CONTROLLER_NAME`, `PRIVACY_CONTACT_EMAIL` exist (empty values allowed).
- [ ] **Step 2: Run** both classes → FAIL.
- [ ] **Step 3:** `docker exec inbox_app composer require resend/resend-php`; rewrite `.env.production.example`: `APP_URL=https://__APP_DOMAIN__`, `GOOGLE_REDIRECT_URI=https://__APP_DOMAIN__/accounts/google/callback`, `MAIL_MAILER=resend`, `RESEND_KEY=`, `MAIL_FROM_ADDRESS="no-reply@your-domain.com"`, WAL keys, privacy keys, `GOOGLE_OAUTH_TESTING=false`; Google comment block describes publishing in Production unverified (spec/SPEC §Google) instead of "Testing". `provision.sh` (Task 6) replaces `__APP_DOMAIN__`.
- [ ] **Step 4: Run** both classes and `php artisan test --group=audit` → PASS.
- [ ] **Step 5: Commit** `feat: send mail through Resend in production`.

### Task 5: Server config templates and leak guard

**Files:**
- Create: `deploy/nginx-bootstrap.conf.template` (port 80 only: ACME webroot `/var/www/letsencrypt` + 301 to HTTPS), `deploy/nginx.conf.template` (bootstrap block + 443 block), `deploy/php-fpm-pool.conf.template`, `deploy/supervisor-queue.conf.template`, `deploy/cron.template`
- Test: `tests/Security/DeployFilesTest.php` (create)

**Interfaces:** Placeholders `__APP_DOMAIN__`, `__APP_DIR__` (`/var/www/inbox`), `__PHP_VERSION__` (`8.5`); FPM socket `/run/php/inbox.sock` (owner `www-data`, mode 0660, pool user/group `inbox`); cron runs `schedule:run` every minute and `deploy/backup.sh` at `30 3 * * *`, both as `inbox`.

- [ ] **Step 1: Failing tests** in `DeployFilesTest` (data-driven over every file in `deploy/`, `.env.production.example`, `.env.example`, `README.md`):
  - `test_files_contain_no_ip_addresses` (IPv4 other than `127.0.0.1`/`0.0.0.0`),
  - `test_files_contain_no_real_email_addresses` (domain must be `example.com|example.org|example.net|your-domain.com`),
  - `test_files_contain_no_secrets` (`/re_[A-Za-z0-9]{8,}|GOCSPX-|base64:[A-Za-z0-9+\/=]{20,}|-----BEGIN /`),
  - `test_nginx_template_hardening`: contains `server_tokens off`, `fastcgi_param HTTPS on`, a `deny all` for dotfiles, `root __APP_DIR__/public`, `X-Frame-Options`, `X-Content-Type-Options`, `client_max_body_size`.
- [ ] **Step 2: Run** → FAIL (no `deploy/`).
- [ ] **Step 3: Write the templates.** nginx 443 block: `ssl_certificate /etc/letsencrypt/live/__APP_DOMAIN__/fullchain.pem`, `fastcgi_read_timeout 300`, same headers as `docker/nginx/default.conf`, `client_max_body_size 2m`. FPM pool: `pm = ondemand`, `pm.max_children = 4`, `php_admin_flag[expose_php] = off`, `php_admin_value[memory_limit] = 256M`. Supervisor: worker command from Global Constraints, `user=inbox`, `stopwaitsecs=1600`, log to `__APP_DIR__/storage/logs/queue.log` with `stdout_logfile_maxbytes=10MB`, `stdout_logfile_backups=3`.
- [ ] **Step 4: Validate nginx syntax**: render the template with `example.com`, generate a throwaway self-signed cert in the scratchpad, run `docker run --rm` an `nginx:alpine` with them mounted and `nginx -t` → "syntax is ok". Run tests → PASS.
- [ ] **Step 5: Commit** `feat: add production server config templates`.

### Task 6: `provision.sh`

**Files:** Create `deploy/provision.sh`; Modify `.github/workflows/ci.yml` (step "Shell scripts" in the `php` job: `shellcheck deploy/*.sh`).

**Interfaces:** Run as root: `APP_DOMAIN=… ADMIN_EMAIL=… bash provision.sh`. Optional `REPO_URL` (default the public HTTPS clone URL from `git remote`, written as `https://github.com/LeonardoSimpldesu/Inbox-Orchestrator.git`, already public). Produces `/var/www/inbox` owned by `inbox`, `.env` (600) with `APP_KEY` set, nginx/FPM/Supervisor/cron live, certificate issued.

- [ ] **Step 1:** Write the script, `set -euo pipefail`, pt-BR messages, fails fast if not root, not Ubuntu 24.04, or `APP_DOMAIN`/`ADMIN_EMAIL` missing. Each block idempotent:
  1. apt update/upgrade; `unattended-upgrades`; timezone UTC.
  2. swap: create `/swapfile` 2G only if absent; fstab line only if absent; `vm.swappiness=10`.
  3. PPA `ondrej/php`; `php8.5-{fpm,cli,sqlite3,mbstring,intl,zip,bcmath,curl,xml}` (+ any extra from `composer check-platform-reqs`), Composer (verified installer), Node 24 (NodeSource), nginx, supervisor, certbot, sqlite3, git, fail2ban, ufw.
  4. ufw allow OpenSSH/80/443, enable. SSH hardening (`PasswordAuthentication no`, `PermitRootLogin prohibit-password`) **only if** `/root/.ssh/authorized_keys` is non-empty; otherwise print a warning and skip.
  5. user `inbox` (system, home `/var/www/inbox`'s parent untouched); clone only if `__APP_DIR__/.git` absent.
  6. `.env`: copy from `.env.production.example` only if absent, replace `__APP_DOMAIN__`, chmod 600; as `inbox`: `composer install --no-dev`, `key:generate --force` **only if** `APP_KEY=` is empty; create `database/database.sqlite` if absent; chmod 750 `database` and `storage`.
  7. Render templates with sed into `/etc/nginx/sites-available/inbox` (bootstrap first), FPM pool, `/etc/supervisor/conf.d/inbox-queue.conf`, `/etc/cron.d/inbox`; remove nginx default site; `certbot certonly --webroot -w /var/www/letsencrypt -d $APP_DOMAIN -m $ADMIN_EMAIL --agree-tos -n` only if no cert yet, deploy-hook `systemctl reload nginx`; then the full nginx template; `nginx -t` before every reload.
  8. Final message: next steps (edit `.env`, run `deploy.sh`, `user:create`).
- [ ] **Step 2:** `docker run --rm -v "$PWD/deploy:/d:ro" koalaman/shellcheck:stable /d/provision.sh` → no findings; `bash -n`.
- [ ] **Step 3: Commit** `feat: add idempotent VPS provisioning script` (with the CI step).

### Task 7: `deploy.sh` and `backup.sh`

**Files:** Create `deploy/deploy.sh`, `deploy/backup.sh`.

- [ ] **Step 1: `deploy.sh`** (run as `inbox` from `__APP_DIR__`; refuses root): check required `.env` keys non-empty (`APP_KEY APP_URL GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET GOOGLE_REDIRECT_URI RESEND_KEY MAIL_FROM_ADDRESS PRIVACY_CONTROLLER_NAME PRIVACY_CONTACT_EMAIL`) and print only missing **names**; then `artisan down --retry=60` → `git fetch origin main` → `git reset --hard origin/main` → `composer install --no-dev --optimize-autoloader --no-interaction` → `npm ci` → `npm run build` → `artisan migrate --force` → `artisan optimize` → `artisan up` → `artisan queue:restart`. A trap on ERR prints "Deploy falhou; o app continua em manutenção" and the failing step.
- [ ] **Step 2: `backup.sh`**: `sqlite3 database/database.sqlite ".backup '/var/backups/inbox/db-YYYYmmdd-HHMM.sqlite'"`, gzip, delete files older than 14 days; dir 700 owned by `inbox` (created by `provision.sh`; add that line to Task 6's block 6).
- [ ] **Step 3:** shellcheck both → clean; run `backup.sh` locally against a copy of the dev DB with a temp target dir, `gunzip` + `sqlite3 … "pragma integrity_check"` → `ok`.
- [ ] **Step 4: Commit** `feat: add deploy and backup scripts`.

### Task 8: Docs, full checks, PR, live run

**Files:** Modify `README.md` ("Produção" rewritten), `CLAUDE.md` (merge rule already changed + short "Deploy" subsection under Architecture), `docs/debito-tecnico.md` (off-site backup), `SPEC.md` hosting line if needed.

- [ ] **Step 1: README "Produção"** (pt-BR): DNS `A` record; `APP_DOMAIN=… ADMIN_EMAIL=… bash provision.sh`; fill `.env` (which keys, where each comes from: Google Console, Resend, privacy fields); keep `APP_KEY` copy in a password manager outside the VPS; first `deploy.sh`; `php artisan user:create`; backup location and restore (`gunzip`, stop Supervisor, copy over `database.sqlite`, start); how to update (`sudo -u inbox __APP_DIR__/deploy/deploy.sh`).
- [ ] **Step 2: Resend live test (needs the user):** user verifies the sending domain in Resend (DNS records), puts `RESEND_KEY` and `MAIL_FROM_ADDRESS` in the **local** `.env` with `MAIL_MAILER=resend` themselves; trigger "Esqueci minha senha" for their account; confirm receipt; restore local `.env`.
- [ ] **Step 3: All checks** per CLAUDE.md (full PHPUnit incl. audit, coverage 100%, Pint, typecheck, build, test:security, test:js) → green. Re-run `DeployFilesTest`.
- [ ] **Step 4:** With the user's go-ahead: push branch, open PR (no server data in text), wait for CI, `gh pr merge --rebase --delete-branch`, pull `main`, re-run checks.
- [ ] **Step 5: Live run on the VPS** (user or Claude via SSH, user's choice): provision → fill `.env` → `deploy.sh` → `user:create`. Verify: `curl -sI https://$APP_DOMAIN/up` 200 with `Strict-Transport-Security` and `X-Robots-Tag`; `/sobre` and `/privacidade` load; login + 2FA; `supervisorctl status` RUNNING; `schedule:list`; `pragma journal_mode` → `wal`; run `backup.sh` and restore-test the file; password-reset e-mail arrives with links to `https://$APP_DOMAIN`; re-run `provision.sh` and confirm `.env`/`APP_KEY` unchanged (`sha256sum .env` before/after).
