# Design: Página "Remetentes"

Status: aprovado · Data: 2026-11-23

## 1. Intenção

O Hub cuida só das assinaturas. Esta página é a central de gestão dos **demais remetentes** (pessoas, bancos, serviços, avisos de sistemas): **classificar** cada um numa categoria e **decidir a limpeza** das mensagens dele. Fecha o combinado ao definir o Hub ("todos os outros remetentes vão numa outra página").

## 2. Objetivos, não-objetivos e anti-objetivos

**Objetivos**
- Página **Remetentes** (`/senders`, item "Remetentes" no menu) com a lista dos remetentes que não são assinaturas (filtro "Mostrar assinaturas" inclui todos).
- **Categoria = marcador do Gmail**: escolher (ou criar) um marcador para o remetente; ele é aplicado aos e-mails existentes e, por uma regra, aos próximos.
- **Limpar**: arquivar ou mandar para a lixeira os e-mails existentes, com a opção de fazer o mesmo com os próximos.
- Busca, filtros (conta, categoria), 100 por página, seleção múltipla para categorizar ou limpar vários de uma vez.

**Não-objetivos**
- Categorias só do app (decisão do dono: a categoria é o marcador do Gmail).
- Abas nativas do Gmail (exigiriam a permissão `gmail.settings.basic`).
- Mais de uma categoria por remetente.

**Anti-objetivos**
- A página e a tela de Regras mostrarem coisas diferentes sobre o mesmo remetente.
- Aplicar marcador ou limpar e-mails de remetentes de outra conta/usuário.
- Uma ação em massa travar a página (tudo que fala com o Gmail roda em fila).

## 3. Restrições

- Marcadores são de cada conta: a categoria é escolhida entre os marcadores da conta do remetente; ações em massa de categoria exigem remetentes de **uma mesma conta**.
- Reaproveitar: `EmailSender` (totais), `SenderMailCleaner` (listar `from:` + `batchModify` + atualização local), motor de regras (`RetentionRule`, `RuleQuery` já exclui por ID quem já tem o marcador), `listLabels`/`createLabel`, controle de cota e travas por conta.
- Regras vigentes: consultas a partir do usuário; sem N+1 (testes contam consultas); logs só com IDs e códigos; TDD, 100% de `app/`; textos em pt-BR; sem subagentes.

## 4. Abordagens consideradas

| | Abordagem | Prós | Contras |
|---|---|---|---|
| **A (escolhida)** | A categoria **é** a regra "remetente → aplicar marcador, 0 dias"; a página a lê das regras | Uma fonte de verdade; apagar a regra na tela de Regras tira a categoria; reaproveita o motor | A listagem precisa de uma consulta extra às regras (constante) |
| B | Campo "categoria" no remetente + regra em paralelo | Leitura mais simples | Duas fontes que podem divergir; sincronização em todo lugar |
| C | Deduzir dos marcadores já presentes nos e-mails | Nada a guardar | Ambíguo com vários marcadores; não define os próximos e-mails |

## 5. Decisões do dono

1. A página serve para **organizar** os remetentes: escolher a categoria e decidir se limpa as mensagens.
2. **Categoria = marcador do Gmail** (opção a), aplicado aos existentes e aos próximos por regra.
3. Aprovado o esboço: lista dos não-assinantes com "Mostrar assinaturas", filtros, uma categoria por remetente, trocar = tira o antigo e aplica o novo, "sem categoria" remove marcador e regra, limpar com opção para os próximos, seleção múltipla, marcadores por conta.
4. Abordagem **A**.

## 6. Design

### 6.1 Dados

- `email_senders.+ label_applying_at` (timestamp, nullable): uma aplicação/remoção de marcador está em andamento (para "Aplicando…" e para a página se atualizar). Limpo pelo job (inclusive em `failed()`).
- Sem tabela nova. A categoria de um remetente é a regra `retention_rules` com `filter_type = sender`, `filter_value = email`, `action = add_label` da mesma conta (no máximo uma por remetente; criar/trocar atualiza a existente).
- Regras de categoria nascem **ativas e marcadas como simuladas** (`simulated_at = now`): o usuário escolheu o remetente e viu o total de e-mails dele (mesma lógica das regras do Hub). Nome: `"Categoria {marcador}: {nome ou e-mail}"` (até 100 caracteres).

### 6.2 Listagem (`GET /senders`)

- Base: `$user->senders()` com `total_messages_received > 0`; padrão = **não** assinaturas (`scopeNonSubscriptions`, o inverso de `subscriptions()`); `all=1` inclui todos.
- Filtros: `account` (conta do usuário), `category` (ID de marcador ou `none`), `q` (nome, e-mail ou domínio; mesmo `search()` do Hub).
- Filtro de categoria via `whereExists`/`whereNotExists` na regra de categoria (consulta única).
- Ordem `total desc, email asc`; `paginate(100)`.
- Por linha: `id, name, email, domain, account_id, account_email, total, unread, last_received_at, is_subscription, category {label_id, name} | null, applying (bool), gmail_url`.
- Nome dos marcadores: `listLabels` (cache de 10 min) uma vez por conta presente na página; Gmail indisponível → mostra o ID.
- Props extras: `accounts`, `filters`, `labelsByAccount` só quando o filtro de conta estiver definido (para o seletor de categoria do filtro).
- Consultas constantes para 1 ou 150 linhas (teste).

### 6.3 Categorizar (`POST /senders/categorize`)

Body: `ids` (1–100), `label_id` (ID de marcador ou `null` = sem categoria).
- Todos os remetentes do usuário, **da mesma conta** (senão erro "Selecione remetentes de uma mesma conta para escolher a categoria."); conta precisando reconectar → erro.
- `label_id` deve existir entre os marcadores da conta (`listLabels`).
- Para cada remetente: guarda o marcador antigo (da regra atual), cria/atualiza/apaga a regra (em massa, consultas constantes), marca `label_applying_at` e despacha `ApplySenderLabelJob(senderId, ?newLabel, ?oldLabel)` — só quando o marcador muda.
- Flash: "Categoria aplicada a N remetente(s)." / "Categoria removida de N remetente(s).".

`ApplySenderLabelJob` (fila `default`, trava `sender-label-{id}` + trava da conta `sync-account-{id}` com `releaseAfter(60)`, `tries` 10, `maxExceptions` 1): lista `from:{email}` e aplica `batchModify` (adiciona o novo, remove o antigo), espelha localmente (`applyLabelChange`), limpa `label_applying_at`. Erros: log com `sender_id` + código; `label_applying_at` limpo.

### 6.4 Limpar (`POST /senders/clean`)

Body: `ids` (1–100), `cleanup` (`archive`|`trash`), `future` (bool).
- Remetentes do usuário (podem ser de contas diferentes); contas precisando reconectar são puladas.
- Para cada um: despacha `CleanSenderMailJob(senderId, cleanup)` (usa `SenderMailCleaner`; mesmas travas); se `future`, cria a regra "remetente → arquivar/lixeira, 0 dias", ativa e simulada (nome `"Arquivar e-mails de …"` / `"Mandar para a lixeira e-mails de …"`), sem duplicar uma existente com a mesma ação.
- Flash: "Limpeza iniciada para N remetente(s)." (+ " Regra criada para os próximos e-mails." quando `future`).

Ambas as rotas com `throttle:30,1`.

### 6.5 Tela (`resources/js/Pages/Senders/Index.tsx`)

```
Remetentes                     [Conta ▾] [Categoria ▾] [Buscar…]  ☐ Mostrar assinaturas
☐ Remetente                       E-mails  Último      Categoria       Ações
☐ Banco Inter <no-reply@inter…>   48       ontem       [Banco ▾]       [Gmail] [Limpar…]
☐ Maria Souza <maria@gmail.com>   12       há 3 dias   [— sem —  ▾]    [Gmail] [Limpar…]
```
- Seletor de categoria por linha: marcadores da conta do remetente (carregados sob demanda pelo endpoint existente `/accounts/{id}/labels`), "— sem categoria —" e "+ Criar marcador". Linha em andamento mostra "Aplicando…".
- "Limpar…" abre um diálogo: Arquivar / Mandar para a lixeira (recuperáveis por 30 dias) + "Fazer o mesmo com os próximos e-mails".
- Seleção múltipla com barra: "Categorizar" (só habilita se todos forem da mesma conta) e "Limpar".
- Assinaturas (com "Mostrar assinaturas") ganham um selo "Assinatura" com link para o Hub.
- `usePoll(5000)` enquanto alguma linha estiver "Aplicando…".
- Menu: "Remetentes" entre "Assinaturas" e "Regras TTL".

### 6.6 Documentos

SPEC (nova seção 4.4 e item na fase), README, CLAUDE.md (categoria = regra), débito técnico se algo ficar de fora.

## 7. Testes esperados

- **Listagem**: só não-assinaturas por padrão, `all=1`, filtros (conta alheia ignorada, categoria por marcador e `none`, busca), categoria e nome do marcador por linha, fallback quando o Gmail falha, consultas constantes, isolamento por usuário.
- **Categorizar**: cria regra ativa/simulada e despacha job com antigo/novo; trocar atualiza a regra; `null` apaga a regra e despacha remoção; mesmo marcador não despacha; contas diferentes → erro; marcador de outra conta → erro; conta precisando reconectar; remetente alheio ignorado; validação; throttle; consultas constantes.
- **Job de marcador**: adiciona/remove nos e-mails do remetente, espelha localmente, limpa `label_applying_at` (sucesso, erro e `failed()`), travas.
- **Limpar**: despacha jobs, `future` cria regra sem duplicar, contas precisando reconectar puladas, validação, isolamento.
- **Regras**: apagar a regra de categoria na tela de Regras faz o remetente aparecer "sem categoria".

## 8. Deixado para quem implementa

Nomes internos, layout fino, textos das dicas, como o seletor por linha carrega os marcadores (cache no cliente por conta).
