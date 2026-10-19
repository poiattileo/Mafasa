<?php
/**
 * Relatórios de ativos — catálogo de tipos, filtros, colunas e busca (v2).
 *
 * API v2 (contrato congelado): getTypeCatalog, normalizeTypes, isCustomToken,
 * getDefinition, getTokenLabel, getFilters, getColumns, getColumnKind,
 * getDefaultColumns, buildSearch, describeFilterValue.
 *
 * Tipos personalizados (Glpi\CustomAsset\*) são tratados SEM carregar classes:
 * tudo por enumeração SQL + montagem de strings (contrato §2.2).
 *
 * Compat: GLPI 11.0.0–12.0.99, PHP >= 8.2. Somente SELECT.
 */

namespace GlpiPlugin\Mafasa;

class Report extends \CommonGLPI
{
    public const DEFAULT_TYPE = 'Computer';

    /** Página da tela e teto do impresso. */
    public const PAGE_SIZE = 50;
    public const PRINT_LIMIT = 5000;

    /**
     * Tokens usados na última chamada de getFilters()/getColumns().
     * Serve para resolver o nome de valores de filtros "models"/"subtypes"
     * em describeFilterValue(), cuja assinatura não recebe os tokens.
     *
     * @var array<int,string>
     */
    private static array $contextTokens = [];

    // ------------------------------------------------------------------
    // API legada v1 (mantida — contrato §3)
    // ------------------------------------------------------------------

    /**
     * @return array<string,string> itemtype GLPI => rótulo PT-BR (plural)
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
        if (self::isCustomToken($type)) {
            return self::getTokenLabel($type);
        }
        $type = self::normalizeType($type);
        $cats = self::getCategories();
        return $cats[$type] ?? $type;
    }

    /**
     * Tabela principal da categoria (uma linha = um item).
     */
    public static function getTable(string $type): string
    {
        if (self::isCustomToken($type)) {
            return 'glpi_assets_assets';
        }
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
     * Ícone do item (compat CommonGLPI) ou da categoria quando $type é dado.
     */
    public static function getIcon($type = '')
    {
        if (is_string($type) && $type !== '') {
            if (self::isCustomToken($type)) {
                $def = self::getDefinition($type);
                $icon = (is_array($def) && isset($def['icon'])) ? trim((string) $def['icon']) : '';
                return 'ti ' . ($icon !== '' ? $icon : 'ti-box');
            }
            $core = self::canonicalToken($type);
            if ($core !== null && !self::isCustomToken($core)) {
                $icons = self::getCategoryIcons();
                return $icons[$core] ?? 'ti ti-report-analytics';
            }
        }
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
     * Menu visível se pode ver ao menos um tipo core OU algum tipo
     * personalizado ativo (contrato §3).
     */
    public static function canView(): bool
    {
        foreach (array_keys(self::coreTypeMeta()) as $core) {
            if (self::isCoreViewable($core)) {
                return true;
            }
        }
        foreach (self::customDefinitions() as $row) {
            if (self::isCustomViewable((string) ($row['system_name'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getTypeName($nb = 0)
    {
        return '[URE] Mafasa';
    }

    /**
     * Conteúdo do menu Ferramentas → Mafasa (shape v1 preservado).
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
        // Relatórios de chamados (sempre; a tela valida Ticket::canView).
        $menu['options']['tickets'] = [
            'title' => 'Chamados',
            'page'  => $base . '/front/tickets.php',
            'icon'  => 'ti ti-ticket',
        ];
        // Relatórios de quadros Kanpro (apenas quando o Kanpro está instalado).
        if (BoardsReport::available() && BoardsReport::canView()) {
            $menu['options']['boards'] = [
                'title' => 'Quadros Kanpro',
                'page'  => $base . '/front/boards.php',
                'icon'  => 'ti ti-layout-kanban',
            ];
        }
        return $menu;
    }

    // ------------------------------------------------------------------
    // Tipos: metadados, catálogo e validação
    // ------------------------------------------------------------------

    /**
     * Metadados dos tipos core (contrato §2.1).
     *
     * @return array<string,array<string,mixed>>
     */
    private static function coreTypeMeta(): array
    {
        return [
            'Computer' => [
                'label' => 'Computador', 'table' => 'glpi_computers',
                'model_col' => 'computermodels_id', 'model_table' => 'glpi_computermodels', 'model_class' => 'ComputerModel',
                'type_col' => 'computertypes_id', 'type_table' => 'glpi_computertypes', 'type_class' => 'ComputerType',
                'has_status' => true, 'has_serial' => true, 'has_otherserial' => true,
            ],
            'Monitor' => [
                'label' => 'Monitor', 'table' => 'glpi_monitors',
                'model_col' => 'monitormodels_id', 'model_table' => 'glpi_monitormodels', 'model_class' => 'MonitorModel',
                'type_col' => 'monitortypes_id', 'type_table' => 'glpi_monitortypes', 'type_class' => 'MonitorType',
                'has_status' => true, 'has_serial' => true, 'has_otherserial' => true,
            ],
            'Printer' => [
                'label' => 'Impressora', 'table' => 'glpi_printers',
                'model_col' => 'printermodels_id', 'model_table' => 'glpi_printermodels', 'model_class' => 'PrinterModel',
                'type_col' => 'printertypes_id', 'type_table' => 'glpi_printertypes', 'type_class' => 'PrinterType',
                'has_status' => true, 'has_serial' => true, 'has_otherserial' => true,
            ],
            'Phone' => [
                'label' => 'Telefone', 'table' => 'glpi_phones',
                'model_col' => 'phonemodels_id', 'model_table' => 'glpi_phonemodels', 'model_class' => 'PhoneModel',
                'type_col' => 'phonetypes_id', 'type_table' => 'glpi_phonetypes', 'type_class' => 'PhoneType',
                'has_status' => true, 'has_serial' => true, 'has_otherserial' => true,
            ],
            'NetworkEquipment' => [
                'label' => 'Equipamento de rede', 'table' => 'glpi_networkequipments',
                'model_col' => 'networkequipmentmodels_id', 'model_table' => 'glpi_networkequipmentmodels', 'model_class' => 'NetworkEquipmentModel',
                'type_col' => 'networkequipmenttypes_id', 'type_table' => 'glpi_networkequipmenttypes', 'type_class' => 'NetworkEquipmentType',
                'has_status' => true, 'has_serial' => true, 'has_otherserial' => true,
            ],
            'Peripheral' => [
                'label' => 'Periférico', 'table' => 'glpi_peripherals',
                'model_col' => 'peripheralmodels_id', 'model_table' => 'glpi_peripheralmodels', 'model_class' => 'PeripheralModel',
                'type_col' => 'peripheraltypes_id', 'type_table' => 'glpi_peripheraltypes', 'type_class' => 'PeripheralType',
                'has_status' => true, 'has_serial' => true, 'has_otherserial' => true,
            ],
            'Software' => [
                'label' => 'Software', 'table' => 'glpi_softwares',
                'model_col' => null, 'model_table' => null, 'model_class' => null,
                'type_col' => null, 'type_table' => null, 'type_class' => null,
                'has_status' => false, 'has_serial' => false, 'has_otherserial' => false,
            ],
            'Rack' => [
                'label' => 'Rack', 'table' => 'glpi_racks',
                'model_col' => 'rackmodels_id', 'model_table' => 'glpi_rackmodels', 'model_class' => 'RackModel',
                'type_col' => 'racktypes_id', 'type_table' => 'glpi_racktypes', 'type_class' => 'RackType',
                'has_status' => true, 'has_serial' => true, 'has_otherserial' => true,
            ],
            'Enclosure' => [
                'label' => 'Gabinete', 'table' => 'glpi_enclosures',
                'model_col' => 'enclosuremodels_id', 'model_table' => 'glpi_enclosuremodels', 'model_class' => 'EnclosureModel',
                'type_col' => null, 'type_table' => null, 'type_class' => null,
                'has_status' => true, 'has_serial' => true, 'has_otherserial' => true,
            ],
            'PDU' => [
                'label' => 'PDU', 'table' => 'glpi_pdus',
                'model_col' => 'pdumodels_id', 'model_table' => 'glpi_pdumodels', 'model_class' => 'PDUModel',
                'type_col' => 'pdutypes_id', 'type_table' => 'glpi_pdutypes', 'type_class' => 'PDUType',
                'has_status' => true, 'has_serial' => true, 'has_otherserial' => true,
            ],
        ];
    }

    /**
     * Definições de ativo personalizado ativas (enumeração SQL). Sem
     * carregamento de classes.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function customDefinitions(): array
    {
        global $DB;
        $rows = [];
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'request')) {
            return $rows;
        }
        try {
            $it = $DB->request([
                'SELECT' => ['id', 'system_name', 'label', 'icon'],
                'FROM'   => 'glpi_assets_assetdefinitions',
                'WHERE'  => ['is_active' => 1],
                'ORDER'  => ['label ASC', 'id ASC'],
            ]);
            foreach ($it as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $rows;
    }

    /**
     * Definição ativa por id.
     *
     * @return array<string,mixed>|null
     */
    private static function getDefinitionById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'request')) {
            return null;
        }
        try {
            $it = $DB->request([
                'SELECT' => ['id', 'system_name', 'label', 'icon'],
                'FROM'   => 'glpi_assets_assetdefinitions',
                'WHERE'  => ['id' => $id, 'is_active' => 1],
                'LIMIT'  => 1,
            ]);
            foreach ($it as $row) {
                if (is_array($row)) {
                    return $row;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    /**
     * Lista todos os tipos (core + personalizados ativos).
     *
     * @return array<int,array{token:string,label:string,icon:string,custom:bool,viewable:bool,definition_id:?int,system_name:?string}>
     */
    public static function getTypeCatalog(): array
    {
        $out = [];
        foreach (self::coreTypeMeta() as $token => $meta) {
            $out[] = [
                'token'         => $token,
                'label'         => (string) $meta['label'],
                'icon'          => self::getIcon($token),
                'custom'        => false,
                'viewable'      => self::isCoreViewable($token),
                'definition_id' => null,
                'system_name'   => null,
            ];
        }
        foreach (self::customDefinitions() as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $system = (string) ($row['system_name'] ?? '');
            $icon = trim((string) ($row['icon'] ?? ''));
            $out[] = [
                'token'         => 'custom:' . $id,
                'label'         => self::customLabel($row),
                'icon'          => 'ti ' . ($icon !== '' ? $icon : 'ti-box'),
                'custom'        => true,
                'viewable'      => self::isCustomViewable($system),
                'definition_id' => $id,
                'system_name'   => $system,
            ];
        }
        return $out;
    }

    /**
     * Token no formato 'custom:<dígitos>'.
     */
    public static function isCustomToken(string $token): bool
    {
        return (bool) preg_match('/^custom:\d+$/', $token);
    }

    /**
     * Linha crua da definição personalizada (id, system_name, label, icon).
     * Somente definições ativas; null caso contrário.
     *
     * @return array<string,mixed>|null
     */
    public static function getDefinition(string $token): ?array
    {
        if (!self::isCustomToken($token)) {
            return null;
        }
        return self::getDefinitionById(self::definitionIdFromToken($token));
    }

    /**
     * Rótulo do token (core ou personalizado).
     */
    public static function getTokenLabel(string $token): string
    {
        if (self::isCustomToken($token)) {
            $def = self::getDefinition($token);
            return is_array($def) ? self::customLabel($def) : $token;
        }
        $core = self::canonicalToken($token);
        if ($core !== null) {
            $meta = self::coreTypeMeta();
            return (string) $meta[$core]['label'];
        }
        return $token;
    }

    /**
     * Normaliza a seleção de tipos vinda do GET.
     * Prioridade: types[] → type → ['Computer']. Valida existência e
     * permissão, deduplica preservando a ordem.
     *
     * @param array<string,mixed> $params
     * @return array<int,string>
     */
    public static function normalizeTypes(array $params): array
    {
        if (isset($params['types']) && is_array($params['types']) && $params['types'] !== []) {
            $candidates = array_values($params['types']);
        } elseif (isset($params['type']) && is_string($params['type']) && trim($params['type']) !== '') {
            $candidates = [trim($params['type'])];
        } else {
            $candidates = [self::DEFAULT_TYPE];
        }

        $out = [];
        foreach ($candidates as $cand) {
            if (!is_string($cand)) {
                continue;
            }
            $canonical = self::canonicalToken($cand);
            if ($canonical === null || !self::isCanonicalViewable($canonical)) {
                continue;
            }
            if (!in_array($canonical, $out, true)) {
                $out[] = $canonical;
            }
        }
        if ($out !== []) {
            return $out;
        }

        if (self::isCanonicalViewable(self::DEFAULT_TYPE)) {
            return [self::DEFAULT_TYPE];
        }
        foreach (array_keys(self::coreTypeMeta()) as $core) {
            if (self::isCoreViewable($core)) {
                return [$core];
            }
        }
        return [];
    }

    // ------------------------------------------------------------------
    // Filtros
    // ------------------------------------------------------------------

    /**
     * Filtros disponíveis para a seleção de tipos (contrato §4).
     *
     * @param array<int,string> $tokens
     * @return array<string,array{label:string,kind:string,dropdown:?string,choices:?array<int,string>}>
     */
    public static function getFilters(array $tokens): array
    {
        self::$contextTokens = array_values($tokens);
        $multi = count($tokens) > 1;
        $single = (count($tokens) === 1) ? (string) $tokens[0] : null;

        $out = [];
        $out['groups_id'] = self::filterDef('Grupo', 'dropdown', 'Group');
        $out['locations_id'] = self::filterDef('Localização', 'dropdown', 'Location');
        $out['status'] = self::filterDef('Status', 'dropdown', 'State');
        $out['manufacturers_id'] = self::filterDef('Fabricante', 'dropdown', 'Manufacturer');

        if (!$multi && $single !== null) {
            $model = self::modelFilterDef($single);
            if ($model !== null) {
                $out['models'] = $model;
            }
            $subtype = self::subtypeFilterDef($single);
            if ($subtype !== null) {
                $out['subtypes'] = $subtype;
            }
            if ($single === 'Computer') {
                $out['os'] = self::filterDef('Sistema operacional', 'dropdown', 'OperatingSystem');
            }
        }

        $out['name'] = self::filterDef('Nome', 'text');
        $out['serial'] = self::filterDef('Nº de série', 'text');
        $out['otherserial'] = self::filterDef('Nº de inventário', 'text');

        if (!$multi && $single !== null) {
            $out = array_merge($out, self::extraFiltersFor($single));
        }

        $out['date_creation_from'] = self::filterDef('Data de criação (de)', 'date');
        $out['date_creation_to'] = self::filterDef('Data de criação (até)', 'date');
        $out['date_mod_from'] = self::filterDef('Última modificação (de)', 'date');
        $out['date_mod_to'] = self::filterDef('Última modificação (até)', 'date');

        if (!$multi && $single !== null) {
            $out = self::applyFilterAvailability($out, $single);
        }
        return $out;
    }

    /**
     * Colunas disponíveis para a seleção, já ordenadas (contrato §5).
     *
     * @param array<int,string> $tokens
     * @return array<string,string>
     */
    public static function getColumns(array $tokens): array
    {
        self::$contextTokens = array_values($tokens);
        $multi = count($tokens) > 1;

        $out = [];
        $out['id'] = 'ID';
        if ($multi) {
            $out['type'] = 'Tipo de ativo';
        }
        $out['name'] = 'Nome';
        if (self::anyTokenSupports($tokens, 'status')) {
            $out['status'] = 'Status';
        }
        if (self::anyTokenSupports($tokens, 'manufacturers_id')) {
            $out['manufacturers_id'] = 'Fabricante';
        }
        if (self::anyTokenSupports($tokens, 'models')) {
            $out['models'] = 'Modelo';
        }
        if (self::anyTokenSupports($tokens, 'subtypes')) {
            $out['subtypes'] = 'Tipo';
        }
        if (self::anyTokenSupports($tokens, 'locations_id')) {
            $out['locations_id'] = 'Localização';
        }
        if (self::anyTokenSupports($tokens, 'groups_id')) {
            $out['groups_id'] = 'Grupo';
        }
        if (self::anyTokenSupports($tokens, 'serial')) {
            $out['serial'] = 'Nº de série';
        }
        if (self::anyTokenSupports($tokens, 'otherserial')) {
            $out['otherserial'] = 'Nº de inventário';
        }
        if (self::anyTokenSupports($tokens, 'users_id')) {
            $out['users_id'] = 'Usuário';
        }
        if (self::anyTokenSupports($tokens, 'users_id_tech')) {
            $out['users_id_tech'] = 'Técnico responsável';
        }
        if (self::anyTokenSupports($tokens, 'entities_id')) {
            $out['entities_id'] = 'Entidade';
        }
        if (self::anyTokenSupports($tokens, 'date_creation')) {
            $out['date_creation'] = 'Data de criação';
        }
        if (self::anyTokenSupports($tokens, 'date_mod')) {
            $out['date_mod'] = 'Última modificação';
        }

        foreach (self::extraColumnOrder($tokens) as $key => $label) {
            $out[$key] = $label;
        }
        return $out;
    }

    /**
     * Tipo de dado de uma coluna (contrato §5).
     */
    public static function getColumnKind(string $key): string
    {
        if ($key === 'date_creation' || $key === 'date_mod') {
            return 'date';
        }
        if ($key === 'id' || $key === 'memory_size' || $key === 'ram') {
            return 'int';
        }
        if (str_starts_with($key, 'have_') || $key === 'is_recursive') {
            return 'bool';
        }
        return 'text';
    }

    /**
     * Colunas marcadas por padrão (contrato §5).
     *
     * @param array<int,string> $tokens
     * @return array<int,string>
     */
    public static function getDefaultColumns(array $tokens): array
    {
        $columns = self::getColumns($tokens);
        $multi = count($tokens) > 1;
        $preferred = ['name', 'status', 'manufacturers_id', 'models', 'locations_id', 'serial', 'otherserial'];
        $out = [];
        if ($multi && isset($columns['type'])) {
            $out[] = 'type';
        }
        foreach ($preferred as $key) {
            if (isset($columns[$key]) && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Busca (SQL union — contrato §6)
    // ------------------------------------------------------------------

    /**
     * @param array<int,string>   $tokens
     * @param array<string,mixed> $params
     * @return array{rows:array<int,array<string,?string>>,total:int,page:int,max_page:int,error:?string}
     */
    public static function buildSearch(array $tokens, array $params, int $page, int $limit, string $sort, string $order): array
    {
        $page = max(1, $page);
        if ($limit < 1) {
            $limit = self::PAGE_SIZE;
        }
        if ($limit > self::PRINT_LIMIT) {
            $limit = self::PRINT_LIMIT;
        }
        $order = (strtoupper($order) === 'DESC') ? 'DESC' : 'ASC';

        if ($tokens === []) {
            return [
                'rows'     => [],
                'total'    => 0,
                'page'     => 1,
                'max_page' => 1,
                'error'    => 'Você não tem permissão para visualizar nenhum tipo de ativo.',
            ];
        }

        $columns = self::getColumns($tokens);
        $colKeys = array_keys($columns);
        if ($sort === '' || !in_array($sort, $colKeys, true)) {
            $sort = 'id';
        }
        $filters = self::getFilters($tokens);

        $branches = [];
        foreach ($tokens as $token) {
            $branches[] = '(' . self::buildBranch($token, $params, $colKeys, $filters) . ')';
        }
        $union = implode(' UNION ALL ', $branches);

        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'doQuery')) {
            return self::searchError(1, 1, 'Banco de dados indisponível.');
        }

        try {
            $countRes = $DB->doQuery('SELECT COUNT(*) AS total FROM (' . $union . ') AS x');
            $countRow = (is_object($countRes) && method_exists($countRes, 'fetch_assoc')) ? $countRes->fetch_assoc() : null;
            $total = is_array($countRow) ? (int) ($countRow['total'] ?? 0) : 0;
        } catch (\Throwable $e) {
            return self::searchError(1, 1, 'Não foi possível executar a consulta do relatório.');
        }

        $maxPage = max(1, (int) ceil($total / $limit));
        $page = min(max(1, $page), $maxPage);
        $offset = ($page - 1) * $limit;

        $rowsSql = 'SELECT * FROM (' . $union . ') AS x ORDER BY mf_' . $sort . ' ' . $order
            . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $rows = [];
        try {
            $res = $DB->doQuery($rowsSql);
            while (is_object($res) && ($row = $res->fetch_assoc()) !== null) {
                $clean = [];
                foreach ($row as $key => $value) {
                    $clean[$key] = ($value === null) ? null : (string) $value;
                }
                $rows[] = $clean;
            }
        } catch (\Throwable $e) {
            return [
                'rows'     => [],
                'total'    => $total,
                'page'     => $page,
                'max_page' => $maxPage,
                'error'    => 'Não foi possível executar a consulta do relatório.',
            ];
        }

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'max_page' => $maxPage,
            'error'    => null,
        ];
    }

    /**
     * Descrição pt-BR de um valor de filtro (contrato §6).
     */
    public static function describeFilterValue(string $key, string $value): string
    {
        $kind = self::filterKindOf($key);
        if ($kind === 'bool') {
            if ($value === '1') {
                return 'Sim';
            }
            if ($value === '0') {
                return 'Não';
            }
            return (string) $value;
        }
        if ($kind === 'dropdown') {
            $name = self::resolveDropdownValue($key, $value);
            if ($name !== '') {
                return $name;
            }
        }
        return (string) $value;
    }

    // ------------------------------------------------------------------
    // Internos — validação de tipos
    // ------------------------------------------------------------------

    /**
     * Token canônico (case-insensitive para core; valida 'custom:<id>').
     */
    private static function canonicalToken(string $token): ?string
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        if (self::isCustomToken($token)) {
            $id = self::definitionIdFromToken($token);
            if (self::getDefinitionById($id) === null) {
                return null;
            }
            return 'custom:' . $id;
        }
        foreach (array_keys(self::coreTypeMeta()) as $core) {
            if (strcasecmp($core, $token) === 0) {
                return $core;
            }
        }
        return null;
    }

    private static function isCanonicalViewable(string $canonical): bool
    {
        if (self::isCustomToken($canonical)) {
            $def = self::getDefinitionById(self::definitionIdFromToken($canonical));
            return is_array($def) && self::isCustomViewable((string) ($def['system_name'] ?? ''));
        }
        return self::isCoreViewable($canonical);
    }

    private static function isCoreViewable(string $token): bool
    {
        if (!class_exists($token) || !is_callable([$token, 'canView'])) {
            return false;
        }
        try {
            return (bool) $token::canView();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function isCustomViewable(string $systemName): bool
    {
        $systemName = trim($systemName);
        if ($systemName === '') {
            return false;
        }
        if (!class_exists('Session') || !method_exists('Session', 'haveRight')) {
            return false;
        }
        $read = defined('READ') ? (int) READ : 1;
        try {
            return (bool) \Session::haveRight('asset_' . strtolower($systemName), $read);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function definitionIdFromToken(string $token): int
    {
        if (!self::isCustomToken($token)) {
            return 0;
        }
        return (int) substr($token, strlen('custom:'));
    }

    /**
     * @param array<string,mixed> $def
     */
    private static function customLabel(array $def): string
    {
        $label = isset($def['label']) ? trim((string) $def['label']) : '';
        if ($label !== '') {
            return $label;
        }
        $system = isset($def['system_name']) ? trim((string) $def['system_name']) : '';
        return $system !== '' ? $system : 'Ativo';
    }

    /**
     * Opções (id => nome) de uma tabela de modelos/tipos personalizados.
     *
     * @return array<int,string>
     */
    private static function assetChoices(int $definitionId, string $table): array
    {
        global $DB;
        $out = [];
        if ($definitionId <= 0 || !isset($DB) || !is_object($DB) || !method_exists($DB, 'request')) {
            return $out;
        }
        try {
            $it = $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => $table,
                'WHERE'  => ['assets_assetdefinitions_id' => $definitionId],
                'ORDER'  => ['name ASC', 'id ASC'],
            ]);
            foreach ($it as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $out[$id] = (string) ($row['name'] ?? '');
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Internos — filtros e colunas
    // ------------------------------------------------------------------

    /**
     * @param array<int,string>|null $choices
     * @return array{label:string,kind:string,dropdown:?string,choices:?array}
     */
    private static function filterDef(string $label, string $kind, ?string $dropdown = null, ?array $choices = null): array
    {
        return [
            'label'    => $label,
            'kind'     => $kind,
            'dropdown' => $dropdown,
            'choices'  => $choices,
        ];
    }

    /**
     * @return array{label:string,kind:string,dropdown:?string,choices:?array}|null
     */
    private static function modelFilterDef(string $token): ?array
    {
        if (self::isCustomToken($token)) {
            $choices = self::assetChoices(self::definitionIdFromToken($token), 'glpi_assets_assetmodels');
            return self::filterDef('Modelo', 'dropdown', null, $choices);
        }
        $core = self::canonicalToken($token);
        if ($core === null) {
            return null;
        }
        $meta = self::coreTypeMeta()[$core];
        if ($meta['model_col'] === null) {
            return null;
        }
        return self::filterDef('Modelo', 'dropdown', (string) $meta['model_class']);
    }

    /**
     * @return array{label:string,kind:string,dropdown:?string,choices:?array}|null
     */
    private static function subtypeFilterDef(string $token): ?array
    {
        if (self::isCustomToken($token)) {
            $choices = self::assetChoices(self::definitionIdFromToken($token), 'glpi_assets_assettypes');
            return self::filterDef('Tipo', 'dropdown', null, $choices);
        }
        $core = self::canonicalToken($token);
        if ($core === null) {
            return null;
        }
        $meta = self::coreTypeMeta()[$core];
        if ($meta['type_col'] === null) {
            return null;
        }
        return self::filterDef('Tipo', 'dropdown', (string) $meta['type_class']);
    }

    /**
     * Definições de campos extras por tipo core (contrato §4.11).
     *
     * @return array<string,array{label:string,kind:string,dropdown?:string}>
     */
    private static function extraDefs(string $core): array
    {
        switch ($core) {
            case 'Monitor':
                return [
                    'size'         => ['label' => 'Tamanho', 'kind' => 'text'],
                    'have_micro'   => ['label' => 'Microfone', 'kind' => 'bool'],
                    'have_speaker' => ['label' => 'Alto-falante', 'kind' => 'bool'],
                    'have_hdmi'    => ['label' => 'HDMI', 'kind' => 'bool'],
                ];
            case 'Printer':
                return [
                    'memory_size'   => ['label' => 'Memória', 'kind' => 'int'],
                    'have_usb'      => ['label' => 'USB', 'kind' => 'bool'],
                    'have_ethernet' => ['label' => 'Ethernet', 'kind' => 'bool'],
                    'have_wifi'     => ['label' => 'Wi-Fi', 'kind' => 'bool'],
                ];
            case 'Phone':
                return [
                    'have_headset' => ['label' => 'Headset', 'kind' => 'bool'],
                    'have_hp'      => ['label' => 'Viva-voz', 'kind' => 'bool'],
                ];
            case 'NetworkEquipment':
                return [
                    'ram'     => ['label' => 'Memória RAM', 'kind' => 'int'],
                    'comment' => ['label' => 'Comentário', 'kind' => 'text'],
                ];
            case 'Peripheral':
                return [
                    'comment' => ['label' => 'Comentário', 'kind' => 'text'],
                ];
            case 'Software':
                return [
                    'softwarecategories_id' => ['label' => 'Categoria', 'kind' => 'dropdown', 'dropdown' => 'SoftwareCategory'],
                    'is_recursive'          => ['label' => 'Recursivo', 'kind' => 'bool'],
                    'comment'               => ['label' => 'Comentário', 'kind' => 'text'],
                ];
            case 'Rack':
                return [
                    'dcrooms_id'   => ['label' => 'Sala do data center', 'kind' => 'dropdown', 'dropdown' => 'DCRoom'],
                    'is_recursive' => ['label' => 'Recursivo', 'kind' => 'bool'],
                    'comment'      => ['label' => 'Comentário', 'kind' => 'text'],
                ];
            case 'Enclosure':
            case 'PDU':
                return [
                    'is_recursive' => ['label' => 'Recursivo', 'kind' => 'bool'],
                    'comment'      => ['label' => 'Comentário', 'kind' => 'text'],
                ];
            default:
                return [];
        }
    }

    /**
     * Filtros extras de um token único (personalizado não tem extras).
     *
     * @return array<string,array{label:string,kind:string,dropdown:?string,choices:?array}>
     */
    private static function extraFiltersFor(string $token): array
    {
        if (self::isCustomToken($token)) {
            return [];
        }
        $core = self::canonicalToken($token);
        if ($core === null) {
            return [];
        }
        $out = [];
        foreach (self::extraDefs($core) as $key => $def) {
            $out[$key] = self::filterDef(
                (string) $def['label'],
                (string) $def['kind'],
                isset($def['dropdown']) ? (string) $def['dropdown'] : null
            );
        }
        return $out;
    }

    /**
     * Remove filtros cujo campo não existe no tipo único selecionado.
     *
     * @param array<string,array<string,mixed>> $filters
     * @return array<string,array<string,mixed>>
     */
    private static function applyFilterAvailability(array $filters, string $token): array
    {
        if (self::isCustomToken($token)) {
            return $filters;
        }
        $core = self::canonicalToken($token);
        if ($core === null) {
            return $filters;
        }
        $meta = self::coreTypeMeta()[$core];
        if (!$meta['has_status']) {
            unset($filters['status']);
        }
        if (!$meta['has_serial']) {
            unset($filters['serial']);
        }
        if (!$meta['has_otherserial']) {
            unset($filters['otherserial']);
        }
        return $filters;
    }

    /**
     * Ordem canônica dos extras para seleções multi-tipo.
     *
     * @return array<int,string>
     */
    private static function masterExtraOrder(): array
    {
        return [
            'size', 'have_micro', 'have_speaker', 'have_hdmi',
            'memory_size', 'have_usb', 'have_ethernet', 'have_wifi',
            'have_headset', 'have_hp', 'ram', 'comment',
            'softwarecategories_id', 'is_recursive', 'dcrooms_id',
        ];
    }

    /**
     * Extras ordenados (por tipo único: ordem do §4.11; multi: ordem canônica).
     *
     * @param array<int,string> $tokens
     * @return array<string,string>
     */
    private static function extraColumnOrder(array $tokens): array
    {
        if (count($tokens) > 1) {
            $out = [];
            foreach (self::masterExtraOrder() as $key) {
                if (self::anyTokenSupports($tokens, $key)) {
                    $out[$key] = self::extraLabel($key);
                }
            }
            return $out;
        }

        $token = (string) ($tokens[0] ?? '');
        if (self::isCustomToken($token)) {
            return [];
        }
        $core = self::canonicalToken($token);
        if ($core === null) {
            return [];
        }
        $out = [];
        foreach (self::extraDefs($core) as $key => $def) {
            $out[$key] = (string) $def['label'];
        }
        return $out;
    }

    private static function extraLabel(string $key): string
    {
        $map = [
            'size'                  => 'Tamanho',
            'have_micro'            => 'Microfone',
            'have_speaker'          => 'Alto-falante',
            'have_hdmi'             => 'HDMI',
            'memory_size'           => 'Memória',
            'have_usb'              => 'USB',
            'have_ethernet'         => 'Ethernet',
            'have_wifi'             => 'Wi-Fi',
            'have_headset'          => 'Headset',
            'have_hp'               => 'Viva-voz',
            'ram'                   => 'Memória RAM',
            'comment'               => 'Comentário',
            'softwarecategories_id' => 'Categoria',
            'is_recursive'          => 'Recursivo',
            'dcrooms_id'            => 'Sala do data center',
        ];
        return $map[$key] ?? $key;
    }

    private static function anyTokenSupports(array $tokens, string $key): bool
    {
        foreach ($tokens as $token) {
            if (self::tokenSupports((string) $token, $key)) {
                return true;
            }
        }
        return false;
    }

    private static function tokenSupports(string $token, string $key): bool
    {
        if (in_array($key, [
            'id', 'name', 'date_creation', 'date_mod',
            'users_id', 'users_id_tech', 'entities_id', 'groups_id',
            'locations_id', 'manufacturers_id',
        ], true)) {
            return true;
        }
        if (self::isCustomToken($token)) {
            return in_array($key, ['status', 'serial', 'otherserial', 'models', 'subtypes'], true);
        }
        $core = self::canonicalToken($token);
        if ($core === null) {
            return false;
        }
        $meta = self::coreTypeMeta()[$core];
        switch ($key) {
            case 'status':
                return (bool) $meta['has_status'];
            case 'serial':
                return (bool) $meta['has_serial'];
            case 'otherserial':
                return (bool) $meta['has_otherserial'];
            case 'models':
                return $meta['model_col'] !== null;
            case 'subtypes':
                return $meta['type_col'] !== null;
            default:
                return isset(self::extraDefs($core)[$key]);
        }
    }

    // ------------------------------------------------------------------
    // Internos — construção do SQL (contrato §6)
    // ------------------------------------------------------------------

    /**
     * Uma perna do UNION para um token.
     *
     * @param array<string,mixed>                $params
     * @param array<int,string>                  $colKeys
     * @param array<string,array<string,mixed>>  $filters
     */
    private static function buildBranch(string $token, array $params, array $colKeys, array $filters): string
    {
        $isCustom = self::isCustomToken($token);
        $meta = self::coreTypeMeta();
        $core = null;
        if ($isCustom) {
            $defId = self::definitionIdFromToken($token);
            $def = self::getDefinitionById($defId);
            $label = is_array($def) ? self::customLabel($def) : $token;
            $system = is_array($def) ? (string) ($def['system_name'] ?? '') : '';
            $table = 'glpi_assets_assets';
            $modelCol = 'assets_assetmodels_id';
            $modelTable = 'glpi_assets_assetmodels';
            $typeCol = 'assets_assettypes_id';
            $typeTable = 'glpi_assets_assettypes';
            $hasStatus = true;
            $hasSerial = true;
            $hasOtherserial = true;
            $itemtype = 'Glpi\\CustomAsset\\' . $system . 'Asset';
        } else {
            $core = self::canonicalToken($token);
            if ($core === null) {
                $core = self::DEFAULT_TYPE;
            }
            $m = $meta[$core];
            $label = (string) $m['label'];
            $table = (string) $m['table'];
            $modelCol = $m['model_col'];
            $modelTable = $m['model_table'];
            $typeCol = $m['type_col'];
            $typeTable = $m['type_table'];
            $hasStatus = (bool) $m['has_status'];
            $hasSerial = (bool) $m['has_serial'];
            $hasOtherserial = (bool) $m['has_otherserial'];
            $itemtype = $core;
        }
        $isComputer = ($core === 'Computer');

        $extraExprs = self::branchExtraExprs($token);
        $support = [
            'status'      => $hasStatus,
            'serial'      => $hasSerial,
            'otherserial' => $hasOtherserial,
        ];
        foreach (array_keys($extraExprs) as $extraKey) {
            $support[$extraKey] = true;
        }

        $exprMap = [];
        $exprMap['id'] = 't.`id`';
        $exprMap['type'] = self::quote($label);
        $exprMap['name'] = 't.`name`';
        if ($hasStatus) {
            $exprMap['status'] = 'st.`name`';
        }
        $exprMap['manufacturers_id'] = 'mf.`name`';
        if ($modelCol !== null) {
            $exprMap['models'] = 'mo.`name`';
        }
        if ($typeCol !== null) {
            $exprMap['subtypes'] = 'ty.`name`';
        }
        $exprMap['locations_id'] = 'l.`name`';
        $exprMap['groups_id'] = self::groupsSubquery($itemtype);
        if ($hasSerial) {
            $exprMap['serial'] = 't.`serial`';
        }
        if ($hasOtherserial) {
            $exprMap['otherserial'] = 't.`otherserial`';
        }
        $exprMap['users_id'] = 'u.`name`';
        $exprMap['users_id_tech'] = 'ut.`name`';
        $exprMap['entities_id'] = 'e.`name`';
        $exprMap['date_creation'] = 't.`date_creation`';
        $exprMap['date_mod'] = 't.`date_mod`';
        foreach ($extraExprs as $extraKey => $extraExpr) {
            $exprMap[$extraKey] = $extraExpr;
        }

        $selectParts = [];
        foreach ($colKeys as $key) {
            $expr = $exprMap[$key] ?? null;
            $selectParts[] = ($expr === null)
                ? ('NULL AS mf_' . $key)
                : ($expr . ' AS mf_' . $key);
        }
        $select = implode(', ', $selectParts);

        $joins = '';
        if ($hasStatus && in_array('status', $colKeys, true)) {
            $joins .= ' LEFT JOIN `glpi_states` st ON st.`id` = t.`states_id`';
        }
        if (in_array('manufacturers_id', $colKeys, true)) {
            $joins .= ' LEFT JOIN `glpi_manufacturers` mf ON mf.`id` = t.`manufacturers_id`';
        }
        if ($modelCol !== null && in_array('models', $colKeys, true)) {
            $joins .= ' LEFT JOIN `' . $modelTable . '` mo ON mo.`id` = t.`' . $modelCol . '`';
        }
        if ($typeCol !== null && in_array('subtypes', $colKeys, true)) {
            $joins .= ' LEFT JOIN `' . $typeTable . '` ty ON ty.`id` = t.`' . $typeCol . '`';
        }
        if (in_array('locations_id', $colKeys, true)) {
            $joins .= ' LEFT JOIN `glpi_locations` l ON l.`id` = t.`locations_id`';
        }
        if (in_array('users_id', $colKeys, true)) {
            $joins .= ' LEFT JOIN `glpi_users` u ON u.`id` = t.`users_id`';
        }
        if (in_array('users_id_tech', $colKeys, true)) {
            $joins .= ' LEFT JOIN `glpi_users` ut ON ut.`id` = t.`users_id_tech`';
        }
        if (in_array('entities_id', $colKeys, true)) {
            $joins .= ' LEFT JOIN `glpi_entities` e ON e.`id` = t.`entities_id`';
        }
        if ($core === 'Software' && in_array('softwarecategories_id', $colKeys, true)) {
            $joins .= ' LEFT JOIN `glpi_softwarecategories` sc ON sc.`id` = t.`softwarecategories_id`';
        }
        if ($core === 'Rack' && in_array('dcrooms_id', $colKeys, true)) {
            $joins .= ' LEFT JOIN `glpi_dcrooms` dr ON dr.`id` = t.`dcrooms_id`';
        }

        $where = 'WHERE t.`is_deleted` = 0 AND t.`is_template` = 0';
        if ($isCustom) {
            $where .= ' AND t.`assets_assetdefinitions_id` = ' . self::definitionIdFromToken($token);
        }
        if (function_exists('mafasa_entity_sql')) {
            $where .= (string) \mafasa_entity_sql('t');
        }
        $where .= self::filterSqlForBranch($filters, $params, $support, $modelCol, $typeCol, $isComputer, $itemtype);

        return 'SELECT ' . $select . ' FROM `' . $table . '` t' . $joins . ' ' . $where;
    }

    /**
     * Expressões de coluna dos extras suportados por um token.
     *
     * @return array<string,string>
     */
    private static function branchExtraExprs(string $token): array
    {
        $out = [];
        if (self::isCustomToken($token)) {
            return $out;
        }
        $core = self::canonicalToken($token);
        if ($core === null) {
            return $out;
        }
        foreach (array_keys(self::extraDefs($core)) as $key) {
            if ($key === 'softwarecategories_id') {
                $out[$key] = 'sc.`name`';
            } elseif ($key === 'dcrooms_id') {
                $out[$key] = 'dr.`name`';
            } else {
                $out[$key] = 't.`' . $key . '`';
            }
        }
        return $out;
    }

    /**
     * Subconsulta GROUP_CONCAT dos grupos do item (type = 1).
     */
    private static function groupsSubquery(string $itemtype): string
    {
        return '(SELECT GROUP_CONCAT(g.`name` ORDER BY g.`name` SEPARATOR \', \') '
            . 'FROM `glpi_groups_items` gi '
            . 'JOIN `glpi_groups` g ON g.`id` = gi.`groups_id` '
            . 'WHERE gi.`itemtype` = ' . self::quote($itemtype)
            . ' AND gi.`items_id` = t.`id` AND gi.`type` = 1)';
    }

    /**
     * Coluna real de um filtro nesta perna (null quando o tipo não tem o
     * campo — vira AND 1=0).
     *
     * @param array<string,bool> $support
     */
    private static function filterColumnExpr(string $key, array $support, ?string $modelCol, ?string $typeCol): ?string
    {
        switch ($key) {
            case 'status':
                return !empty($support['status']) ? 't.`states_id`' : null;
            case 'serial':
                return !empty($support['serial']) ? 't.`serial`' : null;
            case 'otherserial':
                return !empty($support['otherserial']) ? 't.`otherserial`' : null;
            case 'models':
                return $modelCol !== null ? 't.`' . $modelCol . '`' : null;
            case 'subtypes':
                return $typeCol !== null ? 't.`' . $typeCol . '`' : null;
            case 'locations_id':
                return 't.`locations_id`';
            case 'manufacturers_id':
                return 't.`manufacturers_id`';
            case 'name':
                return 't.`name`';
            default:
                return !empty($support[$key]) ? 't.`' . $key . '`' : null;
        }
    }

    /**
     * Cláusulas de filtro ativas para uma perna.
     *
     * @param array<string,array<string,mixed>> $filters
     * @param array<string,mixed>               $params
     * @param array<string,bool>                $support
     */
    private static function filterSqlForBranch(
        array $filters,
        array $params,
        array $support,
        ?string $modelCol,
        ?string $typeCol,
        bool $isComputer,
        string $itemtype
    ): string {
        $sql = '';
        foreach ($filters as $key => $def) {
            $raw = $params[$key] ?? null;
            if ($raw === null || is_array($raw)) {
                continue;
            }
            $rawStr = is_scalar($raw) ? (string) $raw : '';
            if (trim($rawStr) === '') {
                continue;
            }
            $kind = (string) ($def['kind'] ?? 'text');

            if ($kind === 'date') {
                $date = self::normalizeDate($rawStr);
                if ($date === null) {
                    continue;
                }
                if ($key === 'date_creation_from') {
                    $sql .= ' AND t.`date_creation` >= ' . self::quote($date . ' 00:00:00');
                } elseif ($key === 'date_creation_to') {
                    $sql .= ' AND t.`date_creation` <= ' . self::quote($date . ' 23:59:59');
                } elseif ($key === 'date_mod_from') {
                    $sql .= ' AND t.`date_mod` >= ' . self::quote($date . ' 00:00:00');
                } elseif ($key === 'date_mod_to') {
                    $sql .= ' AND t.`date_mod` <= ' . self::quote($date . ' 23:59:59');
                }
                continue;
            }

            if ($key === 'groups_id') {
                $id = (int) $rawStr;
                if ($id <= 0) {
                    continue;
                }
                $sql .= ' AND EXISTS (SELECT 1 FROM `glpi_groups_items` gi WHERE gi.`itemtype` = '
                    . self::quote($itemtype) . ' AND gi.`items_id` = t.`id` AND gi.`type` = 1 AND gi.`groups_id` = ' . $id . ')';
                continue;
            }

            if ($key === 'os') {
                $id = (int) $rawStr;
                if ($id <= 0) {
                    continue;
                }
                if (!$isComputer) {
                    $sql .= ' AND 1=0';
                    continue;
                }
                $sql .= ' AND EXISTS (SELECT 1 FROM `glpi_items_operatingsystems` ios WHERE ios.`itemtype` = \'Computer\''
                    . ' AND ios.`items_id` = t.`id` AND ios.`is_deleted` = 0 AND ios.`operatingsystems_id` = ' . $id . ')';
                continue;
            }

            $col = self::filterColumnExpr($key, $support, $modelCol, $typeCol);
            if ($col === null) {
                $sql .= ' AND 1=0';
                continue;
            }

            if ($kind === 'bool') {
                $bool = trim($rawStr);
                if ($bool !== '0' && $bool !== '1') {
                    continue;
                }
                $sql .= ' AND ' . $col . ' = ' . (int) $bool;
            } elseif ($kind === 'int' || $kind === 'dropdown') {
                $id = (int) $rawStr;
                if ($id <= 0) {
                    continue;
                }
                $sql .= ' AND ' . $col . ' = ' . $id;
            } else {
                $text = trim($rawStr);
                if ($text === '') {
                    continue;
                }
                if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                    if (mb_strlen($text) > 255) {
                        $text = mb_substr($text, 0, 255);
                    }
                } elseif (strlen($text) > 255) {
                    $text = substr($text, 0, 255);
                }
                $sql .= ' AND ' . $col . ' LIKE ' . self::quote('%' . $text . '%');
            }
        }
        return $sql;
    }

    /**
     * Escapa um valor string para uso no SQL.
     */
    private static function quote(string $value): string
    {
        global $DB;
        if (isset($DB) && is_object($DB) && method_exists($DB, 'quoteValue')) {
            try {
                return (string) $DB->quoteValue($value);
            } catch (\Throwable $e) {
                // cai para o fallback manual
            }
        }
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Normaliza uma data YYYY-MM-DD (null se inválida).
     */
    private static function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches)) {
            return null;
        }
        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return null;
        }
        return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
    }

    /**
     * @return array{rows:array<int,array<string,?string>>,total:int,page:int,max_page:int,error:?string}
     */
    private static function searchError(int $page, int $maxPage, string $message): array
    {
        return [
            'rows'     => [],
            'total'    => 0,
            'page'     => max(1, $page),
            'max_page' => max(1, $maxPage),
            'error'    => $message,
        ];
    }

    // ------------------------------------------------------------------
    // Internos — descrição de valores de filtro
    // ------------------------------------------------------------------

    private static function filterKindOf(string $key): string
    {
        $map = [
            'groups_id'             => 'dropdown',
            'locations_id'          => 'dropdown',
            'status'                => 'dropdown',
            'manufacturers_id'      => 'dropdown',
            'models'                => 'dropdown',
            'subtypes'              => 'dropdown',
            'os'                    => 'dropdown',
            'softwarecategories_id' => 'dropdown',
            'dcrooms_id'            => 'dropdown',
            'users_id'              => 'dropdown',
            'name'                  => 'text',
            'serial'                => 'text',
            'otherserial'           => 'text',
            'size'                  => 'text',
            'comment'               => 'text',
            'memory_size'           => 'int',
            'ram'                   => 'int',
            'have_micro'            => 'bool',
            'have_speaker'          => 'bool',
            'have_hdmi'             => 'bool',
            'have_usb'              => 'bool',
            'have_ethernet'         => 'bool',
            'have_wifi'             => 'bool',
            'have_headset'          => 'bool',
            'have_hp'               => 'bool',
            'is_recursive'          => 'bool',
            'date_creation_from'    => 'date',
            'date_creation_to'      => 'date',
            'date_mod_from'         => 'date',
            'date_mod_to'           => 'date',
        ];
        return $map[$key] ?? 'text';
    }

    private static function resolveDropdownValue(string $key, string $value): string
    {
        $id = (int) $value;
        if ($id <= 0) {
            return '';
        }

        if ($key === 'models' || $key === 'subtypes') {
            $contextTable = self::contextModelTypeTable($key);
            if ($contextTable !== null) {
                $name = self::lookupName($contextTable, $id);
                if ($name !== '') {
                    return $name;
                }
            }
            foreach (self::modelTypeTables($key) as $table) {
                $name = self::lookupName($table, $id);
                if ($name !== '') {
                    return $name;
                }
            }
            return '';
        }

        $table = self::dropdownFilterTable($key);
        if ($table === null) {
            return '';
        }
        return self::lookupName($table, $id);
    }

    private static function dropdownFilterTable(string $key): ?string
    {
        $map = [
            'groups_id'             => 'glpi_groups',
            'locations_id'          => 'glpi_locations',
            'status'                => 'glpi_states',
            'manufacturers_id'      => 'glpi_manufacturers',
            'os'                    => 'glpi_operatingsystems',
            'softwarecategories_id' => 'glpi_softwarecategories',
            'dcrooms_id'            => 'glpi_dcrooms',
            'users_id'              => 'glpi_users',
        ];
        return $map[$key] ?? null;
    }

    /**
     * Tabela de modelo/tipo do token de contexto (quando há um único tipo).
     */
    private static function contextModelTypeTable(string $key): ?string
    {
        if (count(self::$contextTokens) !== 1) {
            return null;
        }
        $token = self::$contextTokens[0];
        if (self::isCustomToken($token)) {
            return ($key === 'models') ? 'glpi_assets_assetmodels' : 'glpi_assets_assettypes';
        }
        $core = self::canonicalToken($token);
        if ($core === null) {
            return null;
        }
        $meta = self::coreTypeMeta()[$core];
        $column = ($key === 'models') ? 'model_table' : 'type_table';
        return ($meta[$column] !== null) ? (string) $meta[$column] : null;
    }

    /**
     * Todas as tabelas candidatas de modelo/tipo (core + personalizado).
     *
     * @return array<int,string>
     */
    private static function modelTypeTables(string $key): array
    {
        $field = ($key === 'models') ? 'model_table' : 'type_table';
        $tables = [];
        foreach (self::coreTypeMeta() as $meta) {
            if ($meta[$field] !== null && !in_array($meta[$field], $tables, true)) {
                $tables[] = (string) $meta[$field];
            }
        }
        $tables[] = ($key === 'models') ? 'glpi_assets_assetmodels' : 'glpi_assets_assettypes';
        return $tables;
    }

    /**
     * Resolve o nome de um id numa tabela qualquer ('' quando ausente).
     */
    private static function lookupName(string $table, int $id): string
    {
        global $DB;
        if ($id <= 0 || !isset($DB) || !is_object($DB) || !method_exists($DB, 'request')) {
            return '';
        }
        try {
            $it = $DB->request([
                'SELECT' => ['name'],
                'FROM'   => $table,
                'WHERE'  => ['id' => $id],
                'LIMIT'  => 1,
            ]);
            foreach ($it as $row) {
                if (is_array($row) && isset($row['name']) && $row['name'] !== null) {
                    return (string) $row['name'];
                }
            }
        } catch (\Throwable $e) {
            return '';
        }
        return '';
    }
}
