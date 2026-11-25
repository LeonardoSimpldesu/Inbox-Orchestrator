#!/usr/bin/env bash
# Publishes the current main branch. Run as the app user:
#   sudo -u inbox /var/www/inbox/deploy/deploy.sh
set -euo pipefail

REQUIRED_KEYS=(
    APP_KEY APP_URL
    GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET GOOGLE_REDIRECT_URI
    RESEND_KEY MAIL_FROM_ADDRESS
    PRIVACY_CONTROLLER_NAME PRIVACY_CONTACT_EMAIL
)

fail() { printf '\nERRO: %s\n' "$*" >&2; exit 1; }

env_value() {
    local value
    value=$(sed -n "s/^$1=//p" .env | tail -n 1)
    value=${value%\"}
    printf '%s' "${value#\"}"
}

# Prints key names only: values are secrets and must not reach the terminal log.
missing_keys() {
    local key value
    for key in "${REQUIRED_KEYS[@]}"; do
        value=$(env_value "$key")
        if [[ -z "$value" || "$value" == *__APP_DOMAIN__* || "$value" == *your-domain.com* ]]; then
            printf '  - %s\n' "$key"
        fi
    done
}

main() {
    [[ $EUID -ne 0 ]] || fail "não rode como root. Use: sudo -u inbox $0"

    local app_dir
    app_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
    cd "$app_dir"

    [[ -f .env ]] || fail "arquivo .env não encontrado em ${app_dir}. Rode o provision.sh primeiro."
    local missing
    missing=$(missing_keys)
    [[ -z "$missing" ]] || fail "preencha estas variáveis no .env antes do deploy:
${missing}"

    local step="" in_maintenance=false
    trap 'if $in_maintenance; then printf "\nERRO: o deploy falhou em: %s\nO app continua em manutenção. Corrija o problema e rode o deploy de novo.\n" "$step" >&2; else printf "\nERRO: o deploy falhou em: %s\n" "$step" >&2; fi' ERR

    step="php artisan down"; php artisan down --retry=60 > /dev/null; in_maintenance=true
    step="git fetch"; git fetch -q origin main
    step="git reset"; git reset -q --hard origin/main
    step="composer install"; composer install --no-dev --optimize-autoloader --no-interaction --quiet
    step="npm ci"; npm ci --no-audit --no-fund --loglevel=error
    step="npm run build"; npm run build --silent > /dev/null
    step="php artisan migrate"; php artisan migrate --force
    step="php artisan optimize"; php artisan optimize > /dev/null
    step="php artisan up"; php artisan up > /dev/null; in_maintenance=false
    step="php artisan queue:restart"; php artisan queue:restart > /dev/null

    printf '\nDeploy concluído: %s\n' "$(git log -1 --format='%h %s')"
}

# Everything runs inside main(): bash reads a script while executing it, and
# `git reset` replaces this very file mid-run.
main "$@"
exit
