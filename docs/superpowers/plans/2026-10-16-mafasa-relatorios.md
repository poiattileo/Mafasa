# Mafasa Relatórios Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar plugin Mafasa GLPI 11→12 com relatórios de ativos por categoria, filtros totais, colunas desmarcáveis e impressão/PDF bonito com logo URE Jales.

**Architecture:** Espelha Protocolo: manifest + setup/hook + PSR-4 `GlpiPlugin\Mafasa`, `Report` como registro de categorias/campos, `front/report.php` paginado e `front/print.php` full-data com CSS print, config via `glpi_configs`.

**Tech Stack:** PHP 8.2+, GLPI 11.0.0–12.0.99, MariaDB/MySQL, HTML/CSS/JS vanilla, `Search`/`$DB->request()` do GLPI.

**Spec:** `docs/superpowers/specs/2026-10-16-relatorios-ativos-design.md`

## Global Constraints

- GLPI min 11.0.0, max 12.0.99; PHP >= 8.2.
- Tag `mafasa`, namespace `GlpiPlugin\Mafasa`, nome "Mafasa - Relatórios de Ativos", versão 1.0.0, GPLv2+.
- Sem `Plugin::getWebDir()/getPhpDir()` direto — usar helpers `mafasa_*` com fallback marketplace/plugins sem duplicar `root_doc`.
- Sem `?v=` manual em CSS/JS; sem `@` para esconder erro PHP; escapar input com `$DB->quoteValue()` / cast int.
- Menu só em `tools`; `menu_entry` = `front/report.php`; `config_page` = `front/config.php`.
- Direitos v1 = `Itemtype::canView()` nativo + entidade ativa; excluir `is_deleted=1` e `is_template=1` por padrão.
- Logo embutida em `public/img/logo-ure-jales.png` (copiada de Downloads, nunca caminho absoluto).
- Tela pagina 50/pág; print leva todos (limite 5000 + aviso); filtros+colunas via GET compartilhável.

## Review Focus

- Categoria inválida no GET (`type=Foo`) deve cair para Computer com aviso, não fatal — test no Task 2/3.
- Filtro com aspas/`%` não quebra SQL nem vaza (usa quote/cast) — test no Task 2.
- 5000+ itens não estoura memória no print (limite + aviso) — test no Task 4.
- Sem logo no disco não quebra header (fallback texto URE Jales) — test no Task 4.
- Usuário sem `canView` na categoria vê erro de direito, não tabela vazia — test no Task 3.

---

### Task 1: Esqueleto instalável

**Files:**
- Create: `mafasa.xml`, `setup.php`, `hook.php`, `composer.json`, `src/compat.php`, `src/Install.php`, `install/mysql/plugin_mafasa_empty.sql`, `public/css/mafasa.css`, `.gitignore`, `README.md`
- Test: `php -l` em cada PHP + existência dos arquivos

**Interfaces:**
- Consumes: padrão Protocolo (lido do disco)
- Produces: `plugin_version_mafasa(): array`, `plugin_init_mafasa(): void`, `plugin_mafasa_install(): bool`, `plugin_mafasa_uninstall(): bool`, `mafasa_web_dir(string): string`, `mafasa_is_glpi12_plus(): bool`, `GlpiPlugin\Mafasa\Install::install()/uninstall()`

- [ ] **Step 1: Criar manifest, setup, hook, composer, compat, Install, sql vazio, css vazio, gitignore, readme**
  Criar `mafasa.xml` (nome, 1.0.0, autor, glpi min 11.0.0 max 12.0.99, php min 8.2); `setup.php` com defines `PLUGIN_MAFASA_VERSION 1.0.0` + `plugin_version_mafasa`/`check_prerequisites`/`check_config`/`plugin_init_mafasa` (menu tools, submenu report, config_page, add_css); `hook.php` delegando a `Install`; `composer.json` PSR-4 `GlpiPlugin\Mafasa\ → src/`; `src/compat.php` com `mafasa_glpi_version/major/is_glpi12_plus/web_dir/php_dir/csrf_token/check_csrf` (cópia protocolo trocando prefixo); `src/Install.php::install` cria upload dir + `Config::setConfigurationValues('plugin:mafasa', ['installed'=>...])`, `uninstall` remove config; sql vazio com `-- v1 sem tabelas próprias`; css com `:root` + prefixo `mf-`.
- [ ] **Step 2: Verificar sintaxe e presença**
Run: `php -l setup.php; php -l hook.php; php -l src/compat.php; php -l src/Install.php` em `C:\Users\Administrador\Desktop\Projetos\Mafasa`
Expected: `No syntax errors detected` nos 4 + arquivos listados existem.
- [ ] **Step 3: Commit**
```bash
git add mafasa.xml setup.php hook.php composer.json src/compat.php src/Install.php install/mysql/plugin_mafasa_empty.sql public/css/mafasa.css .gitignore README.md
git commit -m "feat: esqueleto instalavel do plugin mafasa"
```

### Task 2: Registro de categorias e campos

**Files:**
- Create: `src/Report.php`
- Modify: `setup.php` (submenu por categoria)
- Test: verificação via `php -r` do registro

**Interfaces:**
- Consumes: `GlpiPlugin\Mafasa\Install` (nada funcional, só ordem)
- Produces: `Report::getCategories(): array{tipo=>label}`, `Report::getFilters(string $type): array`, `Report::getColumns(string $type): array`, `Report::getDefaultColumns(string $type): array`, `Report::buildWhere(string $type, array $params): array{sql:string,params:array}`

- [ ] **Step 1: Implementar `Report` com 10 categorias e campos diretos**
  Em `src/Report.php`: `getCategories` retorna Computer, Monitor, Printer, NetworkEquipment, Peripheral, Phone, Software, Rack, Enclosure, PDU com labels PT; `getFilters/getColumns` por tipo = comuns (name, status→states_id, entities_id, locations_id, groups_id, manufacturers_id, models, serial, otherserial/inventory_number, users_id, groups_id_tech, date_creation/date_mod) + 3–8 específicos (ex. Computer: operatingsystems_id, processadores, memória); `buildWhere` monta `AND` com cast int e `$DB->quoteValue('%...%')` para texto, sempre com `is_deleted=0 AND is_template=0`; tipo inválido → `Computer`.
- [ ] **Step 2: Verificar registro carrega sem GLPI (stub mínimo)**
Run: `php -r "require 'src/Report.php'; echo count(GlpiPlugin\Mafasa\Report::getCategories());"`
Expected: imprime `10` (ou ajusta stub de namespace se precisar de autoload manual no teste).
- [ ] **Step 3: Registrar submenu por categoria no setup**
  Em `setup.php`: loop `Report::getCategories()` criando `$PLUGIN_HOOKS['submenu_entry']['mafasa'][slug]` → `front/report.php?type=X`.
- [ ] **Step 4: Verificar sintaxe**
Run: `php -l src/Report.php; php -l setup.php`
Expected: `No syntax errors detected` nos 2.
- [ ] **Step 5: Commit**
```bash
git add src/Report.php setup.php
git commit -m "feat: registro de categorias filtros e colunas"
```

### Task 3: Tela de relatório (filtros + gerar + colunas)

**Files:**
- Create: `front/report.php`, `public/js/app.js`
- Modify: `public/css/mafasa.css`
- Test: `php -l` + checklist visual/manual

**Interfaces:**
- Consumes: `Report::getCategories/getFilters/getColumns/getDefaultColumns/buildWhere`
- Produces: GET `type,f_*` + `cols[]` compartilhável; tabela `mf-table` paginada 50/pág; painel `mf-cols` com checkboxes

- [ ] **Step 1: Criar `front/report.php`**
  Inclui `../../../inc/includes.php` + `compat`; `Session::checkLoginUser()`; valida `type` via `Report`; checa `$itemtype::canView()` senão `Html::displayRightError()`; `Html::header()`; formulário GET com seções (Identificação, Localização/Responsáveis, Datas) + botões Gerar/Limpar/Copiar link/Imprimir; após Gerar mostra painel colunas (todas marcadas por padrão, botões tudo/limpar/padrão) + tabela paginada via `$DB->request()` com `buildWhere`, respeitando entidade ativa `$_SESSION['glpiactiveentities']`; `Html::footer()`.
- [ ] **Step 2: Criar `app.js` (colunas + copiar link)**
  Desmarcar esconde coluna via `data-col`; marcar tudo/limpar; copiar link copia URL com filtros+`cols[]`.
- [ ] **Step 3: Estilizar `mafasa.css` (tela)**
  Classes `mf-*`: header `mf-page`, cards, `mf-table` com zebra e sticky thead, `mf-badge`, responsivo.
- [ ] **Step 4: Verificar**
Run: `php -l front/report.php`
Expected: `No syntax errors detected`; checklist: tipo inválido cai p/ Computer; usuário sem canView vê erro; aspas em filtro não quebra.
- [ ] **Step 5: Commit**
```bash
git add front/report.php public/js/app.js public/css/mafasa.css
git commit -m "feat: tela de relatorio com filtros e colunas"
```

### Task 4: Impressão/PDF bonito com logo

**Files:**
- Create: `front/print.php`, `public/css/mafasa-print.css`, `public/img/logo-ure-jales.png` (cópia de `C:\Users\Administrador\Downloads\logo nova.png`)
- Test: `php -l` + checklist de header/print

**Interfaces:**
- Consumes: mesmos GET de Task 3 (`type,f_*,cols[]`) + `Report`
- Produces: documento A4 paisagem/retrato com header institucional e `window.print()`

- [ ] **Step 1: Copiar logo para dentro do plugin**
  Copiar `C:\Users\Administrador\Downloads\logo nova.png` → `public/img/logo-ure-jales.png` (binário, sem renomear conteúdo).
- [ ] **Step 2: Criar `front/print.php` full-data**
  Mesmas checagens de Task 3 + re-checa entidade; busca todos (limite 5000, aviso se estourar); header com logo (fallback texto se arquivo sumir), título `Relatório de Ativos — {Categoria}`, data/hora, usuário, entidade, resumo filtros, total; tabela só com `cols[]`; rodapé com total + `Página X`; botão Imprimir (`window.print()`), sem `Html::header/footer` do GLPI (HTML próprio mínimo).
- [ ] **Step 3: Criar `mafasa-print.css`**
  `@page A4 landscape margin 12mm`; esconde botões (`.no-print`); thead repete; evita quebrar linha no meio (`break-inside: avoid`); zebra suave que imprime (`print-color-adjust: exact`).
- [ ] **Step 4: Verificar**
Run: `php -l front/print.php`
Expected: `No syntax errors detected`; checklist: sem logo não quebra; 5000+ mostra aviso; colunas desmarcadas somem do impresso.
- [ ] **Step 5: Commit**
```bash
git add front/print.php public/css/mafasa-print.css public/img/logo-ure-jales.png
git commit -m "feat: impressao pdf bonito com logo ure jales"
```

### Task 5: Config + acabamento + git push

**Files:**
- Create: `front/config.php`, `src/Config.php`
- Modify: `README_GLPI.md` (instruções)
- Test: `php -l` + `lsp_diagnostics` + push

**Interfaces:**
- Consumes: tudo anterior
- Produces: config `plugin:mafasa` (header_title/footer_text/logo_path) + repo publicado

- [ ] **Step 1: Criar `src/Config.php` + `front/config.php`**
  `Config::getAll/initDefaults` via `Config::setConfigurationValues/getConfigurationValue('plugin:mafasa',...)`; form com título, rodapé, upload logo (salva em `files/_plugins/mafasa/` ou `public/img/`); só `Session::haveRight('config', UPDATE)`.
- [ ] **Step 2: Escrever `README_GLPI.md`**
  Compat, instalação (copiar para `plugins/` ou `marketplace/` → Instalar → Ativar), uso (menu → filtros → gerar → desmarcar → imprimir), limites v1.
- [ ] **Step 3: Diagnóstico final**
Run: `php -l` nos 8 PHP + `lsp_diagnostics` na pasta Mafasa
Expected: zero erros novos.
- [ ] **Step 4: Git init + push main**
```bash
git init; git add .; git commit -m "feat: plugin mafasa relatorios de ativos v1"; git branch -M main; git remote add origin https://github.com/poiattileo/Mafasa.git; git push -u origin main
```
Expected: push ok sem vazar credencial no log.
