<?php
/**
 * Relatórios de chamados GLPI — filtros, colunas, lista e resumos.
 *
 * Somente SELECT. Reusa os helpers de compat (mafasa_entity_sql etc).
 * Respeita entidade ativa; exclui lixeira por padrão.
 *
 * Compat: GLPI 11.0.0–12.0.99, PHP >= 8.2.
 */

namespace GlpiPlugin\Mafasa;

class TicketsReport
{
    /** Página da tela e teto do impresso. */
    public const PAGE_SIZE = 50;
    public const PRINT_LIMIT = 5000;

    // ------------------------------------------------------------------
    // Permissão e disponibilidade
    // ------------------------------------------------------------------

    public static function canView(): bool
    {
        // Direito próprio do plugin (Configurar → Perfis → aba Relatórios).
        if (!function_exists('mafasa_can_access') || !mafasa_can_access()) {
            return false;
        }
        if (!class_exists('\\Ticket') || !is_callable(['\\Ticket', 'canView'])) {
            return false;
        }
        try {
            return (bool) \Ticket::canView();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * O plugin Kanpro (quadros) está instalado com as tabelas necessárias?
     */
    public static function kanproAvailable(): bool
    {
        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'tableExists')) {
            return false;
        }
        try {
            return $DB->tableExists('glpi_plugin_kanpro_boards')
                && $DB->tableExists('glpi_plugin_kanpro_cards');
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Mapas de rótulos
    // ------------------------------------------------------------------

    /**
     * @return array<int,string>
     */
    public static function statusLabels(): array
    {
        return [
            1 => 'Novo',
            2 => 'Atribuído',
            3 => 'Planejado',
            4 => 'Aguardando',
            5 => 'Resolvido',
            6 => 'Fechado',
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function priorityLabels(): array
    {
        return [
            1 => 'Muito baixa',
            2 => 'Baixa',
            3 => 'Média',
            4 => 'Alta',
            5 => 'Muito alta',
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function typeLabels(): array
    {
        return [
            1 => 'Incidente',
            2 => 'Demanda',
        ];
    }

    // ------------------------------------------------------------------
    // Filtros
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{label:string,kind:string,choices?:array<int,string>,dropdown?:string}>
     */
    public static function getFilters(): array
    {
        $out = [];
        $out['status'] = self::choiceFilter('Status', self::statusLabels());
        $out['type'] = self::choiceFilter('Tipo', self::typeLabels());
        $out['priority'] = self::choiceFilter('Prioridade', self::priorityLabels());
        $out['urgency'] = self::choiceFilter('Urgência', self::priorityLabels());
        $out['impact'] = self::choiceFilter('Impacto', self::priorityLabels());
        $out['itilcategories_id'] = ['label' => 'Categoria', 'kind' => 'dropdown', 'dropdown' => 'ITILCategory'];
        $out['users_id'] = ['label' => 'Solicitante', 'kind' => 'dropdown', 'dropdown' => 'User'];
        $out['users_id_tech'] = ['label' => 'Técnico', 'kind' => 'dropdown', 'dropdown' => 'User'];
        $out['groups_id'] = ['label' => 'Grupo atribuído', 'kind' => 'dropdown', 'dropdown' => 'Group'];
        if (self::kanproAvailable()) {
            $out['boards_id'] = self::choiceFilter('Quadro Kanpro', self::boardChoices());
        }
        $out['name'] = ['label' => 'Assunto', 'kind' => 'text'];
        $out['date_creation_from'] = ['label' => 'Abertura (de)', 'kind' => 'date'];
        $out['date_creation_to'] = ['label' => 'Abertura (até)', 'kind' => 'date'];
        $out['date_solved_from'] = ['label' => 'Resolução (de)', 'kind' => 'date'];
        $out['date_solved_to'] = ['label' => 'Resolução (até)', 'kind' => 'date'];
        $out['date_closed_from'] = ['label' => 'Fechamento (de)', 'kind' => 'date'];
        $out['date_closed_to'] = ['label' => 'Fechamento (até)', 'kind' => 'date'];
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
            'id'                 => 'ID',
            'name'               => 'Assunto',
            'status'             => 'Status',
            'type'               => 'Tipo',
            'priority'           => 'Prioridade',
            'urgency'            => 'Urgência',
            'impact'             => 'Impacto',
            'itilcategories_id'  => 'Categoria',
            'users_id_recipient' => 'Solicitante',
            'users_id_tech'      => 'Técnicos',
            'groups_id'          => 'Grupos',
            'entities_id'        => 'Entidade',
            'date'               => 'Abertura',
            'takeintoaccountdate'=> '1ª resposta',
            'solvedate'          => 'Resolução',
            'closedate'          => 'Fechamento',
            'waiting_duration'   => 'Tempo em espera',
            'actiontime'         => 'Tempo de trabalho',
            'elapsed'            => 'Dias em aberto',
        ];
    }

    /**
     * Tipo de dado de uma coluna (front/formatação).
     */
    public static function getColumnKind(string $key): string
    {
        if (in_array($key, ['date', 'takeintoaccountdate', 'solvedate', 'closedate'], true)) {
            return 'date';
        }
        if (in_array($key, ['waiting_duration', 'actiontime'], true)) {
            return 'duration';
        }
        if ($key === 'elapsed') {
            return 'int';
        }
        if (in_array($key, ['id'], true)) {
            return 'int';
        }
        return 'text';
    }

    /**
     * @return array<int,string>
     */
    public static function getDefaultColumns(): array
    {
        return [
            'id', 'name', 'status', 'priority', 'urgency', 'itilcategories_id',
            'users_id_tech', 'date', 'solvedate',
        ];
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

        $columns = self::getColumns();
        if ($sort === '' || !in_array($sort, array_keys($columns), true)) {
            $sort = 'id';
        }

        $where = self::buildWhere($params);
        $select = self::listSelect();
        $joins = self::listJoins();
        $sortExpr = self::sortExpr($sort);

        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'doQuery')) {
            return self::errorResult(1, 1, 'Banco de dados indisponível.');
        }

        $from = ' FROM `glpi_tickets` t' . $joins . ' ' . $where;

        try {
            $res = $DB->doQuery('SELECT COUNT(DISTINCT t.`id`) AS total' . $from);
            $row = (is_object($res) && method_exists($res, 'fetch_assoc')) ? $res->fetch_assoc() : null;
            $total = is_array($row) ? (int) ($row['total'] ?? 0) : 0;
        } catch (\Throwable $e) {
            return self::errorResult(1, 1, self::sqlFailMessage($e));
        }

        $maxPage = max(1, (int) ceil($total / $limit));
        $page = min(max(1, $page), $maxPage);
        $offset = ($page - 1) * $limit;

        $sql = 'SELECT ' . $select . $from
            . ' GROUP BY t.`id` ORDER BY ' . $sortExpr . ' ' . $order
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
     * @return array<string,string> modo => rótulo
     */
    public static function getSummaryModes(): array
    {
        return [
            'status'     => 'Por status',
            'type'       => 'Por tipo',
            'priority'   => 'Por prioridade',
            'urgency'    => 'Por urgência',
            'impact'     => 'Por impacto',
            'category'   => 'Por categoria',
            'technician' => 'Por técnico (atribuições)',
            'requester'  => 'Por solicitante',
            'group'      => 'Por grupo atribuído',
            'month'      => 'Por mês de abertura',
            'entity'     => 'Por entidade',
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
            $mode = 'status';
        }

        $where = self::buildWhere($params);
        $metricSel = ', COUNT(DISTINCT t.`id`) AS mf_total'
            . ', AVG(CASE WHEN t.`takeintoaccountdate` IS NOT NULL'
            . ' THEN TIMESTAMPDIFF(SECOND, t.`date`, t.`takeintoaccountdate`) END) AS mf_avg_first'
            . ', AVG(CASE WHEN t.`solvedate` IS NOT NULL'
            . ' THEN TIMESTAMPDIFF(SECOND, t.`date`, t.`solvedate`) END) AS mf_avg_resolution';

        switch ($mode) {
            case 'type':
                $sql = 'SELECT t.`type` AS mf_key' . $metricSel
                    . ' FROM `glpi_tickets` t ' . $where
                    . ' GROUP BY t.`type` ORDER BY mf_total DESC';
                break;
            case 'priority':
                $sql = 'SELECT t.`priority` AS mf_key' . $metricSel
                    . ' FROM `glpi_tickets` t ' . $where
                    . ' GROUP BY t.`priority` ORDER BY mf_total DESC';
                break;
            case 'urgency':
                $sql = 'SELECT t.`urgency` AS mf_key' . $metricSel
                    . ' FROM `glpi_tickets` t ' . $where
                    . ' GROUP BY t.`urgency` ORDER BY mf_total DESC';
                break;
            case 'impact':
                $sql = 'SELECT t.`impact` AS mf_key' . $metricSel
                    . ' FROM `glpi_tickets` t ' . $where
                    . ' GROUP BY t.`impact` ORDER BY mf_total DESC';
                break;
            case 'category':
                $sql = 'SELECT COALESCE(c.`completename`, \'Sem categoria\') AS mf_key'
                    . $metricSel
                    . ' FROM `glpi_tickets` t'
                    . ' LEFT JOIN `glpi_itilcategories` c ON c.`id` = t.`itilcategories_id`'
                    . ' ' . $where
                    . ' GROUP BY COALESCE(c.`completename`, \'Sem categoria\')'
                    . ' ORDER BY mf_total DESC';
                break;
            case 'technician':
                $sql = 'SELECT u.`name` AS mf_key' . str_replace('COUNT(DISTINCT t.`id`)', 'COUNT(DISTINCT tu.`tickets_id`)', $metricSel)
                    . ' FROM `glpi_tickets_users` tu'
                    . ' INNER JOIN `glpi_users` u ON u.`id` = tu.`users_id`'
                    . ' INNER JOIN `glpi_tickets` t ON t.`id` = tu.`tickets_id`'
                    . ' ' . self::replaceTicketAlias($where)
                    . ' AND tu.`type` = 1'
                    . ' GROUP BY u.`name` ORDER BY mf_total DESC';
                break;
            case 'requester':
                $sql = 'SELECT u.`name` AS mf_key' . str_replace('COUNT(DISTINCT t.`id`)', 'COUNT(DISTINCT tu.`tickets_id`)', $metricSel)
                    . ' FROM `glpi_tickets_users` tu'
                    . ' INNER JOIN `glpi_users` u ON u.`id` = tu.`users_id`'
                    . ' INNER JOIN `glpi_tickets` t ON t.`id` = tu.`tickets_id`'
                    . ' ' . self::replaceTicketAlias($where)
                    . ' AND tu.`type` = 2'
                    . ' GROUP BY u.`name` ORDER BY mf_total DESC';
                break;
            case 'group':
                $sql = 'SELECT g.`name` AS mf_key' . str_replace('COUNT(DISTINCT t.`id`)', 'COUNT(DISTINCT tg.`tickets_id`)', $metricSel)
                    . ' FROM `glpi_groups_tickets` tg'
                    . ' INNER JOIN `glpi_groups` g ON g.`id` = tg.`groups_id`'
                    . ' INNER JOIN `glpi_tickets` t ON t.`id` = tg.`tickets_id`'
                    . ' ' . self::replaceTicketAlias($where)
                    . ' AND tg.`type` = 1'
                    . ' GROUP BY g.`name` ORDER BY mf_total DESC';
                break;
            case 'month':
                $sql = 'SELECT DATE_FORMAT(t.`date`, \'%Y-%m\') AS mf_key' . $metricSel
                    . ' FROM `glpi_tickets` t ' . $where
                    . ' GROUP BY DATE_FORMAT(t.`date`, \'%Y-%m\')'
                    . ' ORDER BY mf_key ASC';
                break;
            case 'entity':
                $sql = 'SELECT e.`name` AS mf_key' . $metricSel
                    . ' FROM `glpi_tickets` t'
                    . ' LEFT JOIN `glpi_entities` e ON e.`id` = t.`entities_id`'
                    . ' ' . $where
                    . ' GROUP BY e.`name` ORDER BY mf_total DESC';
                break;
            case 'status':
            default:
                $sql = 'SELECT t.`status` AS mf_key' . $metricSel
                    . ' FROM `glpi_tickets` t ' . $where
                    . ' GROUP BY t.`status` ORDER BY mf_total DESC';
                break;
        }

        $totalsSql = 'SELECT COUNT(DISTINCT t.`id`) AS mf_grand,'
            . ' AVG(CASE WHEN t.`takeintoaccountdate` IS NOT NULL'
            . ' THEN TIMESTAMPDIFF(SECOND, t.`date`, t.`takeintoaccountdate`) END) AS mf_avg_first,'
            . ' AVG(CASE WHEN t.`solvedate` IS NOT NULL'
            . ' THEN TIMESTAMPDIFF(SECOND, t.`date`, t.`solvedate`) END) AS mf_avg_resolution'
            . ' FROM `glpi_tickets` t ' . $where;

        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'doQuery')) {
            return ['rows' => [], 'totals' => [], 'error' => 'Banco de dados indisponível.'];
        }

        $totals = ['grand' => 0, 'avg_first' => null, 'avg_resolution' => null];
        try {
            $res = $DB->doQuery($totalsSql);
            $row = (is_object($res) && method_exists($res, 'fetch_assoc')) ? $res->fetch_assoc() : null;
            if (is_array($row)) {
                $totals['grand'] = (int) ($row['mf_grand'] ?? 0);
                $totals['avg_first'] = ($row['mf_avg_first'] !== null) ? (float) $row['mf_avg_first'] : null;
                $totals['avg_resolution'] = ($row['mf_avg_resolution'] !== null) ? (float) $row['mf_avg_resolution'] : null;
            }
        } catch (\Throwable $e) {
            return ['rows' => [], 'totals' => [], 'error' => self::sqlFailMessage($e)];
        }

        $rows = [];
        try {
            $res = $DB->doQuery($sql);
            while (is_object($res) && ($row = $res->fetch_assoc()) !== null) {
                $rows[] = [
                    'mf_key'          => ($row['mf_key'] !== null) ? (string) $row['mf_key'] : null,
                    'mf_label'        => self::summaryLabel($mode, $row['mf_key'] ?? null),
                    'mf_total'        => (string) (int) ($row['mf_total'] ?? 0),
                    'mf_avg_first'    => ($row['mf_avg_first'] !== null) ? (string) (float) $row['mf_avg_first'] : null,
                    'mf_avg_resolution' => ($row['mf_avg_resolution'] !== null) ? (string) (float) $row['mf_avg_resolution'] : null,
                ];
            }
        } catch (\Throwable $e) {
            return ['rows' => [], 'totals' => [], 'error' => self::sqlFailMessage($e)];
        }

        return ['rows' => $rows, 'totals' => $totals, 'error' => null];
    }

    /**
     * Rótulo pt-BR de uma linha de resumo.
     */
    public static function summaryLabel(string $mode, $key): string
    {
        if ($key === null || $key === '') {
            return '—';
        }
        switch ($mode) {
            case 'status':
                return self::statusLabels()[(int) $key] ?? (string) $key;
            case 'type':
                return self::typeLabels()[(int) $key] ?? (string) $key;
            case 'priority':
            case 'urgency':
            case 'impact':
                return self::priorityLabels()[(int) $key] ?? (string) $key;
            default:
                return (string) $key;
        }
    }

    // ------------------------------------------------------------------
    // Helpers de formatação
    // ------------------------------------------------------------------

    /**
     * Segundos → "2d 3h" / "3h 20m" / "45m".
     */
    public static function formatDuration($seconds): string
    {
        if ($seconds === null || $seconds === '') {
            return '';
        }
        $s = (int) $seconds;
        if ($s <= 0) {
            return '0m';
        }
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        if ($d > 0) {
            return $d . 'd ' . $h . 'h';
        }
        if ($h > 0) {
            return $h . 'h ' . $m . 'm';
        }
        return $m . 'm';
    }

    /**
     * Descrição pt-BR de um valor de filtro (resumo de filtros no impresso).
     */
    public static function describeFilterValue(string $key, string $value): string
    {
        if (in_array($key, ['status', 'type', 'priority', 'urgency', 'impact'], true)) {
            if ($key === 'type') {
                return self::typeLabels()[(int) $value] ?? $value;
            }
            if ($key === 'status') {
                return self::statusLabels()[(int) $value] ?? $value;
            }
            return self::priorityLabels()[(int) $value] ?? $value;
        }
        if ($key === 'boards_id') {
            $choices = self::boardChoices();
            return $choices[(int) $value] ?? $value;
        }
        if (in_array($key, ['itilcategories_id'], true)) {
            return self::lookupName('glpi_itilcategories', (int) $value) ?: $value;
        }
        if (in_array($key, ['users_id', 'users_id_tech'], true)) {
            return self::lookupName('glpi_users', (int) $value) ?: $value;
        }
        if ($key === 'groups_id') {
            return self::lookupName('glpi_groups', (int) $value) ?: $value;
        }
        return $value;
    }

    // ------------------------------------------------------------------
    // Internos — SQL
    // ------------------------------------------------------------------

    /**
     * WHERE comum (lixeira + entidade + filtros).
     */
    private static function buildWhere(array $params): string
    {
        $sql = 'WHERE t.`is_deleted` = 0';
        if (function_exists('mafasa_entity_sql')) {
            $sql .= (string) \mafasa_entity_sql('t');
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
                $map = [
                    'date_creation_from' => ['t.`date`', '>=', $date . ' 00:00:00'],
                    'date_creation_to'   => ['t.`date`', '<=', $date . ' 23:59:59'],
                    'date_solved_from'   => ['t.`solvedate`', '>=', $date . ' 00:00:00'],
                    'date_solved_to'     => ['t.`solvedate`', '<=', $date . ' 23:59:59'],
                    'date_closed_from'   => ['t.`closedate`', '>=', $date . ' 00:00:00'],
                    'date_closed_to'     => ['t.`closedate`', '<=', $date . ' 23:59:59'],
                ];
                if (isset($map[$key])) {
                    [$col, $op, $val] = $map[$key];
                    $sql .= ' AND ' . $col . ' ' . $op . ' ' . self::quote($val);
                }
                continue;
            }

            if ($key === 'boards_id') {
                $id = (int) $value;
                if ($id > 0 && self::kanproAvailable()) {
                    $sql .= ' AND EXISTS (SELECT 1 FROM `glpi_plugin_kanpro_cards` kc'
                        . ' WHERE kc.`tickets_id` = t.`id` AND kc.`plugin_kanpro_boards_id` = ' . $id . ')';
                }
                continue;
            }

            if ($key === 'itilcategories_id') {
                $id = (int) $value;
                if ($id > 0) {
                    $sql .= ' AND EXISTS (SELECT 1 FROM `glpi_itilcategories` ic'
                        . ' WHERE ic.`id` = t.`itilcategories_id`'
                        . ' AND (ic.`id` = ' . $id . ' OR ic.`completename` LIKE (SELECT CONCAT(ic2.`completename`, \'%\')'
                        . ' FROM `glpi_itilcategories` ic2 WHERE ic2.`id` = ' . $id . ')))';
                }
                continue;
            }

            if ($key === 'users_id') {
                $id = (int) $value;
                if ($id > 0) {
                    $sql .= ' AND EXISTS (SELECT 1 FROM `glpi_tickets_users` rf'
                        . ' WHERE rf.`tickets_id` = t.`id` AND rf.`users_id` = ' . $id . ' AND rf.`type` = 2)';
                }
                continue;
            }

            if ($key === 'users_id_tech') {
                $id = (int) $value;
                if ($id > 0) {
                    $sql .= ' AND EXISTS (SELECT 1 FROM `glpi_tickets_users` tf'
                        . ' WHERE tf.`tickets_id` = t.`id` AND tf.`users_id` = ' . $id . ' AND tf.`type` = 1)';
                }
                continue;
            }

            if ($key === 'groups_id') {
                $id = (int) $value;
                if ($id > 0) {
                    $sql .= ' AND EXISTS (SELECT 1 FROM `glpi_groups_tickets` gf'
                        . ' WHERE gf.`tickets_id` = t.`id` AND gf.`groups_id` = ' . $id . ' AND gf.`type` = 1)';
                }
                continue;
            }

            if ($key === 'name') {
                if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                    if (mb_strlen($value) > 255) {
                        $value = mb_substr($value, 0, 255);
                    }
                }
                $sql .= ' AND t.`name` LIKE ' . self::quote('%' . $value . '%');
                continue;
            }

            if (in_array($key, ['status', 'type', 'priority', 'urgency', 'impact'], true)) {
                $sql .= ' AND t.`' . $key . '` = ' . (int) $value;
            }
        }
        return $sql;
    }

    /**
     * Substitui o alias 't' do WHERE para contextos com JOIN explícito
     * (resumos por técnico/solicitante/grupo usam o mesmo alias t).
     */
    private static function replaceTicketAlias(string $where): string
    {
        return $where;
    }

    /**
     * SELECT das colunas da lista (alias mf_*).
     */
    private static function listSelect(): string
    {
        return 'SELECT t.`id` AS mf_id, t.`name` AS mf_name, t.`status` AS mf_status,'
            . ' t.`type` AS mf_type, t.`priority` AS mf_priority, t.`urgency` AS mf_urgency,'
            . ' t.`impact` AS mf_impact,'
            . ' c.`completename` AS mf_itilcategories_id,'
            . ' ru.`name` AS mf_users_id_recipient,'
            . ' (SELECT GROUP_CONCAT(u.`name` ORDER BY u.`name` SEPARATOR \', \')'
            . '  FROM `glpi_tickets_users` tu JOIN `glpi_users` u ON u.`id` = tu.`users_id`'
            . '  WHERE tu.`tickets_id` = t.`id` AND tu.`type` = 1) AS mf_users_id_tech,'
            . ' (SELECT GROUP_CONCAT(g.`name` ORDER BY g.`name` SEPARATOR \', \')'
            . '  FROM `glpi_groups_tickets` tg JOIN `glpi_groups` g ON g.`id` = tg.`groups_id`'
            . '  WHERE tg.`tickets_id` = t.`id` AND tg.`type` = 1) AS mf_groups_id,'
            . ' e.`name` AS mf_entities_id,'
            . ' t.`date` AS mf_date, t.`takeintoaccountdate` AS mf_takeintoaccountdate,'
            . ' t.`solvedate` AS mf_solvedate, t.`closedate` AS mf_closedate,'
            . ' t.`waiting_duration` AS mf_waiting_duration, t.`actiontime` AS mf_actiontime,'
            . ' CASE WHEN t.`solvedate` IS NOT NULL'
            . '  THEN TIMESTAMPDIFF(DAY, t.`date`, t.`solvedate`)'
            . '  ELSE TIMESTAMPDIFF(DAY, t.`date`, NOW()) END AS mf_elapsed';
    }

    /**
     * JOINs da lista.
     */
    private static function listJoins(): string
    {
        return ' LEFT JOIN `glpi_itilcategories` c ON c.`id` = t.`itilcategories_id`'
            . ' LEFT JOIN `glpi_users` ru ON ru.`id` = t.`users_id_recipient`'
            . ' LEFT JOIN `glpi_entities` e ON e.`id` = t.`entities_id`';
    }

    private static function sortExpr(string $sort): string
    {
        $map = [
            'id'                  => 't.`id`',
            'name'                => 't.`name`',
            'status'              => 't.`status`',
            'type'                => 't.`type`',
            'priority'            => 't.`priority`',
            'urgency'             => 't.`urgency`',
            'impact'              => 't.`impact`',
            'itilcategories_id'   => 'mf_itilcategories_id',
            'users_id_recipient'  => 'mf_users_id_recipient',
            'users_id_tech'       => 'mf_users_id_tech',
            'groups_id'           => 'mf_groups_id',
            'entities_id'         => 'mf_entities_id',
            'date'                => 't.`date`',
            'takeintoaccountdate' => 't.`takeintoaccountdate`',
            'solvedate'           => 't.`solvedate`',
            'closedate'           => 't.`closedate`',
            'waiting_duration'    => 't.`waiting_duration`',
            'actiontime'          => 't.`actiontime`',
            'elapsed'             => 'mf_elapsed',
        ];
        return $map[$sort] ?? 't.`id`';
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
     * @return array{label:string,kind:string,choices:array<int,string>}
     */
    private static function choiceFilter(string $label, array $choices): array
    {
        return ['label' => $label, 'kind' => 'choices', 'choices' => $choices];
    }

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
                if (is_array($row) && isset($row['name'])) {
                    return (string) $row['name'];
                }
            }
        } catch (\Throwable $e) {
            return '';
        }
        return '';
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
