# [URE] Mafasa — Relatórios de Ativos, Chamados e Quadros

Plugin GLPI (compat 11.0.0–12.0.99, PHP >= 8.2) para relatórios de ativos por
categoria, de chamados GLPI e de quadros Kanpro, com filtros totais, colunas
desmarcáveis, resumos agregados e impressão/PDF com cabeçalho institucional
(URE Jales).

## Instalação

1. Copiar a pasta `Mafasa` para `plugins/` ou `marketplace/` do GLPI.
2. Em Configurar → Plugins: Instalar → Ativar.
3. Acessar em Ferramentas → Mafasa.

## Estrutura

```
mafasa.xml, setup.php, hook.php, composer.json
src/compat.php, src/Install.php, src/Report.php, src/Config.php
src/TicketsReport.php, src/BoardsReport.php
front/report.php, front/print.php, front/config.php
front/tickets.php, front/print-tickets.php
front/boards.php, front/print-boards.php
install/mysql/plugin_mafasa_empty.sql
public/css/mafasa.css, public/css/mafasa-print.css, public/js/app.js
public/img/logo-ure-jales.png
```

## Relatórios

- **Ativos** (`front/report.php`): multi-tipo, filtros, colunas desmarcáveis.
- **Chamados** (`front/tickets.php`): lista detalhada + resumos agregados
  (status, tipo, prioridade, técnico, mês etc. com tempos médios).
- **Quadros Kanpro** (`front/boards.php`): cartões por quadro/lista/membro,
  prazos, checklists e vínculo com chamados. Requer o plugin Kanpro instalado.

## Status

v1.2.0 — ver `docs/superpowers/specs/2026-10-19-relatorios-chamados-quadros-design.md`.
