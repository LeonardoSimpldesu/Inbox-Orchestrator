#!/usr/bin/env bash
# Prepares a fresh Ubuntu 24.04 VPS to run Inbox Orchestrator. Safe to run again:
# every step checks what already exists, and .env / APP_KEY are never replaced.
#
# Usage, as root:
#   APP_DOMAIN=inbox.example.com ADMIN_EMAIL=you@example.com bash provision.sh
set -euo pipefail

APP_DIR=/var/www/inbox
APP_USER=inbox
PHP_VERSION=8.5
NODE_MAJOR=24
BACKUP_DIR=/var/backups/inbox
REPO_URL=${REPO_URL:-https://github.com/LeonardoSimpldesu/Inbox-Orchestrator.git}

say() { printf '\n==> %s\n' "$*"; }
warn() { printf '\nATENÇÃO: %s\n' "$*" >&2; }
fail() { printf '\nERRO: %s\n' "$*" >&2; exit 1; }
as_app() { sudo -u "$APP_USER" -H -- "$@"; }

render() {
    sed -e "s|__APP_DOMAIN__|${APP_DOMAIN}|g" \
        -e "s|__APP_DIR__|${APP_DIR}|g" \
        -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
        "$1" > "$2"
}

[[ $EUID -eq 0 ]] || fail "rode como root (ex.: sudo -E bash provision.sh)."
# shellcheck source=/dev/null
. /etc/os-release
[[ "${ID:-}" == ubuntu && "${VERSION_ID:-}" == 24.04 ]] || fail "este script foi feito para Ubuntu 24.04."
[[ -n "${APP_DOMAIN:-}" ]] || fail "defina APP_DOMAIN (ex.: inbox.seudominio.com.br)."
[[ -n "${ADMIN_EMAIL:-}" ]] || fail "defina ADMIN_EMAIL (recebe os avisos do Let's Encrypt)."
[[ "$APP_DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || fail "APP_DOMAIN inválido: use só letras minúsculas, números, ponto e hífen."
getent ahosts "$APP_DOMAIN" > /dev/null || fail "o DNS de ${APP_DOMAIN} ainda não resolve. Crie o registro A apontando para esta VPS e espere propagar."

export DEBIAN_FRONTEND=noninteractive

say "Atualizando o sistema e instalando pacotes base"
apt-get update -q
apt-get upgrade -yq
apt-get install -yq ca-certificates curl gnupg git unzip sqlite3 software-properties-common \
    unattended-upgrades fail2ban ufw nginx supervisor certbot age rclone
timedatectl set-timezone UTC
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'CONF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
CONF

say "Swap de 2 GB"
if ! swapon --show=NAME --noheadings | grep -qx /swapfile; then
    if [[ ! -f /swapfile ]]; then
        fallocate -l 2G /swapfile
        chmod 600 /swapfile
        mkswap /swapfile > /dev/null
    fi
    swapon /swapfile || warn "não foi possível ativar a swap (alguns tipos de VPS não permitem). O build do frontend pode ficar sem memória."
fi
grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
echo 'vm.swappiness=10' > /etc/sysctl.d/99-inbox-swap.conf
sysctl -q -p /etc/sysctl.d/99-inbox-swap.conf

say "PHP ${PHP_VERSION}, Composer e Node ${NODE_MAJOR}"
add-apt-repository -y ppa:ondrej/php > /dev/null
apt-get update -q
apt-get install -yq "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-sqlite3" \
    "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-bcmath" \
    "php${PHP_VERSION}-curl" "php${PHP_VERSION}-xml"

if ! command -v composer > /dev/null; then
    expected=$(curl -fsSL https://composer.github.io/installer.sig)
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    actual=$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")
    [[ "$expected" == "$actual" ]] || { rm -f /tmp/composer-setup.php; fail "assinatura do instalador do Composer não confere."; }
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

if [[ "$(node -v 2> /dev/null || true)" != v${NODE_MAJOR}.* ]]; then
    install -d -m 0755 /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list
    apt-get update -q
    apt-get install -yq nodejs
fi

say "Firewall e SSH"
ufw allow OpenSSH > /dev/null
ufw allow 'Nginx Full' > /dev/null
ufw --force enable > /dev/null
systemctl enable --now fail2ban > /dev/null

key_found=false
for keys in /root/.ssh/authorized_keys ${SUDO_USER:+"/home/${SUDO_USER}/.ssh/authorized_keys"}; do
    [[ -s "$keys" ]] && key_found=true
done
if $key_found; then
    # 00- so it is read before cloud-init's 50- file: sshd keeps the first value it sees.
    cat > /etc/ssh/sshd_config.d/00-inbox-hardening.conf <<'CONF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
CONF
    sshd -t
    systemctl reload ssh 2> /dev/null || systemctl restart ssh
else
    warn "nenhuma chave SSH autorizada encontrada; o login por senha continua ligado. Adicione sua chave e rode o script de novo."
fi

say "Usuário ${APP_USER} e código"
id "$APP_USER" > /dev/null 2>&1 || useradd --system --create-home --home-dir "/home/${APP_USER}" --shell /bin/bash "$APP_USER"
if [[ ! -d "${APP_DIR}/.git" ]]; then
    install -d -o "$APP_USER" -g "$APP_USER" "$APP_DIR"
    as_app git clone -q "$REPO_URL" "$APP_DIR"
fi
install -d -m 0700 -o "$APP_USER" -g "$APP_USER" "$BACKUP_DIR"
install -d -m 0750 -o root -g "$APP_USER" /etc/inbox
# Root-owned so a compromised app user can't redirect or disable the off-site copy.
if [[ ! -f /etc/inbox/backup.env ]]; then
    install -m 0640 -o root -g "$APP_USER" "${APP_DIR}/deploy/backup.env.example" /etc/inbox/backup.env
fi

cd "$APP_DIR"
if [[ ! -f .env ]]; then
    render .env.production.example .env
    chown "$APP_USER:$APP_USER" .env
fi
chmod 600 .env
as_app composer install --no-dev --optimize-autoloader --no-interaction --quiet
# A new key would make every stored Gmail token undecryptable, so it is only
# generated while APP_KEY is still empty.
if grep -q '^APP_KEY=$' .env; then
    as_app php artisan key:generate --force
fi
[[ -f database/database.sqlite ]] || as_app touch database/database.sqlite
as_app php artisan migrate --force
chmod 750 database storage

say "PHP-FPM, fila e agendador"
render deploy/php-fpm-pool.conf.template "/etc/php/${PHP_VERSION}/fpm/pool.d/inbox.conf"
if [[ -f "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" ]]; then
    mv "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf.disabled"
fi
"php-fpm${PHP_VERSION}" -t
systemctl restart "php${PHP_VERSION}-fpm"

render deploy/supervisor-queue.conf.template /etc/supervisor/conf.d/inbox-queue.conf
supervisorctl reread > /dev/null
supervisorctl update > /dev/null

render deploy/cron.template /etc/cron.d/inbox
chmod 644 /etc/cron.d/inbox

say "nginx e certificado HTTPS"
rm -f /etc/nginx/sites-enabled/default
install -d /var/www/letsencrypt
if [[ ! -f "/etc/letsencrypt/live/${APP_DOMAIN}/fullchain.pem" ]]; then
    render deploy/nginx-bootstrap.conf.template /etc/nginx/sites-available/inbox
    ln -sf /etc/nginx/sites-available/inbox /etc/nginx/sites-enabled/inbox
    nginx -t -q
    systemctl reload nginx
    certbot certonly --webroot -w /var/www/letsencrypt -d "$APP_DOMAIN" -m "$ADMIN_EMAIL" \
        --agree-tos --non-interactive --deploy-hook 'systemctl reload nginx'
fi
render deploy/nginx.conf.template /etc/nginx/sites-available/inbox
ln -sf /etc/nginx/sites-available/inbox /etc/nginx/sites-enabled/inbox
nginx -t -q
systemctl reload nginx

say "Pronto. Próximos passos:"
cat <<MSG
  1. Preencha os valores que faltam em ${APP_DIR}/.env (veja a seção Produção do README):
       sudo -u ${APP_USER} nano ${APP_DIR}/.env
  2. Guarde uma cópia da APP_KEY fora desta máquina (gerenciador de senhas).
  3. Publique a versão atual:
       sudo -u ${APP_USER} ${APP_DIR}/deploy/deploy.sh
  4. Crie o primeiro usuário:
       sudo -u ${APP_USER} php ${APP_DIR}/artisan user:create "Seu Nome" voce@example.com
  5. Configure o backup fora da VPS (veja "Backup e restauração" no README):
       sudo nano /etc/inbox/backup.env
MSG
