# Autenticação e isolamento por usuário — Plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task (project rule: **no subagents** — everything is executed and verified inline in the session). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Login obrigatório (Fortify), 2FA opcional, gestão de usuários por comando, dashboard isolado por usuário e frontend em TypeScript estrito.

**Architecture:** Laravel Fortify headless fornece rotas e lógica de autenticação; as views do Fortify são registradas como respostas Inertia que renderizam páginas TSX próprias. O isolamento parte sempre de `auth()->user()` e das relações de `User`. A gestão de usuários fica em comandos Artisan.

**Tech Stack:** Laravel 13.43, PHP 8.5, laravel/fortify ^1.43, Inertia 3, React 19, TypeScript 7 (`strict`), Tailwind 4, PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-11-18-authentication-design.md`

## Global Constraints

- PHP roda no container: `docker exec inbox_app <cmd>`. npm roda no host (`npm ...`).
- Cobertura de `app/` permanece **100%** (`docker exec inbox_app php artisan test --coverage`).
- `npm run typecheck`, `npm run build` e `npm run test:security` passam ao fim de cada task que toca o frontend.
- Toda a interface e todas as mensagens em **pt-BR**. Visual: slate escuro, destaque indigo, ícones `lucide-react`; sem bibliotecas de componentes novas.
- Falha de login (senha errada, e-mail inexistente, usuário desativado): sempre `Credenciais inválidas.`
- "Esqueci minha senha": sempre a mesma resposta, `Se o e-mail estiver cadastrado, você receberá um link para redefinir sua senha.`
- Limites: login 5/min por `email|ip`; 2FA 5/min por `login.id`.
- Senha: mínimo de **12** caracteres em todos os ambientes.
- Nenhuma prop do Inertia, resposta JSON ou log contém `password`, `remember_token`, `two_factor_secret`, `two_factor_recovery_codes`, `access_token` ou `refresh_token`.
- Fortify features: **somente** `resetPasswords()` e `twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])`. Passkeys, registro, verificação, perfil e troca de senha desligados.
- Commits pequenos, um por task, na branch `feat/authentication`, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **E-mail com maiúsculas/espaços** (`" User@Example.com "`) deve logar o usuário `user@example.com` e ser aceito pelos comandos → `fortify.lowercase_usernames = true` e normalização nos comandos. Testes em Task 2 e Task 6.
2. **Usuário desativado com 2FA ligado** não pode chegar ao desafio do 2FA (isso confirmaria que a senha está certa) → Task 7.
3. **Redirecionamento pretendido:** acessar `/settings/security` deslogado e depois logar volta para `/settings/security`, não para `/` → Task 5.
4. **Desafio 2FA sem login pendente** (sessão expirou, acesso direto a `/two-factor-challenge`) redireciona para `/login`, sem erro 500 → Task 5.
5. **Código de recuperação reutilizado** é recusado na segunda vez → Task 5.

---

### Task 1: Migrar o frontend para TypeScript estrito

**Files:**
- Create: `tsconfig.json`, `resources/js/types/index.d.ts`, `resources/js/types/inertia.d.ts`, `resources/js/types/global.d.ts`
- Rename+type: `resources/js/app.jsx` → `app.tsx`, `bootstrap.js` → `bootstrap.ts`, `Layouts/AppLayout.jsx` → `AppLayout.tsx`, `Pages/Dashboard.jsx` → `Dashboard.tsx`
- Modify: `vite.config.js` (input `resources/js/app.tsx`, alias `@` → `/resources/js`), `resources/views/app.blade.php` (`@vite([... 'resources/js/app.tsx'])`), `package.json` (devDeps + script `typecheck`), `tests/js/vite-fs-exposure.test.mjs` (entrypoint `.tsx`)

**Interfaces:**
- Produces (em `resources/js/types/index.d.ts`):
  - `interface User { id: number; name: string; email: string; two_factor_enabled: boolean; accounts: EmailAccountSummary[] }`
  - `interface EmailAccountSummary { id: number; provider: 'gmail' | 'outlook'; account_email: string; last_synced_at: string | null; is_active: boolean }`
  - `interface RetentionRuleSummary { id: number; name: string; filter_type: 'category' | 'domain' | 'sender' | 'query'; filter_value: string; retention_days: number; action: 'archive' | 'trash' | 'add_label'; is_active: boolean }`
  - `interface Flash { success: string | null; error: string | null }`
  - `type SharedProps = { auth: { user: User | null }; flash: Flash; status?: string | null }`
- `inertia.d.ts`: `declare module '@inertiajs/core' { interface InertiaConfig { sharedPageProps: SharedProps } }`
- `global.d.ts`: `interface Window { axios: AxiosStatic }`

- [ ] **Step 1:** `npm i -D typescript@latest @types/react@latest @types/react-dom@latest`; adicionar script `"typecheck": "tsc --noEmit"`.
- [ ] **Step 2:** Criar `tsconfig.json`: `strict: true`, `noEmit: true`, `jsx: "react-jsx"`, `module: "ESNext"`, `moduleResolution: "bundler"`, `target: "ES2022"`, `lib: ["ES2022","DOM","DOM.Iterable"]`, `types: ["vite/client"]`, `skipLibCheck: true`, `noUncheckedIndexedAccess: true`, `paths: {"@/*": ["./resources/js/*"]}`, `include: ["resources/js/**/*"]`.
- [ ] **Step 3:** Atualizar o entrypoint no teste do Vite (`/resources/js/app.tsx`) e rodar `npm run test:security`. Expected: FAIL (`app.tsx` ainda não existe → 404).
- [ ] **Step 4:** Renomear (`git mv`) e tipar os 4 arquivos: `Dashboard` recebe `{ accounts: EmailAccountSummary[]; stats: { totalSenders: number; archivedCount: number }; recentRules: RetentionRuleSummary[] }`; `AppLayout` recebe `{ children: React.ReactNode; title?: string }` e lê `usePage().props` já tipado; `app.tsx` usa `resolvePageComponent(\`./Pages/${name}.tsx\`, import.meta.glob('./Pages/**/*.tsx'))`. Atualizar `vite.config.js` e `app.blade.php`.
- [ ] **Step 5:** `npm run typecheck` → 0 erros; `npm run test:security` → todos passam; `npm run build` → OK.
- [ ] **Step 6:** Verificar no navegador (`http://localhost:8000`) que o dashboard renderiza igual e o console não tem erros. Limpar `php artisan optimize:clear` se necessário.
- [ ] **Step 7:** Commit `refactor: migrate frontend to strict TypeScript`.

---

### Task 2: Fortify, pt-BR e login/logout

**Files:**
- Create (via `fortify:install`, depois podado): `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/ResetUserPassword.php`, `app/Actions/Fortify/PasswordValidationRules.php`, migração `..._add_two_factor_columns_to_users_table.php`
- Delete: `app/Actions/Fortify/CreateNewUser.php`, `UpdateUserProfileInformation.php`, `UpdateUserPassword.php` e qualquer migração/config de passkeys publicada
- Create: `lang/pt_BR/auth.php`, `lang/pt_BR/passwords.php`, `lang/pt_BR/validation.php`, `lang/pt_BR.json`, `resources/js/Layouts/GuestLayout.tsx`, `resources/js/Pages/Auth/Login.tsx`, `resources/js/Components/` (inputs/botões reutilizáveis, a critério)
- Modify: `.env.example`, `.env.production.example`, `.env` local (`APP_LOCALE=pt_BR`), `resources/js/Layouts/AppLayout.tsx` (nome, e-mail, botão "Sair"; remover "Modo Demo")
- Test: `tests/Feature/Auth/LoginTest.php`

**Interfaces:**
- Produces: rotas nomeadas do Fortify `login`, `login.store`, `logout`; página Inertia `Auth/Login` com props `{ status: string | null; canResetPassword: boolean }`; `GuestLayout({ title: string; children })`.

- [ ] **Step 1: Testes que falham** (`LoginTest`):
  - `test_login_page_renders` → `get('/login')` ok, componente `Auth/Login`.
  - `test_user_can_login_and_session_is_regenerated` → POST `/login` com credenciais certas redireciona para `/`, `assertAuthenticatedAs`, id da sessão muda.
  - `test_login_is_case_and_whitespace_insensitive_for_email` → usuário `user@example.com`, login com `' User@Example.com '` autentica.
  - `test_wrong_password_shows_generic_message` e `test_unknown_email_shows_generic_message` → erro em `email` exatamente `Credenciais inválidas.`
  - `test_login_is_rate_limited_after_five_attempts` → 5 POSTs errados, o 6º (mesmo com senha certa) responde 429 e não autentica.
  - `test_remember_me_sets_recaller_cookie` → `remember=1` emite cookie `remember_web_*`.
  - `test_logout_invalidates_session` → POST `/logout` → `assertGuest()` e `assertRedirect('/')` (a resposta de logout do Fortify vai para `/`, que então exige login).
  - `test_registration_routes_do_not_exist` → `get('/register')` e `post('/register')` → 404.
  - `test_passkey_routes_do_not_exist` → `get('/passkeys/login/options')` → 404.
  - `test_authenticated_user_is_redirected_away_from_login` → `actingAs` + `get('/login')` redireciona para `/`.
- [ ] **Step 2:** `docker exec inbox_app php artisan test --filter=LoginTest` → FAIL (rotas inexistentes).
- [ ] **Step 3:** `docker exec inbox_app composer require laravel/fortify:^1.43` e `php artisan fortify:install`; podar actions/migrações conforme Files. `config/fortify.php`: `features` conforme Global Constraints, `views => true`, `home => '/'`, `lowercase_usernames => true`. `FortifyServiceProvider::boot`: só `resetUserPasswordsUsing`, `redirectUserForTwoFactorAuthenticationUsing`, limiters `login` e `two-factor` (do stub) e `Fortify::loginView(fn (Request $r) => Inertia::render('Auth/Login', [...]))`.
- [ ] **Step 4:** Traduções: `auth.failed` = `Credenciais inválidas.`, `auth.throttle` = `Muitas tentativas. Tente novamente em :seconds segundos.`; `validation.php` com `required`, `email`, `string`, `confirmed`, `min.string`, `max.string`, `unique`, `password.*` e `attributes` (`email` → `e-mail`, `password` → `senha`, `code` → `código`, `recovery_code` → `código de recuperação`); `pt_BR.json` com as strings do Fortify (`The provided password was incorrect.`, `The provided two factor authentication code was invalid.`, `The provided two factor recovery code was invalid.`) e do e-mail `ResetPassword` (`Reset Password Notification`, `You are receiving this email because we received a password reset request for your account.`, `Reset Password`, `This password reset link will expire in :count minutes.`, `If you did not request a password reset, no further action is required.`, `Hello!`, `Regards,`, e o texto do rodapé do botão do layout de e-mail). `APP_LOCALE=pt_BR` nos três `.env`.
- [ ] **Step 5:** `GuestLayout.tsx` e `Auth/Login.tsx` (`useForm<{ email: string; password: string; remember: boolean }>`, POST `/login`, erros por campo, link "Esqueci minha senha" quando `canResetPassword`, mensagem `status`). `AppLayout.tsx`: botão "Sair" (`router.post('/logout')`).
- [ ] **Step 6:** `php artisan migrate` (container) e rodar `LoginTest` → PASS; suíte completa → os testes antigos do dashboard podem falhar apenas por falta de login (resolvido na Task 3); nenhum outro.
- [ ] **Step 7:** `npm run typecheck` e `npm run build` OK; no navegador, logar com um usuário criado via `tinker` e sair.
- [ ] **Step 8:** Commit `feat: add Fortify login and logout with pt-BR UI`.

---

### Task 3: Dashboard autenticado e isolado por usuário

**Files:**
- Create: `app/Http/Controllers/DashboardController.php`
- Modify: `routes/web.php`, `app/Models/User.php`, `app/Http/Middleware/HandleInertiaRequests.php`
- Test: `tests/Feature/DashboardTest.php` (atualizar), `tests/Unit/Models/UserTest.php` (novo)

**Interfaces:**
- Produces: `User::emailAccounts(): HasMany`, `User::senders(): HasManyThrough<EmailSender>`, `User::retentionRules(): HasManyThrough<RetentionRule>`; `DashboardController::__invoke(Request): Inertia\Response`; rota `GET /` nomeada `dashboard`, middleware `auth`; shared prop `auth.user` = `{ id, name, email, two_factor_enabled, accounts }`.

- [ ] **Step 1: Testes que falham:**
  - `test_guest_is_redirected_to_login` → `get('/')` redireciona para `/login`; `get('/up')` → 200.
  - Testes existentes do dashboard passam a usar `actingAs($user)` com o dono das contas.
  - `test_dashboard_only_shows_the_authenticated_users_data` → usuários A e B, cada um com 1 conta ativa, 2 remetentes e 1 regra; como A: `accounts` tem só a conta de A, `stats.totalSenders` = 2, `recentRules` só a regra de A; a resposta não contém o e-mail da conta de B.
  - `test_shared_auth_user_props_never_expose_secrets` → `auth.user` tem exatamente as chaves `id, name, email, two_factor_enabled, accounts`; o HTML não contém o hash da senha.
  - `UserTest::test_user_relations_scope_through_accounts` → `senders()` e `retentionRules()` retornam só registros das contas do usuário.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3:** Implementar as relações em `User`, o `DashboardController` (consultas partindo de `$request->user()`, colunas das contas como hoje) e a rota em `Route::middleware('auth')`. `HandleInertiaRequests::share`: `two_factor_enabled` = `! is_null($user->two_factor_confirmed_at)`.
- [ ] **Step 4:** Rodar suíte completa com cobertura → PASS, 100%.
- [ ] **Step 5:** Commit `feat: require login and scope dashboard to the authenticated user`.

---

### Task 4: Esqueci minha senha / redefinir senha + política de senha + e-mail

**Files:**
- Create: `app/Http/Responses/PasswordResetLinkRequestedResponse.php`, `resources/js/Pages/Auth/ForgotPassword.tsx`, `resources/js/Pages/Auth/ResetPassword.tsx`
- Modify: `app/Providers/AppServiceProvider.php` (`Password::defaults(fn () => Password::min(12))`), `app/Actions/Fortify/PasswordValidationRules.php` (usa `Password::defaults()`), `app/Providers/FortifyServiceProvider.php` (views + bind das respostas), `.env.example` (bloco Mailtrap comentado; `MAIL_FROM_ADDRESS="no-reply@inbox-orchestrator.local"`, `MAIL_FROM_NAME="Inbox Orchestrator"`), `.env.production.example` (`MAIL_MAILER=resend` e `RESEND_API_KEY=` comentados + nota)
- Test: `tests/Feature/Auth/PasswordResetTest.php`

**Interfaces:**
- Produces: `PasswordResetLinkRequestedResponse` implementa **ambos** `Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse` e `FailedPasswordResetLinkRequestResponse`, retornando `back()->with('status', __('passwords.sent'))`; `passwords.sent` = mensagem genérica dos Global Constraints. Páginas `Auth/ForgotPassword` (`{ status: string | null }`) e `Auth/ResetPassword` (`{ token: string; email: string }`).

- [ ] **Step 1: Testes que falham** (`Notification::fake()`):
  - `test_reset_link_is_sent_to_existing_user` → `ResetPassword` enviada; sessão `status` = mensagem genérica.
  - `test_unknown_email_gets_the_same_response` → nenhuma notificação; mesmo redirect e mesmo `status`; sem erros de validação.
  - `test_repeated_request_is_throttled_silently` → 2º pedido imediato para o mesmo usuário: mesma resposta, sem erro.
  - `test_password_can_be_reset_with_valid_token` → nova senha de 12+ caracteres funciona no login.
  - `test_reset_token_cannot_be_reused` e `test_expired_token_is_rejected` (`travel(61)->minutes()`).
  - `test_password_shorter_than_12_characters_is_rejected`.
  - `test_reset_email_is_in_portuguese` → `(new ResetPassword('t'))->toMail($user)` tem assunto e linhas em pt-BR (ex.: assunto `Redefinição de senha`).
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3:** Implementar views (`requestPasswordResetLinkView`, `resetPasswordView`), bind dos dois contratos em `FortifyServiceProvider::register`, política de senha e traduções faltantes.
- [ ] **Step 4:** Rodar → PASS; `npm run typecheck`/`build` OK.
- [ ] **Step 5:** Verificação manual: pedir reset no navegador e abrir o link a partir de `storage/logs/laravel.log` (mailer `log`); se o dono já tiver colocado credenciais do Mailtrap, conferir lá.
- [ ] **Step 6:** Commit `feat: add password reset with generic responses and pt-BR email`.

---

### Task 5: 2FA opcional (desafio, confirmar senha, página Segurança)

**Files:**
- Create: `app/Http/Controllers/Settings/SecurityController.php`, `resources/js/Pages/Auth/TwoFactorChallenge.tsx`, `resources/js/Pages/Auth/ConfirmPassword.tsx`, `resources/js/Pages/Settings/Security.tsx`
- Modify: `app/Models/User.php` (`use TwoFactorAuthenticatable`; `$hidden` += `two_factor_secret`, `two_factor_recovery_codes`), `app/Providers/FortifyServiceProvider.php` (`twoFactorChallengeView`, `confirmPasswordView`), `routes/web.php`, `resources/js/Layouts/AppLayout.tsx` (link "Segurança")
- Test: `tests/Feature/Auth/TwoFactorAuthenticationTest.php`, `tests/Feature/Settings/SecurityPageTest.php`

**Interfaces:**
- Consumes: Fortify endpoints `POST/DELETE /user/two-factor-authentication`, `POST /user/confirmed-two-factor-authentication`, `GET /user/two-factor-qr-code` (`{ svg, url }`), `GET /user/two-factor-secret-key` (`{ secretKey }`), `GET|POST /user/two-factor-recovery-codes`, `POST /two-factor-challenge` (`code` | `recovery_code`).
- Produces: rota `GET /settings/security` nomeada `settings.security`, middleware `['auth', 'password.confirm']` (*decisão: pedir a senha ao abrir a página cobre todas as ações por 3 h*); página `Settings/Security` com props `{ twoFactorEnabled: boolean; twoFactorPending: boolean; status: string | null }`. QR, chave e códigos são buscados via `window.axios` **somente** quando exibidos, nunca como props.

- [ ] **Step 1: Testes que falham:**
  - `SecurityPageTest`: deslogado → `/login`; logado sem confirmar senha → `/user/confirm-password`; após `POST /user/confirm-password` → página renderiza com `twoFactorEnabled=false`.
  - `test_intended_url_is_preserved_after_login` → `get('/settings/security')` deslogado, depois login → redireciona para `/settings/security` (passando pela confirmação de senha).
  - `TwoFactorAuthenticationTest` (usar `Google2FA` para gerar códigos com o segredo descriptografado):
    - ativar → `two_factor_secret` preenchido e `two_factor_confirmed_at` nulo; confirmar com código válido → confirmado; código inválido → erro `Código...` em pt-BR.
    - login de usuário com 2FA confirmado → redireciona para `/two-factor-challenge`, ainda não autenticado; código válido → autenticado.
    - `test_recovery_code_works_only_once` → 1º uso autentica; logout; reutilizar o mesmo código → erro.
    - `test_two_factor_challenge_without_pending_login_redirects_to_login`.
    - `test_two_factor_challenge_is_rate_limited` → 6º código errado → 429.
    - desativar → colunas zeradas; próximo login vai direto ao dashboard.
    - `test_two_factor_secrets_are_hidden` → `toArray()` sem `two_factor_secret`/`two_factor_recovery_codes`; JSON de `/settings/security` sem eles.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3:** Implementar `User`, views do Fortify, `SecurityController` e as três páginas. Fluxo da página: desligado → botão "Ativar" (POST enable) → mostra QR SVG + chave + campo de código (POST confirm) → mostra códigos de recuperação com aviso para guardar; ligado → "Ver códigos", "Gerar novos códigos", "Desativar". `TwoFactorChallenge` alterna entre código e código de recuperação.
- [ ] **Step 4:** Rodar suíte completa com cobertura → PASS, 100%; `npm run typecheck`/`build` OK.
- [ ] **Step 5:** Verificação manual no navegador com um app autenticador (ou gerando o código via `tinker`): ativar, sair, logar com código, logar com código de recuperação, desativar.
- [ ] **Step 6:** Commit `feat: add optional TOTP two-factor authentication`.

---

### Task 6: `user:create`

**Files:**
- Create: `app/Console/Commands/CreateUserCommand.php`
- Test: `tests/Feature/Console/CreateUserCommandTest.php`

**Interfaces:**
- Consumes: `Password::defaults()` (Task 4).
- Produces: assinatura `user:create {name} {email} {--password=}`; e-mail normalizado com `Str::lower(trim())`.

- [ ] **Step 1: Testes que falham:**
  - cria usuário com `--password`, e-mail `' New@Example.com '` salvo como `new@example.com`; saída `Usuário new@example.com criado.`; exit 0.
  - interativo: `expectsQuestion('Senha', ...)` e `expectsQuestion('Confirme a senha', ...)`; confirmação diferente → erro, nada criado.
  - não interativo sem `--password` (`--no-interaction`) → gera senha de 24 caracteres, exibe uma vez (`Senha gerada: ...`), e ela funciona no login.
  - e-mail duplicado, e-mail inválido e senha < 12 → exit 1, mensagem em pt-BR, nada criado.
  - `test_password_is_not_logged` → `Log::spy()`; nenhum log contém a senha.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3:** Implementar (validação com `Validator::make` + `Password::defaults()`, `$this->secret()` para entrada oculta, `Str::password(24)` para geração).
- [ ] **Step 4:** Rodar → PASS, cobertura 100%.
- [ ] **Step 5:** Commit `feat: add user:create command`.

---

### Task 7: Desativar, reativar e remover usuários

**Files:**
- Create: migração `..._add_disabled_at_to_users_table.php`, `app/Console/Commands/DisableUserCommand.php`, `EnableUserCommand.php`, `DeleteUserCommand.php`, `app/Http/Middleware/EnsureUserIsActive.php`
- Modify: `app/Models/User.php`, `app/Providers/FortifyServiceProvider.php` (`Fortify::authenticateUsing`), `routes/web.php` (grupo `['auth', EnsureUserIsActive::class]`), `bootstrap/app.php` (alias `active`)
- Test: `tests/Feature/Console/UserLifecycleCommandsTest.php`, `tests/Feature/Auth/DisabledUserTest.php`

**Interfaces:**
- Produces: coluna `users.disabled_at` (`timestamp`, nullable), cast `datetime`; `User::isDisabled(): bool`; `User::sendPasswordResetNotification($token)` não envia se desativado; comandos `user:disable {email} {--force}`, `user:enable {email}`, `user:delete {email} {--force}`; middleware alias `active`.

- [ ] **Step 1: Testes que falham:**
  - `user:disable` com `--force`: `disabled_at` preenchido, linhas do usuário em `sessions` apagadas, `remember_token` alterado; sem `--force` e resposta "não" → nada muda. E-mail inexistente → exit 1 `Usuário não encontrado.`. Argumento com maiúsculas funciona.
  - `user:enable` limpa `disabled_at`; o usuário volta a logar.
  - `user:delete`: confirmação digitando o e-mail; e-mail errado → aborta, nada apagado; correto (ou `--force`) → usuário, contas, remetentes, metadados e regras removidos.
  - `DisabledUserTest`:
    - login com senha certa → `Credenciais inválidas.`, não autenticado.
    - `test_disabled_user_with_two_factor_does_not_reach_challenge` → resposta igual à de senha errada, sem redirect para `/two-factor-challenge`.
    - `test_old_remember_cookie_stops_working_after_disable` → logar com `remember`, desativar, nova requisição só com o cookie recaller → `/login`.
    - `test_active_session_is_logged_out_by_middleware` → `actingAs` de usuário desativado em `/` → logout + `/login`.
    - `test_disabled_user_gets_no_reset_email_but_same_response`.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3:** Implementar migração, `User`, `authenticateUsing` (retorna o usuário só se a senha confere **e** não está desativado), middleware (logout, invalida sessão, redireciona para `login`), comandos (normalizam o e-mail; `$this->confirm()`/`$this->ask()`; `DB::table('sessions')->where('user_id', ...)->delete()`; `remember_token` = `Str::random(60)`).
- [ ] **Step 4:** Rodar suíte completa com cobertura → PASS, 100%.
- [ ] **Step 5:** Commit `feat: add user disable, enable and delete commands`.

---

### Task 8: `user:reset-two-factor`

**Files:**
- Create: `app/Console/Commands/ResetUserTwoFactorCommand.php`
- Test: `tests/Feature/Console/ResetUserTwoFactorCommandTest.php`

**Interfaces:**
- Consumes: colunas do 2FA (Task 5), normalização de e-mail (Task 6).
- Produces: `user:reset-two-factor {email} {--force}`.

- [ ] **Step 1: Testes que falham:** com `--force` zera `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` e o próximo login não pede código; sem `--force` e "não" → nada muda; e-mail inexistente → exit 1.
- [ ] **Step 2:** Rodar → FAIL. **Step 3:** Implementar (usar `DisableTwoFactorAuthentication` do Fortify). **Step 4:** Rodar → PASS.
- [ ] **Step 5:** Commit `feat: add user:reset-two-factor command`.

---

### Task 9: Verificação final

- [ ] **Step 1:** `docker exec inbox_app php artisan test --coverage` → tudo verde, **Total 100%**, incluindo `tests/Security` (audit).
- [ ] **Step 2:** `docker exec inbox_app vendor/bin/pint --test app routes tests config lang` → sem erros nos arquivos tocados.
- [ ] **Step 3:** `npm run typecheck`, `npm run build`, `npm run test:security`, `npm audit --omit=dev` → OK.
- [ ] **Step 4:** Navegador, roteiro completo: `user:create` → login → dashboard só com os próprios dados → Segurança → ativar 2FA → sair → login com código → esqueci a senha (link no log/Mailtrap) → redefinir → `user:disable` com a sessão aberta (próximo clique cai no login) → `user:enable` → `user:delete`. Console do navegador sem erros.
- [ ] **Step 5:** Atualizar `SPEC.md` (seção 6, Fase 1: autenticação concluída) e commit `docs: mark authentication as done in spec`.
