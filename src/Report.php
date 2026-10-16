<?php
/**
 * Registro de categorias, filtros e colunas dos relatórios de ativos.
 *
 * v1: só campos diretos da tabela principal da categoria (sem joins
 * profundos: sem softwares instalados dentro de Computer, sem portas,
 * sem reservas/documentos/notas). Listas refináveis via
 * Search::getOptions($itemtype) contra um GLPI live na Task 3.
 */

namespace GlpiPlugin\Mafasa;

class Report extends \CommonGLPI
{
    public const DEFAULT_TYPE = 'Computer';

    /** Página da tela (Task 3) e teto do impresso (Task 4). */
    public const PAGE_SIZE = 50;
    public const PRINT_LIMIT = 5000;

    /**
     * @return array<string,string> itemtype GLPI => rótulo PT-BR
     */
    public static function getCategories(): array
    {
        return [
            'Computer'         => 'Computadores',
            'Monitor'          => 'Monitores',
            'Printer'          => 'Impressoras',
            'NetworkEquipment' => 'Equipamentos de rede',
            'Peripheral'       => 'Periféricos',
            'Phone'            => 'Telefones',
            'Software'         => 'Softwares',
            'Rack'             => 'Racks',
            'Enclosure'        => 'Enclosures',
            'PDU'              => 'PDUs',
        ];
    }

    /**
     * Normaliza o tipo vindo do GET: case-insensitive, inválido cai
     * para Computer (nunca fatal).
     */
    public static function normalizeType(string $type): string
    {
        $type = trim($type);
        if ($type === '') {
            return self::DEFAULT_TYPE;
        }
        $cats = self::getCategories();
        if (isset($cats[$type])) {
            return $type;
        }
        foreach ($cats as $itemtype => $label) {
            if (strcasecmp($itemtype, $type) === 0) {
                return $itemtype;
            }
        }
        return self::DEFAULT_TYPE;
    }

    public static function getLabel(string $type): string
    {
        $type = self::normalizeType($type);
        return self::getCategories()[$type];
    }

    /**
     * Tabela principal da categoria (uma linha = um item, spec §11.6).
     */
    public static function getTable(string $type): string
    {
        $type = self::normalizeType($type);
        $map = [
            'Computer'         => 'glpi_computers',
            'Monitor'          => 'glpi_monitors',
            'Printer'          => 'glpi_printers',
            'NetworkEquipment' => 'glpi_networkequipments',
            'Peripheral'       => 'glpi_peripherals',
            'Phone'            => 'glpi_phones',
            'Software'         => 'glpi_softwares',
            'Rack'             => 'glpi_racks',
            'Enclosure'        => 'glpi_enclosures',
            'PDU'              => 'glpi_pdus',
        ];
        return $map[$type];
    }

    /**
     * Coluna real do dropdown "Modelo" por tipo (null quando o tipo
     * não tem tabela de modelos — ex. Software).
     */
    public static function getModelColumn(string $type): ?string
    {
        $type = self::normalizeType($type);
        $map = [
            'Computer'         => 'computermodels_id',
            'Monitor'          => 'monitormodels_id',
            'Printer'          => 'printermodels_id',
            'NetworkEquipment' => 'networkequipmentmodels_id',
            'Peripheral'       => 'peripheralmodels_id',
            'Phone'            => 'phonemodels_id',
            'Software'         => null,
            'Rack'             => 'rackmodels_id',
            'Enclosure'        => 'enclosuremodels_id',
            'PDU'              => 'pdumodels_id',
        ];
        return $map[$type];
    }

    /**
     * Campos que NÃO existem como coluna direta em cada tipo e por
     * isso saem dos filtros/colunas (spec §11.4: só campos diretos).
     *
     * @return array<int,string>
     */
    private static function excludedFields(string $type): array
    {
        switch (self::normalizeType($type)) {
            case 'Software':
                // Software não tem status nem tabela de modelos.
                return ['status', 'models', 'groups_id_tech'];
            case 'Rack':
            case 'Enclosure':
            case 'PDU':
                // Itens de datacenter não têm usuário/técnico diretos.
                return ['users_id', 'groups_id_tech'];
            default:
                return [];
        }
    }

    /**
     * Definição dos filtros comuns. Chave = nome amigável usado no
     * GET como f_<chave>; 'column' = coluna real no banco.
     *
     * kind: text (LIKE) | int (= com cast) | bool (= 0/1) | date
     * op: like | eq | gte | lte
     */
    private static function commonFilterDefs(?string $modelColumn): array
    {
        $defs = [
            'name'             => ['label' => 'Nome', 'kind' => 'text', 'column' => 'name', 'op' => 'like'],
            'status'           => ['label' => 'Status', 'kind' => 'int', 'column' => 'states_id', 'op' => 'eq'],
            'entities_id'      => ['label' => 'Entidade', 'kind' => 'int', 'column' => 'entities_id', 'op' => 'eq'],
            'locations_id'     => ['label' => 'Localização', 'kind' => 'int', 'column' => 'locations_id', 'op' => 'eq'],
            'groups_id'        => ['label' => 'Grupo', 'kind' => 'int', 'column' => 'groups_id', 'op' => 'eq'],
            'manufacturers_id' => ['label' => 'Fabricante', 'kind' => 'int', 'column' => 'manufacturers_id', 'op' => 'eq'],
            'serial'           => ['label' => 'Nº de série', 'kind' => 'text', 'column' => 'serial', 'op' => 'like'],
            'otherserial'      => ['label' => 'Nº de inventário / Tombo', 'kind' => 'text', 'column' => 'otherserial', 'op' => 'like'],
            'users_id'         => ['label' => 'Usuário', 'kind' => 'int', 'column' => 'users_id', 'op' => 'eq'],
            'groups_id_tech'   => ['label' => 'Técnico responsável (grupo)', 'kind' => 'int', 'column' => 'groups_id_tech', 'op' => 'eq'],
            'date_creation_from' => ['label' => 'Criado em (de)', 'kind' => 'date', 'column' => 'date_creation', 'op' => 'gte'],
            'date_creation_to'   => ['label' => 'Criado em (até)', 'kind' => 'date', 'column' => 'date_creation', 'op' => 'lte'],
            'date_mod_from'      => ['label' => 'Modificado em (de)', 'kind' => 'date', 'column' => 'date_mod', 'op' => 'gte'],
            'date_mod_to'        => ['label' => 'Modificado em (até)', 'kind' => 'date', 'column' => 'date_mod', 'op' => 'lte'],
        ];
        if ($modelColumn !== null) {
            // Inserido após Fabricante para manter a ordem da tela.
            $defs = array_merge(
                array_slice($defs, 0, 6, true),
                ['models' => ['label' => 'Modelo', 'kind' => 'int', 'column' => $modelColumn, 'op' => 'eq']],
                array_slice($defs, 6, null, true)
            );
        }
        return $defs;
    }

    /**
     * 3–8 filtros específicos por tipo (só colunas diretas, v1).
     */
    private static function specificFilterDefs(string $type): array
    {
        switch (self::normalizeType($type)) {
            case 'Computer':
                return [
                    'computertypes_id'    => ['label' => 'Tipo', 'kind' => 'int', 'column' => 'computertypes_id', 'op' => 'eq'],
                    'operatingsystems_id' => ['label' => 'Sistema operacional', 'kind' => 'int', 'column' => 'operatingsystems_id', 'op' => 'eq'],
                    'networks_id'         => ['label' => 'Rede', 'kind' => 'int', 'column' => 'networks_id', 'op' => 'eq'],
                    'domains_id'          => ['label' => 'Domínio', 'kind' => 'int', 'column' => 'domains_id', 'op' => 'eq'],
                    'uuid'                => ['label' => 'UUID', 'kind' => 'text', 'column' => 'uuid', 'op' => 'like'],
                    'contact'             => ['label' => 'Contato', 'kind' => 'text', 'column' => 'contact', 'op' => 'like'],
                ];
            case 'Monitor':
                return [
                    'monitortypes_id' => ['label' => 'Tipo', 'kind' => 'int', 'column' => 'monitortypes_id', 'op' => 'eq'],
                    'size'            => ['label' => 'Tamanho (pol)', 'kind' => 'text', 'column' => 'size', 'op' => 'like'],
                    'have_micro'      => ['label' => 'Microfone', 'kind' => 'bool', 'column' => 'have_micro', 'op' => 'eq'],
                    'have_speaker'    => ['label' => 'Alto-falante', 'kind' => 'bool', 'column' => 'have_speaker', 'op' => 'eq'],
                    'have_hdmi'       => ['label' => 'HDMI', 'kind' => 'bool', 'column' => 'have_hdmi', 'op' => 'eq'],
                ];
            case 'Printer':
                return [
                    'printertypes_id' => ['label' => 'Tipo', 'kind' => 'int', 'column' => 'printertypes_id', 'op' => 'eq'],
                    'memory_size'     => ['label' => 'Memória', 'kind' => 'text', 'column' => 'memory_size', 'op' => 'like'],
                    'have_usb'        => ['label' => 'USB', 'kind' => 'bool', 'column' => 'have_usb', 'op' => 'eq'],
                    'have_ethernet'   => ['label' => 'Rede cabeada', 'kind' => 'bool', 'column' => 'have_ethernet', 'op' => 'eq'],
                    'have_wifi'       => ['label' => 'Wi-Fi', 'kind' => 'bool', 'column' => 'have_wifi', 'op' => 'eq'],
                ];
            case 'NetworkEquipment':
                return [
                    'networkequipmenttypes_id' => ['label' => 'Tipo', 'kind' => 'int', 'column' => 'networkequipmenttypes_id', 'op' => 'eq'],
                    'networks_id'              => ['label' => 'Rede', 'kind' => 'int', 'column' => 'networks_id', 'op' => 'eq'],
                    'domains_id'               => ['label' => 'Domínio', 'kind' => 'int', 'column' => 'domains_id', 'op' => 'eq'],
                    'ram'                      => ['label' => 'Memória RAM', 'kind' => 'text', 'column' => 'ram', 'op' => 'like'],
                    'comment'                  => ['label' => 'Observação', 'kind' => 'text', 'column' => 'comment', 'op' => 'like'],
                ];
            case 'Peripheral':
                return [
                    'peripheraltypes_id' => ['label' => 'Tipo', 'kind' => 'int', 'column' => 'peripheraltypes_id', 'op' => 'eq'],
                    'contact'            => ['label' => 'Contato', 'kind' => 'text', 'column' => 'contact', 'op' => 'like'],
                    'contact_num'        => ['label' => 'Telefone de contato', 'kind' => 'text', 'column' => 'contact_num', 'op' => 'like'],
                    'comment'            => ['label' => 'Observação', 'kind' => 'text', 'column' => 'comment', 'op' => 'like'],
                ];
            case 'Phone':
                return [
                    'phonetypes_id' => ['label' => 'Tipo', 'kind' => 'int', 'column' => 'phonetypes_id', 'op' => 'eq'],
                    'have_headset'  => ['label' => 'Headset', 'kind' => 'bool', 'column' => 'have_headset', 'op' => 'eq'],
                    'have_hp'       => ['label' => 'Viva-voz', 'kind' => 'bool', 'column' => 'have_hp', 'op' => 'eq'],
                    'have_2lines'   => ['label' => '2 linhas', 'kind' => 'bool', 'column' => 'have_2lines', 'op' => 'eq'],
                    'have_gsm'      => ['label' => 'GSM', 'kind' => 'bool', 'column' => 'have_gsm', 'op' => 'eq'],
                ];
            case 'Software':
                return [
                    'softwarecategories_id' => ['label' => 'Categoria', 'kind' => 'int', 'column' => 'softwarecategories_id', 'op' => 'eq'],
                    'is_recursive'          => ['label' => 'Recursivo', 'kind' => 'bool', 'column' => 'is_recursive', 'op' => 'eq'],
                    'comment'               => ['label' => 'Observação', 'kind' => 'text', 'column' => 'comment', 'op' => 'like'],
                ];
            case 'Rack':
                return [
                    'racktypes_id' => ['label' => 'Tipo', 'kind' => 'int', 'column' => 'racktypes_id', 'op' => 'eq'],
                    'dcrooms_id'   => ['label' => 'Sala (datacenter)', 'kind' => 'int', 'column' => 'dcrooms_id', 'op' => 'eq'],
                    'is_recursive' => ['label' => 'Recursivo', 'kind' => 'bool', 'column' => 'is_recursive', 'op' => 'eq'],
                    'comment'      => ['label' => 'Observação', 'kind' => 'text', 'column' => 'comment', 'op' => 'like'],
                ];
            case 'Enclosure':
                return [
                    'contact'      => ['label' => 'Contato', 'kind' => 'text', 'column' => 'contact', 'op' => 'like'],
                    'contact_num'  => ['label' => 'Telefone de contato', 'kind' => 'text', 'column' => 'contact_num', 'op' => 'like'],
                    'is_recursive' => ['label' => 'Recursivo', 'kind' => 'bool', 'column' => 'is_recursive', 'op' => 'eq'],
                    'comment'      => ['label' => 'Observação', 'kind' => 'text', 'column' => 'comment', 'op' => 'like'],
                ];
            case 'PDU':
                return [
                    'pdutypes_id'  => ['label' => 'Tipo', 'kind' => 'int', 'column' => 'pdutypes_id', 'op' => 'eq'],
                    'plugtypes_id' => ['label' => 'Tipo de plug', 'kind' => 'int', 'column' => 'plugtypes_id', 'op' => 'eq'],
                    'is_recursive' => ['label' => 'Recursivo', 'kind' => 'bool', 'column' => 'is_recursive', 'op' => 'eq'],
                    'comment'      => ['label' => 'Observação', 'kind' => 'text', 'column' => 'comment', 'op' => 'like'],
                ];
            default:
                return [];
        }
    }

    /**
     * Filtros do tipo (comuns + específicos), já sem os campos
     * excluídos. Tipo inválido cai para Computer.
     *
     * @return array<string,array{label:string,kind:string,column:string,op:string}>
     */
    public static function getFilters(string $type): array
    {
        $type = self::normalizeType($type);
        $defs = array_merge(
            self::commonFilterDefs(self::getModelColumn($type)),
            self::specificFilterDefs($type)
        );
        foreach (self::excludedFields($type) as $ex) {
            unset($defs[$ex]);
        }
        return $defs;
    }

    /**
     * Colunas disponíveis do tipo: chave amigável => rótulo PT-BR.
     * Inclui 'id' primeiro. Usa as mesmas chaves dos filtros mais
     * 'id', 'date_creation' e 'date_mod' (colunas, não filtros).
     *
     * @return array<string,string>
     */
    public static function getColumns(string $type): array
    {
        $type = self::normalizeType($type);
        $cols = ['id' => 'ID'];
        foreach (self::getFilters($type) as $field => $def) {
            // Filtros *_from/*_to colapsam numa coluna só.
            if (str_ends_with($field, '_from') || str_ends_with($field, '_to')) {
                continue;
            }
            $cols[$field] = $def['label'];
        }
        $cols['date_creation'] = 'Criado em';
        $cols['date_mod'] = 'Modificado em';
        return $cols;
    }

    /**
     * Coluna real no banco para uma chave amigável de filtro/coluna.
     */
    public static function getFieldColumn(string $type, string $field): string
    {
        $type = self::normalizeType($type);
        if ($field === 'id' || $field === 'date_creation' || $field === 'date_mod') {
            return $field;
        }
        $filters = self::getFilters($type);
        if (isset($filters[$field])) {
            return $filters[$field]['column'];
        }
        return $field;
    }

    /**
     * Subconjunto padrão (marcado) de colunas.
     *
     * @return array<int,string>
     */
    public static function getDefaultColumns(string $type): array
    {
        $type = self::normalizeType($type);
        $preferred = ['name', 'status', 'entities_id', 'locations_id', 'manufacturers_id', 'models', 'serial', 'otherserial'];
        $available = self::getColumns($type);
        $out = [];
        foreach ($preferred as $field) {
            if (isset($available[$field])) {
                $out[] = $field;
            }
        }
        return $out;
    }

    /**
     * Monta o WHERE (sem a palavra WHERE) combinando os filtros com
     * AND. Aceita chaves com ou sem prefixo 'f_'. Chaves
     * desconhecidas são ignoradas (anti-injeção via GET).
     *
     * Sempre inclui is_deleted=0 AND is_template=0.
     *
     * @param array<string,mixed> $params ex. $_GET
     * @return array{sql:string,params:array<string,mixed>} sql pronto p/
     *     "WHERE {$r['sql']}" e valores saneados p/ reexibir no form
     */
    public static function buildWhere(string $type, array $params): array
    {
        $type = self::normalizeType($type);
        $filters = self::getFilters($type);
        $clauses = ['`is_deleted` = 0', '`is_template` = 0'];
        $clean = [];

        global $DB;
        $quote = static function (string $value) use ($DB): string {
            if (isset($DB) && is_object($DB) && method_exists($DB, 'quoteValue')) {
                try {
                    return (string) $DB->quoteValue($value);
                } catch (\Throwable $e) {
                    // cai para o fallback manual abaixo
                }
            }
            return "'" . str_replace("'", "''", $value) . "'";
        };

        foreach ($filters as $field => $def) {
            $raw = $params['f_' . $field] ?? $params[$field] ?? null;
            if ($raw === null) {
                continue;
            }
            $kind = $def['kind'];
            $col = '`' . str_replace('`', '', $def['column']) . '`';

            if ($kind === 'int') {
                if ($raw === '' || $raw === null) {
                    continue;
                }
                $id = (int) $raw;
                if ($id <= 0) {
                    continue;
                }
                $clauses[] = $col . ' = ' . $id;
                $clean[$field] = $id;
            } elseif ($kind === 'bool') {
                $s = trim((string) $raw);
                if ($s !== '0' && $s !== '1') {
                    continue;
                }
                $clauses[] = $col . ' = ' . (int) $s;
                $clean[$field] = (int) $s;
            } elseif ($kind === 'date') {
                $s = trim((string) $raw);
                if ($s === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
                    continue;
                }
                $s = substr($s, 0, 10);
                $parts = explode('-', $s);
                if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                    continue;
                }
                $cmp = (($def['op'] ?? 'gte') === 'lte') ? '<=' : '>=';
                $clauses[] = $col . ' ' . $cmp . ' ' . $quote($s);
                $clean[$field] = $s;
            } else {
                // text: LIKE com valor escapado via quoteValue.
                $s = trim((string) $raw);
                if ($s === '') {
                    continue;
                }
                if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                    if (mb_strlen($s) > 255) {
                        $s = mb_substr($s, 0, 255);
                    }
                } elseif (strlen($s) > 255) {
                    $s = substr($s, 0, 255);
                }
                $clauses[] = $col . ' LIKE ' . $quote('%' . $s . '%');
                $clean[$field] = $s;
            }
        }

        return ['sql' => implode(' AND ', $clauses), 'params' => $clean];
    }

    // ---- Integração com o menu Ferramentas (menu_toadd) ----
    // Precisa estender CommonGLPI: o GLPI só exibe no menu classes
    // registradas que herdam dele.

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Relatórios de Ativos' : 'Relatório de Ativos';
    }

    public static function getIcon()
    {
        return 'ti ti-report-analytics';
    }

    /**
     * Ícones Tabler por categoria (usados no submenu do menu).
     *
     * @return array<string,string>
     */
    public static function getCategoryIcons(): array
    {
        return [
            'Computer'         => 'ti ti-device-laptop',
            'Monitor'          => 'ti ti-device-desktop',
            'Printer'          => 'ti ti-printer',
            'NetworkEquipment' => 'ti ti-network',
            'Peripheral'       => 'ti ti-usb',
            'Phone'            => 'ti ti-phone',
            'Software'         => 'ti ti-apps',
            'Rack'             => 'ti ti-server',
            'Enclosure'        => 'ti ti-box',
            'PDU'              => 'ti ti-bolt',
        ];
    }

    /**
     * Menu visível se o usuário pode ver ao menos uma categoria de ativo.
     */
    public static function canView(): bool
    {
        foreach (self::getCategories() as $itemtype => $label) {
            if (!class_exists($itemtype) || !is_callable([$itemtype, 'canView'])) {
                continue;
            }
            try {
                if ($itemtype::canView()) {
                    return true;
                }
            } catch (\Throwable $e) {
            }
        }
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Conteúdo do menu Ferramentas → Mafasa.
     *
     * Estrutura moderna do GLPI 11/12: 'options' vira o submenu — os
     * hooks menu_entry/submenu_entry do GLPI 10 foram removidos do núcleo.
     * Espelha o getMenuContent() do plugin Protocolo, que funciona em produção.
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return [];
        }
        global $CFG_GLPI;
        $base = '/plugins/mafasa';
        if (function_exists('mafasa_web_dir')) {
            try {
                $base = (string) mafasa_web_dir('mafasa');
            } catch (\Throwable $e) {
                // mantém o fallback
            }
        }
        // getMenuContent espera caminho sem root_doc (GLPI prefixa sozinho)
        $root = (isset($CFG_GLPI['root_doc']) && is_string($CFG_GLPI['root_doc'])) ? $CFG_GLPI['root_doc'] : '';
        if ($root !== '' && str_starts_with($base, $root)) {
            $base = substr($base, strlen($root));
            if ($base === '' || $base[0] !== '/') {
                $base = '/' . ltrim($base, '/');
            }
        }

        $icons = self::getCategoryIcons();
        $menu = [
            'title' => self::getTypeName(),
            'page'  => $base . '/front/report.php',
            'icon'  => self::getIcon(),
        ];
        $menu['options']['report'] = [
            'title' => self::getTypeName(),
            'page'  => $base . '/front/report.php',
            'icon'  => self::getIcon(),
        ];
        foreach (self::getCategories() as $itemtype => $label) {
            $menu['options']['report_' . strtolower($itemtype)] = [
                'title' => $label,
                'page'  => $base . '/front/report.php?type=' . rawurlencode($itemtype),
                'icon'  => $icons[$itemtype] ?? 'ti ti-file-analytics',
            ];
        }
        return $menu;
    }
}
