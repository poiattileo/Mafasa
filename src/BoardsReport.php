<?php
/**
 * Relatórios dos quadros Kanpro — cartões por quadro/lista/membro, prazos,
 * checklists e vínculo com chamados GLPI.
 *
 * Somente SELECT. Guard defensivo: se o Kanpro não estiver instalado
 * (tabelas ausentes), available() retorna false e as telas mostram aviso.
 *
 * Compat: GLPI 11.0.0–12.0.99, PHP >= 8.2.
 */

namespace GlpiPlugin\Mafasa;

class BoardsReport
{
    public const PAGE_SIZE = 50;
    public const PRINT_LIMIT = 5000;

    /** Cache por requisição (menu chama available() a cada load). */
    private static ?bool $available_cache = null;

    // ------------------------------------------------------------------
    // Disponibilidade e permissão
    // ------------------------------------------------------------------

    /**
     * Kanpro instalado com as tabelas mínimas?
     */
    public static function available(): bool
    {
        if (self::$available_cache !== null) {
            return self::$available_cache;
        }
        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'tableExists')) {
            return self::$available_cache = false;
        }
        try {
            return self::$available_cache = $DB->tableExists('glpi_plugin_kanpro_boards')
                && $DB->tableExists('glpi_plugin_kanpro_lists')
                && $DB->tableExists('glpi_plugin_kanpro_cards');
        } catch (\Throwable $e) {
            return self::$available_cache = false;
        }
    }

    /**
     * Permissão do Kanpro (direito próprio do plugin).
     */
    public static function canView(): bool
    {
        if (!class_exists('Session') || !method_exists('Session', 'haveRight')) {
            return false;
        }
        $read = defined('READ') ? (int) READ : 1;
        try {
            return (bool) \Session::haveRight('plugin_kanpro', $read);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function isReady(): bool
    {
        return self::available() && self::canView();
    }

    // ------------------------------------------------------------------
    // Filtros
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{label:string,kind:string,choices?:array<int,string>}>
     */
    public static function getFilters(): array
    {
        $out = [];
        $out['boards_id'] = ['label' => 'Quadro', 'kind' => 'choices', 'choices' => self::boardChoices()];
        $out['lists_id'] = ['label' => 'Coluna (lista)', 'kind' => 'choices', 'choices' => self::listChoices()];
        $out['members_id'] = ['label' => 'Membro', 'kind' => 'choices', 'choices' => self::memberChoices()];
        $out['due_status'] = [
            'label'   => 'Situação do prazo',
            'kind'    => 'choices',
            'choices' => [
                'overdue' => 'Atrasado',
                'ok'      => 'No prazo',
                'today'   => 'Vence hoje',
                'none'    => 'Sem prazo',
            ],
        ];
        $out['has_ticket'] = [
            'label'   => 'Vinculado a chamado',
            'kind'    => 'choices',
            'choices' => ['1' => 'Sim', '0' => 'Não'],
        ];
        $out['is_completed'] = [
            'label'   => 'Concluído',
            'kind'    => 'choices',
            'choices' => ['1' => 'Sim', '0' => 'Não'],
        ];
        $out['name'] = ['label' => 'Nome do cartão', 'kind' => 'text'];
        $out['date_creation_from'] = ['label' => 'Criação (de)', 'kind' => 'date'];
        $out['date_creation_to'] = ['label' => 'Criação (até)', 'kind' => 'date'];
        return $out;
    }

    // ------------------------------------------------------------------
    // Colunas
    // ------------------------------------------------------------------

    /**
     * @return array<string,string>
     */
    public static function getColumns(): array
    {
        return [
            'id'            => 'ID',
            'name'          => 'Cartão',
            'board'         => 'Quadro',
            'list'          => 'Coluna',
            'members'       => 'Membros',
            'due_status'    => 'Prazo',
            'due_date'      => 'Vencimento',
            'is_completed'  => 'Concluído',
            'tickets_id'    => 'Chamado',
            'checklist'     => 'Checklist',
            'entities_id'   => 'Entidade',
            'users_id'      => 'Criador',
            'date_creation' => 'Criação',
            'date_mod'      => 'Última alteração',
        ];
    }

    public static function getColumnKind(string $key): string
    {
        if (in_array($key, ['due_date', 'date_creation', 'date_mod'], true)) {
            return 'date';
        }
        if ($key === 'id' || $key === 'tickets_id') {
            return 'int';
        }
        if ($key === 'is_completed') {
            return 'bool';
        }
        return 'text';
    }

    /**
     * @return array<int,string>
     */
    public static function getDefaultColumns(): array
    {
        return ['id', 'name', 'board', 'list', 'members', 'due_status', 'due_date', 'tickets_id'];
    }

    // ------------------------------------------------------------------
    // Lista paginada
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $params GET
     * @return array{rows:array<int,array<string,?string>>,total:int,page:int,max_page:int,error:?string}
     */
    public static function buildList(array $params, int $page, int $limit, string $sort, string $order): array
    {
        $page = max(1, $page);
        if ($limit < 1) {
            $limit = self::PAGE_SIZE;
        }
        if ($limit > self::PRINT_LIMIT) {
            $limit = self::PRINT_LIMIT;
        }
        $order = (strtoupper($order) === 'DESC') ? 'DESC' : 'ASC';

        if (!self::available()) {
            return self::errorResult(1, 1, 'O plugin Kanpro não está instalado (tabelas de quadros ausentes).');
        }

        $columns = self::getColumns();
        if ($sort === '' || !in_array($sort, array_keys($columns), true)) {
            $sort = 'id';
        }

        $where = self::buildWhere($params);
        $from = ' FROM `glpi_plugin_kanpro_cards` c'
            . ' INNER JOIN `glpi_plugin_kanpro_boards` b ON b.`id` = c.`plugin_kanpro_boards_id`'
            . ' LEFT JOIN `glpi_plugin_kanpro_lists` l ON l.`id` = c.`plugin_kanpro_lists_id`'
            . ' LEFT JOIN `glpi_users` cu ON cu.`id` = c.`users_id`'
            . ' LEFT JOIN `glpi_entities` ce ON ce.`id` = c.`entities_id`'
            . ' ' . $where;

        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'doQuery')) {
            return self::errorResult(1, 1, 'Banco de dados indisponível.');
        }

        try {
            $res = $DB->doQuery('SELECT COUNT(*) AS total' . $from);
            $row = (is_object($res) && method_exists($res, 'fetch_assoc')) ? $res->fetch_assoc() : null;
            $total = is_array($row) ? (int) ($row['total'] ?? 0) : 0;
        } catch (\Throwable $e) {
            return self::errorResult(1, 1, self::sqlFailMessage($e));
        }

        $maxPage = max(1, (int) ceil($total / $limit));
        $page = min(max(1, $page), $maxPage);
        $offset = ($page - 1) * $limit;

        $sql = 'SELECT c.`id` AS mf_id, c.`name` AS mf_name, b.`name` AS mf_board,'
            . ' l.`name` AS mf_list,'
            . ' (SELECT GROUP_CONCAT(u.`name` ORDER BY u.`name` SEPARATOR \', \')'
            . '  FROM `glpi_plugin_kanpro_cards_members` cm'
            . '  JOIN `glpi_users` u ON u.`id` = cm.`users_id`'
            . '  WHERE cm.`plugin_kanpro_cards_id` = c.`id`) AS mf_members,'
            . self::dueStatusExpr('c') . ' AS mf_due_status,'
            . ' c.`due_date` AS mf_due_date, c.`is_completed` AS mf_is_completed,'
            . ' CASE WHEN c.`tickets_id` > 0 THEN c.`tickets_id` ELSE NULL END AS mf_tickets_id,'
            . ' (SELECT CONCAT(CAST(SUM(ci.`is_checked`) AS CHAR), \'/\', CAST(COUNT(*) AS CHAR))'
            . '  FROM `glpi_plugin_kanpro_checklist_items` ci'
            . '  INNER JOIN `glpi_plugin_kanpro_checklists` ch ON ch.`id` = ci.`plugin_kanpro_checklists_id`'
            . '  WHERE ch.`plugin_kanpro_cards_id` = c.`id`) AS mf_checklist,'
            . ' ce.`name` AS mf_entities_id, cu.`name` AS mf_users_id,'
            . ' c.`date_creation` AS mf_date_creation, c.`date_mod` AS mf_date_mod'
            . $from
            . ' ORDER BY ' . self::sortExpr($sort) . ' ' . $order
            . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $rows = [];
        try {
            $res = $DB->doQuery($sql);
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
                'error'    => self::sqlFailMessage($e),
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

    // ------------------------------------------------------------------
    // Resumos agregados
    // ------------------------------------------------------------------

    /**
     * @return array<string,string>
     */
    public static function getSummaryModes(): array
    {
        return [
            'board'      => 'Cartões por quadro',
            'list'       => 'Cartões por coluna',
            'member'     => 'Cartões por membro',
            'due_status' => 'Cartões por situação de prazo',
            'ticket'     => 'Cartões com/sem chamado',
            'month'      => 'Cartões por mês de criação',
            'checklist'  => 'Progresso de checklists',
        ];
    }

    /**
     * @param array<string,mixed> $params GET
     * @return array{rows:array<int,array<string,?string>>,totals:array<string,mixed>,error:?string}
     */
    public static function buildSummary(string $mode, array $params): array
    {
        $modes = self::getSummaryModes();
        if (!isset($modes[$mode])) {
            $mode = 'board';
        }
        if (!self::available()) {
            return ['rows' => [], 'totals' => [], 'error' => 'O plugin Kanpro não está instalado (tabelas de quadros ausentes).'];
        }

        $where = self::buildWhere($params);
        $cardsFrom = ' FROM `glpi_plugin_kanpro_cards` c'
            . ' INNER JOIN `glpi_plugin_kanpro_boards` b ON b.`id` = c.`plugin_kanpro_boards_id`'
            . ' LEFT JOIN `glpi_plugin_kanpro_lists` l ON l.`id` = c.`plugin_kanpro_lists_id`'
            . ' ' . $where;

        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'doQuery')) {
            return ['rows' => [], 'totals' => [], 'error' => 'Banco de dados indisponível.'];
        }

        $totals = [];
        try {
            $res = $DB->doQuery('SELECT COUNT(*) AS mf_grand, SUM(c.`is_completed`) AS mf_done'
                . ' FROM `glpi_plugin_kanpro_cards` c'
                . ' INNER JOIN `glpi_plugin_kanpro_boards` b ON b.`id` = c.`plugin_kanpro_boards_id`'
                . ' LEFT JOIN `glpi_plugin_kanpro_lists` l ON l.`id` = c.`plugin_kanpro_lists_id`'
                . ' ' . $where);
            $row = (is_object($res) && method_exists($res, 'fetch_assoc')) ? $res->fetch_assoc() : null;
            if (is_array($row)) {
                $totals['grand'] = (int) ($row['mf_grand'] ?? 0);
                $totals['done'] = (int) ($row['mf_done'] ?? 0);
            }
        } catch (\Throwable $e) {
            return ['rows' => [], 'totals' => [], 'error' => self::sqlFailMessage($e)];
        }

        switch ($mode) {
            case 'list':
                $sql = 'SELECT l.`name` AS mf_key, COUNT(*) AS mf_total'
                    . $cardsFrom
                    . ' GROUP BY l.`name` ORDER BY mf_total DESC';
                break;
            case 'member':
                $sql = 'SELECT u.`name` AS mf_key, COUNT(*) AS mf_total'
                    . ' FROM `glpi_plugin_kanpro_cards_members` cm'
                    . ' INNER JOIN `glpi_users` u ON u.`id` = cm.`users_id`'
                    . ' INNER JOIN `glpi_plugin_kanpro_cards` c ON c.`id` = cm.`plugin_kanpro_cards_id`'
                    . ' INNER JOIN `glpi_plugin_kanpro_boards` b ON b.`id` = c.`plugin_kanpro_boards_id`'
                    . ' LEFT JOIN `glpi_plugin_kanpro_lists` l ON l.`id` = c.`plugin_kanpro_lists_id`'
                    . ' ' . $where
                    . ' GROUP BY u.`name` ORDER BY mf_total DESC';
                break;
            case 'due_status':
                $sql = 'SELECT ' . self::dueStatusExpr('c') . ' AS mf_key, COUNT(*) AS mf_total'
                    . $cardsFrom
                    . ' GROUP BY ' . self::dueStatusExpr('c')
                    . ' ORDER BY mf_total DESC';
                break;
            case 'ticket':
                $sql = 'SELECT CASE WHEN c.`tickets_id` > 0 THEN \'Com chamado\' ELSE \'Sem chamado\' END AS mf_key,'
                    . ' COUNT(*) AS mf_total'
                    . $cardsFrom
                    . ' GROUP BY CASE WHEN c.`tickets_id` > 0 THEN \'Com chamado\' ELSE \'Sem chamado\' END'
                    . ' ORDER BY mf_total DESC';
                break;
            case 'month':
                $sql = 'SELECT DATE_FORMAT(c.`date_creation`, \'%Y-%m\') AS mf_key, COUNT(*) AS mf_total'
                    . $cardsFrom
                    . ' GROUP BY DATE_FORMAT(c.`date_creation`, \'%Y-%m\')'
                    . ' ORDER BY mf_key ASC';
                break;
            case 'checklist':
                // Uma linha: total de itens, concluídos, percentual médio.
                $sql = 'SELECT \'Itens de checklist\' AS mf_key,'
                    . ' CAST(COUNT(*) AS CHAR) AS mf_total,'
                    . ' CAST(COALESCE(SUM(ci.`is_checked`), 0) AS CHAR) AS mf_checked'
                    . ' FROM `glpi_plugin_kanpro_checklist_items` ci'
                    . ' INNER JOIN `glpi_plugin_kanpro_checklists` ch ON ch.`id` = ci.`plugin_kanpro_checklists_id`'
                    . ' INNER JOIN `glpi_plugin_kanpro_cards` c ON c.`id` = ch.`plugin_kanpro_cards_id`'
                    . ' INNER JOIN `glpi_plugin_kanpro_boards` b ON b.`id` = c.`plugin_kanpro_boards_id`'
                    . ' LEFT JOIN `glpi_plugin_kanpro_lists` l ON l.`id` = c.`plugin_kanpro_lists_id`'
                    . ' ' . $where;
                break;
            case 'board':
            default:
                $sql = 'SELECT b.`name` AS mf_key, COUNT(*) AS mf_total'
                    . $cardsFrom
                    . ' GROUP BY b.`name` ORDER BY mf_total DESC';
                break;
        }

        $rows = [];
        try {
            $res = $DB->doQuery($sql);
            while (is_object($res) && ($row = $res->fetch_assoc()) !== null) {
                $rows[] = [
                    'mf_key'     => ($row['mf_key'] !== null) ? (string) $row['mf_key'] : null,
                    'mf_label'   => ($row['mf_key'] !== null) ? (string) $row['mf_key'] : '—',
                    'mf_total'   => (string) (int) ($row['mf_total'] ?? 0),
                    'mf_checked' => isset($row['mf_checked']) && $row['mf_checked'] !== null
                        ? (string) (int) $row['mf_checked']
                        : null,
                ];
            }
        } catch (\Throwable $e) {
            return ['rows' => [], 'totals' => [], 'error' => self::sqlFailMessage($e)];
        }

        return ['rows' => $rows, 'totals' => $totals, 'error' => null];
    }

    /**
     * Descrição pt-BR de um valor de filtro.
     */
    public static function describeFilterValue(string $key, string $value): string
    {
        if ($key === 'boards_id') {
            return self::boardChoices()[(int) $value] ?? $value;
        }
        if ($key === 'lists_id') {
            return self::listChoices()[(int) $value] ?? $value;
        }
        if ($key === 'members_id') {
            return self::memberChoices()[(int) $value] ?? $value;
        }
        if ($key === 'due_status') {
            $map = ['overdue' => 'Atrasado', 'ok' => 'No prazo', 'today' => 'Vence hoje', 'none' => 'Sem prazo'];
            return $map[$value] ?? $value;
        }
        if ($key === 'has_ticket') {
            return ($value === '1') ? 'Sim' : 'Não';
        }
        if ($key === 'is_completed') {
            return ($value === '1') ? 'Sim' : 'Não';
        }
        return $value;
    }

    // ------------------------------------------------------------------
    // Internos — SQL
    // ------------------------------------------------------------------

    /**
     * Expressão de situação do prazo (Atrasado/No prazo/Vence hoje/Sem prazo).
     */
    private static function dueStatusExpr(string $alias): string
    {
        return 'CASE'
            . ' WHEN ' . $alias . '.`is_completed` = 1 THEN \'Concluído\''
            . ' WHEN ' . $alias . '.`due_date` IS NULL THEN \'Sem prazo\''
            . ' WHEN DATE(' . $alias . '.`due_date`) < CURDATE() THEN \'Atrasado\''
            . ' WHEN DATE(' . $alias . '.`due_date`) = CURDATE() THEN \'Vence hoje\''
            . ' ELSE \'No prazo\' END';
    }

    private static function buildWhere(array $params): string
    {
        $sql = 'WHERE c.`is_archived` = 0';
        if (function_exists('mafasa_entity_sql')) {
            $sql .= (string) \mafasa_entity_sql('c');
        }

        foreach (self::getFilters() as $key => $def) {
            $raw = $params[$key] ?? null;
            if ($raw === null || is_array($raw)) {
                continue;
            }
            $value = trim((string) $raw);
            if ($value === '') {
                continue;
            }
            $kind = (string) ($def['kind'] ?? 'text');

            if ($kind === 'date') {
                $date = self::normalizeDate($value);
                if ($date === null) {
                    continue;
                }
                if ($key === 'date_creation_from') {
                    $sql .= ' AND c.`date_creation` >= ' . self::quote($date . ' 00:00:00');
                } elseif ($key === 'date_creation_to') {
                    $sql .= ' AND c.`date_creation` <= ' . self::quote($date . ' 23:59:59');
                }
                continue;
            }

            if ($key === 'boards_id') {
                $id = (int) $value;
                if ($id > 0) {
                    $sql .= ' AND c.`plugin_kanpro_boards_id` = ' . $id;
                }
                continue;
            }

            if ($key === 'lists_id') {
                $id = (int) $value;
                if ($id > 0) {
                    $sql .= ' AND c.`plugin_kanpro_lists_id` = ' . $id;
                }
                continue;
            }

            if ($key === 'members_id') {
                $id = (int) $value;
                if ($id > 0) {
                    $sql .= ' AND EXISTS (SELECT 1 FROM `glpi_plugin_kanpro_cards_members` cmx'
                        . ' WHERE cmx.`plugin_kanpro_cards_id` = c.`id` AND cmx.`users_id` = ' . $id . ')';
                }
                continue;
            }

            if ($key === 'due_status') {
                $map = [
                    'overdue' => ' AND c.`is_completed` = 0 AND c.`due_date` IS NOT NULL AND DATE(c.`due_date`) < CURDATE()',
                    'today'   => ' AND c.`is_completed` = 0 AND DATE(c.`due_date`) = CURDATE()',
                    'ok'      => ' AND c.`is_completed` = 0 AND c.`due_date` IS NOT NULL AND DATE(c.`due_date`) > CURDATE()',
                    'none'    => ' AND c.`due_date` IS NULL',
                ];
                if (isset($map[$value])) {
                    $sql .= $map[$value];
                }
                continue;
            }

            if ($key === 'has_ticket') {
                if ($value === '1') {
                    $sql .= ' AND c.`tickets_id` > 0';
                } elseif ($value === '0') {
                    $sql .= ' AND c.`tickets_id` = 0';
                }
                continue;
            }

            if ($key === 'is_completed') {
                $sql .= ' AND c.`is_completed` = ' . (int) $value;
                continue;
            }

            if ($key === 'name') {
                if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                    if (mb_strlen($value) > 255) {
                        $value = mb_substr($value, 0, 255);
                    }
                }
                $sql .= ' AND c.`name` LIKE ' . self::quote('%' . $value . '%');
            }
        }
        return $sql;
    }

    private static function sortExpr(string $sort): string
    {
        $map = [
            'id'            => 'c.`id`',
            'name'          => 'c.`name`',
            'board'         => 'b.`name`',
            'list'          => 'l.`name`',
            'members'       => 'mf_members',
            'due_status'    => 'mf_due_status',
            'due_date'      => 'c.`due_date`',
            'is_completed'  => 'c.`is_completed`',
            'tickets_id'    => 'c.`tickets_id`',
            'checklist'     => 'mf_checklist',
            'entities_id'   => 'ce.`name`',
            'users_id'      => 'cu.`name`',
            'date_creation' => 'c.`date_creation`',
            'date_mod'      => 'c.`date_mod`',
        ];
        return $map[$sort] ?? 'c.`id`';
    }

    /**
     * @return array<int,string>
     */
    private static function boardChoices(): array
    {
        global $DB;
        $out = [];
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'request')) {
            return $out;
        }
        try {
            $it = $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_plugin_kanpro_boards',
                'WHERE'  => ['is_archived' => 0],
                'ORDER'  => ['name ASC', 'id ASC'],
            ]);
            foreach ($it as $row) {
                if (is_array($row)) {
                    $out[(int) $row['id']] = (string) $row['name'];
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $out;
    }

    /**
     * @return array<int,string>
     */
    private static function listChoices(): array
    {
        global $DB;
        $out = [];
        if (!self::available() || !isset($DB) || !is_object($DB) || !method_exists($DB, 'request')) {
            return $out;
        }
        try {
            $it = $DB->request([
                'SELECT' => ['l.id', 'l.name', 'b.name AS board_name'],
                'FROM'   => 'glpi_plugin_kanpro_lists l',
                'INNER JOIN' => [
                    'glpi_plugin_kanpro_boards b' => ['ON' => ['b' => 'id', 'l' => 'plugin_kanpro_boards_id']],
                ],
                'WHERE'  => ['l.is_archived' => 0],
                'ORDER'  => ['b.name ASC', 'l.rank ASC', 'l.name ASC'],
            ]);
            foreach ($it as $row) {
                if (is_array($row)) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id > 0) {
                        $out[$id] = (string) ($row['board_name'] ?? '') . ' › ' . (string) ($row['name'] ?? '');
                    }
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $out;
    }

    /**
     * @return array<int,string>
     */
    private static function memberChoices(): array
    {
        global $DB;
        $out = [];
        if (!self::available() || !isset($DB) || !is_object($DB) || !method_exists($DB, 'request')) {
            return $out;
        }
        try {
            $it = $DB->request([
                'SELECT' => ['DISTINCT u.id', 'u.name'],
                'FROM'   => 'glpi_plugin_kanpro_cards_members cm',
                'INNER JOIN' => [
                    'glpi_users u' => ['ON' => ['u' => 'id', 'cm' => 'users_id']],
                ],
                'ORDER'  => ['u.name ASC'],
            ]);
            foreach ($it as $row) {
                if (is_array($row)) {
                    $out[(int) $row['id']] = (string) $row['name'];
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $out;
    }

    private static function quote(string $value): string
    {
        global $DB;
        if (isset($DB) && is_object($DB) && method_exists($DB, 'quoteValue')) {
            try {
                return (string) $DB->quoteValue($value);
            } catch (\Throwable $e) {
                // fallback abaixo
            }
        }
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private static function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    /**
     * @return array{rows:array<int,array<string,?string>>,total:int,page:int,max_page:int,error:?string}
     */
    private static function errorResult(int $page, int $maxPage, string $message): array
    {
        return [
            'rows'     => [],
            'total'    => 0,
            'page'     => max(1, $page),
            'max_page' => max(1, $maxPage),
            'error'    => $message,
        ];
    }

    /**
     * Mensagem de falha de SQL com o detalhe do banco (facilita o diagnóstico).
     */
    private static function sqlFailMessage(\Throwable $e): string
    {
        $msg = trim((string) $e->getMessage());
        $pos = strpos($msg, ' in ');
        if ($pos !== false) {
            $msg = substr($msg, 0, $pos);
        }
        if (function_exists('mb_strlen') && mb_strlen($msg) > 300) {
            $msg = mb_substr($msg, 0, 300) . '...';
        }
        return 'Não foi possível executar a consulta do relatório.'
            . ($msg !== '' ? ' [' . $msg . ']' : '');
    }
}
