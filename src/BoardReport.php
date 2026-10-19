<?php
/**
 * Relatório de quadros do Kanpro — leitura somente.
 *
 * Lê as tabelas do plugin Kanpro por SQL, sem incluir arquivos nem depender de
 * classes/funções do Kanpro. A visibilidade de quadros e listas é replicada
 * aqui (criador/membro/perfil/legado aberto e lists_viewers).
 *
 * Compat: GLPI 11.0.0–12.0.99, PHP >= 8.2. Somente SELECT.
 */

namespace GlpiPlugin\Mafasa;

final class BoardReport
{
    private const BOARD_MEMBERS = 'glpi_plugin_kanpro_boards_members';
    private const BOARD_PROFILES = 'glpi_plugin_kanpro_boards_profiles';
    private const LIST_VIEWERS = 'glpi_plugin_kanpro_lists_viewers';
    private const CARD_MEMBERS = 'glpi_plugin_kanpro_cards_members';

    /** Categorias de lista confirmadas no Kanpro, na ordem fixa de exibição. */
    private const CATEGORY_LABELS = [
        'backlog'             => 'Pautas futuras',
        'todo'                => 'A Fazer',
        'doing'               => 'Em Progresso',
        'done'                => 'Concluído',
        'awaiting'            => 'Aguardando Chegada',
        'pending'             => 'Pendente',
        'andamento'           => 'Em Andamento',
        'retirada'            => 'Retirada',
        'pend_chamado'        => 'Pendência chamado',
        'chamados_pendencia'  => 'Chamados Pendência',
        'chamados_espera'     => 'Chamados Espera',
        'abrir_chamado'       => 'Abrir chamado',
        'andamento_chamado'   => 'Em Andamento Chamado',
        'chamado_finalizado'  => 'Chamado finalizado',
        'none'                => 'Sem categoria',
    ];

    /** Status internos de chamado confirmados no Kanpro. */
    private const CHAMADO_STATUS_LABELS = [
        'pendente'    => 'Pendente',
        'liberado'    => 'Liberado',
        'conclusao'   => 'Conclusão solicitada',
        'conclusao_ok' => 'Conclusão liberada',
        'exclusao'    => 'Exclusão solicitada',
        'exclusao_ok' => 'Exclusão liberada',
        'edicao'      => 'Edição solicitada',
        'edicao_ok'   => 'Edição liberada',
        'separacao'   => 'Separação solicitada',
        'separacao_ok' => 'Separação liberada',
    ];

    public static function isAvailable(): bool
    {
        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'tableExists')) {
            return false;
        }

        try {
            $tablesOk = $DB->tableExists('glpi_plugin_kanpro_boards')
                && $DB->tableExists('glpi_plugin_kanpro_lists')
                && $DB->tableExists('glpi_plugin_kanpro_cards');
        } catch (\Throwable $e) {
            return false;
        }
        if (!$tablesOk) {
            return false;
        }

        if (class_exists('\\Plugin')) {
            foreach (['isPluginActivated', 'isPluginActive', 'isActivated'] as $method) {
                if (!method_exists('\\Plugin', $method)) {
                    continue;
                }
                try {
                    return (bool) \Plugin::$method('kanpro');
                } catch (\Throwable $e) {
                    // método de versão diferente: cai para as tabelas
                }
            }
        }

        return true;
    }

    public static function canView(): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        if (!method_exists('Session', 'haveRight')) {
            return false;
        }
        try {
            return (bool) \Session::haveRight('plugin_kanpro', READ);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Filtros normalizados. entities_id=0 significa "todas as entidades
     * visíveis" (mafasa_entity_sql); boards_id/list_type vazios = todos.
     *
     * @return array<string,mixed>
     */
    public static function getDefaults(array $params = []): array
    {
        return self::normalizeFilters($params);
    }

    /**
     * @return array<int,string> id => nome
     */
    public static function getBoards(array $params = []): array
    {
        if (!self::isAvailable()) {
            return [];
        }
        $filters = self::normalizeFilters($params);
        try {
            $out = [];
            foreach (self::activeBoards($filters) as $row) {
                $out[(int) $row['id']] = (string) $row['name'];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<string,string> chave normalizada => rótulo
     */
    public static function getCategoryChoices(): array
    {
        return self::CATEGORY_LABELS;
    }

    public static function getCategoryKey(?string $listType): string
    {
        $key = strtolower(trim((string) $listType));
        if ($key === '' || $key === 'none' || !isset(self::CATEGORY_LABELS[$key])) {
            return 'none';
        }
        return $key;
    }

    public static function getCategoryLabel(?string $listType): string
    {
        return self::CATEGORY_LABELS[self::getCategoryKey($listType)];
    }

    public static function getChamadoStatusLabel(?string $status): string
    {
        $raw = trim((string) $status);
        if ($raw === '') {
            return '—';
        }
        return self::CHAMADO_STATUS_LABELS[strtolower($raw)] ?? $raw;
    }

    /**
     * @return array<string,mixed>
     */
    public static function buildReport(array $params): array
    {
        $filters = self::normalizeFilters($params);
        $empty = self::emptyReport($filters);

        if (!self::isAvailable()) {
            return array_merge($empty, [
                'ok'    => false,
                'error' => 'O plugin Kanpro não está ativo ou as tabelas de quadros não existem.',
            ]);
        }
        if (!self::canView()) {
            return array_merge($empty, [
                'ok'    => false,
                'error' => 'Você não tem permissão para visualizar os quadros do Kanpro.',
            ]);
        }

        try {
            $boards = self::activeBoards($filters);

            $cardFrom = self::cardFromSql();
            $cardWhere = self::cardWhereSql($filters);

            $kpiRow = self::fetchAll(
                'SELECT COUNT(`c`.`id`) AS `cards`,'
                . ' COALESCE(SUM(CASE WHEN `c`.`tickets_id` > 0 THEN 1 ELSE 0 END), 0) AS `linked`,'
                . ' COALESCE(SUM(CASE WHEN `c`.`is_maintenance` = 1 THEN 1 ELSE 0 END), 0) AS `maintenance`,'
                . ' COALESCE(SUM(CASE WHEN `c`.`is_archived` = 1 THEN 1 ELSE 0 END), 0) AS `archived`'
                . $cardFrom . $cardWhere
            );
            $kpi = $kpiRow[0] ?? [];
            $total = (int) ($kpi['cards'] ?? 0);

            $byBoardRows = self::fetchAll(self::byBoardSql($filters));
            $byBoard = [];
            foreach ($byBoardRows as $row) {
                $item = [
                    'id'          => (int) $row['id'],
                    'name'        => (string) $row['name'],
                    'cards'       => (int) $row['cards'],
                    'linked'      => (int) $row['linked'],
                    'maintenance' => (int) $row['maintenance'],
                    'archived'    => (int) $row['archived'],
                ];
                $byBoard[] = $item;
            }

            $matrixCategoryKeys = [];
            $matrixCells = [];
            foreach (self::fetchAll(self::matrixSql($filters)) as $row) {
                $bid = (int) $row['board_id'];
                $key = self::getCategoryKey((string) ($row['category_key'] ?? ''));
                $count = (int) $row['count'];
                if ($count <= 0) {
                    continue;
                }
                $matrixCells[$bid][$key] = ($matrixCells[$bid][$key] ?? 0) + $count;
                $matrixCategoryKeys[$key] = true;
            }

            $categories = [];
            foreach (array_keys(self::CATEGORY_LABELS) as $key) {
                if (isset($matrixCategoryKeys[$key])) {
                    $categories[] = ['key' => $key, 'label' => self::CATEGORY_LABELS[$key]];
                }
            }

            $matrixRows = [];
            $totals = [];
            foreach ($categories as $category) {
                $totals[$category['key']] = 0;
            }
            $grand = 0;
            foreach ($boards as $board) {
                $bid = (int) $board['id'];
                $cells = [];
                $rowTotal = 0;
                foreach ($categories as $category) {
                    $key = $category['key'];
                    $count = (int) ($matrixCells[$bid][$key] ?? 0);
                    $cells[$key] = $count;
                    $rowTotal += $count;
                    $totals[$key] += $count;
                }
                $grand += $rowTotal;
                $matrixRows[] = [
                    'board' => (string) $board['name'],
                    'cells' => $cells,
                    'total' => $rowTotal,
                ];
            }

            $byCategory = [];
            foreach ($categories as $category) {
                $count = (int) ($totals[$category['key']] ?? 0);
                $byCategory[] = [
                    'label' => $category['label'],
                    'count' => $count,
                    'pct'   => $total > 0 ? round(($count * 100) / $total, 2) : 0.0,
                ];
            }

            $byList = [];
            foreach (self::fetchAll(self::byListSql($filters)) as $row) {
                $byList[] = [
                    'board'    => (string) $row['board'],
                    'list'     => (string) $row['list'],
                    'category' => self::getCategoryLabel((string) ($row['list_type'] ?? '')),
                    'count'    => (int) $row['count'],
                ];
            }

            return [
                'ok'          => true,
                'error'       => null,
                'total'       => $total,
                'filters'     => $filters,
                'kpis'        => [
                    'cards'       => $total,
                    'linked'      => (int) ($kpi['linked'] ?? 0),
                    'maintenance' => (int) ($kpi['maintenance'] ?? 0),
                    'archived'    => (int) ($kpi['archived'] ?? 0),
                    'boards'      => count($boards),
                ],
                'by_board'    => $byBoard,
                'matrix'      => [
                    'categories' => $categories,
                    'rows'       => $matrixRows,
                    'totals'     => $totals,
                    'grand'      => $grand,
                ],
                'by_category' => $byCategory,
                'by_list'     => $byList,
            ];
        } catch (\Throwable $e) {
            return array_merge($empty, [
                'ok'    => false,
                'error' => 'Não foi possível gerar o relatório de quadros.',
            ]);
        }
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,max_page:int}
     */
    public static function getDetails(array $params, int $page = 1, int $limit = 50): array
    {
        $page = max(1, $page);
        $limit = max(1, min(5000, $limit));
        $filters = self::normalizeFilters($params);
        $empty = ['rows' => [], 'total' => 0, 'page' => $page, 'max_page' => 1];

        if (!self::isAvailable() || !self::canView()) {
            return $empty;
        }

        try {
            $cardFrom = self::cardFromSql();
            $cardWhere = self::cardWhereSql($filters);

            $countRows = self::fetchAll('SELECT COUNT(`c`.`id`) AS `total`' . $cardFrom . $cardWhere);
            $total = (int) ($countRows[0]['total'] ?? 0);
            $maxPage = max(1, (int) ceil($total / $limit));
            $page = min($page, $maxPage);
            $offset = ($page - 1) * $limit;

            $categorySql = self::categoryCaseSql();
            $rowsSql = 'SELECT `c`.`id`, `c`.`name`, `b`.`name` AS `board`, `l`.`name` AS `list`,'
                . ' ' . $categorySql . ' AS `list_type`, `c`.`tickets_id`, `c`.`chamado_status`,'
                . ' `c`.`users_id`, `c`.`date_creation`'
                . $cardFrom . $cardWhere
                . ' ORDER BY `c`.`date_creation` DESC, `c`.`id` DESC'
                . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

            $rawRows = self::fetchAll($rowsSql);
            $owners = self::hydrateOwners($rawRows);

            $rows = [];
            foreach ($rawRows as $row) {
                $cid = (int) $row['id'];
                $rows[] = [
                    'id'             => $cid,
                    'name'           => (string) $row['name'],
                    'board'          => (string) $row['board'],
                    'list'           => (string) $row['list'],
                    'category'       => self::getCategoryLabel((string) ($row['list_type'] ?? '')),
                    'tickets_id'     => (int) $row['tickets_id'],
                    'chamado_status' => self::getChamadoStatusLabel((string) ($row['chamado_status'] ?? '')),
                    'owner'          => (string) ($owners[$cid] ?? ''),
                    'date_creation'  => (string) ($row['date_creation'] ?? ''),
                ];
            }

            return [
                'rows'     => $rows,
                'total'    => $total,
                'page'     => $page,
                'max_page' => $maxPage,
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /**
     * @return array<int,string> apenas filtros ativos, em português
     */
    public static function describeFilters(array $params): array
    {
        $filters = self::normalizeFilters($params);
        $parts = [];

        if ($filters['entities_id'] > 0) {
            $entityId = (int) $filters['entities_id'];
            $parts[] = 'Entidade: ' . self::entityLabel($entityId) . ' (#' . $entityId . ')';
        }

        if ($filters['boards_id'] !== []) {
            $names = [];
            $allBoards = self::getBoards($filters);
            foreach ($filters['boards_id'] as $boardId) {
                $names[] = $allBoards[$boardId] ?? ('#' . $boardId);
            }
            $parts[] = 'Quadros: ' . implode(', ', $names);
        }

        if ($filters['list_type'] !== []) {
            $labels = [];
            foreach ($filters['list_type'] as $key) {
                $labels[] = self::CATEGORY_LABELS[$key] ?? self::CATEGORY_LABELS['none'];
            }
            $parts[] = 'Categorias: ' . implode(', ', $labels);
        }

        if ($filters['include_archived']) {
            $parts[] = 'Incluir arquivados';
        }

        if ($filters['date_from'] !== '' && $filters['date_to'] !== '') {
            $parts[] = 'Período: ' . self::displayDate($filters['date_from'])
                . ' a ' . self::displayDate($filters['date_to']);
        } elseif ($filters['date_from'] !== '') {
            $parts[] = 'Período: a partir de ' . self::displayDate($filters['date_from']);
        } elseif ($filters['date_to'] !== '') {
            $parts[] = 'Período: até ' . self::displayDate($filters['date_to']);
        }

        return $parts;
    }

    /**
     * @return array<int,int> sessão + pessoa (login compartilhado), nunca vazio
     */
    public static function getViewerUserIds(): array
    {
        $ids = [];
        if (method_exists('Session', 'getLoginUserID')) {
            try {
                $id = (int) \Session::getLoginUserID();
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            } catch (\Throwable $e) {
                // segue para a pessoa
            }
        }
        if (function_exists('kanpro_acting_user_id')) {
            try {
                $id = (int) kanpro_acting_user_id();
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            } catch (\Throwable $e) {
                // mantém apenas a sessão
            }
        }
        return $ids === [] ? [0] : array_values($ids);
    }

    // ------------------------------------------------------------------
    // Normalização
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private static function normalizeFilters(array $params): array
    {
        $defaults = [
            'entities_id'      => 0,
            'boards_id'        => [],
            'list_type'        => [],
            'include_archived' => false,
            'date_from'        => '',
            'date_to'          => '',
            'show_details'     => false,
        ];
        $params = array_merge($defaults, $params);

        $categories = [];
        foreach (self::normalizeList($params['list_type']) as $value) {
            $categories[self::getCategoryKey($value)] = self::getCategoryKey($value);
        }

        $from = self::normalizeDate((string) $params['date_from']);
        $to = self::normalizeDate((string) $params['date_to']);

        return [
            'entities_id'      => max(0, (int) $params['entities_id']),
            'boards_id'        => self::normalizeIntList($params['boards_id']),
            'list_type'        => array_values($categories),
            'include_archived' => self::truthy($params['include_archived']),
            'date_from'        => $from ?? '',
            'date_to'          => $to ?? '',
            'show_details'     => self::truthy($params['show_details']),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,int>
     */
    private static function normalizeIntList($value): array
    {
        $out = [];
        foreach (self::normalizeList($value) as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private static function normalizeList($value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_scalar($value) && trim((string) $value) !== '') {
            $items = explode(',', (string) $value);
        } else {
            $items = [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $text = trim((string) $item);
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param mixed $value
     */
    private static function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        $text = strtolower(trim((string) $value));
        return in_array($text, ['1', 'true', 'on', 'yes', 'sim'], true);
    }

    private static function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            return null;
        }
        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return null;
        }
        return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
    }

    private static function displayDate(string $value): string
    {
        $date = self::normalizeDate($value);
        if ($date === null) {
            return $value;
        }
        [$year, $month, $day] = explode('-', $date);
        return $day . '/' . $month . '/' . $year;
    }

    // ------------------------------------------------------------------
    // SQL — escopo e visibilidade
    // ------------------------------------------------------------------

    private static function cardFromSql(): string
    {
        return ' FROM `glpi_plugin_kanpro_cards` `c`'
            . ' INNER JOIN `glpi_plugin_kanpro_boards` `b` ON `b`.`id` = `c`.`plugin_kanpro_boards_id`'
            . ' INNER JOIN `glpi_plugin_kanpro_lists` `l` ON `l`.`id` = `c`.`plugin_kanpro_lists_id`';
    }

    /**
     * @param array<string,mixed> $filters
     */
    private static function cardWhereSql(array $filters): string
    {
        $sql = ' WHERE ' . self::boardVisibilitySql();
        $sql .= self::entitySql($filters);
        $sql .= self::boardFilterSql($filters);
        $sql .= self::listVisibilitySql();
        if (!$filters['include_archived']) {
            $sql .= ' AND `b`.`is_archived` = 0 AND `l`.`is_archived` = 0 AND `c`.`is_archived` = 0';
        }
        $sql .= self::periodSql($filters);
        $sql .= self::categoryFilterSql($filters);
        return $sql;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    private static function activeBoards(array $filters): array
    {
        $sql = 'SELECT `b`.`id`, `b`.`name` FROM `glpi_plugin_kanpro_boards` `b`'
            . ' WHERE ' . self::boardVisibilitySql()
            . self::entitySql($filters)
            . self::boardFilterSql($filters);
        if (!$filters['include_archived']) {
            $sql .= ' AND `b`.`is_archived` = 0';
        }
        $sql .= ' ORDER BY `b`.`name` ASC, `b`.`id` ASC';
        return self::fetchAll($sql);
    }

    private static function boardVisibilitySql(): string
    {
        $viewerIds = self::getViewerUserIds();
        $in = implode(',', array_map('intval', $viewerIds));

        $hasMembers = self::hasTable(self::BOARD_MEMBERS);
        $hasProfiles = self::hasTable(self::BOARD_PROFILES);
        $hasProfileUsers = self::hasTable('glpi_profiles_users');

        $creator = '`b`.`users_id` IN (' . $in . ')';
        $member = $hasMembers
            ? 'EXISTS (SELECT 1 FROM `' . self::BOARD_MEMBERS . '` `bm`'
                . ' WHERE `bm`.`plugin_kanpro_boards_id` = `b`.`id` AND `bm`.`users_id` IN (' . $in . '))'
            : '0=1';
        $profile = ($hasProfiles && $hasProfileUsers)
            ? 'EXISTS (SELECT 1 FROM `' . self::BOARD_PROFILES . '` `bp`'
                . ' INNER JOIN `glpi_profiles_users` `pu` ON `pu`.`profiles_id` = `bp`.`profiles_id`'
                . ' WHERE `bp`.`plugin_kanpro_boards_id` = `b`.`id` AND `pu`.`users_id` IN (' . $in . '))'
            : '0=1';
        $noMembers = $hasMembers
            ? 'NOT EXISTS (SELECT 1 FROM `' . self::BOARD_MEMBERS . '` `bm2`'
                . ' WHERE `bm2`.`plugin_kanpro_boards_id` = `b`.`id`)'
            : '1=1';
        $noProfiles = $hasProfiles
            ? 'NOT EXISTS (SELECT 1 FROM `' . self::BOARD_PROFILES . '` `bp2`'
                . ' WHERE `bp2`.`plugin_kanpro_boards_id` = `b`.`id`)'
            : '1=1';

        return '(' . $creator . ' OR ' . $member . ' OR ' . $profile
            . ' OR (' . $noMembers . ' AND ' . $noProfiles . '))';
    }

    private static function listVisibilitySql(): string
    {
        if (!self::hasTable(self::LIST_VIEWERS)) {
            return ' AND 1=1';
        }
        $in = implode(',', array_map('intval', self::getViewerUserIds()));
        return ' AND (NOT EXISTS (SELECT 1 FROM `' . self::LIST_VIEWERS . '` `lv`'
            . ' WHERE `lv`.`plugin_kanpro_lists_id` = `l`.`id`)'
            . ' OR EXISTS (SELECT 1 FROM `' . self::LIST_VIEWERS . '` `lv2`'
            . ' WHERE `lv2`.`plugin_kanpro_lists_id` = `l`.`id` AND `lv2`.`users_id` IN (' . $in . ')))';
    }

    /**
     * @param array<string,mixed> $filters
     */
    private static function entitySql(array $filters): string
    {
        $sql = function_exists('mafasa_entity_sql') ? (string) mafasa_entity_sql('b') : ' AND 1=0';
        $entitiesId = (int) $filters['entities_id'];
        if ($entitiesId > 0) {
            $ids = [$entitiesId];
            if (function_exists('getSonsOf')) {
                try {
                    foreach ((array) getSonsOf('glpi_entities', $entitiesId) as $id) {
                        $ids[] = (int) $id;
                    }
                } catch (\Throwable $e) {
                    // mantém apenas a entidade selecionada
                }
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
                return $id >= 0;
            })));
            $sql .= ' AND `b`.`entities_id` IN (' . implode(',', $ids) . ')';
        }
        return $sql;
    }

    /**
     * @param array<string,mixed> $filters
     */
    private static function boardFilterSql(array $filters): string
    {
        $ids = array_map('intval', (array) $filters['boards_id']);
        if ($ids === []) {
            return '';
        }
        return ' AND `b`.`id` IN (' . implode(',', $ids) . ')';
    }

    /**
     * @param array<string,mixed> $filters
     */
    private static function periodSql(array $filters): string
    {
        $sql = '';
        if ($filters['date_from'] !== '') {
            $sql .= ' AND `c`.`date_creation` >= ' . self::quote($filters['date_from'] . ' 00:00:00');
        }
        if ($filters['date_to'] !== '') {
            $sql .= ' AND `c`.`date_creation` <= ' . self::quote($filters['date_to'] . ' 23:59:59');
        }
        return $sql;
    }

    /**
     * @param array<string,mixed> $filters
     */
    private static function categoryFilterSql(array $filters): string
    {
        $keys = [];
        foreach ((array) $filters['list_type'] as $key) {
            $keys[] = self::quote(self::getCategoryKey((string) $key));
        }
        if ($keys === []) {
            return '';
        }
        return ' AND ' . self::categoryCaseSql() . ' IN (' . implode(',', array_unique($keys)) . ')';
    }

    private static function categoryCaseSql(string $alias = 'l'): string
    {
        $alias = str_replace('`', '', $alias);
        $cases = '';
        foreach (array_keys(self::CATEGORY_LABELS) as $key) {
            if ($key === 'none') {
                continue;
            }
            $cases .= ' WHEN ' . self::quote($key) . ' THEN ' . self::quote($key);
        }
        return 'CASE LOWER(TRIM(`' . $alias . '`.`list_type`))' . $cases . ' ELSE ' . self::quote('none') . ' END';
    }

    /**
     * @param array<string,mixed> $filters
     */
    private static function byBoardSql(array $filters): string
    {
        $sql = 'SELECT `b`.`id`, `b`.`name`, COUNT(`c`.`id`) AS `cards`,'
            . ' COALESCE(SUM(CASE WHEN `c`.`tickets_id` > 0 THEN 1 ELSE 0 END), 0) AS `linked`,'
            . ' COALESCE(SUM(CASE WHEN `c`.`is_maintenance` = 1 THEN 1 ELSE 0 END), 0) AS `maintenance`,'
            . ' COALESCE(SUM(CASE WHEN `c`.`is_archived` = 1 THEN 1 ELSE 0 END), 0) AS `archived`'
            . ' FROM `glpi_plugin_kanpro_boards` `b`'
            . ' LEFT JOIN `glpi_plugin_kanpro_cards` `c` ON `c`.`plugin_kanpro_boards_id` = `b`.`id`'
            . ' LEFT JOIN `glpi_plugin_kanpro_lists` `l` ON `l`.`id` = `c`.`plugin_kanpro_lists_id`'
            . ' WHERE ' . self::boardVisibilitySql()
            . self::entitySql($filters)
            . self::boardFilterSql($filters);
        if (!$filters['include_archived']) {
            $sql .= ' AND `b`.`is_archived` = 0';
        }
        $sql .= ' AND (`c`.`id` IS NULL OR (' . self::cardConditionSql($filters) . '))'
            . ' GROUP BY `b`.`id`, `b`.`name`'
            . ' ORDER BY `b`.`name` ASC, `b`.`id` ASC';
        return $sql;
    }

    /**
     * Condições de lista/card usadas no LEFT JOIN (sem o alias b já filtrado).
     *
     * @param array<string,mixed> $filters
     */
    private static function cardConditionSql(array $filters): string
    {
        $sql = '1=1' . self::listVisibilitySql();
        if (!$filters['include_archived']) {
            $sql .= ' AND `l`.`is_archived` = 0 AND `c`.`is_archived` = 0';
        }
        $sql .= self::periodSql($filters);
        $sql .= self::categoryFilterSql($filters);
        return $sql;
    }

    /**
     * @param array<string,mixed> $filters
     */
    private static function matrixSql(array $filters): string
    {
        $categorySql = self::categoryCaseSql();
        return 'SELECT `b`.`id` AS `board_id`, `b`.`name` AS `board`,'
            . ' ' . $categorySql . ' AS `category_key`, COUNT(`c`.`id`) AS `count`'
            . self::cardFromSql() . self::cardWhereSql($filters)
            . ' GROUP BY `b`.`id`, `b`.`name`, ' . $categorySql
            . ' ORDER BY `b`.`name` ASC, `b`.`id` ASC';
    }

    /**
     * @param array<string,mixed> $filters
     */
    private static function byListSql(array $filters): string
    {
        $cardConditions = [];
        if (!$filters['include_archived']) {
            $cardConditions[] = '`c`.`is_archived` = 0';
        }
        if ($filters['date_from'] !== '') {
            $cardConditions[] = '`c`.`date_creation` >= ' . self::quote($filters['date_from'] . ' 00:00:00');
        }
        if ($filters['date_to'] !== '') {
            $cardConditions[] = '`c`.`date_creation` <= ' . self::quote($filters['date_to'] . ' 23:59:59');
        }
        $cardCondition = $cardConditions === [] ? '1=1' : implode(' AND ', $cardConditions);

        $sql = 'SELECT `b`.`name` AS `board`, `l`.`name` AS `list`, `l`.`list_type`,'
            . ' COUNT(`c`.`id`) AS `count`'
            . ' FROM `glpi_plugin_kanpro_boards` `b`'
            . ' INNER JOIN `glpi_plugin_kanpro_lists` `l` ON `l`.`plugin_kanpro_boards_id` = `b`.`id`'
            . ' LEFT JOIN `glpi_plugin_kanpro_cards` `c` ON `c`.`plugin_kanpro_lists_id` = `l`.`id`'
            . ' WHERE ' . self::boardVisibilitySql()
            . self::entitySql($filters)
            . self::boardFilterSql($filters)
            . self::listVisibilitySql()
            . self::categoryFilterSql($filters);
        if (!$filters['include_archived']) {
            $sql .= ' AND `b`.`is_archived` = 0 AND `l`.`is_archived` = 0';
        }
        $sql .= ' AND (`c`.`id` IS NULL OR (' . $cardCondition . '))'
            . ' GROUP BY `b`.`id`, `b`.`name`, `l`.`id`, `l`.`name`, `l`.`list_type`, `l`.`rank`'
            . ' ORDER BY `b`.`name` ASC, `b`.`id` ASC, `l`.`rank` ASC, `l`.`id` ASC';
        return $sql;
    }

    // ------------------------------------------------------------------
    // Detalhes — responsáveis
    // ------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,string> cards_id => nome
     */
    private static function hydrateOwners(array $rows): array
    {
        $owners = [];
        if ($rows === []) {
            return $owners;
        }

        $cardIds = [];
        foreach ($rows as $row) {
            $cid = (int) $row['id'];
            $cardIds[$cid] = $cid;
        }
        $cardIds = array_values($cardIds);

        if (self::hasTable(self::CARD_MEMBERS) && self::hasTable('glpi_users')) {
            $memberRows = self::fetchAll(
                'SELECT `cm`.`plugin_kanpro_cards_id` AS `card_id`, `u`.`name` AS `user_name`'
                . ' FROM `' . self::CARD_MEMBERS . '` `cm`'
                . ' INNER JOIN `glpi_users` `u` ON `u`.`id` = `cm`.`users_id`'
                . ' WHERE `cm`.`plugin_kanpro_cards_id` IN (' . implode(',', $cardIds) . ')'
                . ' ORDER BY `cm`.`id` ASC'
            );
            foreach ($memberRows as $member) {
                $cid = (int) $member['card_id'];
                $memberName = trim((string) $member['user_name']);
                if (!isset($owners[$cid]) && $memberName !== '') {
                    $owners[$cid] = $memberName;
                }
            }
        }

        $missingCreators = [];
        foreach ($rows as $row) {
            $cid = (int) $row['id'];
            $uid = (int) ($row['users_id'] ?? 0);
            if (!isset($owners[$cid]) && $uid > 0) {
                $missingCreators[$uid] = $uid;
            }
        }

        $creatorNames = [];
        if ($missingCreators !== [] && self::hasTable('glpi_users')) {
            $creatorRows = self::fetchAll(
                'SELECT `id`, `name` FROM `glpi_users` WHERE `id` IN ('
                . implode(',', array_values($missingCreators)) . ')'
            );
            foreach ($creatorRows as $creator) {
                $creatorNames[(int) $creator['id']] = (string) $creator['name'];
            }
        }

        foreach ($rows as $row) {
            $cid = (int) $row['id'];
            if (isset($owners[$cid])) {
                continue;
            }
            $uid = (int) ($row['users_id'] ?? 0);
            if ($uid > 0) {
                $owners[$cid] = (string) ($creatorNames[$uid] ?? '');
            }
        }

        return $owners;
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private static function emptyReport(array $filters): array
    {
        return [
            'ok'          => true,
            'error'       => null,
            'total'       => 0,
            'filters'     => $filters,
            'kpis'        => [
                'cards'       => 0,
                'linked'      => 0,
                'maintenance' => 0,
                'archived'    => 0,
                'boards'      => 0,
            ],
            'by_board'    => [],
            'matrix'      => [
                'categories' => [],
                'rows'       => [],
                'totals'     => [],
                'grand'      => 0,
            ],
            'by_category' => [],
            'by_list'     => [],
        ];
    }

    private static function hasTable(string $table): bool
    {
        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'tableExists')) {
            return false;
        }
        try {
            return (bool) $DB->tableExists($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function entityLabel(int $entityId): string
    {
        if (!self::hasTable('glpi_entities')) {
            return '#' . $entityId;
        }
        try {
            $rows = self::fetchAll('SELECT `name` FROM `glpi_entities` WHERE `id` = ' . (int) $entityId . ' LIMIT 1');
            $name = isset($rows[0]['name']) ? trim((string) $rows[0]['name']) : '';
            return $name === '' ? '#' . $entityId : $name;
        } catch (\Throwable $e) {
            return '#' . $entityId;
        }
    }

    private static function quote(string $value): string
    {
        global $DB;
        if (isset($DB) && is_object($DB) && method_exists($DB, 'quoteValue')) {
            try {
                return (string) $DB->quoteValue($value);
            } catch (\Throwable $e) {
                // fallback manual
            }
        }
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function fetchAll(string $sql): array
    {
        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'doQuery')) {
            return [];
        }
        $out = [];
        $result = $DB->doQuery($sql);
        if (is_object($result) && method_exists($result, 'fetch_assoc')) {
            while (($row = $result->fetch_assoc()) !== null) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
        }
        return $out;
    }
}
