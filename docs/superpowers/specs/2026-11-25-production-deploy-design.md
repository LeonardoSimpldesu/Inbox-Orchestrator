# Design: Publicação em produção (VPS)

Status: aprovado · Data: 2026-11-25

## 1. Intenção

Colocar o Inbox Orchestrator no ar para o dono e poucas pessoas próximas, num subdomínio de um domínio próprio (ex.: `inbox.<domínio>`), com HTTPS, numa VPS pequena (1 vCPU, 2 GB RAM, 20 GB NVMe) dedicada só a este app. Junto, preparar o que o Google exige para publicar o app OAuth em Produção (página inicial e Política de Privacidade públicas) e o envio real de e-mail (Resend) para a redefinição de senha. A troca de status no Google Console é o passo seguinte, fora deste trabalho.

## 2. Objetivos, não-objetivos e anti-objetivos

**Objetivos**
- Scripts e modelos versionados para preparar uma VPS Ubuntu 24.04 do zero e fazer deploys repetíveis (instalação direta: nginx + PHP-FPM 8.5 + Supervisor + cron).
- HTTPS com Let's Encrypt e renovação automática.
- Página inicial pública e página de Política de Privacidade pública, em pt-BR.
- E-mail de redefinição de senha enviado de verdade pelo Resend, testado de ponta a ponta.
- SQLite em modo WAL; backup diário local do banco.
- Documentação de deploy no README (pt-BR).

**Não-objetivos**
- Docker em produção (escolha do dono: opção A).
- Deploy automático pelo CI; o deploy é um comando rodado por SSH.
- Backup fora da VPS (fica registrado em `docs/debito-tecnico.md`; o Gmail continua sendo a fonte da verdade, perder o banco custa reconectar contas e recriar regras).
- Hospedar outros sites na mesma máquina.
- Trocar o status do app no Google Console (próximo trabalho).

**Anti-objetivos**
- Qualquer dado específico do servidor no repositório, que é **público**: IP, domínio real, e-mails, usuário SSH, chaves, tokens, `APP_KEY`, chave do Resend. Os arquivos versionados usam variáveis/placeholders; os valores reais vivem só no `.env` e em `/etc` da VPS.
- Expor algo além de 80/443 na internet (o SSH fica aberto, só com chave).
- Um deploy deixar o app num estado meio atualizado sem avisar (o script para no primeiro erro).

## 3. Restrições

- Repositório público: o mesmo cuidado vale para commits, mensagens de PR e a página de privacidade no código (o e-mail de contato e o nome do responsável vêm do `.env`).
- 2 GB de RAM: PHP-FPM com poucos workers, um único worker de fila, swap de 2 GB para o `npm run build`.
- O código entra na `main` só por PR com CI verde, então o deploy sempre pega a `main`.
- Testes: `app/` continua com 100% de cobertura; textos para o usuário em pt-BR; nada de N+1.
- O callback do Google é GET cross-site: o cookie de sessão continua `SameSite=lax`.

## 4. Abordagens

| | Prós | Contras |
|---|---|---|
| **A) Instalação direta (escolhida)** | menor uso de RAM; é o que o SPEC prevê; logs e serviços no padrão do Linux; trocar de máquina = rodar o provisionamento e copiar banco + `.env` + `storage/` | ambiente diferente do Docker de desenvolvimento; atualizar PHP é `apt upgrade` |
| B) Docker em produção | mesma imagem PHP do dev/CI; mudar de máquina = copiar volume | +150–250 MB de RAM; build lento com 1 vCPU ou imagem gerada no CI + registry |
| C) Painel gerenciado (Forge, Ploi) | pouco trabalho manual | pago; esconde o que roda no servidor |

O dono escolheu **A**.

## 5. Decisões

### 5.1 Arquivos novos no repositório (`deploy/`)
- `deploy/provision.sh`: roda como root numa VPS nova (e pode rodar de novo sem estragar nada). Lê `APP_DOMAIN` e `ADMIN_EMAIL` (para o Let's Encrypt) de variáveis de ambiente (nunca do repositório) e:
  - atualiza o sistema, liga `unattended-upgrades`, cria swap de 2 GB, ajusta fuso UTC;
  - cria o usuário `inbox` (dono do código, roda PHP-FPM, fila e cron); `.env` com permissão 600;
  - instala PHP 8.5 (PPA ondrej) com as extensões do app, Composer, Node 24 LTS (só para o build), nginx, Supervisor, certbot, sqlite3, fail2ban, ufw;
  - firewall: libera 22, 80, 443; SSH só com chave e sem login de root por senha, **mas só desliga a senha se já existir uma chave autorizada** (evita trancar o dono para fora);
  - clona o repositório público por HTTPS em `/var/www/inbox`, instala os modelos de nginx/PHP-FPM/Supervisor/cron trocando os placeholders, emite o certificado.
- `deploy/deploy.sh`: roda como `inbox` a cada atualização. `set -euo pipefail`; `artisan down` → `git fetch` + `git reset --hard origin/main` → `composer install --no-dev --optimize-autoloader` → `npm ci && npm run build` → `migrate --force` → `optimize` → `up` → `queue:restart`. Se algo falha, o app fica em manutenção e o script mostra o erro (melhor que meio atualizado).
- `deploy/nginx.conf.template`: HTTP → HTTPS, `server_tokens off`, nega arquivos ocultos, cabeçalhos iguais aos do dev, `client_max_body_size` pequeno, só `public/` como raiz.
- `deploy/php-fpm-pool.conf`: pool `inbox`, `pm = ondemand`, até 4 workers, socket Unix só para o nginx.
- `deploy/supervisor-queue.conf`: um `queue:work database --queue=default,sync --tries=3 --timeout=1500 --max-time=3600 --memory=256`.
- `deploy/cron`: `schedule:run` a cada minuto e o backup diário.
- `deploy/backup.sh`: `sqlite3 .backup` (seguro com WAL), gzip, guarda 14 dias em `/var/backups/inbox` (dono `inbox`, permissão 700).

### 5.2 Mudanças no app
- **SQLite**: `config/database.php` passa a ler `DB_JOURNAL_MODE` (padrão `wal`), `DB_BUSY_TIMEOUT` (padrão 5000) e `DB_SYNCHRONOUS` (padrão `normal`). Vale também no dev.
- **Página inicial pública** (`/sobre`): página curta (o que o app faz, que o acesso é só por convite, botão "Entrar" ou "Ir para o app", link para a Política de Privacidade). É a URL informada ao Google como página inicial. `/` continua sendo o Dashboard protegido (visitante vai para `/login`), porque os testes de segurança de sessão e de usuário desativado dependem disso. Sem cadastro público.
- **Política de Privacidade** (`/privacidade`, pública, também linkada no login e no menu do app): em pt-BR, cobrindo:
  - dados guardados: conta Google conectada, tokens OAuth (criptografados), metadados de mensagens (remetente, assunto, marcadores, data, ids), estatísticas por remetente, links de descadastro, regras e histórico de execuções; **nunca** corpo de e-mail nem anexos;
  - para que servem as permissões `gmail.modify` e `gmail.send` (ler metadados, aplicar marcadores/arquivar/lixeira, enviar e-mail de descadastro `mailto:`);
  - declaração de **Uso Limitado** exigida pelo Google ("O uso e a transferência de informações recebidas das APIs do Google obedecem à Política de Dados do Usuário dos Serviços de API do Google, incluindo os requisitos de Uso Limitado");
  - não há venda, compartilhamento nem uso para anúncios; operadores: hospedagem (VPS) e Resend (só e-mails de redefinição de senha);
  - retenção e exclusão: desconectar revoga o acesso no Google e os dados da conta são apagados após `DISCONNECTED_ACCOUNT_RETENTION_DAYS` dias; exclusão do usuário a pedido;
  - direitos da LGPD e contato.
  - Nome do responsável, e-mail de contato e data da última atualização vêm de config (`PRIVACY_CONTROLLER_NAME`, `PRIVACY_CONTACT_EMAIL`), nunca fixos no código.
- **Resend**: `composer require resend/resend-php`; `MAIL_MAILER=resend`, `RESEND_KEY` (nome lido por `config/services.php`; o `.env.production.example` usava `RESEND_API_KEY` por engano) e `MAIL_FROM_ADDRESS` no `.env` (local para o teste e no servidor). O domínio de envio é verificado no painel do Resend (registros DNS SPF/DKIM no domínio). Teste: pedir redefinição de senha pela tela e receber o e-mail.
- `X-Robots-Tag: noindex, nofollow` em todas as respostas (app privado; a página de privacidade continua acessível por link, que é o que o Google exige).

### 5.3 Documentação
- README: seção "Produção" reescrita com o passo a passo (DNS, provisionamento, `.env`, primeiro usuário, deploy, backup, restauração, onde guardar a `APP_KEY` fora da VPS).
- `.env.production.example`: `APP_NAME="Inbox Orchestrator"` (nome do app no Google e no remetente dos e-mails), domínio como placeholder, WAL, Resend, campos da privacidade; continua passando no `EnvironmentHardeningTest`.
- `CLAUDE.md`: seção curta de deploy; regra de merge atualizada para a `main` protegida.
- `docs/debito-tecnico.md`: backup fora da VPS.

### 5.4 Proteções automáticas
- Teste de segurança que varre `deploy/` e `.env.production.example` e falha se encontrar IPv4 público, e-mail que não seja placeholder ou chave com cara de segredo (`re_…`, `GOCSPX-…`, `base64:…`).
- Testes de feature das páginas públicas (visitante vê a inicial; logado vê o Dashboard; `/privacidade` sem login; contato vindo da config) e do cabeçalho `X-Robots-Tag`.
- CI: `bash -n` + `shellcheck` nos scripts de `deploy/`.

## 6. Execução na VPS

O dono compra a VPS (Ubuntu 24.04), cria o registro DNS `A` do subdomínio para o IP e decide como os comandos rodam (ver pergunta em aberto). A chave do Resend e a `APP_KEY` vão direto para o `.env` da máquina, digitadas pelo dono; nunca passam pelo chat nem pelo git.

## 7. Em aberto

- Quem executa os comandos na VPS: o dono, seguindo o README, ou o Claude via SSH com uma chave que o dono autoriza.

## 8. Deixado para quem implementa
- Texto exato das páginas públicas, desde que cubra os pontos da seção 5.2.
- Lista final de extensões PHP (a partir do `composer.json` e do `composer check-platform-reqs`).
- Ajustes finos de nginx/PHP-FPM dentro dos limites de memória acima.
