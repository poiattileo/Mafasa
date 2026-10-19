<?php
/**
 * Relatório de chamados do GLPI — motor de consultas do Mafasa.
 *
 * Contrato público consumido por front/chamados.php e front/print.php:
 * canView, getDefaults, getStatusChoices, getPriorityChoices, getTypeChoices,
 * buildReport, getDetails, describeFilters, getMonthlyWindow e formatDuration.
 *
 * Somente SELECT. Compatível com GLPI 11.0.0–12.0.99 e PHP >= 8.2.
 * Classes do core são sempre referenciadas com guardas (class_exists /
 * method_exists) para não exigir carregamento no include do arquivo.
 */

namespace GlpiPlugin\Mafasa;

final class TicketReport
{
    private const MONTHS_WINDOW = 12;
    private const DETAIL_LIMIT = 5000;

    /** @var array<int,string> */
    private const FALLBACK_STATUS = [
        1  => 'Novo',
        2  => 'Atribuído',
        3  => 'Planejado',
        4  => 'Pendente',
        5  => 'Resolvido',
        6  => 'Fechado',
        10 => 'Aprovação',
    ];

    /** @var array<int,string> */
    private const FALLBACK_PRIORITY = [
        1 => 'Muito baixa',
        2 => 'Baixa',
        3 => 'Média',
        4 => 'Alta',
        5 => 'Muito alta',
    ];

    /** @var array<int,string> */
    private const MONTH_ABBR = [
        1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr',
        5 => 'mai', 6 => 'jun', 7 => 'jul', 8 => 'ago',
        9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez',
    ];

    /**
     * Usuário pode abrir o relatório de chamados?
     */
    public static function canView(): bool
    {
        if (!class_exists('Session')) {
            return false;
        }
        try {
            if (method_exists('Session', 'haveRightsOr')) {
                return (bool) \Session::haveRightsOr(
                    'ticket',
                    [1, 1024, 2048, 4096, 262144]
                );
            }
            if (method_exists('Session', 'haveRight')) {
                return (bool) \Session::haveRight('ticket', 1);
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    /**
     * Normaliza os filtros recebidos (GET ou array equivalente).
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function getDefaults(array $params = []): array
    {
        $today = date('Y-m-d');
        $to = self::normalizeDate((string) ($params['date_to'] ?? '')) ?? $today;
        $from = self::normalizeDate((string) ($params['date_from'] ?? ''));
        if ($from === null) {
            $from = self::shiftDate($to, '-29 days');
        }
        if ($from > $to) {
            $swap = $from;
            $from = $to;
            $to = $swap;
        }

        $statusAllowed = array_keys(self::getStatusChoices());
        $typeAllowed = array_keys(self::getTypeChoices());
        $priorityAllowed = array_keys(self::getPriorityChoices());

        $topLimit = self::toPositiveInt($params['top_limit'] ?? 15);
        if ($topLimit <= 0) {
            $topLimit = 15;
        }

        $showDetails = 0;
        if (isset($params['show_details']) && is_scalar($params['show_details'])) {
            $raw = strtolower(trim((string) $params['show_details']));
            $showDetails = ($raw === '1' || $raw === 'true' || $raw === 'on' || $raw === 'yes') ? 1 : 0;
        }

        return [
            'date_from'          => $from,
            'date_to'            => $to,
            'entities_id'        => self::toPositiveInt($params['entities_id'] ?? 0),
            'status'             => self::choiceIds($params['status'] ?? [], $statusAllowed),
            'type'               => self::choiceIds($params['type'] ?? [], $typeAllowed),
            'priority'           => self::choiceIds($params['priority'] ?? [], $priorityAllowed),
            'itilcategories_id'  => self::toPositiveInt($params['itilcategories_id'] ?? 0),
            'users_id_tech'      => self::toPositiveInt($params['users_id_tech'] ?? 0),
            'groups_id'          => self::toPositiveInt($params['groups_id'] ?? 0),
            'users_id_requester' => self::toPositiveInt($params['users_id_requester'] ?? 0),
            'top_limit'          => $topLimit,
            'show_details'       => $showDetails,
        ];
    }

    /**
     * Status disponíveis (1–6 e 10 quando o core o expõe).
     *
     * @return array<int,string>
     */
    public static function getStatusChoices(): array
    {
        $core = null;
        if (class_exists('Ticket') && method_exists('Ticket', 'getAllStatusArray')) {
            try {
                $core = \Ticket::getAllStatusArray();
            } catch (\Throwable $e) {
                $core = null;
            }
        }
        if (is_array($core) && $core !== []) {
            $out = [];
            foreach ([1, 2, 3, 4, 5, 6, 10] as $id) {
                if (isset($core[$id]) && trim((string) $core[$id]) !== '') {
                    $out[$id] = (string) $core[$id];
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
        return self::FALLBACK_STATUS;
    }

    /**
     * Prioridades 1–5, com rótulo do core quando disponível.
     *
     * @return array<int,string>
     */
    public static function getPriorityChoices(): array
    {
        $out = [];
        $hasCore = class_exists('Ticket') && method_exists('Ticket', 'getPriorityName');
        foreach ([1, 2, 3, 4, 5] as $id) {
            $label = self::FALLBACK_PRIORITY[$id];
            if ($hasCore) {
                try {
                    $name = trim((string) \Ticket::getPriorityName($id));
                    if ($name !== '') {
                        $label = $name;
                    }
                } catch (\Throwable $e) {
                    // mantém o fallback em português
                }
            }
            $out[$id] = $label;
        }
        return $out;
    }

    /**
     * Tipos de chamado do GLPI.
     *
     * @return array<int,string>
     */
    public static function getTypeChoices(): array
    {
        return [
            1 => 'Incidente',
            2 => 'Requisição',
        ];
    }

    /**
     * Monta KPIs, quebras, evolução mensal e metadados do relatório.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function buildReport(array $params): array
    {
        $filters = self::getDefaults($params);

        try {
            global $DB;
            if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'doQuery')) {
                throw new \RuntimeException('Banco de dados indisponível.');
            }

            $where = self::whereSql($filters);
            $aggregate = self::fetchRows(
                'SELECT COUNT(DISTINCT t.`id`) AS total,'
                . ' COUNT(DISTINCT CASE WHEN t.`status` NOT IN (5, 6) THEN t.`id` END) AS open_count,'
                . ' COUNT(DISTINCT CASE WHEN t.`status` = 5 THEN t.`id` END) AS solved_count,'
                . ' COUNT(DISTINCT CASE WHEN t.`status` = 6 THEN t.`id` END) AS closed_count,'
                . ' AVG(CASE WHEN t.`takeintoaccount_delay_stat` > 0 THEN t.`takeintoaccount_delay_stat` END) AS avg_take,'
                . ' AVG(CASE WHEN t.`solve_delay_stat` > 0 THEN t.`solve_delay_stat` END) AS avg_solve'
                . ' FROM `glpi_tickets` t WHERE 1=1' . $where
            );
            $base = (is_array($aggregate[0] ?? null)) ? $aggregate[0] : [];
            $total = (int) ($base['total'] ?? 0);

            $kpis = [
                'total'     => $total,
                'open'      => (int) ($base['open_count'] ?? 0),
                'solved'    => (int) ($base['solved_count'] ?? 0),
                'closed'    => (int) ($base['closed_count'] ?? 0),
                'avg_take'  => self::nullableFloat($base['avg_take'] ?? null),
                'avg_solve' => self::nullableFloat($base['avg_solve'] ?? null),
            ];

            $statusCounts = self::groupCount('t.`status`', $where);
            $priorityCounts = self::groupCount('t.`priority`', $where);
            $typeCounts = self::groupCount('t.`type`', $where);

            return [
                'ok'          => true,
                'error'       => null,
                'total'       => $total,
                'filters'     => $filters,
                'kpis'        => $kpis,
                'by_status'   => self::fixedBreakdown($statusCounts, self::getStatusChoices(), $total),
                'by_priority' => self::fixedBreakdown($priorityCounts, self::getPriorityChoices(), $total),
                'by_type'     => self::fixedBreakdown($typeCounts, self::getTypeChoices(), $total),
                'by_category' => self::byCategory($where, $total, (int) $filters['top_limit']),
                'by_tech'     => self::byTech($where, $total),
                'by_group'    => self::byGroup($where, $total),
                'by_entity'   => self::byEntity($where, $total),
                'monthly'     => self::monthlyBreakdown($filters),
            ];
        } catch (\Throwable $e) {
            return self::emptyReport($filters, 'Não foi possível gerar o relatório de chamados.');
        }
    }

    /**
     * Lista detalhada paginada. Técnico/requerente são hidratados por página.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function getDetails(array $params, int $page = 1, int $limit = 50): array
    {
        $filters = self::getDefaults($params);
        $page = max(1, $page);
        $limit = max(1, min(self::DETAIL_LIMIT, $limit));

        try {
            $where = self::whereSql($filters);
            $countRows = self::fetchRows(
                'SELECT COUNT(DISTINCT t.`id`) AS total FROM `glpi_tickets` t WHERE 1=1' . $where
            );
            $total = (int) ($countRows[0]['total'] ?? 0);
            $maxPage = max(1, (int) ceil($total / $limit));
            $offset = ($page - 1) * $limit;

            $rows = self::fetchRows(
                'SELECT t.`id` AS id, t.`name` AS name, t.`status` AS status,'
                . ' t.`priority` AS priority, t.`type` AS type,'
                . ' t.`itilcategories_id` AS itilcategories_id, t.`entities_id` AS entities_id,'
                . ' t.`date` AS date, t.`solvedate` AS solvedate, t.`closedate` AS closedate,'
                . ' c.`completename` AS category, e.`name` AS entity'
                . ' FROM `glpi_tickets` t'
                . ' LEFT JOIN `glpi_itilcategories` c ON c.`id` = t.`itilcategories_id`'
                . ' LEFT JOIN `glpi_entities` e ON e.`id` = t.`entities_id`'
                . ' WHERE 1=1' . $where
                . ' ORDER BY t.`date` DESC, t.`id` DESC'
                . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
            );

            $ids = [];
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            $techs = self::hydrateActors(array_values($ids), 2);
            $requesters = self::hydrateActors(array_values($ids), 1);

            $statusLabels = self::getStatusChoices();
            $priorityLabels = self::getPriorityChoices();
            $typeLabels = self::getTypeChoices();

            $out = [];
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $status = (int) ($row['status'] ?? 0);
                $priority = (int) ($row['priority'] ?? 0);
                $type = (int) ($row['type'] ?? 0);
                $category = trim((string) ($row['category'] ?? ''));
                $entity = trim((string) ($row['entity'] ?? ''));
                $endDate = self::dateOrEmpty($row['closedate'] ?? null);
                if ($endDate === '') {
                    $endDate = self::dateOrEmpty($row['solvedate'] ?? null);
                }

                $out[] = [
                    'id'        => $id,
                    'name'      => (string) ($row['name'] ?? ''),
                    'status'    => $statusLabels[$status] ?? ('Status ' . $status),
                    'priority'  => $priorityLabels[$priority] ?? (string) $priority,
                    'type'      => $typeLabels[$type] ?? (string) $type,
                    'category'  => $category !== '' ? $category : 'Sem categoria',
                    'tech'      => implode(', ', $techs[$id] ?? []),
                    'requester' => implode(', ', $requesters[$id] ?? []),
                    'entity'    => $entity,
                    'date'      => (string) ($row['date'] ?? ''),
                    'end_date'  => $endDate,
                ];
            }

            return [
                'rows'     => $out,
                'total'    => $total,
                'page'     => $page,
                'max_page' => $maxPage,
            ];
        } catch (\Throwable $e) {
            return [
                'rows'     => [],
                'total'    => 0,
                'page'     => $page,
                'max_page' => 1,
            ];
        }
    }

    /**
     * Resumo em português apenas dos filtros ativos.
     *
     * @param array<string,mixed> $params
     * @return array<int,string>
     */
    public static function describeFilters(array $params): array
    {
        $filters = self::getDefaults($params);
        $out = [];

        $from = self::displayDate((string) $filters['date_from']);
        $to = self::displayDate((string) $filters['date_to']);
        $out[] = ($from === $to)
            ? 'Período: ' . $from
            : 'Período: ' . $from . ' a ' . $to;

        $entityId = (int) $filters['entities_id'];
        if ($entityId > 0) {
            $name = self::lookupName('glpi_entities', $entityId);
            $out[] = 'Entidade: ' . ($name !== '' ? $name : '#' . $entityId);
        }

        if ($filters['status'] !== []) {
            $out[] = 'Status: ' . self::labelsFor($filters['status'], self::getStatusChoices());
        }
        if ($filters['type'] !== []) {
            $out[] = 'Tipo: ' . self::labelsFor($filters['type'], self::getTypeChoices());
        }
        if ($filters['priority'] !== []) {
            $out[] = 'Prioridade: ' . self::labelsFor($filters['priority'], self::getPriorityChoices());
        }

        $categoryId = (int) $filters['itilcategories_id'];
        if ($categoryId > 0) {
            $name = self::lookupCategoryName($categoryId);
            $out[] = 'Categoria ITIL: ' . ($name !== '' ? $name : '#' . $categoryId);
        }

        $techId = (int) $filters['users_id_tech'];
        if ($techId > 0) {
            $name = self::lookupName('glpi_users', $techId);
            $out[] = 'Técnico atribuído: ' . ($name !== '' ? $name : '#' . $techId);
        }

        $groupId = (int) $filters['groups_id'];
        if ($groupId > 0) {
            $name = self::lookupName('glpi_groups', $groupId);
            $out[] = 'Grupo atribuído: ' . ($name !== '' ? $name : '#' . $groupId);
        }

        $requesterId = (int) $filters['users_id_requester'];
        if ($requesterId > 0) {
            $name = self::lookupName('glpi_users', $requesterId);
            $out[] = 'Requerente: ' . ($name !== '' ? $name : '#' . $requesterId);
        }

        return $out;
    }

    /**
     * Janela de 12 meses ancorada no mês de date_to.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function getMonthlyWindow(array $params): array
    {
        $filters = self::getDefaults($params);
        $to = (string) $filters['date_to'];
        $base = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($base === false) {
            $base = new \DateTimeImmutable(date('Y-m-d'));
        }

        $start = $base->modify('first day of this month')->format('Y-m-d 00:00:00');
        $end = $base->modify('last day of this month')->format('Y-m-d 23:59:59');

        $months = [];
        for ($i = self::MONTHS_WINDOW - 1; $i >= 0; $i--) {
            $month = $base->modify('first day of this month')->modify('-' . $i . ' months');
            $months[] = [
                'ym'    => $month->format('Y-m'),
                'label' => self::MONTH_ABBR[(int) $month->format('n')] . '/' . $month->format('Y'),
            ];
        }

        return [
            'start'  => $start,
            'end'    => $end,
            'months' => $months,
        ];
    }

    /**
     * Formata segundos como duração compacta (ex.: "2d 3h 4m").
     *
     * @param mixed $seconds
     */
    public static function formatDuration($seconds): string
    {
        if ($seconds === null || !is_numeric($seconds)) {
            return '—';
        }
        $total = (int) floor((float) $seconds);
        if ($total <= 0) {
            return '—';
        }

        $parts = [];
        $days = intdiv($total, 86400);
        $total -= $days * 86400;
        $hours = intdiv($total, 3600);
        $total -= $hours * 3600;
        $minutes = intdiv($total, 60);
        $remaining = $total - ($minutes * 60);

        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }
        if ($remaining > 0) {
            $parts[] = $remaining . 's';
        }

        return implode(' ', $parts);
    }

    /**
     * Cláusula de visibilidade equivalente a Ticket::canViewItem().
     */
    private static function visibilitySql(): string
    {
        if (self::hasTicketRight(1024)) {
            return '';
        }

        $branches = [];
        $uid = self::currentUserId();

        if ($uid > 0 && self::hasTicketRight(1)) {
            $branches[] = '(t.`users_id_recipient` = ' . $uid
                . ' OR EXISTS (SELECT 1 FROM `glpi_tickets_users` v_req'
                . ' WHERE v_req.`tickets_id` = t.`id` AND v_req.`type` = 1 AND v_req.`users_id` = ' . $uid . ')'
                . ' OR EXISTS (SELECT 1 FROM `glpi_tickets_users` v_obs'
                . ' WHERE v_obs.`tickets_id` = t.`id` AND v_obs.`type` = 3 AND v_obs.`users_id` = ' . $uid . '))';
        }

        if (self::hasTicketRight(2048)) {
            $groups = self::sessionGroupIds();
            if ($groups === []) {
                $branches[] = '1=0';
            } else {
                $branches[] = 'EXISTS (SELECT 1 FROM `glpi_groups_tickets` v_grp'
                    . ' WHERE v_grp.`tickets_id` = t.`id` AND v_grp.`type` IN (1, 3)'
                    . ' AND v_grp.`groups_id` IN (' . implode(',', $groups) . '))';
            }
        }

        if (self::hasTicketRight(262144)) {
            $branches[] = 't.`status` = 1';
        }

        if (self::hasTicketRight(4096)) {
            $parts = [];
            if ($uid > 0) {
                $parts[] = 'EXISTS (SELECT 1 FROM `glpi_tickets_users` v_assign'
                    . ' WHERE v_assign.`tickets_id` = t.`id` AND v_assign.`type` = 2'
                    . ' AND v_assign.`users_id` = ' . $uid . ')';
            }
            $groups = self::sessionGroupIds();
            if ($groups !== []) {
                $parts[] = 'EXISTS (SELECT 1 FROM `glpi_groups_tickets` v_assign_grp'
                    . ' WHERE v_assign_grp.`tickets_id` = t.`id` AND v_assign_grp.`type` = 2'
                    . ' AND v_assign_grp.`groups_id` IN (' . implode(',', $groups) . '))';
            }
            $branches[] = ($parts === []) ? '1=0' : '(' . implode(' OR ', $parts) . ')';
        }

        if ($branches === []) {
            return ' AND 1=0';
        }
        return ' AND (' . implode(' OR ', $branches) . ')';
    }

    /**
     * Condições comuns. Flags desligam filtros na evolução mensal.
     *
     * @param array<string,mixed> $params
     */
    private static function whereSql(
        array $params,
        bool $withStatus = true,
        bool $withDate = true,
        bool $withPriority = true,
        bool $withActors = true
    ): string {
        $sql = ' AND t.`is_deleted` = 0';

        if (function_exists('mafasa_entity_sql')) {
            $sql .= (string) \mafasa_entity_sql('t');
        }

        $entityId = (int) ($params['entities_id'] ?? 0);
        if ($entityId > 0) {
            $ids = self::sonsOf('glpi_entities', $entityId);
            $sql .= ' AND t.`entities_id` IN (' . implode(',', $ids) . ')';
        }

        $types = self::choiceIds($params['type'] ?? [], array_keys(self::getTypeChoices()));
        if ($types !== []) {
            $sql .= ' AND t.`type` IN (' . implode(',', $types) . ')';
        }

        $categoryId = (int) ($params['itilcategories_id'] ?? 0);
        if ($categoryId > 0) {
            $ids = self::sonsOf('glpi_itilcategories', $categoryId);
            $sql .= ' AND t.`itilcategories_id` IN (' . implode(',', $ids) . ')';
        }

        if ($withStatus) {
            $statuses = self::choiceIds($params['status'] ?? [], array_keys(self::getStatusChoices()));
            if ($statuses !== []) {
                $sql .= ' AND t.`status` IN (' . implode(',', $statuses) . ')';
            }
        }

        if ($withPriority) {
            $priorities = self::choiceIds($params['priority'] ?? [], array_keys(self::getPriorityChoices()));
            if ($priorities !== []) {
                $sql .= ' AND t.`priority` IN (' . implode(',', $priorities) . ')';
            }
        }

        if ($withDate) {
            $from = self::normalizeDate((string) ($params['date_from'] ?? ''));
            $to = self::normalizeDate((string) ($params['date_to'] ?? ''));
            if ($from !== null) {
                $sql .= ' AND t.`date` >= ' . self::quote($from . ' 00:00:00');
            }
            if ($to !== null) {
                $sql .= ' AND t.`date` <= ' . self::quote($to . ' 23:59:59');
            }
        }

        if ($withActors) {
            $techId = (int) ($params['users_id_tech'] ?? 0);
            if ($techId > 0) {
                $sql .= ' AND EXISTS (SELECT 1 FROM `glpi_tickets_users` f_tech'
                    . ' WHERE f_tech.`tickets_id` = t.`id` AND f_tech.`type` = 2'
                    . ' AND f_tech.`users_id` = ' . $techId . ')';
            }

            $groupId = (int) ($params['groups_id'] ?? 0);
            if ($groupId > 0) {
                $sql .= ' AND EXISTS (SELECT 1 FROM `glpi_groups_tickets` f_group'
                    . ' WHERE f_group.`tickets_id` = t.`id` AND f_group.`type` = 2'
                    . ' AND f_group.`groups_id` = ' . $groupId . ')';
            }

            $requesterId = (int) ($params['users_id_requester'] ?? 0);
            if ($requesterId > 0) {
                $sql .= ' AND EXISTS (SELECT 1 FROM `glpi_tickets_users` f_req'
                    . ' WHERE f_req.`tickets_id` = t.`id` AND f_req.`type` = 1'
                    . ' AND f_req.`users_id` = ' . $requesterId . ')';
            }
        }

        return $sql . self::visibilitySql();
    }

    /**
     * Contagem por uma expressão fixa.
     *
     * @return array<int,int>
     */
    private static function groupCount(string $expr, string $where): array
    {
        $rows = self::fetchRows(
            'SELECT ' . $expr . ' AS key_value, COUNT(DISTINCT t.`id`) AS cnt'
            . ' FROM `glpi_tickets` t WHERE 1=1' . $where
            . ' GROUP BY ' . $expr
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) ($row['key_value'] ?? 0)] = (int) ($row['cnt'] ?? 0);
        }
        return $out;
    }

    /**
     * Quebra de ordem fixa, incluindo linhas zeradas.
     *
     * @param array<int,int>    $counts
     * @param array<int,string> $labels
     * @return array<int,array{label:string,count:int,pct:float}>
     */
    private static function fixedBreakdown(array $counts, array $labels, int $total): array
    {
        $out = [];
        foreach ($labels as $id => $label) {
            $count = (int) ($counts[(int) $id] ?? 0);
            $out[] = [
                'label' => (string) $label,
                'count' => $count,
                'pct'   => self::pct($count, $total),
            ];
        }
        return $out;
    }

    /**
     * Top categorias ITIL e agrupamento residual em "Outras categorias".
     *
     * @return array<int,array{label:string,count:int,pct:float}>
     */
    private static function byCategory(string $where, int $total, int $limit): array
    {
        $rows = self::fetchRows(
            'SELECT t.`itilcategories_id` AS cid, c.`completename` AS cname,'
            . ' COUNT(DISTINCT t.`id`) AS cnt'
            . ' FROM `glpi_tickets` t'
            . ' LEFT JOIN `glpi_itilcategories` c ON c.`id` = t.`itilcategories_id`'
            . ' WHERE 1=1' . $where
            . ' GROUP BY t.`itilcategories_id`, c.`completename`'
            . ' ORDER BY COUNT(DISTINCT t.`id`) DESC, c.`completename` ASC'
        );
        usort($rows, static function (array $a, array $b): int {
            $cmp = ((int) ($b['cnt'] ?? 0)) <=> ((int) ($a['cnt'] ?? 0));
            return $cmp !== 0 ? $cmp : strcmp((string) ($a['cname'] ?? ''), (string) ($b['cname'] ?? ''));
        });

        $out = [];
        $others = 0;
        $index = 0;
        foreach ($rows as $row) {
            $count = (int) ($row['cnt'] ?? 0);
            if ($index >= $limit) {
                $others += $count;
                $index++;
                continue;
            }
            $label = trim((string) ($row['cname'] ?? ''));
            $out[] = [
                'label' => $label !== '' ? $label : 'Sem categoria',
                'count' => $count,
                'pct'   => self::pct($count, $total),
            ];
            $index++;
        }

        if ($others > 0) {
            $out[] = [
                'label' => 'Outras categorias',
                'count' => $others,
                'pct'   => self::pct($others, $total),
            ];
        }
        return $out;
    }

    /**
     * Chamados por técnico atribuído (type = 2).
     *
     * @return array<int,array{label:string,count:int,pct:float}>
     */
    private static function byTech(string $where, int $total): array
    {
        $rows = self::fetchRows(
            'SELECT tu.`users_id` AS actor_id, u.`name` AS label,'
            . ' COUNT(DISTINCT t.`id`) AS cnt'
            . ' FROM `glpi_tickets` t'
            . ' JOIN `glpi_tickets_users` tu ON tu.`tickets_id` = t.`id` AND tu.`type` = 2'
            . ' JOIN `glpi_users` u ON u.`id` = tu.`users_id`'
            . ' WHERE 1=1' . $where
            . ' GROUP BY tu.`users_id`, u.`name`'
            . ' ORDER BY COUNT(DISTINCT t.`id`) DESC, u.`name` ASC'
        );
        return self::actorRows($rows, $total, 'Usuário #');
    }

    /**
     * Chamados por grupo atribuído (type = 2).
     *
     * @return array<int,array{label:string,count:int,pct:float}>
     */
    private static function byGroup(string $where, int $total): array
    {
        $rows = self::fetchRows(
            'SELECT gt.`groups_id` AS actor_id, g.`name` AS label,'
            . ' COUNT(DISTINCT t.`id`) AS cnt'
            . ' FROM `glpi_tickets` t'
            . ' JOIN `glpi_groups_tickets` gt ON gt.`tickets_id` = t.`id` AND gt.`type` = 2'
            . ' JOIN `glpi_groups` g ON g.`id` = gt.`groups_id`'
            . ' WHERE 1=1' . $where
            . ' GROUP BY gt.`groups_id`, g.`name`'
            . ' ORDER BY COUNT(DISTINCT t.`id`) DESC, g.`name` ASC'
        );
        return self::actorRows($rows, $total, 'Grupo #');
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{label:string,count:int,pct:float}>
     */
    private static function actorRows(array $rows, int $total, string $fallbackPrefix): array
    {
        usort($rows, static function (array $a, array $b): int {
            $cmp = ((int) ($b['cnt'] ?? 0)) <=> ((int) ($a['cnt'] ?? 0));
            return $cmp !== 0 ? $cmp : strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        $out = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = $fallbackPrefix . (int) ($row['actor_id'] ?? 0);
            }
            $count = (int) ($row['cnt'] ?? 0);
            $out[] = [
                'label' => $label,
                'count' => $count,
                'pct'   => self::pct($count, $total),
            ];
        }
        return $out;
    }

    /**
     * Chamados por entidade.
     *
     * @return array<int,array{label:string,count:int,pct:float}>
     */
    private static function byEntity(string $where, int $total): array
    {
        $rows = self::fetchRows(
            'SELECT t.`entities_id` AS eid, e.`name` AS label,'
            . ' COUNT(DISTINCT t.`id`) AS cnt'
            . ' FROM `glpi_tickets` t'
            . ' LEFT JOIN `glpi_entities` e ON e.`id` = t.`entities_id`'
            . ' WHERE 1=1' . $where
            . ' GROUP BY t.`entities_id`, e.`name`'
            . ' ORDER BY COUNT(DISTINCT t.`id`) DESC, e.`name` ASC'
        );
        usort($rows, static function (array $a, array $b): int {
            $cmp = ((int) ($b['cnt'] ?? 0)) <=> ((int) ($a['cnt'] ?? 0));
            return $cmp !== 0 ? $cmp : strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        $out = [];
        foreach ($rows as $row) {
            $count = (int) ($row['cnt'] ?? 0);
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = 'Entidade #' . (int) ($row['eid'] ?? 0);
            }
            $out[] = [
                'label' => $label,
                'count' => $count,
                'pct'   => self::pct($count, $total),
            ];
        }
        return $out;
    }

    /**
     * Série de 12 meses: abertos por date, resolvidos por solvedate e
     * fechados por closedate. Honra entidade, tipo e categoria; ignora
     * status, prioridade, atores e o recorte date_from/date_to.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array{label:string,opened:int,solved:int,closed:int}>
     */
    private static function monthlyBreakdown(array $filters): array
    {
        $window = self::getMonthlyWindow($filters);
        $where = self::whereSql($filters, false, false, false, false);
        $start = self::quote((string) $window['start']);
        $end = self::quote((string) $window['end']);

        $opened = self::monthlyCount('t.`date`', $where, $start, $end);
        $solved = self::monthlyCount('t.`solvedate`', $where, $start, $end);
        $closed = self::monthlyCount('t.`closedate`', $where, $start, $end);

        $out = [];
        foreach ($window['months'] as $month) {
            $ym = (string) $month['ym'];
            $out[] = [
                'label'  => (string) $month['label'],
                'opened' => (int) ($opened[$ym] ?? 0),
                'solved' => (int) ($solved[$ym] ?? 0),
                'closed' => (int) ($closed[$ym] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @return array<string,int>
     */
    private static function monthlyCount(string $dateColumn, string $where, string $start, string $end): array
    {
        $rows = self::fetchRows(
            'SELECT DATE_FORMAT(' . $dateColumn . ', \'%Y-%m\') AS ym,'
            . ' COUNT(DISTINCT t.`id`) AS cnt'
            . ' FROM `glpi_tickets` t WHERE 1=1' . $where
            . ' AND ' . $dateColumn . ' >= ' . $start
            . ' AND ' . $dateColumn . ' <= ' . $end
            . ' GROUP BY DATE_FORMAT(' . $dateColumn . ', \'%Y-%m\')'
        );
        $out = [];
        foreach ($rows as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if ($ym !== '') {
                $out[$ym] = (int) ($row['cnt'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Nomes dos atores de um tipo (1 requerente, 2 técnico, 3 observador).
     *
     * @param array<int,int> $ticketIds
     * @return array<int,array<int,string>>
     */
    private static function hydrateActors(array $ticketIds, int $type): array
    {
        if ($ticketIds === [] || ($type !== 1 && $type !== 2 && $type !== 3)) {
            return [];
        }
        $ids = [];
        foreach ($ticketIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $rows = self::fetchRows(
            'SELECT tu.`tickets_id` AS tid, tu.`users_id` AS uid, u.`name` AS name'
            . ' FROM `glpi_tickets_users` tu'
            . ' JOIN `glpi_users` u ON u.`id` = tu.`users_id`'
            . ' WHERE tu.`tickets_id` IN (' . implode(',', array_values($ids)) . ')'
            . ' AND tu.`type` = ' . (int) $type
            . ' ORDER BY u.`name` ASC, u.`id` ASC'
        );

        $out = [];
        foreach ($rows as $row) {
            $tid = (int) ($row['tid'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = 'Usuário #' . (int) ($row['uid'] ?? 0);
            }
            if (!isset($out[$tid])) {
                $out[$tid] = [];
            }
            if (!in_array($name, $out[$tid], true)) {
                $out[$tid][] = $name;
            }
        }
        return $out;
    }

    /**
     * Relatório vazio com o mesmo contrato e quebras fixas zeradas.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private static function emptyReport(array $filters, string $error): array
    {
        $zeroStatus = [];
        $zeroPriority = [];
        $zeroType = [];
        foreach (array_keys(self::getStatusChoices()) as $id) {
            $zeroStatus[(int) $id] = 0;
        }
        foreach (array_keys(self::getPriorityChoices()) as $id) {
            $zeroPriority[(int) $id] = 0;
        }
        foreach (array_keys(self::getTypeChoices()) as $id) {
            $zeroType[(int) $id] = 0;
        }

        $monthly = [];
        foreach (self::getMonthlyWindow($filters)['months'] as $month) {
            $monthly[] = [
                'label'  => (string) $month['label'],
                'opened' => 0,
                'solved' => 0,
                'closed' => 0,
            ];
        }

        return [
            'ok'          => false,
            'error'       => $error,
            'total'       => 0,
            'filters'     => $filters,
            'kpis'        => [
                'total'     => 0,
                'open'      => 0,
                'solved'    => 0,
                'closed'    => 0,
                'avg_take'  => null,
                'avg_solve' => null,
            ],
            'by_status'   => self::fixedBreakdown($zeroStatus, self::getStatusChoices(), 0),
            'by_priority' => self::fixedBreakdown($zeroPriority, self::getPriorityChoices(), 0),
            'by_type'     => self::fixedBreakdown($zeroType, self::getTypeChoices(), 0),
            'by_category' => [],
            'by_tech'     => [],
            'by_group'    => [],
            'by_entity'   => [],
            'monthly'     => $monthly,
        ];
    }

    /**
     * Executa uma consulta e devolve todas as linhas.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function fetchRows(string $sql): array
    {
        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'doQuery')) {
            throw new \RuntimeException('Banco de dados indisponível.');
        }

        $result = $DB->doQuery($sql);
        if (!is_object($result) || !method_exists($result, 'fetch_assoc')) {
            throw new \RuntimeException('Falha ao executar a consulta.');
        }

        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Ids de uma lista de escolhas (aceita escalar ou array).
     *
     * @param mixed          $value
     * @param array<int,int> $allowed
     * @return array<int,int>
     */
    private static function choiceIds($value, array $allowed): array
    {
        if (is_scalar($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $id = (int) $item;
            if ($id > 0 && in_array($id, $allowed, true) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Ids do registro e dos descendentes; fallback para o próprio id.
     *
     * @return array<int,int>
     */
    private static function sonsOf(string $table, int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        $ids = [];
        if (class_exists('DbUtils') && method_exists('DbUtils', 'getSonsOf')) {
            try {
                $sons = \DbUtils::getSonsOf($table, $id);
                if (is_array($sons)) {
                    foreach ($sons as $value) {
                        if (is_scalar($value)) {
                            $child = (int) $value;
                            if ($child >= 0) {
                                $ids[$child] = $child;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $ids = [];
            }
        }
        $ids[$id] = $id;
        $out = array_values($ids);
        sort($out);
        return $out;
    }

    /**
     * Data Y-m-d estrita; null quando inválida.
     */
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

    private static function shiftDate(string $date, string $modifier): string
    {
        $base = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($base === false) {
            $base = new \DateTimeImmutable(date('Y-m-d'));
        }
        return $base->modify($modifier)->format('Y-m-d');
    }

    /**
     * Escapa string para SQL (escape do $DB ou addslashes).
     */
    private static function quote(string $value): string
    {
        global $DB;
        if (isset($DB) && is_object($DB) && method_exists($DB, 'escape')) {
            try {
                return "'" . (string) $DB->escape($value) . "'";
            } catch (\Throwable $e) {
                // usa o fallback abaixo
            }
        }
        return "'" . addslashes($value) . "'";
    }

    private static function currentUserId(): int
    {
        if (!class_exists('Session') || !method_exists('Session', 'getLoginUserID')) {
            return 0;
        }
        try {
            return (int) \Session::getLoginUserID();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function hasTicketRight(int $value): bool
    {
        if (!class_exists('Session') || !method_exists('Session', 'haveRight')) {
            return false;
        }
        try {
            return (bool) \Session::haveRight('ticket', $value);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<int,int>
     */
    private static function sessionGroupIds(): array
    {
        $raw = $_SESSION['glpigroups'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $id = (int) $value;
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function toPositiveInt($value): int
    {
        if (!is_scalar($value)) {
            return 0;
        }
        $id = (int) $value;
        return $id > 0 ? $id : 0;
    }

    /**
     * @param mixed $value
     */
    private static function nullableFloat($value): ?float
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        return round((float) $value, 1);
    }

    private static function pct(int $count, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }
        return round(($count / $total) * 100, 1);
    }

    /**
     * @param mixed $value
     */
    private static function dateOrEmpty($value): string
    {
        if ($value === null) {
            return '';
        }
        $date = trim((string) $value);
        if ($date === '' || str_starts_with($date, '0000-00-00')) {
            return '';
        }
        return $date;
    }

    /**
     * @param array<int,int>    $ids
     * @param array<int,string> $labels
     */
    private static function labelsFor(array $ids, array $labels): string
    {
        $out = [];
        foreach ($ids as $id) {
            $out[] = isset($labels[(int) $id]) ? (string) $labels[(int) $id] : (string) $id;
        }
        return implode(', ', $out);
    }

    private static function displayDate(string $date): string
    {
        if (class_exists('Html') && method_exists('Html', 'convDate')) {
            try {
                $converted = \Html::convDate($date);
                if (is_string($converted) && $converted !== '') {
                    return $converted;
                }
            } catch (\Throwable $e) {
                // usa o fallback abaixo
            }
        }
        $timestamp = strtotime($date);
        return $timestamp === false ? $date : date('d/m/Y', $timestamp);
    }

    private static function lookupName(string $table, int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'request')) {
            return '';
        }
        try {
            $iterator = $DB->request([
                'SELECT' => ['name'],
                'FROM'   => $table,
                'WHERE'  => ['id' => $id],
                'LIMIT'  => 1,
            ]);
            foreach ($iterator as $row) {
                if (is_array($row) && isset($row['name']) && $row['name'] !== null) {
                    return (string) $row['name'];
                }
            }
        } catch (\Throwable $e) {
            return '';
        }
        return '';
    }

    private static function lookupCategoryName(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        global $DB;
        if (!isset($DB) || !is_object($DB) || !method_exists($DB, 'request')) {
            return '';
        }
        try {
            $iterator = $DB->request([
                'SELECT' => ['completename', 'name'],
                'FROM'   => 'glpi_itilcategories',
                'WHERE'  => ['id' => $id],
                'LIMIT'  => 1,
            ]);
            foreach ($iterator as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $complete = trim((string) ($row['completename'] ?? ''));
                if ($complete !== '') {
                    return $complete;
                }
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        } catch (\Throwable $e) {
            return '';
        }
        return '';
    }
}
