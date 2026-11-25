# Inbox Orchestrator

[![CI](https://github.com/LeonardoSimpldesu/Inbox-Orchestrator/actions/workflows/ci.yml/badge.svg)](https://github.com/LeonardoSimpldesu/Inbox-Orchestrator/actions/workflows/ci.yml)

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
docker exec -it inbox_app php artisan user:create "Seu Nome" voce@example.com
```

Acesse **http://localhost:8000**. O servidor do Vite roda em `localhost:5173`.

As portas só aceitam conexões da própria máquina (`127.0.0.1`). Não exponha o Vite nem o app em desenvolvimento para a rede: o Vite e o Inertia DevTools servem dados sem autenticação.

## Gestão de usuários

Contas são criadas e administradas pelo terminal. Todos os comandos aceitam o e-mail com maiúsculas ou espaços.

| Comando | O que faz |
|---|---|
| `php artisan user:create "Nome" email@example.com` | Cria o usuário. Pede a senha no terminal (mínimo de 12 caracteres). |
| `php artisan user:create "Nome" email@example.com --password=...` | Cria com a senha informada. |
| `php artisan user:create "Nome" email@example.com -n` | Modo não interativo: gera uma senha forte e mostra uma única vez. |
| `php artisan user:disable email@example.com` | Desativa: a pessoa não consegue mais entrar, as sessões abertas caem na hora e o "lembrar-me" deixa de valer. |
| `php artisan user:enable email@example.com` | Reativa um usuário desativado. |
| `php artisan user:delete email@example.com` | Remove o usuário e todos os dados dele (contas de e-mail, remetentes, regras). Pede para digitar o e-mail como confirmação. |
| `php artisan user:reset-two-factor email@example.com` | Desliga o 2FA de quem perdeu o app autenticador e os códigos de recuperação. |

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
2. Em **Google Auth Platform**:
   - **Branding**: nome do app, e-mail de suporte, página inicial `https://<domínio>/sobre`, política de privacidade `https://<domínio>/privacidade` e o domínio autorizado. Não envie logo (exigiria verificação da marca).
   - **Data access**: escopos `gmail.modify` e `gmail.send`.
   - **Clients**: tipo *Web application*, com as URIs de redirecionamento `http://localhost:8000/accounts/google/callback` (desenvolvimento) e `https://<domínio>/accounts/google/callback` (produção).
   - **Audience**: tipo *External*, **Publish app** sem enviar para verificação.
4. Preencha no `.env` e rode `php artisan config:clear`:

```dotenv
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=http://localhost:8000/accounts/google/callback
GOOGLE_OAUTH_TESTING=false
```

- O app está publicado no Google **sem verificação**: ao conectar, aparece "O Google não verificou este app"; clique em **Avançado > Acessar**. O limite é de 100 usuários, e o acesso não expira mais por semana.
- Só se o app voltar ao status *Testing*: o Google expira o acesso 7 dias depois de cada conexão. Com `GOOGLE_OAUTH_TESTING=true` o app mostra "Reconecte até dd/mm" e avisa no painel.
- **Desconectar** revoga o acesso no Google, mas guarda remetentes e regras por **90 dias** (`DISCONNECTED_ACCOUNT_RETENTION_DAYS`): reconectar a mesma conta nesse prazo traz tudo de volta. Depois disso, `inbox:purge-disconnected-accounts` (de hora em hora) apaga a conta e os dados dela.
- Um Gmail só pode estar conectado por um usuário de cada vez.
- O app pede todas as permissões. Se alguma for desmarcada na tela do Google, a conexão é recusada.

## Sincronização

Só metadados são importados (remetente, assunto, data, labels e cabeçalhos de desinscrição), nunca corpo ou anexos.

| Etapa | Quando | Limite |
|---|---|---|
| E-mails recentes | Ao conectar a conta | Últimos 3 meses, até 5.000 e-mails ou 20 min |
| Histórico antigo | A cada 10 min (`inbox:import-history`) | 500 e-mails por conta por vez |
| Importar histórico agora | Botão em **Contas** | 20 min |
| Novidades | A cada 5 min (`inbox:sync`) e botão **Sincronizar** do painel | — |

- Os jobs rodam na fila `sync`. O container `queue` atende `--queue=default,sync --timeout=1500`, e `DB_QUEUE_RETRY_AFTER` (1600) precisa ser maior que o job mais longo.
- **Depois de mudar código PHP, reinicie os workers:** `docker exec inbox_app php artisan queue:restart`. O worker é um processo longo e mantém o código antigo na memória.
- **Cota do Gmail:** cada conta gasta no máximo `GMAIL_QUOTA_UNITS_PER_MINUTE` unidades por minuto (padrão 1.200, cerca de 240 e-mails por minuto). O limite real do projeto fica no Cloud Console, em *Gmail API > Quotas > "Units per minute per user"*. Se ele for maior, aumente o valor no `.env` (sem passar do limite).
- Rodar manualmente: `docker exec inbox_app php artisan inbox:sync` ou `inbox:import-history`.

## Hub de Assinaturas

Menu **Assinaturas**: newsletters e e-mails promocionais das suas contas, do que mais manda para o que menos manda, 100 por página.

- **Gmail** abre os e-mails do remetente no Gmail.
- **Desinscrever** usa, na ordem de preferência, a desinscrição de um clique, um e-mail de desinscrição enviado pela sua conta, a página do remetente (você conclui lá) ou uma regra para os próximos e-mails.
- Na confirmação dá para também **arquivar** ou **mandar para a lixeira** os e-mails que já existem daquele remetente.
- A aba **Tratados** avisa quando um remetente continua mandando depois do pedido, e permite **Tentar de novo** ou **Voltar para pendentes** (a regra criada, se houver, continua na tela de Regras).
- **Busca** por nome, e-mail ou domínio e **seleção múltipla**: "Desinscrever selecionados" usa só a desinscrição automática (um clique ou e-mail); remetentes que precisam da página ou de uma regra são pulados e avisados.

A desinscrição de um clique é feita pelo servidor, e o endereço vem de um cabeçalho de e-mail. Por isso o app só chama hosts `https` públicos, valida e fixa o IP antes de conectar e não segue redirecionamentos.

## Remetentes

Menu **Remetentes**: quem escreve para você e não é newsletter (pessoas, bancos, serviços). As newsletters ficam no Hub.

- **Categoria** = um marcador do Gmail. Escolher (ou criar) a categoria aplica o marcador aos e-mails existentes do remetente e cria uma regra para os próximos (aparece em Regras como "Categoria …"). Trocar a categoria move o marcador; "sem categoria" remove o marcador e a regra.
- **Limpar…** arquiva ou manda para a lixeira os e-mails existentes, com a opção de fazer o mesmo com os próximos.
- Seleção múltipla para limpar vários; para categorizar vários, eles precisam ser da mesma conta.

## Regras de expiração

Menu **Regras TTL**: regras como "Promoções com mais de 15 dias → arquivar", "e-mails de `nubank.com.br` → marcador Banco" ou "marcador Faturas com mais de 90 dias → lixeira".

- Toda regra nasce **pausada**. Clique em **Simular** para ver quantos e-mails ela afetaria hoje e uma amostra, e só então em **Ativar**. Editar uma regra a pausa de novo.
- A busca é feita no próprio Gmail, então alcança a caixa inteira (não só o que já foi importado).
- **Todas as contas** (ao criar) faz uma cópia pausada da regra em cada conta; cada cópia é simulada e ativada na própria linha. Não vale para marcadores, que são de cada conta.
- Nunca são tocados: e-mails com estrela, enviados, rascunhos e spam.
- As regras ativas rodam uma vez por dia, na primeira hora depois das 03:00 (`APP_SCHEDULE_TIMEZONE`), e pelo botão **Executar regras agora**. Rodar manualmente: `docker exec inbox_app php artisan inbox:apply-retention`.
- Arquivados ficam em "Todos os e-mails" e a lixeira do Gmail guarda os e-mails por 30 dias: dá para desfazer pelo próprio Gmail.

## E-mail

| Ambiente | Configuração |
|---|---|
| Padrão | `MAIL_MAILER=log`: os e-mails vão para `storage/logs/laravel.log`. |
| Desenvolvimento | Mailtrap (sandbox): troque os `MAIL_*` do `.env` pelos valores indicados no `.env.example` e rode `php artisan config:clear`. |
| Produção | Resend (`MAIL_MAILER=resend`, `RESEND_KEY`). O domínio do remetente (`MAIL_FROM_ADDRESS`) precisa estar verificado no painel do Resend. |

## Testes e verificações

O CI (GitHub Actions, `.github/workflows/ci.yml`) roda essas verificações em todo push e pull request para a `main`. Rode tudo localmente também antes de qualquer merge na `main` ou pull request:

```bash
docker exec inbox_app php artisan test            # PHPUnit, inclui o audit de dependências
docker exec inbox_app vendor/bin/phpunit --exclude-group=audit --coverage-text --only-summary-for-coverage-text  # cobertura (meta: 100% de app/)
npm run typecheck                                 # TypeScript strict
npm run build                                     # build de produção
npm run test:security                             # o Vite não pode servir arquivos sensíveis
npm run test:js                                   # funções puras do frontend (datas)
```

- O grupo `audit` consulta as bases de vulnerabilidades do Composer e do npm e precisa de internet. Para rodar offline: `php artisan test --exclude-group=audit`.
- Exceções temporárias de audit ficam em [`audit-allowlist.json`](audit-allowlist.json). Cada uma exige justificativa e data de expiração, e o teste falha quando ela vence.
- Estilo do PHP: `docker exec inbox_app vendor/bin/pint --test`.

## Produção

Uma VPS Ubuntu 24.04 dedicada ao app (1 vCPU e 2 GB de RAM bastam), com nginx + PHP-FPM 8.5 + Supervisor (fila) + cron (agendador e backup), HTTPS pelo Let's Encrypt. Tudo fica em [`deploy/`](deploy/):

| Arquivo | Para quê |
|---|---|
| `provision.sh` | Prepara a máquina do zero (root). Pode rodar de novo: nunca troca o `.env` nem a `APP_KEY`. |
| `deploy.sh` | Publica a `main` atual (usuário `inbox`). Recusa começar se faltar variável no `.env`. |
| `backup.sh` | Backup diário do SQLite em `/var/backups/inbox` (14 dias) e cópia criptografada fora da VPS (Backblaze B2). |
| `backup.env.example` | Modelo de `/etc/inbox/backup.env`: chaves do envio externo e do aviso de falha. |
| `*.template` | nginx, PHP-FPM, Supervisor e cron, com o domínio como variável. |

O repositório é público: o domínio, os e-mails e as chaves só existem no `.env` e em `/etc` da VPS. Nunca coloque esses valores em arquivos versionados (o `DeployFilesTest` barra IPs, e-mails reais e chaves).

### Primeira instalação

1. No DNS do domínio, crie um registro `A` do subdomínio (ex.: `inbox`) apontando para o IP da VPS e espere propagar (`dig +short inbox.example.com`).
2. Na VPS, como root (com sua chave SSH já autorizada, para o script poder desligar o login por senha):

   ```bash
   curl -fsSLO https://raw.githubusercontent.com/LeonardoSimpldesu/Inbox-Orchestrator/main/deploy/provision.sh
   less provision.sh   # leia antes de rodar
   APP_DOMAIN=inbox.example.com ADMIN_EMAIL=voce@example.com bash provision.sh
   ```

3. Preencha o `.env` (`sudo -u inbox nano /var/www/inbox/.env`):

   | Variável | De onde vem |
   |---|---|
   | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Google Cloud Console > Google Auth Platform > Clients |
   | `RESEND_KEY` | Painel do Resend > API Keys (permissão só de envio) |
   | `MAIL_FROM_ADDRESS` | Um endereço do domínio verificado no Resend |
   | `PRIVACY_CONTROLLER_NAME`, `PRIVACY_CONTACT_EMAIL` | Responsável e contato mostrados em `/privacidade` |

   `APP_URL` e `GOOGLE_REDIRECT_URI` já vêm com o domínio, e a `APP_KEY` já foi gerada. **Guarde uma cópia da `APP_KEY` fora da VPS** (gerenciador de senhas): sem ela, nenhum backup consegue ler os tokens do Gmail.
4. Publique e crie o primeiro usuário:

   ```bash
   sudo -u inbox /var/www/inbox/deploy/deploy.sh
   sudo -u inbox php /var/www/inbox/artisan user:create "Seu Nome" voce@example.com
   ```

### Atualizar

Depois que um PR entra na `main`: `sudo -u inbox /var/www/inbox/deploy/deploy.sh`. O app fica em manutenção durante o deploy; se algo falhar, ele continua em manutenção e o script diz em que passo parou. Mudou só o `.env`? Rode o deploy também (ele recria o cache de configuração).

### Backup e restauração

O cron roda `deploy/backup.sh` às 03:30 UTC (log em `storage/logs/backup.log`). Ele guarda 14 dias em `/var/backups/inbox` e, se `/etc/inbox/backup.env` estiver preenchido, envia uma cópia criptografada com [`age`](https://github.com/FiloSottile/age) para o Backblaze B2. A VPS só tem a chave **pública** do `age` e uma chave do B2 **só de escrita**: quem invadir a VPS não lê nem apaga os backups enviados.

**Configurar o envio externo (uma vez):**

1. No seu computador: `age-keygen -o inbox-backup-age.key`. A linha `Public key: age1…` vai para a VPS; o arquivo (chave privada) vai para o gerenciador de senhas, junto com a `APP_KEY`. Sem os dois, nenhum backup é restaurável.
2. No Backblaze B2:
   - crie um bucket **privado**;
   - em *Lifecycle Settings*, use uma regra própria com *Days till hide* = 30 e *Days till delete* = 1 (as cópias somem depois de ~31 dias);
   - em *Application Keys*, crie uma chave restrita a esse bucket com acesso **Write Only**.
3. Opcional, aviso de falha: no [healthchecks.io](https://healthchecks.io), crie um check com período de 1 dia e tolerância de 2 horas. O script avisa o sucesso e chama `<URL>/fail` quando algo dá errado; se o aviso diário não chegar, você recebe um e-mail.
4. Na VPS, preencha `sudo nano /etc/inbox/backup.env` (criado pelo `provision.sh`, dono root, só leitura para o usuário `inbox`) e teste:

   ```bash
   sudo -u inbox /var/www/inbox/deploy/backup.sh
   ```

   O log deve terminar com `envio externo ok`, e o arquivo `db-….sqlite.gz.age` aparece no bucket.

**Restaurar:**

```bash
# Da cópia local (na VPS):
gunzip -c /var/backups/inbox/db-AAAAMMDD-HHMMSS.sqlite.gz > database.sqlite
# Ou da cópia no B2: baixe o .gz.age pelo site do Backblaze e, no seu computador:
age -d -i inbox-backup-age.key db-AAAAMMDD-HHMMSS.sqlite.gz.age | gunzip > database.sqlite
```

Com o `database.sqlite` na VPS (ex.: `scp`), troque o banco com o app parado. Numa VPS nova, rode antes o `provision.sh` e coloque no `.env` a **mesma** `APP_KEY` de antes.

```bash
sudo supervisorctl stop inbox-queue
sudo -u inbox php /var/www/inbox/artisan down
sudo install -m 600 -o inbox -g inbox database.sqlite /var/www/inbox/database/database.sqlite
sudo -u inbox rm -f /var/www/inbox/database/database.sqlite-wal /var/www/inbox/database/database.sqlite-shm
sudo -u inbox php /var/www/inbox/artisan up
sudo supervisorctl start inbox-queue
```

### Conferir

```bash
curl -sI https://inbox.example.com/up          # 200, com Strict-Transport-Security e X-Robots-Tag
sudo supervisorctl status                           # inbox-queue RUNNING
sudo -u inbox php /var/www/inbox/artisan schedule:list
tail -f /var/www/inbox/storage/logs/laravel-$(date +%F).log
```

## Documentação

- [`SPEC.md`](SPEC.md): especificação funcional e técnica, fases do projeto.
- [`docs/superpowers/specs/`](docs/superpowers/specs/): designs aprovados.
- [`docs/superpowers/plans/`](docs/superpowers/plans/): planos de implementação.
- [`docs/debito-tecnico.md`](docs/debito-tecnico.md): pendências conhecidas.
