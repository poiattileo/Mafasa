# Mafasa - Relatórios de Ativos

Plugin GLPI (compat 11.0.0–12.0.99, PHP >= 8.2) para relatórios de ativos por
categoria, com filtros totais, colunas desmarcáveis e impressão/PDF com
cabeçalho institucional (URE Jales).

## Instalação

1. Copiar a pasta `Mafasa` para `plugins/` ou `marketplace/` do GLPI.
2. Em Configurar → Plugins: Instalar → Ativar.
3. Acessar em Ferramentas → Mafasa.

## Estrutura (espelho Protocolo)

```
mafasa.xml, setup.php, hook.php, composer.json
src/compat.php, src/Install.php, src/Report.php, src/Config.php
front/report.php, front/print.php, front/config.php
install/mysql/plugin_mafasa_empty.sql
public/css/mafasa.css, public/css/mafasa-print.css, public/js/app.js
public/img/logo-ure-jales.png
```

## Status

v1 em construção — ver `docs/superpowers/plans/2026-10-16-mafasa-relatorios.md`.
