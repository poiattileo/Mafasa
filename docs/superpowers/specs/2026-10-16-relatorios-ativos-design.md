# Mafasa — Relatórios de Ativos (Design aprovado)

Data: 2026-10-16
Status: aprovado pelo solicitante ("pode construir, já aprovo sua ideia")
Repo destino: https://github.com/poiattileo/Mafasa.git (pasta `C:\Users\Administrador\Desktop\Projetos\Mafasa`)
Referência de padrão: `C:\Users\Administrador\Desktop\Projetos\Protocolo` (plugin GLPI 11→12)

## 1. Intenção e porquê

O relatório nativo do GLPI é genérico e feio para apresentar. O solicitante precisa provar inventário, prestar contas e entregar para chefia um documento com cara oficial.

O plugin Mafasa é um plugin GLPI 12 (compat 11→12) só de relatórios de ativos: o usuário escolhe a categoria de ativo num menu, marca todos os filtros que precisa, aperta Gerar, vê a tabela e depois limpa as colunas desmarcando o que não quer, e manda para impressão ou PDF bonito com cabeçalho institucional (logo URE Jales).

Palavra do solicitante que guia tudo: "filtrar tudo", "tirando colunas depois de gerar", "bonito e apresentável", "menu para selecionar categoria", "só a logo por enquanto" no cabeçalho.

## 2. Objetivos (goals)

1. Menu por categoria cobrindo todos os tipos de ativo (v1: Computer, Monitor, Printer, NetworkEquipment, Peripheral, Phone, Software, Rack, Enclosure, PDU; extensível para demais via registro).
2. Tela única de relatório por categoria com todos os filtros possíveis daquele tipo (comuns + específicos), combinados com E lógico.
3. Fluxo: escolhe categoria → preenche filtros → Gerar → tabela + painel de colunas disponíveis já marcadas → desmarca → tabela atualiza na hora → Imprimir / Salvar PDF.
4. Saída tela + impressão + PDF com o mesmo visual: cabeçalho com logo URE Jales, título, categoria, data/hora, quem gerou, entidade ativa, total de itens; tabela limpa com zebra, respiro, cabeçalho azul; rodapé com paginação e total.
5. Respeitar permissões e entidade ativa do GLPI (só mostra o que o usuário pode ver).
6. Mesmo esqueleto do Protocolo para não quebrar no GLPI 12: setup.php, hook.php, xml, composer PSR-4, src/compat.php, front com `inc/includes.php`, install com empty.sql, public com css/js, sem `?v=` manual.

## 3. Não-objetivos (non-goals v1)

- Sem gráficos/dashboard v1.
- Sem modelos salvos de relatório, sem agendamento, sem envio por e-mail.
- Sem edição de ativos (só leitura).
- Sem biblioteca PDF pesada (TCPDF/Dompdf/mPDF) na v1 — usa impressão do navegador (Imprimir → Salvar PDF), que já é bonito se o CSS de print for bem feito.
- Sem export XLSX v1 (pode entrar CSV simples se sobrar tempo, mas não é prometido).

## 4. Anti-objetivos (o que seria fracasso mesmo funcionando)

- Um relatório gigante misturando todos os tipos numa tabela só confusa.
- Tela de filtros tão cheia que trava ou ninguém entende.
- PDF que quebra por depender de lib externa ou caminho de logo solto em Downloads.
- Duplicar menu em Ferramentas + Plug-ins ou quebrar CSRF/entidade no GLPI 12.
- Suprimir erro de tipo com `as any`, `@ts-ignore` — proibido (vale para PHP: sem `@` para esconder erro, sem gambiarra).

## 5. Restrições

- GLPI min 11.0.0, max 12.0.99; PHP >= 8.2; MariaDB/MySQL.
- GLPI 12 removeu `Plugin::getWebDir()`, `Plugin::getPhpDir()`, CSRF via `Session::getNewCSRFToken/checkCSRF` virou no-op (validado pelo CheckCsrfListener). Copiar `src/compat.php` do Protocolo adaptando prefixo `mafasa_` (`mafasa_web_dir`, `mafasa_php_dir`, `mafasa_csrf_token`, `mafasa_is_glpi12_plus`).
- Caminhos devem resolver `plugins/` ou `marketplace/` sem duplicar `root_doc` (mesma lógica do Protocolo).
- `menu_toadd` só em `tools`, `menu_entry` para `front/report.php`, `submenu_entry` por categoria, `config_page` para `front/config.php`.
- Direitos: usar `Itemtype::canView()` do próprio ativo + aba em Perfis se precisar (espelhar `Profile.php` do Protocolo de forma mínima).
- Logo: copiar `C:\Users\Administrador\Downloads\logo nova.png` para `public/img/logo-ure-jales.png` dentro do plugin e referenciar local (nunca caminho absoluto da máquina).
- Identidade plugin: tag `mafasa`, namespace `GlpiPlugin\Mafasa`, nome "Mafasa - Relatórios de Ativos", versão 1.0.0, autor Leonardo Poiatti Fação, licença GPLv2+.

## 6. Como fica (decisões do solicitante)

- Menu: Ferramentas → Mafasa; submenu/abas por categoria (Computadores, Monitores, Impressoras, Redes, Periféricos, Telefones, Softwares, Racks, etc.).
- Filtros: incluir todos que der por categoria. Implementação: levantar via `Search::getOptions($itemtype)` + lista manual de comuns (nome, ID, status, entidade, localização, grupo, fabricante, modelo, tipo, nº série, nº inventário, usuário, técnico responsável, datas criação/alteração, lixeira) e específicos (ex.: SO, processador, memória para Computer). Filtros como GET (link compartilhável), com seções colapsáveis.
- Colunas: após Gerar, painel lateral/listagem com todas as colunas disponíveis marcadas; usuário desmarca; tabela, impressão e PDF obedecem. Botões marcar tudo / limpar / restaurar padrão.
- Cabeçalho impresso: logo à esquerda, à direita título "Relatório de Ativos — {Categoria}", data/hora, usuário, entidade, filtros aplicados (resumo), total de itens. Linha azul fina abaixo. Cores base azul (#1a73b5 / #4f46e5) + cinza (#6b7280) da logo.
- Ações: Imprimir / Salvar PDF (window.print com CSS `@media print`), Limpar filtros, Copiar link.
- Config (`front/config.php`): texto de cabeçalho/rodapé opcional, troca de logo (upload), valor padrão de colunas por categoria. Guardado via `Config::setConfigurationValues('plugin:mafasa', ...)`.

## 7. Estrutura de arquivos (espelho Protocolo)

```
mafasa.xml, setup.php, hook.php, composer.json
src/compat.php, src/Install.php, src/Report.php, src/Config.php, src/Profile.php
front/report.php, front/print.php, front/config.php
ajax/columns.php (opcional p/ atualizar colunas sem reload)
install/mysql/plugin_mafasa_empty.sql (mínimo: sem tabelas de ativo; só config/rights se precisar)
public/css/mafasa.css, public/css/mafasa-print.css, public/js/app.js, public/img/logo-ure-jales.png
tools/, .gitignore, README.md, README_GLPI.md
```

## 8. O que fica para o builder decidir (chamadas minhas)

- Ordem exata das categorias no menu e quais tipos entram na v1 além dos 10 listados (se descobrir Cartridge/Consumable/Unmanaged fáceis, pode incluir sem perguntar).
- Subconjunto padrão de colunas marcadas por tipo (o comum resolve 80%).
- Detalhe do CSS (prefixo `mf-*`) desde que mantenha zebra, respiro e print limpo.
- Se usa `Search` do GLPI por baixo ou SQL direto com `$DB->request()` (preferir `Search`/`$DB->request` com `quoteValue`, nunca concatenar sem escapar).
- Se precisa de tabela própria para prefs de colunas por usuário ou só GET + sessão.

## 9. Critérios de aceite

- Instala sem erro no GLPI 11 e 12, aparece em Ferramentas, respeita entidade e perfil.
- Para cada categoria: filtra, gera, desmarca coluna e some da tela e do impresso, imprime/PDF com logo e total.
- `lsp_diagnostics` limpo nos arquivos alterados; sem erro PHP no log ao gerar.
- Git init + push para `https://github.com/poiattileo/Mafasa.git` na branch main.

## 10. Chamadas feitas por mim (para conferir na leitura)

- Plugin novo do zero na pasta Mafasa, não fork do Protocolo (só copia padrão).
- PDF v1 = print CSS do navegador, sem lib externa.
- Logo embutida no plugin, não caminho de Downloads.
- Filtros via GET compartilhável; colunas via checkboxes pós-Gerar.

## 11. Respostas ao builder-check (minha decisão, sem nova pergunta — solicitante já aprovou construir)

1. Ambiente: referência Protocolo legível no disco, logo em Downloads, repo Mafasa com push. Sem GLPI live neste ambiente — construir espelhando Protocolo + `php -l` + diagnostics. (já respondido pelo projeto)
2. Direitos: só `Itemtype::canView()` nativo por categoria + entidade ativa. Sem right próprio v1. `Profile.php` vira stub ou nem entra. (minha decisão)
3. Volume: tela pagina 50/página com ordenação; impressão/PDF usa `front/print.php` com todos os filtrados (sem paginação, com limite seguro 5000 + aviso). "Copiar link" carrega filtros + colunas. (minha decisão)
4. Escopo "todos": todos os campos diretos + dropdowns diretos via `Search::getOptions()` filtrado (sem joins profundos v1: sem softwares instalados dentro de Computer, sem portas, sem reservas/documentos/notas). Financeiro/garantia só se for campo direto. (minha decisão)
5. Padrões: respeita entidade ativa (sem override), recursão segue GLPI, exclui `is_deleted=1` e `is_template=1` por padrão, status sem filtro por padrão (= todos). Total conta só o filtrado não-deletado. (minha decisão)
6. Uma linha = um item da tabela principal da categoria (ex.: `glpi_computers`, `glpi_softwares` com coluna qtd instalações, `glpi_racks`). Sem explodir instalação/versão em linha v1. (minha decisão)
7. `print.php` é endpoint dedicado de impressão: recebe mesmos GET (filtros+colunas), re-checa login/`canView`/entidade, imprime tudo com `mafasa-print.css` + botão Imprimir. (minha decisão)
