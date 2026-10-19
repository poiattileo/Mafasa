# [URE] Mafasa — Relatórios de Chamados GLPI e Quadros Kanpro (Design)

- Data: 2026-10-19
- Status: aguardando aprovação
- Repositório alvo: `D:\Projetos\Mafasa`
- Fonte de dados dos quadros: `D:\Projetos\kanpro` (somente leitura)
- Pedido original: renomear o plugin para **[URE] Mafasa**, colocar a logo nova, adicionar relatórios variados de chamados do GLPI e relatórios dos quadros do kanpro, com numeração de páginas na impressão.

## 1. Contexto

O Mafasa é um plugin GLPI 11–12 de relatórios de ativos. Ele já tem um motor de relatório v2: catálogo de tipos, filtros, seleção de colunas, busca paginada e uma página de impressão/PDF (`front/print.php`) com cabeçalho institucional, logo e CSS de impressão.

O kanpro é um plugin GLPI de quadros kanban. A hierarquia é `boards → lists → cards`:

- `glpi_plugin_kanpro_boards`: quadros, com `entities_id`, `is_archived`, `visibility`, `parent_boards_id`.
- `glpi_plugin_kanpro_lists`: colunas do quadro, com `name`, `rank`, `is_archived` e `list_type`.
- `glpi_plugin_kanpro_cards`: cards, com `plugin_kanpro_boards_id`, `plugin_kanpro_lists_id`, `tickets_id`, `is_archived`, `is_completed`, `is_maintenance`, `chamado_status`, `entities_id` e datas.

O `list_type` da lista é a **categoria** do quadro. Valores documentados no DDL: `backlog`, `todo`, `doing`, `done`, `awaiting`, `pending`, `andamento`, `retirada`, `pend_chamado`, `none` (vazio/none = sem categoria). O usuário confirmou que "categoria do quadro" é esse campo.

Como os dois plugins rodam no mesmo GLPI, o Mafasa pode ler as tabelas do kanpro pelo `$DB` sem alterar o kanpro.

## 2. Objetivos

1. Renomear o plugin para **[URE] Mafasa** onde o nome é exibido (lista de plugins, XML, cabeçalhos próprios).
2. Usar a logo nova `C:\Users\leonardo.facao\Documents\logo_mafasa\mafasa_fundo_arredondado.png` no plugin e nos impressos.
3. Adicionar relatório de **chamados do GLPI**: contagens por status, prioridade, tipo, categoria, técnico/grupo, entidade e período, com resumo, totais e lista detalhada opcional.
4. Adicionar relatório dos **quadros do kanpro**: total de cards/chamados por quadro, quebra por categoria (`list_type`) e cards de cada categoria em cada quadro, com detalhamento opcional.
5. Colocar **numeração de páginas** ("Página X de Y") em todos os impressos do Mafasa.

## 3. Não-objetivos

- Não escrever nada em chamados, cards, quadros ou listas: os relatórios são somente leitura.
- Não alterar código, schema ou comportamento do kanpro.
- Não gerar PDF no servidor nesta versão; a impressão continua pelo navegador.
- Não criar agendamento, envio por e-mail ou exportação automática.
- Não replicar todos os relatórios nativos do GLPI; o escopo é o definido na seção 9.

## 4. Anti-objetivos

- Quebrar ou alterar os relatórios de ativos existentes.
- Exigir o kanpro ativo para o Mafasa funcionar.
- Mostrar chamados ou quadros que o usuário não tem permissão de ver.
- Contar cards/listas/quadros arquivados por padrão.
- Imprimir sem refletir os filtros ativos ou com contagens divergentes da tela.

## 5. Decisões do usuário

- Nome do plugin: `[URE] Mafasa`.
- Logo: `mafasa_fundo_arredondado.png`, em `C:\Users\leonardo.facao\Documents\logo_mafasa`.
- Categoria do quadro: `list_type` da lista/coluna do kanpro (confirmado).
- Numeração de páginas na impressão.
- Relatórios de chamados GLPI e de quadros kanpro entram no Mafasa.

## 6. Nossas decisões (o executor pode ajustar sem mudar o resultado)

- Versão do plugin: `1.2.0` (features novas; sem migração de dados).
- Menu: topo `[URE] Mafasa` com as opções existentes de **Ativos**, mais **Chamados GLPI** e **Quadros Kanpro**.
- Ler as tabelas do kanpro diretamente, com `$DB->tableExists()`, sem depender de classes/funções do kanpro (não incluir arquivos do plugin); as regras de visibilidade são replicadas em `BoardReport`.
- Imprimir em A4 paisagem, mantendo o padrão atual.
- Numeração de páginas com Paged.js vendorizado localmente (MIT), carregado apenas nas páginas de impressão.
- Período padrão do relatório de chamados: últimos 30 dias.
- Quadros: mostrar matriz quadro × categoria, tabela por lista e detalhamento opcional de cards.
- `list_type` vazio, `none` ou desconhecido aparece como "Sem categoria".

Ajustes do check do builder (nossas decisões — revisar na leitura):

- Visibilidade de quadros: replicar a regra real do kanpro. O campo `boards.visibility` (private/team/public) é cosmético — não controla acesso — e será ignorado. Quadro visível se: criador (`users_id`), ou membro em `glpi_plugin_kanpro_boards_members` (sessão + pessoa), ou perfil em `glpi_plugin_kanpro_boards_profiles`, ou legado aberto (sem membros E sem perfis).
- Visibilidade de listas: `glpi_plugin_kanpro_lists_viewers` vazio = aberta; preenchido = só os viewers (sem bypass de admin). Cards de listas invisíveis não entram em nenhuma contagem.
- Arquivados: o filtro "incluir arquivados" vale para os três níveis (cards, listas e quadros). Padrão: só não arquivados.
- Entidade dos quadros: filtra `boards.entities_id`; os cards contam pelo quadro a que pertencem, mesmo que o `entities_id` do card seja de outra escola/entidade.
- Chamados — período: filtra pela abertura (`glpi_tickets.date`); as quebras por status/prioridade/etc. refletem o estado atual dos chamados abertos no período.
- Chamados — evolução mensal: série separada; "abertos" pelo mês de abertura, "resolvidos/fechados" pelo mês de `solvedate`/`closedate`; janela de 12 meses ancorada no fim do período; honra entidade, tipo e categoria (não o filtro de status).
- Chamados — tempos médios: atendimento = média de `takeintoaccount_delay_stat`; resolução = média de `solve_delay_stat`; só valores > 0; sem base, mostrar "—".
- Chamados — visibilidade: entidade ativa + `ticket READ`; restrições finas por grupo/atribuição não são replicadas nesta versão (usar helper oficial do core se a versão instalada tiver). Limitação documentada.
- Card — responsável: membros (`glpi_plugin_kanpro_cards_members`); sem membros, o criador (`users_id`).
- Card — status do chamado: `chamado_status` do kanpro (pendência interna: pendente, liberado, conclusao, conclusao_ok, exclusao, exclusao_ok, edicao, edicao_ok), com rótulo amigável; "com chamado vinculado" = `tickets_id > 0` (sem join em `glpi_tickets` para essa coluna).

## 7. Identidade do plugin e logo

Alterações de nome exibido:

- `setup.php`: `plugin_version_mafasa()['name']` → `[URE] Mafasa`; versão → `1.2.0`.
- `mafasa.xml`: `<name>` → `[URE] Mafasa`; descrição atualizada para citar chamados e quadros.
- `composer.json`: descrição atualizada.
- `front/report.php`: título do `Html::header()` → `[URE] Mafasa`.
- `README.md` e `README_GLPI.md`: nome e descrição atualizados.
- `src/Config.php`: `DEFAULT_HEADER_TITLE` → `[URE] Mafasa — Relatórios`; `DEFAULT_FOOTER_TEXT` → `[URE] Mafasa`.
- `src/Report.php`: a opção de menu de ativos continua chamada "Relatórios de Ativos"; o topo do menu passa a ser `[URE] Mafasa`.

Não mudar: tag `mafasa`, namespace `GlpiPlugin\Mafasa`, tabelas e chaves de configuração. Isso evita quebra de instalação e upgrade.

Logo:

- Copiar o binário de `C:\Users\leonardo.facao\Documents\logo_mafasa\mafasa_fundo_arredondado.png` para `public/img/logo-mafasa.png` no repositório. Nunca referenciar o caminho absoluto da máquina.
- `Config::getLogoUrl()` passa a usar `public/img/logo-mafasa.png` como fallback padrão.
- O upload configurável em `files/_plugins/mafasa/` continua tendo prioridade sobre a logo embutida.
- A logo antiga (`logo-ure-jales.png`) pode permanecer no repositório por compatibilidade, mas deixa de ser o padrão.

## 8. Arquitetura dos relatórios

Classes novas em `src/`:

- `TicketReport.php`: catálogo de filtros/colunas, montagem e execução das consultas de chamados, rótulos via dicionários do GLPI.
- `BoardReport.php`: catálogo de filtros/colunas, consultas de contagem de cards/listas/categorias e detalhamento.
- `PrintSupport.php`: casco HTML comum de impressão (cabeçalho com logo, título, metadados, resumo de filtros, rodapé, inclusão do Paged.js e CSS de impressão).

Telas novas em `front/`:

- `front/chamados.php`: filtros + KPIs + tabelas do relatório de chamados.
- `front/quadros.php`: filtros + KPIs + matriz/tabelas do relatório de quadros.

Impressão:

- `front/print.php` passa a aceitar `report=ativos|chamados|quadros` (padrão `ativos`, preservando o comportamento atual).
- Para `chamados` e `quadros`, monta os dados com as mesmas funções usadas na tela e delega o casco para `PrintSupport`.
- Os links "Imprimir" das telas novas levam para `print.php?report=...&<filtros atuais>`.

Menu:

- `Report::getMenuContent()` ganha as opções `chamados` (`ti ti-ticket`) e `quadros` (`ti ti-layout-kanban`), visíveis apenas se o usuário tiver a permissão correspondente (seção 12) e, no caso de quadros, se o kanpro estiver ativo e as tabelas existirem.

Padrão de estado: as telas seguem o padrão do `report.php` atual — estado 100% GET, compartilhável, botão "Gerar relatório", botão "Limpar filtros" e link de impressão com a query string atual.

## 9. Relatório de Chamados GLPI

Filtros:

- Período: `date_from`, `date_to` (padrão últimos 30 dias), com atalhos: hoje, 7 dias, 30 dias, mês atual, ano atual.
- Entidade: entidade ativa e descendentes (padrão: entidades ativas da sessão).
- Status: novos, atribuídos, planejados, pendentes, resolvidos, fechados (múltipla escolha).
- Tipo: incidente e/ou requisição.
- Prioridade: muito baixa a muito alta (múltipla escolha).
- Categoria ITIL: `glpi_itilcategories`.
- Técnico atribuído: `glpi_tickets_users` com `type = 2`.
- Grupo atribuído: `glpi_groups_tickets`.
- Requerente: `glpi_tickets_users` com `type = 1`.

Seções do resultado:

1. Cartões de KPI: total de chamados, abertos, resolvidos, fechados, tempo médio de atendimento e tempo médio de resolução.
2. Por status, com total e percentual.
3. Por prioridade.
4. Por tipo (incidente/requisição).
5. Top categorias ITIL (quantidade e percentual; limite configurável, padrão 15).
6. Por técnico atribuído.
7. Por grupo atribuído.
8. Por entidade.
9. Evolução mensal dos últimos 12 meses: abertos versus resolvidos/fechados.
10. Lista detalhada opcional: `id`, título, status, prioridade, tipo, categoria, técnico, requerente, entidade, data de abertura, data de resolução/fechamento. Limitada por `Report::PRINT_LIMIT` na impressão, com aviso de truncamento.

Diretrizes de consulta:

- Contar chamados únicos; quando houver múltiplos técnicos ou grupos por chamado, usar `EXISTS`/subconsulta por tipo para não duplicar a contagem.
- Excluir `is_deleted = 1`.
- Período por `glpi_tickets.date` (abertura); as quebras por status/prioridade/etc. refletem o estado atual dos chamados abertos no período.
- Evolução mensal: "abertos" pelo mês de abertura; "resolvidos/fechados" pelo mês de `solvedate`/`closedate`; janela ancorada no fim do período; honra entidade, tipo e categoria (não o filtro de status).
- Tempos médios: `takeintoaccount_delay_stat` (atendimento) e `solve_delay_stat` (resolução), só valores > 0; sem base, "—".
- Usar os rótulos do GLPI (`Ticket::getAllStatusArray()`, dicionário de prioridade, `ITILCategory`) em vez de strings fixas, com fallback em português.
- Respeitar as entidades ativas e a permissão `ticket` / `READ`; restrições finas por grupo/atribuição não são replicadas nesta versão (usar helper oficial do core se existir).

## 10. Relatório de Quadros Kanpro

Filtros:

- Entidade (padrão: entidades ativas da sessão).
- Quadros: um, vários ou todos (somente os visíveis para o usuário).
- Categorias (`list_type`): uma, várias ou todas.
- Incluir arquivados: cards/listas/quadros arquivados (padrão: não).
- Período por `date_creation` do card (opcional; padrão: sem corte).

Definições de contagem:

- **Card**: linha em `glpi_plugin_kanpro_cards`, não arquivada por padrão.
- **Chamado vinculado**: card com `tickets_id > 0`.
- **Status do chamado**: campo `chamado_status` do kanpro (pendência interna: `pendente`, `liberado`, `conclusao`, `conclusao_ok`, `exclusao`, `exclusao_ok`, `edicao`, `edicao_ok`), exibido com rótulo amigável — não é o status do ticket do GLPI.
- **Responsável**: membros em `glpi_plugin_kanpro_cards_members`; sem membros, o criador (`users_id`).
- **Card de manutenção**: `is_maintenance = 1`.
- **Categoria**: `list_type` da lista do card; vazio/`none`/desconhecido agrupa em "Sem categoria".
- **Lista**: `glpi_plugin_kanpro_lists` do quadro, não arquivada por padrão.

Seções do resultado:

1. Cartões de KPI: total de cards, cards com chamado vinculado, cards de manutenção, cards arquivados (quando incluídos) e total de quadros.
2. Por quadro: total de cards, chamados vinculados, manutenção e arquivados.
3. Matriz quadro × categoria: células com a contagem de cards; totais por linha e por coluna.
4. Por categoria: total geral de cards por `list_type`.
5. Por lista: quadro, nome da lista, categoria, total de cards.
6. Detalhamento opcional de cards: `id`, nome, quadro, lista, categoria, `tickets_id`, status do chamado (quando disponível), responsável e datas.

Regras:

- Kanpro inativo ou tabelas ausentes: a opção de menu fica indisponível e a tela mostra aviso amigável em vez de erro fatal.
- Visibilidade dos quadros: replicar a regra do kanpro em `BoardReport` (consultas próprias; não incluir arquivos do kanpro): entidades ativas + `is_archived = 0` (ou incluir, conforme filtro) + criador (`users_id`), membro (`glpi_plugin_kanpro_boards_members` para sessão/pessoa), perfil (`glpi_plugin_kanpro_boards_profiles`) ou legado aberto (sem membros E sem perfis). O campo `boards.visibility` é ignorado.
- Visibilidade das listas: `glpi_plugin_kanpro_lists_viewers` vazio = aberta; preenchido = só viewers (sem bypass); listas invisíveis ficam fora de todas as contagens.

## 11. Impressão e numeração de páginas

- Todas as páginas de impressão do Mafasa (ativos, chamados, quadros) usam o mesmo casco do `PrintSupport`.
- Cabeçalho: logo nova, título do relatório, "Gerado em", total de registros e resumo dos filtros ativos.
- Rodapé institucional: texto configurável em `Config`.
- Numeração com Paged.js (MIT), vendorizado em `public/js/paged.polyfill.min.js`; nenhuma dependência de CDN ou internet no servidor.
- CSS de impressão:

```css
@page {
    size: A4 landscape;
    margin: 12mm 12mm 16mm 12mm;
    @bottom-center {
        content: "Página " counter(page) " de " counter(pages);
        font-size: 9px;
        color: #6b7280;
    }
}
```

- O botão "Imprimir / Salvar PDF" fica desabilitado com o rótulo "Preparando impressão…" até o evento `pagedjs:rendered`; se o Paged.js falhar, o botão é reabilitado e a impressão funciona sem a numeração.
- Tabelas continuam com `thead` repetido por página e linhas sem quebra interna.

## 12. Permissões e escopo de dados

- Ativos: mantém `Report::canView()` como está.
- Chamados: `Session::haveRight('ticket', READ)`; resultados restritos às entidades ativas da sessão; `is_deleted = 0`. Restrições finas por grupo/atribuição não são replicadas nesta versão; usar helper oficial do core se existir.
- Quadros: `Session::haveRight('plugin_kanpro', READ)` + regra de visibilidade replicada (criador/membro/perfil/legado aberto) e listas por `lists_viewers`; `is_archived = 0` por padrão. Kanpro inativo/tabelas ausentes: opção indisponível, sem erro fatal.
- Sem permissão: mostrar a mensagem de direito existente do Mafasa; nunca renderizar dados parcialmente.

## 13. Arquivos impactados

Alterados:

- `setup.php` — nome, versão, menu.
- `mafasa.xml` — nome e descrição.
- `composer.json`, `README.md`, `README_GLPI.md` — identidade.
- `src/Config.php` — título/rodapé padrão e logo padrão.
- `src/Report.php` — opções de menu e canView das novas entradas.
- `front/report.php` — título do cabeçalho.
- `front/print.php` — dispatch `report=ativos|chamados|quadros`.
- `public/css/mafasa.css` — estilos das novas telas.
- `public/css/mafasa-print.css` — `@page` com rodapé de numeração e estilos das novas tabelas.
- `public/js/app.js` — comportamento dos filtros das novas telas, se necessário.

Novos:

- `src/TicketReport.php`
- `src/BoardReport.php`
- `src/PrintSupport.php`
- `front/chamados.php`
- `front/quadros.php`
- `public/js/paged.polyfill.min.js` (vendorizado, com licença)
- `public/js/print-init.js`
- `public/img/logo-mafasa.png`

Intocados:

- Todo o repositório `D:\Projetos\kanpro`.
- Relatórios de ativos existentes (exceto o título do plugin e o casco de impressão compartilhado).

## 14. Validação

- `php -l` em todos os arquivos PHP alterados/novos.
- No GLPI: instalar/atualizar o plugin e conferir o nome `[URE] Mafasa` e a logo nova.
- Chamados: conferir manualmente os totais de pelo menos uma entidade/período contra uma consulta SQL direta; testar usuário sem permissão de ticket.
- Quadros: conferir total por quadro, por categoria e por lista contra as consultas do kanpro; testar kanpro inativo e banco sem as tabelas.
- Quadros restritos: usuário sem vínculo não vê o quadro nem seus cards; viewers de lista restrita veem apenas a própria lista.
- Impressão: imprimir/salvar PDF no Edge e no Chrome; confirmar "Página X de Y" em todas as páginas, cabeçalho e rodapé repetidos.
- Regressão: gerar e imprimir um relatório de ativos como antes.

## 15. Riscos e mitigações

- Paged.js com tabelas muito grandes pode demorar: limitar a lista detalhada a `PRINT_LIMIT` e avisar quando truncar.
- Contagens duplicadas por múltiplos técnicos/grupos: usar `EXISTS`/subconsulta por tipo.
- Visibilidade dos quadros: a regra foi levantada do código do kanpro (membros/perfis/legado aberto); manter em um único ponto do `BoardReport` e validar com quadro restrito, pois o kanpro pode mudar a regra no futuro.
- Conflito entre o `@page` atual e o do Paged.js: centralizar o `@page` no CSS de impressão e testar.
- Rename do plugin pode ficar em cache: reinstalar/atualizar o plugin no GLPI após o deploy.
- Sem suíte de testes no Mafasa hoje: a validação principal é lint + checklist manual; um harness CLI simples pode ser adicionado se o custo for baixo (decisão do executor).

## 16. A critério do executor

- Nomes finais de classes e arquivos.
- Estrutura exata das consultas, índices e paginação das seções.
- Layout visual das tabelas e dos cartões de KPI (CSS puro, sem biblioteca de gráficos).
- Limite de itens do "top N" (sugestão: 15).
- Adição ou não de um harness de testes automatizados no Mafasa.
