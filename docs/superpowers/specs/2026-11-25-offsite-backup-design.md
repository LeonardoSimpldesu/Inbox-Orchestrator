# Design: Backup fora da VPS (débito #20)

Status: aprovado · Data: 2026-11-25

## Intenção
Hoje o backup diário do SQLite fica só em `/var/backups/inbox`, no mesmo disco do app: se a VPS for perdida, os backups vão junto. Queremos uma cópia diária fora da VPS, que um invasor da VPS não consiga ler nem apagar.

## Objetivos
- Depois do backup local, `deploy/backup.sh` criptografa a cópia com `age` (só a chave **pública** fica na VPS) e envia para um bucket do Backblaze B2 com `rclone`.
- A chave do B2 é **só de escrita** e restrita ao bucket: quem tiver a VPS não lê nem apaga backups antigos.
- O próprio bucket apaga cópias com mais de 30 dias (regra de ciclo de vida do B2). O local continua com 14 dias.
- Passo a passo de restauração no README.
- Falha no envio é visível: o script sai com erro no log, e há um aviso externo opcional (ver Decisões).

## Não-objetivos
- Snapshot da VPS (pode ser somado depois, fora do código).
- Backup de outros arquivos além do banco (`.env` e `APP_KEY` ficam no gerenciador de senhas do dono).
- Restauração automática.

## Anti-objetivos
- Chave privada do `age`, chave do B2, nome do bucket ou qualquer dado real no repositório (público). `DeployFilesTest` continua cobrindo `deploy/`.
- Backup sem criptografia saindo da VPS.
- O backup local deixar de existir quando o envio falhar.

## Abordagens consideradas
- **A. Snapshot da MagnoHost:** sem código, mas fica no mesmo provedor e depende do plano.
- **B. `age` + `rclone` para o B2 (escolhida pelo dono):** cópia fora do provedor, criptografada, à prova de apagamento pela VPS.
- **C. O Mac busca por SSH:** sem conta extra, mas falha calada quando o Mac está desligado.

## Decisões
- **Configuração em `/etc/inbox/backup.env`** (dono `root`, grupo `inbox`, modo `640`), fora do app e do `.env` do Laravel. Modelo versionado: `deploy/backup.env.example`. Campos:
  - `BACKUP_AGE_RECIPIENT` (chave pública `age1…`);
  - `RCLONE_CONFIG_OFFSITE_TYPE=b2`, `RCLONE_CONFIG_OFFSITE_ACCOUNT` (keyID), `RCLONE_CONFIG_OFFSITE_KEY` (applicationKey): o `rclone` lê o remoto direto das variáveis, sem arquivo de configuração;
  - `BACKUP_REMOTE_PATH` (ex.: `offsite:nome-do-bucket/db`);
  - `BACKUP_HEARTBEAT_URL` (opcional, ver abaixo).
- **Sem `backup.env` ou com campos vazios, o script faz só o backup local**, como hoje (e avisa no log). Assim nada quebra antes da configuração.
- Envio: `age -r` gera `db-<data>.sqlite.gz.age` num arquivo temporário, `rclone copyto --no-check-dest` envia (a chave só de escrita não lista arquivos), o temporário é apagado. Nomes únicos por execução, então nada é sobrescrito.
- **Aviso de falha (opcional):** se `BACKUP_HEARTBEAT_URL` estiver preenchido, o script chama a URL só quando tudo dá certo (ex.: healthchecks.io, gratuito); se o ping não chega num dia, o serviço avisa por e-mail. Vazio = sem aviso externo.
- `provision.sh` instala `age` e `rclone` (apt) e cria `/etc/inbox/backup.env` a partir do modelo **só se não existir**.
- A chave do `age` é gerada no Mac do dono (`age-keygen`); a privada vai para o gerenciador de senhas. Sem ela (e sem a `APP_KEY`), os backups não servem.
- Verificação: `shellcheck` e um teste num container Ubuntu 24.04 com o remoto do `rclone` apontado para uma pasta local (`RCLONE_CONFIG_OFFSITE_TYPE=local`), conferindo que o arquivo enviado é descriptografado com a chave privada e restaura um banco idêntico, que o local continua quando o envio falha e que nada sai sem criptografia.

## Fica a cargo de quem implementa
- Mensagens exatas do log e formato do ping.
- Como o teste em container é montado.
