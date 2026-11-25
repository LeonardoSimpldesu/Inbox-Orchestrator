# Design: Publicação automática por tag

Status: aprovado · Data: 2026-11-25

## Intenção
Hoje o deploy é manual: depois de um merge, o dono entra na VPS e roda `deploy.sh`, que publica o que estiver na `main`. O dono quer decidir **quando** a `main` vai ao ar criando uma tag (ex.: `v1.1.0`), e que a publicação aconteça sozinha a partir daí, sem entrar na VPS.

## Objetivos
- Criar uma tag `vX.Y.Z` num commit da `main` publica esse commit na VPS, sem passo manual.
- Só publica se os três jobs do CI passarem **nesse commit** e se ele estiver na `main`.
- Voltar para uma versão anterior é publicar a tag anterior (pelo GitHub, sem SSH manual). Só vale para tags que já têm este fluxo; tags antigas (ex.: `v1.0.0`) são recusadas, porque o `deploy.sh` delas não sabe publicar por tag.
- Falha no deploy fica visível: o workflow fica vermelho e o GitHub avisa por e-mail. A página `/up` é conferida no fim.
- O `deploy.sh` manual continua funcionando, agora por tag.

## Não-objetivos
- Criar tags ou numerar versões automaticamente (o dono cria a tag quando decide).
- Rollback automático quando o deploy falha, e desfazer migrations ao voltar de versão.
- Notas de versão / GitHub Releases.
- Deploy sem tempo fora do ar (o modo manutenção de ~1 min continua).

## Anti-objetivos
- Uma chave no GitHub que dê shell na VPS. A chave de deploy só consegue rodar o deploy de uma tag válida.
- Endereço da VPS, chave ou host key no repositório (público). Também não podem aparecer nos **logs do Actions**, que num repositório público são públicos.
- Publicar um commit que não está na `main` ou cujo CI não passou.
- Uma tag publicada ser movida para outro commit depois.

## Abordagens consideradas
- **1. GitHub Actions entra na VPS por SSH (escolhida):** publica na hora, histórico de cada deploy no Actions. Custo: um segredo de SSH no GitHub, limitado a um comando.
- **2. A VPS consulta o GitHub a cada minuto:** nenhuma chave sai da VPS, mas até 1 min de atraso, lógica de "CI verde?" no servidor e falhas menos visíveis.
- **3. Webhook do GitHub para uma rota no app:** o app teria que se atualizar sozinho (o PHP-FPM rodando `git`/`composer`), misturando o app com a infraestrutura.

## Decisões

**Fluxo**
1. O dono cria e envia a tag: `git tag v1.1.0 && git push origin v1.1.0` (ou pelo GitHub).
2. Novo workflow `.github/workflows/deploy.yml`, disparado por push de tag `v*` e por `workflow_dispatch` (para republicar ou voltar versão pelo GitHub: *Run workflow* escolhendo a tag em "Use workflow from"). Rodado a partir de uma branch, o job de deploy recusa.
3. Primeiro job: roda o mesmo CI no commit da tag. `ci.yml` ganha `on: workflow_call` e é chamado pelo deploy (`needs`). Assim não depende de o CI da `main` já ter terminado quando a tag chega.
4. Job `deploy` (só se o CI passou): confere que a tag casa com `^v[0-9]+\.[0-9]+\.[0-9]+$` e que o commit está na `main` (`git merge-base --is-ancestor`); depois `ssh` na VPS mandando só o nome da tag; no fim, `curl -fsS https://<host>/up`.
5. `concurrency: production` sem cancelar o que está rodando: dois deploys nunca se sobrepõem.

**Segredos no GitHub** (Environment `production`, liberado só para tags `v*`)
- `DEPLOY_HOST` (o domínio do app, serve para o SSH e para o `/up`), `DEPLOY_SSH_KEY` (chave privada ed25519 só para isso), `DEPLOY_KNOWN_HOSTS` (host key da VPS, para não aceitar um servidor falso).
- Valores de secrets aparecem como `***` nos logs. O workflow não imprime nada da VPS além do resumo do deploy.

**Na VPS**
- A chave pública de deploy vai em `/home/inbox/.ssh/authorized_keys` com `restrict,command="/var/www/inbox/deploy/remote-deploy.sh"`: sem shell, sem túnel, sem agente; qualquer comando enviado vira só um argumento desse script.
- `deploy/remote-deploy.sh` (novo): lê `SSH_ORIGINAL_COMMAND`, aceita só um nome de tag no formato acima e chama `deploy.sh <tag>`. Qualquer outra coisa é recusada.
- `deploy.sh` muda:
  - aceita uma tag como argumento; sem argumento, publica a **tag mais recente** (`v*` por versão). Não publica mais a `main` solta;
  - `git fetch --tags --force origin main`, confere que a tag existe e está na `main`, e troca para ela com `git checkout --detach` (substitui o `reset --hard origin/main`);
  - recusa tags sem `deploy/remote-deploy.sh` (anteriores a este fluxo), para um rollback não deixar a VPS sem o script de deploy remoto;
  - trava com `flock` para um deploy manual e um automático não rodarem juntos;
  - a mensagem final mostra a tag publicada.

**No GitHub**
- Regra de proteção de tags `v*` (ruleset): ninguém além do dono cria, e ninguém move nem apaga uma tag já criada.
- Environment `production` com regra "só tags `v*`", para que os segredos não fiquem disponíveis em PRs nem em branches.

**Testes**
- `DeployFilesTest` passa a ler também `.github/workflows/` (sem IP, e-mail real ou segredo) e confere que o deploy usa `secrets.*` e não valores fixos.
- `remote-deploy.sh` e a escolha da tag no `deploy.sh`: testados num container Ubuntu com um repositório git falso (tag válida, tag inválida, comando com `;`/espaço, tag fora da `main`, sem argumento = tag mais recente, `flock` ocupado).
- `shellcheck` cobre os scripts novos; o workflow é validado com `actionlint`.
- Teste real: a primeira tag nova publicada por esse fluxo.

## Fica a cargo de quem implementa
- Ações do GitHub usadas para SSH (preferência: `ssh` puro do runner, sem ação de terceiros).
- Texto das mensagens e formato da saída no log.
- Como o container de teste monta o repositório falso.
