# Inbox Orchestrator

Cockpit para automatizar a limpeza de caixas de e-mail: desinscrição de newsletters, regras de expiração (TTL) e organização por remetente. Uso pessoal, para o dono e algumas pessoas próximas. Não há cadastro público.

Especificação completa: [`SPEC.md`](SPEC.md). Débitos conhecidos: [`docs/debito-tecnico.md`](docs/debito-tecnico.md).

## Stack

- Laravel 13 · PHP 8.5 · Laravel Fortify (login, reset de senha, 2FA)
- Inertia 3 · React 19 · TypeScript (strict) · Tailwind CSS 4 · Vite 8
- SQLite por padrão · fila e sessões no banco
- Docker: `app` (PHP-FPM), `web` (nginx), `queue`, `scheduler`, `vite` (Node 26)

## Primeiros passos

Pré-requisitos: Docker e Node 26 no host (para build, typecheck e testes de frontend).

```bash
cp .env.example .env
docker compose up -d --build

docker exec inbox_app composer install
docker exec inbox_app php artisan key:generate
docker exec inbox_app php artisan migrate
npm install

# primeiro usuário (não existe tela de cadastro)
docker exec -it inbox_app php artisan user:create "Seu Nome" voce@exemplo.com
```

Acesse **http://localhost:8000**. O servidor do Vite roda em `localhost:5173`.

As portas só aceitam conexões da própria máquina (`127.0.0.1`). Não exponha o Vite nem o app em desenvolvimento para a rede: o Vite e o Inertia DevTools servem dados sem autenticação.

## Gestão de usuários

Contas são criadas e administradas pelo terminal. Todos os comandos aceitam o e-mail com maiúsculas ou espaços.

| Comando | O que faz |
|---|---|
| `php artisan user:create "Nome" email@x.com` | Cria o usuário. Pede a senha no terminal (mínimo de 12 caracteres). |
| `php artisan user:create "Nome" email@x.com --password=...` | Cria com a senha informada. |
| `php artisan user:create "Nome" email@x.com -n` | Modo não interativo: gera uma senha forte e mostra uma única vez. |
| `php artisan user:disable email@x.com` | Desativa: a pessoa não consegue mais entrar, as sessões abertas caem na hora e o "lembrar-me" deixa de valer. |
| `php artisan user:enable email@x.com` | Reativa um usuário desativado. |
| `php artisan user:delete email@x.com` | Remove o usuário e todos os dados dele (contas de e-mail, remetentes, regras). Pede para digitar o e-mail como confirmação. |
| `php artisan user:reset-two-factor email@x.com` | Desliga o 2FA de quem perdeu o app autenticador e os códigos de recuperação. |

Rode-os com `docker exec -it inbox_app php artisan ...`. `user:disable`, `user:delete` e `user:reset-two-factor` aceitam `--force` para pular a confirmação.

## Autenticação

- Login com e-mail e senha, "lembrar-me" e limite de 5 tentativas por minuto.
- Falhas sempre mostram "Credenciais inválidas.", sem revelar se a conta existe ou está desativada.
- "Esqueci minha senha" envia um link válido por 60 minutos e sempre responde a mesma mensagem.
- Redefinir a senha encerra as sessões abertas em outros dispositivos.
- 2FA opcional (app autenticador) em **Segurança**, no menu superior, com códigos de recuperação de uso único.

## Conectar o Gmail

Cada usuário conecta as próprias contas Gmail em **Contas**, no menu superior. Antes, configure um cliente OAuth no [Google Cloud Console](https://console.cloud.google.com/):

1. Crie um projeto e ative a **Gmail API**.
2. Em **OAuth consent screen**: tipo *External*, status *Testing*. Adicione como *test users* os e-mails de todas as contas Gmail que serão conectadas, e os escopos `gmail.modify` e `gmail.send`.
3. Em **Credentials > Create credentials > OAuth client ID**: tipo *Web application*, com a URI de redirecionamento `http://localhost:8000/accounts/google/callback`.
4. Preencha no `.env` e rode `php artisan config:clear`:

```dotenv
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=http://localhost:8000/accounts/google/callback
GOOGLE_OAUTH_TESTING=true
```

- Em modo *Testing* o Google expira o acesso 7 dias depois de cada conexão. O app mostra "Reconecte até dd/mm" e avisa no painel. Soluções definitivas: [`docs/debito-tecnico.md`](docs/debito-tecnico.md), item 7.
- **Desconectar** revoga o acesso no Google, mas guarda remetentes e regras: reconectar a mesma conta traz tudo de volta.
- Um Gmail só pode estar conectado por um usuário de cada vez.
- O app pede todas as permissões. Se alguma for desmarcada na tela do Google, a conexão é recusada.

## E-mail

| Ambiente | Configuração |
|---|---|
| Padrão | `MAIL_MAILER=log`: os e-mails vão para `storage/logs/laravel.log`. |
| Desenvolvimento | Mailtrap (sandbox): troque os `MAIL_*` do `.env` pelos valores indicados no `.env.example` e rode `php artisan config:clear`. |
| Produção | Resend (a habilitar): veja `.env.production.example`. |

## Testes e verificações

Rode tudo antes de qualquer merge na `main` ou pull request:

```bash
docker exec inbox_app php artisan test            # PHPUnit, inclui o audit de dependências
docker exec inbox_app php artisan test --coverage # cobertura (meta: 100% de app/)
npm run typecheck                                 # TypeScript strict
npm run build                                     # build de produção
npm run test:security                             # o Vite não pode servir arquivos sensíveis
```

- O grupo `audit` consulta as bases de vulnerabilidades do Composer e do npm e precisa de internet. Para rodar offline: `php artisan test --exclude-group=audit`.
- Exceções temporárias de audit ficam em [`audit-allowlist.json`](audit-allowlist.json). Cada uma exige justificativa e data de expiração, e o teste falha quando ela vence.
- Estilo do PHP: `docker exec inbox_app vendor/bin/pint --test`.

## Produção

Parta de [`.env.production.example`](.env.production.example): debug desligado, cookie de sessão seguro e criptografado, DevTools desligado e logs a partir de `warning`. Hospedagem alvo: VPS com nginx + PHP-FPM + Supervisor/Cron (detalhes no SPEC).

## Documentação

- [`SPEC.md`](SPEC.md): especificação funcional e técnica, fases do projeto.
- [`docs/superpowers/specs/`](docs/superpowers/specs/): designs aprovados.
- [`docs/superpowers/plans/`](docs/superpowers/plans/): planos de implementação.
- [`docs/debito-tecnico.md`](docs/debito-tecnico.md): pendências conhecidas.
