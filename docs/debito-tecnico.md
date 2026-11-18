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
| 7 | Acesso ao Gmail expira a cada 7 dias (app do Google em modo Teste) | UX / operação | Alta antes de uso diário |
| 8 | Contas Gmail desconectadas guardadas para sempre | Privacidade / limpeza | Média |
| 9 | `user:delete` não revoga o acesso ao Gmail no Google | Segurança (higiene) | Baixa |
| 10 | Sessão expirada durante o consentimento do Google gera erro de validação | UX | Baixa |

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

## 7. Acesso ao Gmail expira a cada 7 dias

**Origem:** conexão Gmail (2026-11-18).

**O que acontece:** o app OAuth do Google é "Externo" e está em modo **Teste**. Nesse modo o Google expira o refresh token 7 dias depois da autorização, e só testadores cadastrados conseguem conectar. O app mostra "Reconecte até dd/mm" e marca a conta como "Precisa reconectar" (`GOOGLE_OAUTH_TESTING=true`), mas cada pessoa precisa reconectar toda semana.

**Onde:** configuração do projeto no Google Cloud Console; `config/services.php` (`google.testing`); `EmailAccount::reconnectBy()`.

**Soluções:**
1. **Publicar o app em Produção sem verificação (recomendada para uso pessoal).** Em "OAuth consent screen", clicar em "Publish app". Os refresh tokens deixam de expirar por semana. Custos: a pessoa vê a tela "O Google não verificou este app" e precisa clicar em "Avançado > Acessar"; apps não verificados com escopos restritos têm limite de 100 usuários. Depois, trocar `GOOGLE_OAUTH_TESTING=false`.
2. **Verificação completa do Google.** Exige política de privacidade, domínio verificado, vídeo de demonstração e, para escopos restritos (`gmail.modify`, `gmail.send`), a avaliação de segurança CASA anual, que é paga. Só compensa se o app for aberto ao público.
3. **Google Workspace com app "Interno".** Sem expiração e sem verificação, mas só aceita contas do próprio domínio Workspace. Não serve para contas @gmail.com.

Mesmo sem a expiração semanal, o refresh token ainda pode ser invalidado (senha trocada, acesso revogado pela pessoa, 6 meses sem uso). Esse caso já é tratado: `reauth_required_at` + "Precisa reconectar".

## 8. Contas Gmail desconectadas guardadas para sempre

**Origem:** conexão Gmail (2026-11-18).

**O que acontece:** desconectar faz soft delete. Remetentes, metadados e regras ficam guardados para quando a pessoa reconectar, sem prazo de exclusão.

**Onde:** `EmailAccountController::destroy`, `email_accounts.deleted_at`.

**Sugestão:** comando agendado (ex.: `inbox:purge-disconnected-accounts`, diário) que faz `forceDelete()` das contas desconectadas há mais de N dias (ex.: 90; configurável), apagando em cascata os dados ligados. Mostrar o prazo na mensagem de desconexão.

## 9. `user:delete` não revoga o acesso ao Gmail no Google

**Origem:** conexão Gmail (2026-11-18).

**O que acontece:** `php artisan user:delete` apaga o usuário e, em cascata, as contas Gmail (inclusive tokens). O app perde os tokens, mas a autorização continua listada em "Apps com acesso à sua conta" do Google até expirar ou ser removida pela pessoa.

**Onde:** `app/Console/Commands/DeleteUserCommand.php`.

**Sugestão:** antes de apagar, chamar `EmailProviderInterface::revoke()` para cada conta ativa do usuário (falhas só geram aviso, como no "Desconectar"). Teste com o `FakeEmailProvider`.

## 10. Sessão expirada durante o consentimento do Google gera erro de validação

**Origem:** conexão Gmail (2026-11-18).

**O que acontece:** se a sessão expira enquanto a pessoa está na tela do Google, a volta pede login. Depois do login ela cai no callback sem o `state` na sessão e vê "Não foi possível validar a resposta do Google. Tente novamente.". Basta conectar de novo, mas a mensagem não explica o motivo.

**Onde:** `EmailAccountController::callback`.

**Sugestão:** quando não houver `state` na sessão (em vez de um `state` diferente), mostrar "Sua sessão expirou durante a conexão. Clique em Conectar conta Gmail de novo.".

