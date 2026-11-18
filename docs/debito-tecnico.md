# Débito técnico

Itens conhecidos que ficaram para depois. Cada um traz o impacto, onde está e uma sugestão de correção. Ao resolver um item, remova-o daqui no mesmo commit da correção.

| # | Item | Impacto | Prioridade |
|---|------|---------|------------|
| 1 | Senha pedida de novo logo após o login | UX | Baixa |
| 2 | Página "Segurança" ignora erros de carregamento | UX | Média |
| 3 | "Esqueci minha senha" sem limite por IP | Segurança (abuso) | Média |
| 4 | Campo interno extra nas regras do dashboard | Limpeza | Baixa |
| 5 | Endpoints de 2FA sem checagem de usuário desativado | Segurança (defesa em profundidade) | Baixa |
| 6 | Título da aba vazio nas páginas internas | UX | Baixa |
| 7 | Aviso de estilo do Pint no `UserFactory` | Qualidade | Baixa |

---

## 1. Senha pedida de novo logo após o login

**Origem:** autenticação (2026-11-18).

**O que acontece:** ao abrir `/settings/security` logo depois de entrar, a pessoa é mandada para "Confirme sua senha", mesmo tendo digitado a senha segundos antes. O middleware `password.confirm` só considera `auth.password_confirmed_at` na sessão, que o login não preenche.

**Onde:** `routes/web.php` (rota `settings.security`), pipeline de login do Fortify.

**Sugestão:** gravar `auth.password_confirmed_at` ao concluir o login com senha (não no login por cookie "lembrar-me"), por exemplo com um passo extra em `Fortify::authenticateThrough()`. Teste: login com senha → `/settings/security` abre direto; login via cookie "lembrar-me" → continua pedindo a senha.

## 2. Página "Segurança" ignora erros de carregamento

**Origem:** autenticação (2026-11-18).

**O que acontece:** o QR code, a chave e os códigos de recuperação são buscados via `axios` sem tratamento de erro. Se a confirmação de senha expirar (3 horas) com a página aberta, os endpoints do Fortify respondem 423 e nada aparece, sem mensagem.

**Onde:** `resources/js/Pages/Settings/Security.tsx` (`loadSetup`, `loadRecoveryCodes`).

**Sugestão:** tratar falhas: em 423, redirecionar para `/user/confirm-password`; nos demais erros, mostrar uma mensagem com opção de tentar de novo.

## 3. "Esqueci minha senha" sem limite por IP

**Origem:** autenticação (2026-11-18).

**O que acontece:** `POST /forgot-password` não tem `throttle`. O broker do Laravel limita um envio a cada 60 s por usuário existente, e e-mails desconhecidos não disparam envio, mas alguém pode fazer muitas requisições seguidas (custo de banco e de envio de e-mail para contas existentes, de minuto em minuto).

**Onde:** rotas do Fortify (`password.email`).

**Sugestão:** limitador `password-reset` (ex.: 5/min por IP) aplicado à rota, mantendo a mesma resposta genérica quando o limite for atingido.

## 4. Campo interno extra nas regras do dashboard

**Origem:** autenticação (2026-11-18).

**O que acontece:** `recentRules` usa `hasManyThrough`, que acrescenta `laravel_through_key` (o id da conta de e-mail do próprio usuário) às props. Não vaza dados de terceiros, mas é ruído fora do tipo `RetentionRuleSummary`.

**Onde:** `app/Http/Controllers/DashboardController.php`.

**Sugestão:** mapear para as colunas exibidas (ou usar um API Resource) antes de enviar como prop.

## 5. Endpoints de 2FA sem checagem de usuário desativado

**Origem:** autenticação (2026-11-18).

**O que acontece:** as rotas JSON do Fortify (`/user/two-factor-*`) usam só o middleware `auth`, sem `active`. Hoje isso não é explorável, porque `user:disable` apaga as sessões e troca o `remember_token`, mas a checagem existe só nas rotas da aplicação.

**Onde:** `config/fortify.php` (`middleware`).

**Sugestão:** incluir `active` (e `auth.session`) no `middleware` do Fortify, com teste garantindo que um usuário desativado com sessão residual recebe 401/redirect nesses endpoints.

## 6. Título da aba vazio nas páginas internas

**Origem:** anterior à autenticação.

**O que acontece:** as páginas com `AppLayout` mostram "- Inbox Orchestrator" na aba, porque o `AppLayout` recebe `title` mas não o usa.

**Onde:** `resources/js/Layouts/AppLayout.tsx`.

**Sugestão:** renderizar `<Head title={title} />` no `AppLayout` (como já faz o `GuestLayout`).

## 7. Aviso de estilo do Pint no `UserFactory`

**Origem:** anterior à autenticação (código gerado pelo Laravel).

**O que acontece:** `vendor/bin/pint --test` aponta `fully_qualified_strict_types` e `ordered_imports` em `database/factories/UserFactory.php`.

**Sugestão:** rodar `vendor/bin/pint database/factories/UserFactory.php` e considerar adicionar `pint --test` à verificação antes de merge.
