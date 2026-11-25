# Backup fora da VPS: plano de implementação

> Execução: inline nesta sessão (sem subagentes), tarefa por tarefa.

**Objetivo:** depois do backup local, `deploy/backup.sh` criptografa a cópia com `age`, envia para o Backblaze B2 com `rclone` (chave só de escrita) e avisa o healthchecks.io do sucesso ou da falha.

**Design:** `docs/superpowers/specs/2026-11-25-offsite-backup-design.md`

## Restrições globais
- Repositório público: nenhum valor real (chave `age`, chaves do B2, bucket, URL do heartbeat) em arquivo versionado.
- Sem `/etc/inbox/backup.env` ou com `BACKUP_AGE_RECIPIENT`/`BACKUP_REMOTE_PATH` vazios: só backup local, sai com 0 e registra "envio externo desativado" no log.
- Nada sai da VPS sem criptografia; o backup local nunca é apagado por falha no envio.
- Textos de log e do README em pt-BR; código e comentários em inglês.

## Pontos de atenção na revisão
1. Falha no `sqlite3`/`age`/`rclone` deve chamar `<heartbeat>/fail` e sair com erro (não pode pingar sucesso).
2. Heartbeat configurado mas envio externo não configurado: **não** pinga sucesso (o serviço avisa).
3. `backup.env` só legível por root e pelo grupo `inbox`; o usuário `inbox` não consegue alterá-lo.
4. O arquivo temporário criptografado é apagado mesmo quando o envio falha.
5. `BACKUP_REMOTE_PATH` com ou sem barra final gera o mesmo destino.

---

### Tarefa 1: barrar segredos novos no repositório
**Arquivos:** `tests/Security/DeployFilesTest.php`
- [ ] Ampliar a regex de `test_files_contain_no_secrets` com `AGE-SECRET-KEY-1`, chave pública `age1[0-9a-z]{50,}`, applicationKey do B2 `K00[A-Za-z0-9]{20,}` e URL real do healthchecks `hc-ping\.com/[0-9a-f-]{20,}`.
- [ ] Novo teste `test_backup_env_template_has_no_values`: em `deploy/backup.env.example`, toda linha `CHAVE=valor` não comentada tem valor vazio, exceto `RCLONE_CONFIG_OFFSITE_TYPE=b2`.
- [ ] Rodar e ver falhar (o modelo ainda não existe).

### Tarefa 2: modelo de configuração
**Arquivos:** `deploy/backup.env.example`
- [ ] Campos: `BACKUP_AGE_RECIPIENT=`, `RCLONE_CONFIG_OFFSITE_TYPE=b2`, `RCLONE_CONFIG_OFFSITE_ACCOUNT=`, `RCLONE_CONFIG_OFFSITE_KEY=`, `BACKUP_REMOTE_PATH=` (comentário: `offsite:<bucket>/db`), `BACKUP_HEARTBEAT_URL=`; comentários em pt-BR dizendo de onde vem cada valor.
- [ ] Testes da Tarefa 1 passam.

### Tarefa 3: `backup.sh`
**Arquivos:** `deploy/backup.sh`
- [ ] Carrega `${BACKUP_CONFIG:-/etc/inbox/backup.env}` com `set -a` se legível.
- [ ] `trap` de erro: se `BACKUP_HEARTBEAT_URL` existe, `curl -fsS -m 10 --retry 3 "$URL/fail"`; registra a falha no log.
- [ ] Backup local igual ao atual.
- [ ] Se configurado: `age -r` para `mktemp "$BACKUP_DIR/.upload-XXXXXX"` (apagado num `trap EXIT`), `rclone copyto --no-check-dest` para `${BACKUP_REMOTE_PATH%/}/db-<data>.sqlite.gz.age`, log "envio externo ok", depois ping de sucesso (falha no ping só vai para o log).
- [ ] `shellcheck deploy/*.sh` limpo.

### Tarefa 4: `provision.sh`
- [ ] Instalar `age` e `rclone` junto com os pacotes base.
- [ ] Após criar o usuário `inbox`: `install -d -m 0750 -o root -g inbox /etc/inbox`; se `/etc/inbox/backup.env` não existe, `install -m 0640 -o root -g inbox deploy/backup.env.example /etc/inbox/backup.env`.
- [ ] Mensagem final ganha o passo "preencha `/etc/inbox/backup.env`".

### Tarefa 5: verificação em container (Ubuntu 24.04)
Com o repositório montado só leitura, `age`, `rclone`, `sqlite3`, `curl` e um servidor HTTP local de mentira para o heartbeat:
- [ ] Sem config: só local, sai 0, nenhum ping.
- [ ] Com remoto `local`: o arquivo enviado não é gzip/SQLite em claro; `age -d -i <privada> | gunzip` dá um banco com o mesmo `.dump` do original; temporário apagado; ping de sucesso recebido.
- [ ] Remoto inválido: sai com erro, backup local existe, temporário apagado, ping em `/fail`.
- [ ] Heartbeat sem remoto: nenhum ping de sucesso.
- [ ] `provision.sh` não é rodado inteiro aqui; o trecho do `/etc/inbox` é conferido com `bash -n` + `shellcheck` e na VPS.

### Tarefa 6: documentação
- [ ] README "Produção": `backup.sh` na tabela; seção "Backup e restauração" com a configuração do B2 (bucket privado, ciclo de vida 30/1 dias, chave Write Only restrita ao bucket), do `age` (`age-keygen` no Mac), do healthchecks.io (período 1 dia, tolerância 2 h) e a restauração a partir do B2.
- [ ] `docs/debito-tecnico.md`: remover o #20. CLAUDE.md: mencionar o envio externo na seção Production.
- [ ] Todas as verificações do projeto (PHPUnit com auditoria e cobertura, Pint, typecheck, build, test:security, test:js, shellcheck) e commit.
