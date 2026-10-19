# Design — Relatórios de Chamados e Quadros (v1.2.0)

Data: 2026-10-19
Status: aprovado pelo usuário ("Duas" — lista detalhada + resumos agregados)

## Escopo

Extensão do Mafasa ([URE] Mafasa) com dois novos pares tela+impressão:

| Par | Tela | Impressão |
|---|---|---|
| Chamados | `front/tickets.php` | `front/print-tickets.php` |
| Quadros Kanpro | `front/boards.php` | `front/print-boards.php` |

Renomeação para "[URE] Mafasa" e logo novo (`public/img/logo-ure-jales.png`)
também fazem parte desta entrega.

## Decisões

### 1. Abas lista/resumo (cada tela)

GET `view=lista|resumo`. A lista espelha o relatório de ativos (filtros,
colunas desmarcáveis via `cols[]`, paginação, copiar link, imprimir). O
resumo tem seletor de agrupamento (`mode=`) e tabela com contagem +
percentual.

### 2. Chamados — `src/TicketsReport.php`

- Permissão: `Ticket::canView()`.
- Filtros: status, tipo, prioridade, urgência, impacto, categoria (árvore),
  solicitante, técnico, grupo, quadro Kanpro (se disponível), assunto,
  datas (abertura/resolução/fechamento de/até).
- Colunas: id, assunto, status, tipo, prioridade, urgência, impacto,
  categoria, solicitante, técnicos, grupos, entidade, abertura, 1ª resposta,
  resolução, fechamento, espera, trabalho, dias em aberto.
- Resumos: status, tipo, prioridade, urgência, impacto, categoria,
  técnico, solicitante, grupo, mês, entidade. Métricas: contagem,
  % do total, tempo médio até 1ª resposta (`takeintoaccountdate`) e de
  resolução (`solvedate`), em segundos → formatados ("2d 3h").
- Status GLPI 1–6 → rótulos pt-BR fixos (não usa dicionário, evita
  dependência de tradução).

### 3. Quadros — `src/BoardsReport.php`

- Guard defensivo: `available()` verifica as 3 tabelas Kanpro via
  `tableExists` (cacheado por requisição). `canView()` usa direito
  `plugin_kanpro` READ. Sem Kanpro → tela mostra aviso, não quebra.
- Colunas: id, cartão, quadro, coluna, membros (GROUP_CONCAT), situação do
  prazo (Concluído/Sem prazo/Atrasado/Vence hoje/No prazo via CASE),
  vencimento, concluído, chamado vinculado, checklist (concluídos/total),
  entidade, criador, criação, alteração.
- Resumos: quadro, coluna, membro, situação de prazo, com/sem chamado, mês
  de criação, progresso de checklists.
- Exclui arquivados (`is_archived = 0`), respeita entidade.

### 4. Impressão

Páginas standalone (como `front/print.php`): logo/título/rodapé via
`Config`, resumo de filtros ativos, truncamento em `PRINT_LIMIT` (5000) com
aviso. `app.js` ganhou `data-print` no link Imprimir para cada tela apontar
para seu próprio print (fallback `print.php`).

### 5. Menu

`Report::getMenuContent()` ganhou as opções "Chamados" (sempre) e "Quadros
Kanpro" (se `BoardsReport::available() && canView()`).

### 6. Segurança

Somente SELECT; valores de filtro via int/date/choices validados ou
`$DB->quoteValue`; entidade ativa via `mafasa_entity_sql()`; lixeira excluída;
HTML escapado nas telas.

## Fora de escopo

- Exportação CSV.
- Relatórios de problemas/solicitações ITIL (só chamados).
- Drill-down clique-a-linha.
