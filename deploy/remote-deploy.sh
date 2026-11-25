#!/usr/bin/env bash
# Forced command of the GitHub Actions deploy key. In /home/inbox/.ssh/authorized_keys:
#   restrict,command="/var/www/inbox/deploy/remote-deploy.sh" ssh-ed25519 AAAA... github-deploy
# Whatever the client asks to run arrives in SSH_ORIGINAL_COMMAND; only a bare
# release tag is accepted, so the key can't open a shell or run anything else.
set -euo pipefail

tag=${SSH_ORIGINAL_COMMAND:-}
if [[ ! "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    printf 'ERRO: comando recusado. Esta chave só publica uma tag no formato vX.Y.Z.\n' >&2
    exit 2
fi

exec "$(dirname "${BASH_SOURCE[0]}")/deploy.sh" "$tag"
