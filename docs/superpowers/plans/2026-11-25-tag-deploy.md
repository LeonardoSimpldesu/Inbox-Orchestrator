# Publicação automática por tag: plano de implementação

> Execução: inline nesta sessão (sem subagentes), tarefa por tarefa.

**Objetivo:** criar uma tag `vX.Y.Z` num commit da `main` roda o CI nesse commit e, se passar, publica a tag na VPS por SSH, com uma chave presa a um único comando.

**Design:** `docs/superpowers/specs/2026-11-25-tag-deploy-design.md`

## Restrições globais
- Formato de tag aceito em todo lugar: `^v[0-9]+\.[0-9]+\.[0-9]+$`.
- Repositório e logs do Actions são públicos: host, chave e host key só via `secrets.*` do Environment `production`.
- Toda validação (formato, existência, tag na `main`, tag com `deploy/remote-deploy.sh`, trava) acontece **antes** do `php artisan down`.
- Textos para o usuário em pt-BR; código e comentários em inglês.

## Pontos de atenção na revisão
1. `SSH_ORIGINAL_COMMAND` com `;`, espaço, `$(…)`, quebra de linha ou vazio é recusado sem rodar nada.
2. Tag que existe mas aponta para commit fora da `main` é recusada (no workflow e no `deploy.sh`).
3. Sem argumento, a tag mais recente é escolhida por **versão** (`v1.10.0` > `v1.9.0`).
4. Deploy manual durante um automático: o segundo falha na hora, sem pôr o app em manutenção.
5. `workflow_dispatch` a partir de uma branch não publica.

---

### Tarefa 1: proteger o workflow no `DeployFilesTest`
**Arquivos:** `tests/Security/DeployFilesTest.php`
- [ ] `publicFiles()` inclui `.github/workflows/*.yml`.
- [ ] `test_deploy_workflow_uses_secrets_and_pins_the_host_key`: `deploy.yml` contém `environment: production`, `secrets.DEPLOY_HOST`, `secrets.DEPLOY_SSH_KEY`, `secrets.DEPLOY_KNOWN_HOSTS`, `StrictHostKeyChecking=yes`, `uses: ./.github/workflows/ci.yml`; não contém `StrictHostKeyChecking=no`.
- [ ] Ver falhar (arquivo não existe).

### Tarefa 2: `deploy.sh` por tag + `remote-deploy.sh`
**Arquivos:** `deploy/deploy.sh`, criar `deploy/remote-deploy.sh`
- [ ] Teste em container primeiro (`scratchpad/td/run.sh`): repositório `origin` falso com tags `v1.0.0` (sem `remote-deploy.sh`), `v1.1.0`, `v1.9.0`, `v1.10.0`, uma tag `v2.0.0` num commit fora da `main`; `php`/`composer`/`npm` trocados por stubs que registram as chamadas; roda como usuário comum. Cenários: sem argumento → `v1.10.0`; `v1.9.0` → HEAD nele; `main`, `v1.2`, `v1.0.0`, `v2.0.0`, `v9.9.9` → erro e nenhum `artisan down`; trava ocupada → erro sem `down`; `remote-deploy.sh` com `SSH_ORIGINAL_COMMAND` = `v1.9.0` publica, e `v1.9.0; id`, `$(id)`, vazio, `v1.9.0\nid` são recusados sem chamar nada. Ver falhar.
- [ ] `deploy.sh [tag]`: trava `flock -n` em `storage/framework/deploy.lock`; `git fetch -q --tags --force origin main`; sem argumento, `git tag -l 'v*' --merged origin/main --sort=-v:refname` filtrado pelo formato; valida formato, `rev-parse "$tag^{commit}"`, `merge-base --is-ancestor`, `cat-file -e "$tag:deploy/remote-deploy.sh"`; só então `down` → `git checkout -q --force --detach "$tag"` → resto igual; mensagem final `Deploy concluído: <tag> (<hash> <assunto>)`.
- [ ] `remote-deploy.sh`: valida `SSH_ORIGINAL_COMMAND` contra o formato e faz `exec deploy.sh "$tag"`; recusa com código 2.
- [ ] Container verde; `shellcheck` limpo.

### Tarefa 3: workflows
**Arquivos:** `.github/workflows/ci.yml`, criar `.github/workflows/deploy.yml`
- [ ] `ci.yml`: `on.workflow_call: {}`.
- [ ] `deploy.yml`: `on: push.tags ['v*']` + `workflow_dispatch`; `permissions: contents: read`; `concurrency: {group: production, cancel-in-progress: false}`; job `ci` (`uses: ./.github/workflows/ci.yml`); job `deploy` (`needs: ci`, `environment: production`, timeout 20): confere `ref_type == tag` e o formato; `actions/checkout@v7` com `fetch-depth: 0`; `git merge-base --is-ancestor "$GITHUB_SHA" origin/main`; grava chave e known_hosts em `~/.ssh` (600); `ssh -i … -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o BatchMode=yes "inbox@$DEPLOY_HOST" "$TAG"`; `curl -fsS --retry 5 --retry-all-errors https://$DEPLOY_HOST/up`.
- [ ] `actionlint` limpo; Tarefa 1 verde.

### Tarefa 4: documentação
- [ ] README "Produção": seção "Publicar uma versão" (criar tag, acompanhar no Actions, voltar versão com *Run workflow*, deploy manual `deploy.sh [tag]`) e "Configurar a publicação automática (uma vez)": gerar a chave no Mac, `authorized_keys` com `restrict,command="/var/www/inbox/deploy/remote-deploy.sh"`, host key a partir de `/etc/ssh/ssh_host_ed25519_key.pub`, Environment `production` (só tags `v*`) com os 3 segredos, ruleset de tags `v*`, primeira vez (rodar o `deploy.sh` antigo uma vez para a VPS ter o `remote-deploy.sh`).
- [ ] Tabela de `deploy/` ganha `remote-deploy.sh`. CLAUDE.md (Production + regra de merge: depois do merge, o deploy é por tag).
- [ ] Todas as verificações do projeto + `actionlint`; commit.
