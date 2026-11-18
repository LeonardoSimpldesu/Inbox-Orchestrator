# Design: Autenticação e isolamento por usuário

**Data:** 2026-11-18
**Status:** Aprovado pelo dono em 2026-11-18 (decisões do redator marcadas como *decisão do redator*)
**Contexto:** SPEC.md, Fase 1 — "Autenticação e gestão de múltiplos usuários"

## 1. Intenção

O Inbox Orchestrator guarda tokens OAuth que leem, alteram e **enviam** e-mails em nome do usuário. Hoje a página `/` é pública e mostra as contas de e-mail de todos os usuários. Precisamos de:

1. Login obrigatório para tudo que não seja a tela de login, a recuperação de senha e o health check (`/up`).
2. Cada usuário vê e manipula **apenas os próprios dados**.

**Quem usa:** o dono do projeto e algumas pessoas próximas. Não é um produto aberto ao público.

## 2. Objetivos, não-objetivos e anti-objetivos

**Objetivos**
- Login com e-mail e senha, opção "lembrar-me" e logout.
- Limite de tentativas de login.
- "Esqueci minha senha", com link de redefinição enviado por e-mail.
- 2FA por TOTP (app autenticador), **opcional por usuário**, com códigos de recuperação.
- Comandos Artisan para o dono criar, desativar, reativar e remover usuários.
- Frontend migrado para **TypeScript (TSX) com checagem estrita**, para pegar erros antes de rodar.
- Dashboard restrito ao usuário logado.
- Toda a interface em pt-BR, no visual escuro existente.

**Não-objetivos (fora deste trabalho)**
- Cadastro público, convites e verificação de e-mail.
- Edição de perfil (nome/e-mail) e troca de senha pela interface. A troca de senha pode vir depois; hoje o reset cobre o caso.
- Papéis ou permissões (admin, membro). Todos os usuários são iguais.
- Login social ("Entrar com Google"). O OAuth do Gmail é outra coisa: conecta caixas de e-mail, não autentica no app.
- Passkeys. O Fortify 1.43 traz `laravel/passkeys` como dependência, mas o recurso fica desligado.
- Gerenciar usuários pela interface. A gestão é só por comando (6.2).
- Telas de Assinaturas e Regras TTL (próximas etapas). Elas devem nascer já usando o isolamento definido aqui.

**Anti-objetivos (seria um fracasso mesmo funcionando)**
- Qualquer caminho que permita criar uma conta sem passar pelo dono.
- Um usuário enxergar, por qualquer rota ou prop, dados de outro usuário.
- Expor segredos de 2FA, códigos de recuperação, tokens de reset ou tokens OAuth em props do Inertia, logs ou respostas JSON.
- Respostas que revelem se um e-mail está cadastrado (no login e no "esqueci minha senha").

## 3. Restrições

- Stack: Laravel 13, PHP 8.5, Inertia 3, React 19 (**TypeScript/TSX, `strict: true`**), Tailwind 4. Sem bibliotecas de componentes novas; seguir o estilo de `resources/js/Layouts/AppLayout` e `Pages/Dashboard` (slate escuro, destaque indigo, ícones `lucide-react`).
- `npm run typecheck` (`tsc --noEmit`) precisa passar sem erros, como os testes.
- A suíte PHP deve manter **100% de cobertura** de `app/` (padrão do repositório) e os testes de segurança existentes (`tests/Security`, `npm run test:security`) devem continuar passando.
- A tabela `users` já tem `email_verified_at`, `remember_token`, `password_reset_tokens` e `sessions` (driver `database`). O 2FA precisa das colunas do Fortify (`two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`) em uma migração nova.
- E-mail: em dev, o dono usa o **Mailtrap** (sandbox SMTP) para ver os e-mails; sem credenciais, `MAIL_MAILER=log` continua funcionando (o link aparece em `storage/logs/laravel.log`). Em produção o envio será pelo **Resend**, habilitado depois (fora deste trabalho).

## 4. Abordagens consideradas

| Abordagem | Prós | Contras |
|---|---|---|
| **A. Laravel Fortify (headless) + páginas próprias em TSX** ✅ escolhida | Backend de autenticação oficial e mantido: rate limiting, reset de senha, 2FA TOTP com códigos de recuperação e confirmação de senha. Recursos desligáveis por configuração. Não toca no frontend existente. | Precisamos escrever as telas (~5). |
| B. Laravel Breeze (React) | Telas e controllers prontos. | Traz cadastro, verificação e perfil, que teríamos que remover. **Não tem 2FA.** Sobrescreve `app.jsx` e o layout. Não é mais o caminho recomendado. |
| C. Implementação manual (Auth + biblioteca TOTP) | Controle total. | Nós mesmos teríamos que acertar rate limiting, replay de TOTP, códigos de recuperação e expiração de tokens. Mais código sensível para manter, sem ganho. |

Também descartado: o starter kit React do Laravel 13. Ele é TypeScript, mas impõe shadcn e uma estrutura própria, sobrescrevendo o visual existente, e por baixo usa o próprio Fortify. Migrar os 4 arquivos atuais para TSX sai mais barato.

**Decisão do dono:** opção A.

## 5. Decisões já tomadas (pelo dono)

1. **Sem cadastro público.** Contas são criadas pelo dono via comando Artisan.
2. **Login:** e-mail + senha, "lembrar-me", logout, limite de 5 tentativas por minuto por combinação e-mail + IP.
3. **"Esqueci minha senha": incluso**, via e-mail.
4. **Sem verificação de e-mail.**
5. **2FA TOTP opcional por usuário**, com códigos de recuperação.
6. **Interface em pt-BR**, mesmo visual escuro.
7. **Dashboard** exige login e mostra só os dados do usuário. Visitante é redirecionado para `/login`.
8. **Frontend em TypeScript**: a tipagem é importante para evitar erros.
9. **Desativar usuário é obrigatório**; remover também é importante.
10. **E-mail:** Mailtrap agora para testes; Resend depois.

## 6. Design

### 6.0 Migração do frontend para TypeScript (primeiro passo)

- Dependências de dev: `typescript`, `@types/react`, `@types/react-dom` (versões estáveis mais recentes).
- `tsconfig.json` com `strict: true`, `noEmit`, `jsx: react-jsx`, `moduleResolution: bundler`, `types: ["vite/client"]` e alias `@/*` → `resources/js/*` (o mesmo alias no `vite.config`).
- Renomear `app.jsx` → `app.tsx`, `bootstrap.js` → `bootstrap.ts`, `Layouts/AppLayout.jsx` → `.tsx`, `Pages/Dashboard.jsx` → `.tsx`, tipando props e removendo `any` implícitos. Ajustar `@vite(...)` no `app.blade.php`, o `input` do `vite.config` e o glob do `resolvePageComponent` para `.tsx`.
- Tipos compartilhados em `resources/js/types/`: `User`, `EmailAccountSummary`, `RetentionRuleSummary`, `Flash`, e a declaração dos *shared props* do Inertia (`auth`, `flash`) via module augmentation de `@inertiajs/core`, para que `usePage().props` seja tipado.
- Script `npm run typecheck` (`tsc --noEmit`). O teste de exposição do Vite (`npm run test:security`) é atualizado para `resources/js/app.tsx`.
- Comportamento e visual inalterados; validar no navegador antes de seguir.

### 6.1 Backend (Fortify)

- Instalar `laravel/fortify` (versão estável mais recente; v1.43 em 2026-11-18) e publicar config, migração e actions.
- `config/fortify.php`:
  - `features`: **somente** `resetPasswords()` e `twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])`. **Sem** `registration()`, `emailVerification()`, `updateProfileInformation()`, `updatePasswords()`.
  - `views => true`; as views são registradas como respostas Inertia (ver 6.3).
  - `home => '/'`.
- Remover as actions publicadas que não serão usadas (por exemplo `CreateNewUser`, `UpdateUserProfileInformation`, `UpdateUserPassword`), para não deixar caminho de criação de conta nem código morto fora da cobertura. Manter `ResetUserPassword` e `PasswordValidationRules`.
- Rate limiters em `FortifyServiceProvider`: `login` com 5/min por `email|ip`, e `two-factor` com 5/min por `login.id` da sessão (padrão do Fortify).
- `User` usa a trait `TwoFactorAuthenticatable`. Adicionar `two_factor_secret` e `two_factor_recovery_codes` a `$hidden` (o Fortify já os grava criptografados).
- Política de senha (`PasswordValidationRules`): `Password::defaults()` definido no `AppServiceProvider` como **mínimo de 12 caracteres em todos os ambientes** (*decisão do redator*: uma regra só evita surpresas entre dev e produção; os testes usam a factory, que já gera senhas longas).

### 6.2 Criação de usuários

`php artisan user:create {name} {email} {--password=}`
- Valida e-mail (formato e unicidade) e senha (mesma política de 6.1).
- Sem `--password`: pede a senha no terminal (oculta, com confirmação). Se o terminal não for interativo, gera uma senha aleatória forte e a exibe **uma única vez**.
- Nunca grava a senha em log.
- Saída em pt-BR. Código de retorno ≠ 0 em erro de validação.

**Desativar, reativar e remover** (pedido do dono):
- Nova coluna `users.disabled_at` (timestamp nulo). `User::isDisabled()`.
- `php artisan user:disable {email}`:
  - grava `disabled_at`;
  - **encerra as sessões ativas** do usuário (apaga as linhas dele na tabela `sessions`) e troca o `remember_token`, para derrubar o "lembrar-me";
  - pede confirmação (`--force` pula).
- `php artisan user:enable {email}`: limpa `disabled_at`.
- `php artisan user:delete {email}`: pede confirmação digitando o e-mail (`--force` pula); remove o usuário e, em cascata, as contas de e-mail, remetentes, metadados e regras. A revogação dos tokens no Google ao remover fica para a fase da integração com o Gmail, quando ela existir.
- Um usuário desativado:
  - **não consegue logar**: checado no `Fortify::authenticateUsing`, com a mesma mensagem genérica "Credenciais inválidas." para não revelar que a conta existe ou está desativada;
  - **não recebe link de reset de senha**, com a mesma resposta genérica do "esqueci minha senha";
  - se ainda tiver uma requisição em andamento, é deslogado: um middleware no grupo `auth` checa `isDisabled()` (*decisão do redator*, defesa extra caso alguma sessão escape).
  - Os jobs futuros (sync e regras TTL) devem ignorar contas de usuários desativados; isso vira requisito das próximas etapas.

`php artisan user:reset-two-factor {email}` (*decisão do redator*)
- Desativa o 2FA de um usuário que perdeu o celular **e** os códigos de recuperação. Como o 2FA é opcional e o dono administra as contas, sem isso a única saída seria editar o banco à mão.
- Pede confirmação no terminal (`--force` pula). Só quem tem acesso ao servidor consegue executar.

### 6.3 Rotas e páginas (Inertia, TSX)

Layout novo `Layouts/GuestLayout.tsx`: card centralizado, logo e nome "Inbox Orchestrator", mesmo fundo slate.

| Rota (Fortify) | Página | Conteúdo |
|---|---|---|
| `GET /login` | `Auth/Login` | e-mail, senha, "Lembrar-me", link "Esqueci minha senha", status de sucesso (ex.: senha redefinida) |
| `GET /forgot-password` | `Auth/ForgotPassword` | e-mail; mensagem **sempre igual**, exista ou não a conta |
| `GET /reset-password/{token}` | `Auth/ResetPassword` | e-mail (pré-preenchido), nova senha, confirmação |
| `GET /two-factor-challenge` | `Auth/TwoFactorChallenge` | código de 6 dígitos ou alternância para "usar código de recuperação" |
| `GET /user/confirm-password` | `Auth/ConfirmPassword` | senha atual (exigida antes de ativar/desativar 2FA ou ver códigos) |
| `GET /settings/security` (rota da app, `auth`) | `Settings/Security` | estado do 2FA; ativar → QR code + chave manual → confirmar com código → mostrar códigos de recuperação; regenerar códigos; desativar |

- As telas de 2FA usam os endpoints JSON do Fortify (`/user/two-factor-qr-code`, `/user/two-factor-secret-key`, `/user/two-factor-recovery-codes`), chamados só depois da confirmação de senha. O QR code é SVG inline gerado pelo servidor.
- Mensagens de validação em pt-BR: publicar `lang/pt_BR` com o mínimo necessário (`auth.php`, `passwords.php`, `validation.php` para as regras usadas) e `APP_LOCALE=pt_BR` (em `.env.example` e `.env.production.example`).
- O **e-mail de redefinição de senha** também sai em pt-BR. As strings da notificação padrão `ResetPassword` do Laravel são traduzidas em `lang/pt_BR.json` (*decisão do redator*: evita uma notificação customizada só para trocar o idioma).

### 6.4 Layout autenticado

Em `AppLayout.tsx`:
- O canto direito mostra nome e e-mail do usuário, um link "Segurança" (`/settings/security`) e o botão "Sair" (POST `/logout`).
- Remover o fallback "Modo Demo", já que o layout só será usado autenticado.
- O badge "Ambiente Docker" do dashboard não faz parte deste trabalho.

### 6.5 Isolamento de dados

- O grupo de rotas da aplicação (hoje só `/`) fica em `middleware('auth')`.
- `/` vira `DashboardController@__invoke`, e todas as consultas partem do usuário:
  - contas: `$user->emailAccounts()` (ativas, só as colunas exibidas, como já é feito);
  - total de remetentes: `EmailSender` via `whereHas('account', fn ($q) => $q->where('user_id', $user->id))`, ou relação `hasManyThrough` em `User`;
  - regras: idem, via contas do usuário.
- Adicionar em `User` as relações `senders()` e `retentionRules()` (`hasManyThrough` via `EmailAccount`), para que as próximas telas (Assinaturas e Regras) partam sempre do usuário.
- `HandleInertiaRequests::share` já compartilha `auth.user` com as contas do próprio usuário; manter, garantindo que nenhum campo de 2FA ou token apareça.

### 6.6 Sessão e segurança

- Regenerar a sessão no login e invalidá-la no logout (comportamento do Fortify; coberto por teste).
- Rotas de convidado (`/login` etc.) redirecionam usuários autenticados para `/`.
- Mensagem genérica de falha de login: "Credenciais inválidas." (não diferenciar e-mail inexistente de senha errada).
- Os headers e o hardening do `SecurityHeaders` e do `.env.production.example` continuam valendo. Acrescentar as variáveis `MAIL_*` comentadas no template de produção.

### 6.7 Ajustes no que já existe

- Os testes atuais de `tests/Feature/DashboardTest.php` acessam `/` sem login; passam a autenticar com `actingAs` e o usuário dono das contas criadas.
- O teste do dashboard que verifica que contas inativas não aparecem continua valendo, agora dentro do escopo do usuário.
- `.env.example` ganha o bloco do **Mailtrap** comentado (`MAIL_MAILER=smtp`, `MAIL_HOST=sandbox.smtp.mailtrap.io`, `MAIL_PORT=2525`, `MAIL_USERNAME`/`MAIL_PASSWORD` vazios), mantendo `log` como padrão.
- `.env.production.example` ganha `MAIL_MAILER=resend` e `RESEND_API_KEY=` comentados, com uma nota de que o reset de senha depende disso. O pacote do Resend só será instalado quando o envio for habilitado.
- `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` com valores do Inbox Orchestrator nos dois templates.

## 7. Testes esperados

Feature tests (PHPUnit), além de manter a cobertura em 100%:
- Visitante em `/` é redirecionado para `/login`; `/up` continua público.
- Login com sucesso, falha com mensagem genérica, rate limit após 5 tentativas, "lembrar-me" emite o cookie, logout invalida a sessão.
- **Não existe rota de cadastro** (`GET`/`POST /register` → 404).
- Reset de senha: a mesma resposta para e-mail existente e inexistente; o token funciona uma vez; o token expirado falha.
- 2FA: ativar exige confirmação de senha; confirmar com código válido; login de usuário com 2FA cai no desafio; código TOTP e código de recuperação funcionam; código de recuperação é de uso único; desativar.
- Isolamento: com dois usuários, cada um com contas, remetentes e regras, o dashboard de A não contém nenhum dado de B (contagens e e-mails).
- Props do Inertia nunca contêm `password`, `remember_token`, `two_factor_*`, `access_token` ou `refresh_token`.
- `user:create`: cria usuário; rejeita e-mail duplicado ou inválido e senha fraca; modo não interativo gera e exibe senha.
- `user:reset-two-factor`: limpa segredo, códigos e confirmação; e-mail inexistente retorna erro; sem `--force`, recusar a confirmação não altera nada.
- `user:disable`: usuário desativado não loga (mensagem genérica), suas sessões são apagadas, o `remember_token` muda, o cookie "lembrar-me" antigo deixa de funcionar e uma sessão já aberta é derrubada pelo middleware. Não recebe e-mail de reset (`Notification::fake`).
- `user:enable`: volta a logar normalmente.
- `user:delete`: remove o usuário e os dados em cascata; e-mail errado na confirmação aborta sem apagar.
- Frontend: `npm run typecheck` sem erros e `npm run build` funcionando.

## 8. Deixado para quem implementa

- Organização interna dos componentes React (inputs, botões e cards reutilizáveis entre as páginas de auth), todos tipados.
- Textos exatos das mensagens, desde que em pt-BR e sem revelar existência de conta.
- A estratégia de escopo (relações `hasManyThrough` ou `whereHas`), desde que tudo parta do usuário autenticado.
- Gerar o QR code com a dependência do próprio Fortify (`bacon/bacon-qr-code`).
