#!/usr/bin/env bash
# Publishes a release tag (vX.Y.Z) that is on main. Run as the app user:
#   sudo -u inbox /var/www/inbox/deploy/deploy.sh           # newest tag
#   sudo -u inbox /var/www/inbox/deploy/deploy.sh v1.2.0    # a given tag (rollback)
# GitHub Actions calls it through remote-deploy.sh when a tag is pushed.
set -euo pipefail

REQUIRED_KEYS=(
    APP_KEY APP_URL
    GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET GOOGLE_REDIRECT_URI
    RESEND_KEY MAIL_FROM_ADDRESS
    PRIVACY_CONTROLLER_NAME PRIVACY_CONTACT_EMAIL
)

TAG_PATTERN='^v[0-9]+\.[0-9]+\.[0-9]+$'

fail() { printf '\nERRO: %s\n' "$*" >&2; exit 1; }

newest_tag() {
    git tag -l 'v*' --merged origin/main --sort=-v:refname | grep -E "$TAG_PATTERN" | head -n 1 || true
}

# Everything that can refuse a tag runs before the app goes into maintenance.
check_tag() {
    local tag=$1
    [[ "$tag" =~ $TAG_PATTERN ]] || fail "tag inválida: '${tag}'. Use o formato vX.Y.Z."
    git rev-parse -q --verify "refs/tags/${tag}^{commit}" > /dev/null || fail "a tag ${tag} não existe no GitHub."
    git merge-base --is-ancestor "${tag}^{commit}" origin/main || fail "a tag ${tag} aponta para um commit que não está na main."
    # Older releases can only deploy main; checking one out would break every later deploy.
    git cat-file -e "${tag}:deploy/remote-deploy.sh" 2> /dev/null \
        || fail "a tag ${tag} é anterior à publicação por tag e não pode ser publicada por este script."
}

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

    exec 9> storage/framework/deploy.lock
    flock -n 9 || fail "já existe um deploy em andamento."

    git fetch -q --tags --force origin main || fail "não foi possível buscar as tags no GitHub."
    local tag=${1:-}
    if [[ -z "$tag" ]]; then
        tag=$(newest_tag)
        [[ -n "$tag" ]] || fail "nenhuma tag vX.Y.Z na main. Crie uma: git tag v1.0.0 && git push origin v1.0.0"
    fi
    check_tag "$tag"

    local step="" in_maintenance=false
    trap 'if $in_maintenance; then printf "\nERRO: o deploy falhou em: %s\nO app continua em manutenção. Corrija o problema e rode o deploy de novo.\n" "$step" >&2; else printf "\nERRO: o deploy falhou em: %s\n" "$step" >&2; fi' ERR

    step="php artisan down"; php artisan down --retry=60 > /dev/null; in_maintenance=true
    step="git checkout ${tag}"; git checkout -q --force --detach "$tag"
    step="composer install"; composer install --no-dev --optimize-autoloader --no-interaction --quiet
    step="npm ci"; npm ci --no-audit --no-fund --loglevel=error
    step="npm run build"; npm run build --silent > /dev/null
    step="php artisan migrate"; php artisan migrate --force
    step="php artisan optimize"; php artisan optimize > /dev/null
    step="php artisan up"; php artisan up > /dev/null; in_maintenance=false
    step="php artisan queue:restart"; php artisan queue:restart > /dev/null

    printf '\nDeploy concluído: %s (%s)\n' "$tag" "$(git log -1 --format='%h %s')"
}

# Everything runs inside main(): bash reads a script while executing it, and
# `git checkout` replaces this very file mid-run.
main "$@"
exit
