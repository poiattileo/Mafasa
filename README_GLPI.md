# Mafasa — Relatórios de Ativos (README GLPI)

Plugin GLPI para relatórios de ativos por categoria, com filtros totais,
colunas desmarcáveis e impressão/PDF com cabeçalho institucional (URE Jales).

## Compatibilidade

- GLPI **12.0.0 – 12.0.99**
- PHP **>= 8.2**
- MariaDB/MySQL (o que o GLPI usar)

## Instalação

1. Copie a pasta `Mafasa` para `plugins/` **ou** `marketplace/` do GLPI
   (o plugin resolve os dois caminhos sozinho).
2. No GLPI, acesse **Configurar → Plugins**.
3. Clique em **Instalar** e depois em **Ativar** no plugin
   **"Mafasa - Relatórios de Ativos"**.
4. (Opcional) Ajuste título/rodapé/logo em **Configurar → Plugins → Mafasa**
   (ícone de configuração → `front/config.php`, requer direito `config` UPDATE).

## Uso

1. Acesse **Ferramentas → Mafasa** (ou o submenu da categoria:
   Computadores, Monitores, Impressoras, Redes, Periféricos, Telefones,
   Softwares, Racks, etc.).
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
