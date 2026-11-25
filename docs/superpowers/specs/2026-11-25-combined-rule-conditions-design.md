# Design: Regras com condições combinadas (débito #19)

Status: aprovado · Data: 2026-11-25

## Intenção
Hoje cada regra de expiração tem um único filtro (categoria, domínio, remetente ou marcador). O dono quer regras como:
- assunto "Bem-vindo" **ou** "Welcome" → lixeira;
- remetente `no-reply@accounts.google.com` **e** assunto "Alerta de segurança" → arquivar.

Para isso a regra passa a ter uma lista de condições que valem juntas (E), e ganha um tipo novo de condição: assunto.

## Objetivos
- Uma regra tem condições de um ou mais tipos; tipos diferentes valem juntos (E) e valores do mesmo tipo são alternativas (OU). Exceção: no máximo **um** marcador por regra.
- Tipo novo **Assunto contém**: com vários termos, basta um deles aparecer. Vira `subject:("termo 1" OR "termo 2")` na busca do Gmail.
- As regras existentes continuam funcionando sem mudança, como regras de uma condição.
- A lista de regras e o Dashboard descrevem todas as condições (ex.: `Remetente: no-reply@… · Assunto: "Alerta de segurança"`).
- Simulação, execução, pausa por marcador apagado e "todas as contas" continuam valendo para qualquer combinação.

## Não-objetivos
- Busca livre do Gmail (caminho B). Fica registrada no débito técnico como opção futura.
- Condições com OU entre tipos diferentes (ex.: domínio X **ou** assunto Y). Isso continua sendo feito com duas regras.
- Condições novas além de assunto (anexo, tamanho, corpo).

## Anti-objetivos
- Uma regra combinada (ex.: remetente + assunto) ser tratada como a categoria do remetente na página Remetentes, ou como bloqueio do remetente no Hub. Só uma regra com **uma única** condição de remetente tem esse papel.
- Um termo de assunto conseguir alterar a busca além do assunto e anular as exclusões fixas (`-is:starred -in:sent -in:drafts`).
- A contagem simulada ser diferente do que a execução aplica.

## Abordagens consideradas
- **A. Condições estruturadas com E, mais o tipo assunto (escolhida pelo dono).** Segura, porque o app monta a busca. Fica limitada aos tipos oferecidos.
- **B. Busca livre do Gmail.** Flexível, mas exige validar a sintaxe e recusar operadores perigosos. Vai para o débito técnico como opção futura.
- **C. A + B.** Adiada junto com o B.

## Decisões
**Dados.** Tabela nova `retention_rule_conditions` (`id`, `retention_rule_id` com FK e `cascadeOnDelete`, `type`, `value`, timestamps), com índice em `(type, value)` e em `retention_rule_id`. `RetentionRule hasMany conditions`.
- **Semântica:** linhas do **mesmo tipo** valem com OU; **tipos diferentes** valem com E. O assunto "Bem-vindo" ou "Welcome" são duas linhas `subject`. Isso também permite "domínio X ou domínio Y" sem regra extra.
- A migration cria a tabela, copia `filter_type`/`filter_value` de cada regra existente para uma linha e remove as duas colunas. O `down` faz o caminho inverso com a primeira condição de cada regra e remove `kind`; condições extras se perdem, porque o modelo antigo só guarda uma (aceito: `down` só serve para desenvolvimento).
- **Papel explícito da regra:** coluna nova `kind` em `retention_rules`: `manual` (padrão, criada na página Regras), `sender_category` (categoria, página Remetentes), `sender_cleanup` ("limpar e manter limpo" na página Remetentes: remetente → arquivar/lixeira, 0 dias) ou `hub_block` (criada por "arquivar/lixeira" no Hub). A página Remetentes hoje evita duplicar a regra de limpeza procurando "qualquer regra de remetente com essa ação"; passa a procurar pelo `kind`. Remetentes e Hub buscam pelo `kind` + a condição de remetente; nada é deduzido do formato da regra. As consultas usam subconsultas/joins com número constante de queries (os testes de contagem continuam).
- Regras `sender_category`, `sender_cleanup` e `hub_block` têm sempre uma única condição `sender`. Na página Regras, as condições delas ficam travadas com o aviso "gerenciada pela página Remetentes" / "gerenciada pelo Hub"; nome, dias e ação continuam editáveis (como hoje). O backend recusa a mudança de condições nelas (não basta esconder no formulário).
- **Backfill do `kind` na migration**, reproduzindo o que o sistema já considera hoje: regra `sender` com `add_label` → `sender_category`; regra `sender` com arquivar/lixeira cujo remetente está `blocked` na mesma conta → `hub_block`; outra regra `sender` com arquivar/lixeira e 0 dias → `sender_cleanup`; o resto → `manual`. (Uma regra manual com exatamente esse formato vira `sender_cleanup`; o comportamento dela é o mesmo, só passa a ter as condições travadas.)
- **Hub:** apagar uma regra `hub_block` devolve o remetente para Pendentes direto (some o `releaseHubSender`, que procurava se ainda havia alguma regra apontando para ele). "Voltar para Pendentes" num remetente bloqueado também apaga a regra `hub_block` dele; hoje a regra continua ativa e o remetente aparece como pendente enquanto seus e-mails seguem indo para a lixeira.
- "Todas as contas" grava as regras e as condições em lote (número constante de consultas).
- Listas (Regras, Dashboard) carregam as condições com eager loading.

**Busca (`RuleQuery`).** As condições são agrupadas por tipo. Um valor só gera o termo de hoje; vários do mesmo tipo geram um grupo com OU: `{from:@a.com from:@b.com}`, `subject:("a" OR "b")`. Categoria com um valor usa `labelIds` como hoje; com mais de um vira grupo de busca `{category:promotions category:updates}` (os nomes das categorias são fixos), porque `labelIds` da API combina com E. Marcador: no máximo um por regra, sempre por id em `labelIds`; a busca nunca contém nome de marcador. As exclusões fixas, `older_than` e `in:inbox` continuam iguais.

**Validação (`RetentionRuleRequest`).**
- `conditions` é uma lista de 1 a 20 itens `{type, value}`, sem pares repetidos e com no máximo uma condição `label`.
- Cada valor é validado como hoje conforme o tipo (domínio, e-mail, categoria, marcador da conta).
- Assunto: cada termo com 1 a 100 caracteres depois do trim, sem `"`, `(`, `)`, `{`, `}`. Assim um termo não escapa das aspas.
- "Todas as contas" continua proibido quando houver condição de marcador.

**Marcadores.** `RuleMatcher::ensureLabelsExist` e o `labelNames` do controller passam a olhar todas as condições de marcador.

**Formulário.** Uma linha por condição (tipo + valor) com "Adicionar condição" e remover. Linhas do mesmo tipo aparecem agrupadas com "ou" na descrição da regra.

**Débito técnico.** Remover o #19 e criar um item novo: "Busca livre do Gmail nas regras (opção futura)", descrevendo o caminho B e as validações necessárias.

**Testes.**
- `RuleQuery`: cada tipo combinado e assunto com aspas/OU.
- `RetentionRuleRequest`: limites, tipos repetidos, caracteres proibidos no assunto, marcador em "todas as contas".
- Migration: regras existentes viram uma condição cada, com o mesmo resultado no `RuleQuery`.
- Migration: backfill do `kind` nos quatro casos.
- Remetentes e Hub usam só regras do seu `kind`: uma regra `manual` "remetente X → marcador" não vira categoria; contagem de consultas constante.
- Editar condições de regra `sender_category`/`sender_cleanup`/`hub_block` é recusado pelo backend; nome, dias e ação continuam editáveis.
- Apagar regra `hub_block` libera o remetente; "Voltar para Pendentes" apaga a regra `hub_block`.
- `RuleMatcher` pausa a regra quando um marcador usado numa condição extra some.
- Frontend: `describeFilter` com várias condições.
- Cobertura de `app/` continua 100%.

## Fica a cargo de quem implementa
- Textos dos erros de validação.
- Como os nomes de marcador chegam ao frontend (ex.: nome junto de cada condição).
