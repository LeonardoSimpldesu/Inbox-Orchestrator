# Débito técnico

Itens conhecidos que ficaram para depois. Cada um traz o impacto, onde está e uma sugestão de correção. Ao resolver um item, remova-o daqui no mesmo commit da correção.

| # | Item | Impacto | Prioridade |
|---|------|---------|------------|
| 21 | Busca livre do Gmail nas regras (opção futura) | Funcionalidade | Baixa |
| 22 | Opção para incluir e-mails com estrela ao arquivar ou apagar | Funcionalidade | Baixa |

---

## 21. Busca livre do Gmail nas regras (opção futura)

**Origem:** Regras com condições combinadas (2026-11-25). O dono escolheu só as condições estruturadas (caminho A) e deixou a busca livre registrada como opção.

**O que acontece:** as regras aceitam categoria, marcador, domínio, remetente e assunto, combinados com E (e OU dentro do mesmo tipo). Não há como usar outros operadores do Gmail (anexo, tamanho, palavra no corpo, `has:`, `larger:`).

**Onde:** `RetentionRuleRequest`, `RuleQuery`, `RetentionRule::CONDITION_TYPES`.

**Sugestão (caminho B):** um tipo de condição novo, "Busca avançada (Gmail)", que se combina com E com as outras condições:
1. O texto vai entre parênteses na busca, e as exclusões fixas (`-is:starred -in:sent -in:drafts`) continuam fora deles.
2. Recusar operadores que furam a proteção ou mudam o escopo: `in:anywhere`, `in:sent`, `is:starred`, `in:trash`, `in:spam`, `label:` (marcador é sempre por id), e parênteses, chaves ou aspas desbalanceados.
3. A simulação obrigatória antes de ativar já serve de pré-visualização.

## 22. Opção para incluir e-mails com estrela ao arquivar ou apagar

**Origem:** revisão de segurança e bugs (2026-11-27). A limpeza de remetente passou a preservar e-mails com estrela, como as regras já faziam.

**O que acontece:** regras, "Limpar…" da página Remetentes e a limpeza do Hub nunca tocam em e-mails com estrela (`GmailSearch::PROTECTED`). Quem quiser apagar também os com estrela precisa fazer isso direto no Gmail.

**Onde:** `GmailSearch::PROTECTED`, `RuleQuery`, `SenderMailCleaner`, formulário de regras, `CleanSenderDialog` e `UnsubscribeDialog`.

**Sugestão:** nas ações arquivar e lixeira, um checkbox "Incluir e-mails com estrela", desmarcado por padrão. Nas regras, guardar a escolha na regra (e exigir nova simulação quando ela muda); nas limpezas, passar a escolha ao job. Enviados e rascunhos continuam sempre protegidos.
