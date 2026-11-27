# Design: Layout para celular, tablet e notebook pequeno

Status: aprovado · Data: 2026-11-27

## Intenção
No celular, no tablet e em notebooks de 1024px, todas as páginas logadas rolam de lado: o cabeçalho só cabe a partir de ~1200px. As tabelas de Assinaturas, Remetentes e Regras também rolam de lado abaixo de 1024px. O dono quer usar o app bem no celular, e nenhuma página pode ter scroll horizontal em nenhuma largura.

## Objetivos
- Nenhuma página (logada ou pública), nenhum modal e nenhum elemento com scroll horizontal em 360, 375, 768, 1024, 1280 e 1440px.
- Navegação no celular e no tablet (< 1024px): barra fixa embaixo com Visão Geral, Assinaturas, Remetentes e Regras (ícone + nome, item ativo destacado); no topo, uma barra fina com a logo e um botão de perfil que abre Contas, Segurança e Sair.
- Em 1024px ou mais: navegação no topo, compacta, com Contas, Segurança e Sair no mesmo menu de perfil e sem o selo "v1.0 MVP". O item da página atual fica destacado.
- Tabelas de Assinaturas, Remetentes e Regras viram cartões abaixo de 1024px; em 1024px ou mais continuam tabela, sem estourar.
- Áreas de toque de pelo menos 44px nos controles do celular.

## Não-objetivos
- Mudar cores, identidade visual ou o conteúdo das páginas.
- Teste automático visual no CI (o dono decidiu não fazer agora).

## Abordagens consideradas (navegação no celular)
- **A. Barra fixa embaixo (escolhida pelo dono):** mais rápida de usar com uma mão.
- **B. Menu hambúrguer:** ocupa menos espaço, mas exige um toque a mais.

## Decisões
- Tabelas: a mesma marcação vira cartão abaixo de `lg` por CSS (uma classe `responsive-table` em `app.css`: cabeçalho escondido, cada linha um cartão, células com `data-label` mostram o nome da coluna). Sem duplicar a marcação de cada página.
- O menu de perfil fecha com Esc e com clique fora.
- O texto de ajuda de "Voltar para pendentes" no Hub, que dizia que a regra continua ativa, fica desatualizado desde o #19 e sai.
- Verificação: o script de medição usado no levantamento, rodado no navegador local em todas as larguras, mais capturas de tela.
