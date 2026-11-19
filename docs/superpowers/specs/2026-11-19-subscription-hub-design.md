# Design: Hub de Assinaturas

Status: aprovado · Data: 2026-11-19 · SPEC: seção 4.2 (Fase 1)

## 1. Intenção

Com os metadados sincronizados, o usuário precisa de uma tela para se livrar de newsletters e e-mails promocionais com poucos cliques: ver quem manda, abrir no Gmail, desinscrever pelo melhor caminho disponível e, na mesma ação, arquivar ou apagar o que já está na caixa.

## 2. Objetivos, não-objetivos e anti-objetivos

**Objetivos**
- Página **Hub de Assinaturas** (`/subscriptions`, item "Assinaturas" do menu, que hoje dá 404) listando **só assinaturas**, 100 por página.
- Abrir no Gmail os e-mails do remetente (deep link do SPEC).
- Desinscrever: um clique (RFC 8058), e-mail de desinscrição enviado pela conta, página de desinscrição do remetente, ou regra para os próximos e-mails.
- Na confirmação, opcionalmente **arquivar** ou **mandar para a lixeira** os e-mails já existentes do remetente.
- Status visível e sinal de que o remetente continua mandando depois do pedido.

**Não-objetivos (nesta etapa)**
- Página com todos os remetentes (será outra página, em outro momento — decisão do dono).
- Motor de TTL: a regra criada aqui só age quando o motor existir (próxima etapa).
- Ações em massa (vários remetentes de uma vez), busca por texto.

**Anti-objetivos**
- O servidor do app acessar endereços internos por causa de um link vindo de e-mail (SSRF).
- Um clique travar a página esperando terceiros ou a cota do Google.
- Agir sobre e-mails de outra conta ou de outro usuário.
- Apagar e-mails sem confirmação explícita.

## 3. Restrições

- Dados de `email_senders` (totais recalculados, `unsubscribe_url` só https, `unsubscribe_mailto`, `unsubscribe_one_click`) e `email_messages_meta` (labels).
- Escopos já concedidos: `gmail.modify` (ler, arquivar, lixeira) e `gmail.send` (enviar).
- Cota do Gmail: `messages.send` = 100 unidades, `messages.batchModify` = 50 (até 1.000 IDs), `messages.list` = 5. Passa pelo controle de cota por conta já existente.
- Regras vigentes: consultas a partir do usuário; contas desconectadas, de usuários desativados ou que precisam reconectar não executam ações; sem N+1 (testes contam consultas); nada de tokens, URLs de desinscrição ou endereços em logs; código em inglês, textos em pt-BR; TDD com 100% de cobertura de `app/`; sem subagentes.

## 4. Abordagens consideradas

| | Abordagem | Prós | Contras |
|---|---|---|---|
| **A (escolhida)** | Desinscrição e limpeza num job na fila; a tela mostra "Desinscrevendo…" e se atualiza | Nenhum clique fica refém de terceiros ou da cota; reaproveita fila, cota e padrões existentes | Resultado em alguns segundos, não instantâneo |
| B | Tudo dentro da requisição do clique | Resposta imediata | Pode travar segundos ou minutos (cota, servidor lento, milhares de e-mails); risco de timeout |
| C | Desinscrição na hora, limpeza na fila | Resultado da desinscrição imediato | Envio por e-mail ainda espera a cota; dois caminhos para manter e testar |

## 5. Decisões do dono

1. O Hub mostra **só assinaturas**; todos os remetentes ficam para outra página, depois.
2. Na confirmação da desinscrição, oferecer arquivar ou mandar para a lixeira os e-mails existentes (opção B da conversa).
3. Abordagem A (fila).
4. Aceita a proposta de lista única com seletor de conta.

## 6. Design

### 6.1 O que é "assinatura"

Remetente com `total_messages_received > 0` **e** (`unsubscribe_url` ou `unsubscribe_mailto` preenchido **ou** `total_messages_promotional > 0`).

- Nova coluna `email_senders.total_messages_promotional` (int, default 0): e-mails não deletados com label `CATEGORY_PROMOTIONS`, recalculada no mesmo `UPDATE` correlacionado do `MessageStore` (continua com número constante de consultas). Evita varrer labels na listagem.

### 6.2 Banco de dados

`email_senders`:
- `unsubscribe_status` passa a usar: `none`, `processing`, `requested`, `blocked` (regra criada), `failed`. (`unsubscribed` do SPEC sai: não há como confirmar que o remetente parou.)
- `+ unsubscribe_method` (string, nullable): `one_click`, `mailto`, `page` ou `rule`.
- `+ unsubscribe_requested_at` (timestamp, nullable).
- `+ unsubscribe_error` (string, nullable): código do erro (ex.: `http_404`, `timeout`, `blocked_address`, `invalid_mailto`, `rate_limited`, `reauth_required`).
- `+ total_messages_promotional` (6.1).
- Índice `(email_account_id, unsubscribe_status)`.

`retention_rules`: sem mudanças. Regra do cenário C = `filter_type = sender`, `filter_value = email do remetente`, `retention_days = 0`, `action = archive|trash`, `name = "Arquivar e-mails de {nome}"` / `"Mandar para a lixeira e-mails de {nome}"`.

### 6.3 Listagem (`GET /subscriptions`)

- Query string: `account` (id de uma conta do usuário; vazio = todas), `status` (`pending` = `none`/`processing`/`failed`, padrão; `handled` = `requested`/`blocked`), `page`.
- Ordem: `total_messages_received desc, email asc`; `paginate(100)` a partir de `$user->senders()` (contas desconectadas já ficam de fora pelo soft delete).
- `account_email` vem do join com `email_accounts` que o `hasManyThrough` já faz; `emails_after_request` via `withCount` com `whereColumn('email_messages_meta.received_at', '>', 'email_senders.unsubscribe_requested_at')`.
- Por linha: `id`, `name`, `email`, `domain`, `account_email`, `total`, `unread`, `last_received_at`, `status`, `method`, `error`, `requested_at`, `emails_after_request` (e-mails recebidos depois de `unsubscribe_requested_at`, via `withCount` com condição — uma consulta só), `options` (`one_click`, `mailto`, `page`: o que esse remetente permite), `gmail_url`.
- Nunca enviar `unsubscribe_url` ou `unsubscribe_mailto` como props (podem conter identificadores pessoais). A página do remetente é aberta por uma rota do app (6.6).
- `gmail_url` = `https://mail.google.com/mail/u/?authuser={account_email}#search/from:{sender_email}` com os valores codificados em URL.
- Contadores no topo das abas ("Pendentes 95 · Tratados 12").
- Sem N+1: a listagem faz um número constante de consultas para 1 ou 100 linhas (teste).

```
Hub de Assinaturas                      Conta: [Todas ▾]   [Pendentes 95] [Tratados 12]
Remetente                       Conta       E-mails  Não lidos  Último    Ações
TechCrunch <news@tc.com>        voce@gm…    184      120        ontem     [Abrir no Gmail] [Desinscrever]
Loja X <promo@lojax.com.br>     outra@gm…   42       40         12/11     [Abrir no Gmail] [Criar regra]
                                                     ◀ 1 2 3 ▶
```
Aba "Tratados": status ("Desinscrição pedida em 18/11", "Regra criada", "Falhou: …") e, se houver, "Ainda mandou 3 e-mails depois do pedido" em destaque.

### 6.4 Confirmação

Modal ao clicar em "Desinscrever" / "Criar regra":
- Explica o caminho que será usado, na ordem de preferência: **um clique** → **e-mail de desinscrição** ("O Inbox Orchestrator vai enviar um e-mail de desinscrição pela sua conta {conta} para {domínio}") → **página do remetente** (abre em nova aba; a pessoa conclui lá e confirma "Já me desinscrevi") → **regra** (sem mecanismo: "Arquivar" ou "Mandar para a lixeira" os próximos e-mails).
- E-mails existentes: ( ) Manter · ( ) Arquivar os {total} e-mails · ( ) Mandar para a lixeira (recuperáveis por 30 dias no Gmail). Padrão: **Manter**.
- No caminho "regra", o modal pede a ação dos próximos e-mails (Arquivar / Mandar para a lixeira) e troca as opções de existentes por uma caixa "Aplicar também aos {total} e-mails existentes" (marcada = `cleanup` igual à ação da regra; desmarcada = `keep`).

### 6.5 Ações (`POST /subscriptions/{sender}/unsubscribe`)

Body: `method` (`one_click`|`mailto`|`page`|`rule`), `cleanup` (`keep`|`archive`|`trash`), `rule_action` (`archive`|`trash`, só para `rule`).
- O remetente é buscado em `$request->user()->senders()` (404 senão). Conta precisando reconectar → erro "Reconecte a conta antes de se desinscrever.". Método indisponível para o remetente → 422. Status `processing` → ignorado (evita duplo clique).
- `page`: marca `requested` com método `page` na hora (a pessoa concluiu no site) e, se `cleanup != keep`, enfileira só a limpeza.
- `rule`: cria a regra (6.2) na hora, status `blocked`, e enfileira a limpeza com a mesma ação.
- `one_click`/`mailto`: status `processing` na hora e enfileira `UnsubscribeSenderJob`.
- Limite: 30 ações por minuto por usuário (`throttle`).
- Flash: "Desinscrição iniciada." / "Regra criada." / "Marcado como desinscrito.".

### 6.6 Página do remetente (`GET /subscriptions/{sender}/page`)

Redireciona (302) para o `unsubscribe_url` do remetente do próprio usuário. A URL nunca vai nas props; o link na tela aponta para esta rota com `target="_blank" rel="noopener noreferrer"`. A navegação é do navegador do usuário, não do servidor (sem risco de SSRF).

### 6.7 Job `UnsubscribeSenderJob(int $senderId, string $method, string $cleanup)`

- Fila `default`; trava `WithoutOverlapping("unsubscribe-sender-{id}")`; `$tries = 1`; carrega o remetente com a conta e o usuário e desiste se a conta não estiver sincronizável.
- **one_click** (`OneClickUnsubscriber`): `POST` para `unsubscribe_url` com corpo `List-Unsubscribe=One-Click` (`application/x-www-form-urlencoded`), proteção 6.8, timeout 10 s, sem seguir redirecionamentos. Sucesso = 2xx ou 3xx.
- **mailto** (`MailtoUnsubscriber`): interpreta `mailto:endereço?subject=…&body=…` (primeiro endereço válido; assunto padrão "unsubscribe", corpo padrão "unsubscribe") e envia pela conta com `EmailProviderInterface::sendMessage()`.
- Sucesso → `requested`, `unsubscribe_requested_at = now`, erro limpo. Falha → `failed` com o código; a pessoa pode tentar de novo.
- **Limpeza** (`cleanup != keep`, também usada pelos caminhos `page` e `rule`): lista no Gmail **todos** os IDs com `from:{email}` (inclui e-mails ainda não importados) e aplica `batchModify` em blocos de até 1.000 — arquivar = remover `INBOX`; lixeira = adicionar `TRASH`. Depois atualiza as linhas locais em massa (`is_archived`/`is_deleted`, labels) e recalcula os totais do remetente (consultas constantes). A limpeza roda mesmo se a desinscrição falhar? **Não**: só após sucesso (ou nos caminhos `page`/`rule`, que não falham).
- Erros de cota/servidor → `failed` com `rate_limited`/`http_*`; `ReauthorizationRequired` → `failed` com `reauth_required`. Log: `sender_id` + código, nunca URL, endereço ou assunto.

### 6.8 Proteção contra SSRF (one-click)

`SafeHttpClient` (ou validação dentro do `OneClickUnsubscriber`):
1. Só `https`, porta 443 ou ausente, sem credenciais na URL.
2. Resolve o host (A e AAAA); recusa se **qualquer** IP for privado, loopback, link-local, reservado, multicast ou de metadados de nuvem (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` + checagens IPv6 como `::1`, `fc00::/7`, `fe80::/10`, IPv4 mapeado).
3. Fixa a conexão no IP validado (`CURLOPT_RESOLVE`) para impedir *DNS rebinding*.
4. `allow_redirects = false`, timeout de conexão 5 s e total 10 s, `User-Agent` genérico, corpo da resposta ignorado.
Falha em 1–2 → `failed` com `blocked_address`, sem nenhuma requisição.

### 6.9 Provedor (amplia `EmailProviderInterface`)

- `sendMessage(EmailAccount $account, string $to, string $subject, string $body): void` — monta RFC 2822 simples (From = conta, `Content-Type: text/plain; charset=UTF-8`, assunto codificado), `users.messages.send`, custo 100.
- `listAllMessageIds(EmailAccount $account, string $query): list<string>` — pagina `messages.list` com `maxResults=500` (custo 5 por página).
- `batchModify(EmailAccount $account, array $ids, array $addLabels, array $removeLabels): void` — blocos de 1.000, custo 50 cada.
- Mesmo tratamento de erros e controle de cota dos métodos atuais. `FakeEmailProvider` registra envios e modificações.

### 6.10 Frontend

- `resources/js/Pages/Subscriptions/Index.tsx` com filtros (conta, abas), tabela paginada, modal de confirmação (6.4), status por linha.
- `usePoll(5000, { only: ['subscriptions'] })` ligado só enquanto algum remetente da página estiver `processing`.
- Datas com os helpers existentes (`formatDate`, `timeAgo`).
- Painel: "Assinaturas detectadas" passa a contar só assinaturas pendentes pelo critério 6.1 (resolve o débito técnico 13).

### 6.11 Documentos

SPEC 3.3 (colunas/status), 4.2 (fluxo final) e 6 (✅ "Tela do Hub de Assinaturas"); README (Hub, segurança do um clique); CLAUDE.md (camada de unsubscribe, SSRF, sem URLs nas props/logs); débito técnico: remover o item 13, registrar o que ficar de fora (ações em massa, busca).

## 7. Testes esperados

- **Critério de assinatura:** com cabeçalho, só promoções, sem nada (fora), totais zerados (fora), conta desconectada (fora), outro usuário (fora).
- **Listagem:** paginação de 100, ordem, filtro de conta (conta alheia ignorada), abas, contadores, `emails_after_request`, props sem `unsubscribe_url`/`mailto`, `gmail_url` codificado, consultas constantes.
- **Ações:** cada método; método indisponível 422; remetente alheio 404; conta precisando reconectar; duplo clique; throttle; regra criada com os valores certos; `page` com e sem limpeza.
- **Job:** one-click 2xx/3xx sucesso, 4xx/5xx/timeout falha; mailto com e sem assunto/corpo, mailto inválido; limpeza arquivar/lixeira com paginação e blocos de 1.000; limpeza só após sucesso; atualização local e totais; erros de cota e reautorização; logs sem URL/endereço.
- **SSRF:** http, porta estranha, credenciais na URL, IPs privados/loopback/link-local/metadados (169.254.169.254), IPv6 locais e mapeados, host que resolve para vários IPs com um privado, sem redirecionamento seguido, conexão fixada no IP validado.
- **Provedor (MockHandler):** `sendMessage` (MIME codificado, `raw` base64url), `listAllMessageIds` paginado, `batchModify` em blocos.

## 8. Deixado para quem implementa

Nomes internos auxiliares, layout fino da tabela e do modal, texto exato das explicações do modal, estrutura das classes de unsubscribe (uma por método ou um serviço com estratégias).
