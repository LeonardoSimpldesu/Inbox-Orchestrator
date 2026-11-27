# Layout para celular: plano de implementação

> Execução: inline nesta sessão (sem subagentes). Mudança só de frontend (TSX + CSS), sem lógica nova; a verificação é o script de medição no navegador local em 360/375/768/1024/1280/1440px, mais `typecheck`, `build`, `test:js` e `test:security`.

**Spec:** `docs/superpowers/specs/2026-11-27-mobile-layout-design.md`

### Tarefa 1: navegação
- [ ] `AppLayout`: barra do topo com logo + menu de perfil (Contas, Segurança, Sair; nome e e-mail do usuário no topo do menu); navegação principal no topo só em `lg+`; barra fixa embaixo em `< lg` com os 4 itens e destaque do ativo (`usePage().url`); espaço no fim do `main` para a barra não cobrir conteúdo (incluindo `env(safe-area-inset-bottom)`).
- [ ] Medir: nenhuma página logada com `scrollWidth > clientWidth` por causa do cabeçalho em nenhuma largura.

### Tarefa 2: tabelas em cartões
- [ ] Classe `responsive-table` em `resources/css/app.css` (só abaixo de `lg`).
- [ ] Assinaturas, Remetentes e Regras: `min-w-*` só em `lg+`, `data-label` nas células numéricas/de data, ações com quebra de linha; Remetentes sem estourar em 1024px.
- [ ] Hub: remover o `title` desatualizado de "Voltar para pendentes".

### Tarefa 3: ajustes pontuais e verificação
- [ ] Visão Geral (selo "Conectada"), filtros (busca com largura total no celular), barras de seleção em massa, modais (`RetentionRuleForm`, `UnsubscribeDialog`, `CleanSenderDialog`, `SimulationPanel`), Contas, Segurança, páginas de login e públicas.
- [ ] Script de medição em todas as páginas e larguras, incluindo modais abertos; capturas em 375 e 1024px.
- [ ] `npm run typecheck`, `npm run build`, `npm run test:js`, `npm run test:security`; PHPUnit completo (nada de PHP muda, mas a suíte roda antes do PR).
