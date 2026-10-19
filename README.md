# [URE] Mafasa

Plugin GLPI (compat. 11.0.0–12.0.99, PHP >= 8.2) para relatórios de ativos,
chamados do GLPI e quadros do Kanpro, com filtros totais, colunas desmarcáveis
e impressão/PDF com cabeçalho institucional (URE Jales).

- **Versão:** 1.2.0
- **Autor:** Leonardo Poiatti Fação
- **Licença:** GPLv2+
- **Repositório:** https://github.com/poiattileo/Mafasa

## Compatibilidade

- GLPI **11.0.0 – 12.0.99**
- PHP **>= 8.2**
- MariaDB/MySQL (o que o GLPI usar)

## Instalação

1. Copie a pasta `Mafasa` para `plugins/` **ou** `marketplace/` do GLPI
   (o plugin resolve os dois caminhos sozinho).
2. No GLPI, acesse **Configurar → Plugins**.
3. Clique em **Instalar** e depois em **Ativar** no plugin **"[URE] Mafasa"**.
4. (Opcional) Ajuste título/rodapé/logo em **Configurar → Plugins → [URE] Mafasa**
   (ícone de configuração → `front/config.php`, requer direito `config` UPDATE).

## Uso

1. Acesse **Ferramentas → [URE] Mafasa** (ou o submenu da categoria:
   Computadores, Monitores, Impressoras, Redes, Periféricos, Telefones,
   Softwares, Racks, etc.). As opções **Chamados GLPI** e **Quadros Kanpro**
   aparecem quando o usuário tem a permissão correspondente.
2. Preencha os **filtros** (Identificação, Localização/Responsáveis, Datas).
3. Clique em **Gerar** — a tabela pagina de 50 em 50.
4. No painel **Colunas**, **desmarque** o que não quiser
   (a tabela atualiza na hora; o impresso obedece às mesmas colunas).
   Botões: Marcar tudo / Limpar / Padrão.
5. Clique em **Imprimir** — abre `front/print.php` com cabeçalho
   (logo URE Jales, título, data/hora, usuário, entidade, filtros, total)
   e botão **Imprimir / Salvar PDF** (`window.print()`).
6. **Copiar link** copia a URL com filtros + `cols[]` (GET compartilhável).

Cabeçalho/rodapé/logo podem ser ajustados na tela de configuração
(`header_title`, `footer_text`, `logo_path` em `glpi_configs`,
contexto `plugin:mafasa`).

## Relatórios disponíveis

- **Ativos** — relatórios por categoria de ativo (Computadores, Monitores,
  Impressoras, Redes, Periféricos, Telefones, Softwares, Racks, etc.) com
  filtros totais e colunas configuráveis.
- **Chamados GLPI** — contagens por status, prioridade, tipo, categoria ITIL,
  técnico/grupo, entidade e período, com KPIs, evolução mensal e lista
  detalhada opcional.
- **Quadros Kanpro** — total de cards/chamados por quadro, quebra por
  categoria (`list_type`) e cards de cada categoria em cada quadro, com
  detalhamento opcional.

## Estrutura

```
mafasa.xml, setup.php, hook.php, composer.json
src/compat.php, src/Install.php, src/Report.php, src/Config.php
src/TicketReport.php, src/BoardReport.php, src/PrintSupport.php
front/report.php, front/print.php, front/config.php
front/chamados.php, front/quadros.php
install/mysql/plugin_mafasa_empty.sql
public/css/mafasa.css, public/css/mafasa-chamados.css
public/css/mafasa-quadros.css, public/css/mafasa-print.css
public/js/app.js, public/js/print-init.js
public/js/paged.polyfill.min.js, public/js/pagedjs.LICENSE.txt
public/img/logo-mafasa.png, public/img/logo-ure-jales.png
```

## Limites da v1

- **Sem gráficos/dashboard.**
- **Sem modelos salvos** de relatório, sem agendamento, sem envio por e-mail.
- **Impressão limitada a 5000 itens** (mostra aviso se estourar —
  refine os filtros para ver o restante).
- **Só campos diretos** da tabela da categoria + dropdowns diretos
  (sem joins profundos: sem softwares instalados dentro de Computer,
  sem portas, reservas, documentos ou notas).
- Direitos = `Itemtype::canView()` nativo da categoria + entidade ativa;
  `is_deleted=1` e `is_template=1` sempre excluídos.
- PDF v1 = impressão do navegador (Imprimir → Salvar PDF), sem lib externa.
- Numeração de páginas via Paged.js vendorizado localmente (sem CDN).

## Status

Plugin em uso — versão 1.2.0 (relatórios de ativos, chamados GLPI e
quadros Kanpro). Detalhes de design em `docs/superpowers/`.
