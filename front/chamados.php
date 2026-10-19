<?php
/**
 * Tela do relatório de chamados do GLPI.
 *
 * Estado 100% GET, compartilhável: período, entidade, status[], prioridade[],
 * tipo[], categoria, técnico, requerente, grupo, top_limit e show_details.
 */

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/compat.php';
require_once __DIR__ . '/../src/TicketReport.php';

use GlpiPlugin\Mafasa\TicketReport;

Session::checkLoginUser();

if (!TicketReport::canView()) {
    mafasa_display_right_error(__('Você não tem permissão para acessar relatórios de chamados.', 'mafasa'));
    return;
}

$filters = TicketReport::getDefaults($_GET);
$data = TicketReport::buildReport($filters);

/** Escapa texto para HTML. */
function mf_chamados_esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Query string preservando o GET atual, com overrides e remoções. */
function mf_chamados_qs(array $extra = [], array $drop = []): string
{
    $q = $_GET;
    foreach ($drop as $key) {
        unset($q[$key]);
    }
    foreach ($extra as $key => $value) {
        $q[$key] = $value;
    }
    return http_build_query($q);
}

/** Primeira chave existente em $filters (mantém o contrato do TicketReport). */
function mf_chamados_key(array $filters, array $candidates, string $fallback): string
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $filters)) {
            return $candidate;
        }
    }
    return $fallback;
}

/** Valor escalar do filtro, tentando as chaves informadas. */
function mf_chamados_value(array $filters, array $keys, $default = '')
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $filters)) {
            continue;
        }
        $value = $filters[$key];
        if (is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            $first = reset($value);
            return is_scalar($first) ? $first : $default;
        }
    }
    return $default;
}

/** Lista de valores (para checkboxes múltiplos). */
function mf_chamados_list(array $filters, array $keys): array
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $filters)) {
            continue;
        }
        $raw = $filters[$key];
        if (!is_array($raw)) {
            $raw = ($raw === null || $raw === '') ? [] : [$raw];
        }
        $out = [];
        foreach ($raw as $value) {
            if (is_scalar($value)) {
                $out[] = (string)$value;
            }
        }
        return $out;
    }
    return [];
}

/** Data/hora para exibição, tolerante a valores já formatados. */
function mf_chamados_datetime($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    try {
        return mf_chamados_esc(Html::convDateTime((string)$value));
    } catch (Throwable $e) {
        return mf_chamados_esc((string)$value);
    }
}

/** Duração média; sem base mostra travessão. */
function mf_chamados_duration($seconds): string
{
    if ($seconds === null || !is_numeric($seconds) || (float)$seconds <= 0) {
        return '—';
    }
    return mf_chamados_esc(TicketReport::formatDuration((int)$seconds));
}

/** Seção de quebra com label/contagem/percentual. */
function mf_chamados_breakdown(string $title, array $items, string $labelHeader): void
{
    echo "<section class='mf-section'>";
    echo "<h3>" . mf_chamados_esc($title) . "</h3>";

    if ($items === []) {
        echo "<div class='mf-empty'>" . mf_chamados_esc(__('Nenhum dado no período.', 'mafasa')) . "</div>";
        echo "</section>";
        return;
    }

    echo "<div class='mf-table-wrap'><table class='mf-table'><thead><tr>";
    echo "<th>" . mf_chamados_esc($labelHeader) . "</th>";
    echo "<th class='mf-num'>" . mf_chamados_esc(__('Chamados', 'mafasa')) . "</th>";
    echo "<th class='mf-num'>" . mf_chamados_esc(__('Percentual', 'mafasa')) . "</th>";
    echo "</tr></thead><tbody>";

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $label = (string)($item['label'] ?? '');
        $count = (int)($item['count'] ?? 0);
        $pct = (float)($item['pct'] ?? 0);
        echo "<tr>";
        echo "<td>" . mf_chamados_esc($label) . "</td>";
        echo "<td class='mf-num'>" . mf_chamados_esc((string)$count) . "</td>";
        echo "<td class='mf-num'>" . mf_chamados_esc(number_format($pct, 1, ',', '.')) . "%</td>";
        echo "</tr>";
    }

    echo "</tbody></table></div>";
    echo "</section>";
}

$statusKey = mf_chamados_key($filters, ['status', 'statuses'], 'status');
$priorityKey = mf_chamados_key($filters, ['priority', 'priorities'], 'priority');
$typeKey = mf_chamados_key($filters, ['type', 'types'], 'type');
$entityKey = mf_chamados_key($filters, ['entities_id', 'entity'], 'entities_id');
$categoryKey = mf_chamados_key($filters, ['itilcategories_id', 'category', 'categories_id'], 'itilcategories_id');
$techKey = mf_chamados_key($filters, ['users_id_tech', 'tech', 'technician', 'users_id_technician'], 'users_id_tech');
$requesterKey = mf_chamados_key($filters, ['users_id', 'requester', 'requester_id', 'users_id_requester'], 'users_id');
$groupKey = mf_chamados_key($filters, ['groups_id', 'group', 'groups_id_tech'], 'groups_id');
$topKey = mf_chamados_key($filters, ['top_limit', 'top', 'limit'], 'top_limit');
$detailsKey = mf_chamados_key($filters, ['show_details', 'details'], 'show_details');
$dateFromKey = mf_chamados_key($filters, ['date_from', 'from'], 'date_from');
$dateToKey = mf_chamados_key($filters, ['date_to', 'to'], 'date_to');

$dateFrom = (string)mf_chamados_value($filters, [$dateFromKey], '');
$dateTo = (string)mf_chamados_value($filters, [$dateToKey], '');
$entityValue = (string)mf_chamados_value($filters, [$entityKey], '');
$categoryValue = (string)mf_chamados_value($filters, [$categoryKey], '');
$techValue = (string)mf_chamados_value($filters, [$techKey], '');
$requesterValue = (string)mf_chamados_value($filters, [$requesterKey], '');
$groupValue = (string)mf_chamados_value($filters, [$groupKey], '');

$selectedStatus = mf_chamados_list($filters, [$statusKey]);
$selectedPriority = mf_chamados_list($filters, [$priorityKey]);
$selectedType = mf_chamados_list($filters, [$typeKey]);

$topLimitRaw = mf_chamados_value($filters, [$topKey], 15);
$topLimit = is_numeric($topLimitRaw) ? (int)$topLimitRaw : 15;
if ($topLimit <= 0) {
    $topLimit = 15;
}
$topLimit = max(1, min(50, $topLimit));

$showDetailsRaw = mf_chamados_value($filters, [$detailsKey], 0);
$showDetails = is_scalar($showDetailsRaw) && (bool)(int)$showDetailsRaw;

$ok = (bool)($data['ok'] ?? false);
$error = (string)($data['error'] ?? '');
$kpis = (isset($data['kpis']) && is_array($data['kpis'])) ? $data['kpis'] : [];
$total = (int)($data['total'] ?? ($kpis['total'] ?? 0));

$byStatus = (isset($data['by_status']) && is_array($data['by_status'])) ? $data['by_status'] : [];
$byPriority = (isset($data['by_priority']) && is_array($data['by_priority'])) ? $data['by_priority'] : [];
$byType = (isset($data['by_type']) && is_array($data['by_type'])) ? $data['by_type'] : [];
$byCategory = (isset($data['by_category']) && is_array($data['by_category'])) ? $data['by_category'] : [];
$byTech = (isset($data['by_tech']) && is_array($data['by_tech'])) ? $data['by_tech'] : [];
$byGroup = (isset($data['by_group']) && is_array($data['by_group'])) ? $data['by_group'] : [];
$byEntity = (isset($data['by_entity']) && is_array($data['by_entity'])) ? $data['by_entity'] : [];
$monthly = (isset($data['monthly']) && is_array($data['monthly'])) ? $data['monthly'] : [];

$details = null;
if ($ok && $showDetails) {
    $detailsPageRequest = max(1, (int)($_GET['page'] ?? 1));
    $details = TicketReport::getDetails($filters, $detailsPageRequest, 50);
}

$statusChoices = TicketReport::getStatusChoices();
$priorityChoices = TicketReport::getPriorityChoices();
$typeChoices = TicketReport::getTypeChoices();

Html::header(
    __('[URE] Mafasa — Chamados GLPI', 'mafasa'),
    $_SERVER['PHP_SELF'],
    'tools',
    \GlpiPlugin\Mafasa\Report::class
);

$webdir = mafasa_web_dir('mafasa');

echo "<link rel='stylesheet' href='" . mf_chamados_esc($webdir . '/public/css/mafasa.css') . "'>";
echo "<link rel='stylesheet' href='" . mf_chamados_esc($webdir . '/public/css/mafasa-chamados.css') . "'>";

echo "<div class='mf-page'>";
echo "<div class='mf-page-header'>";
echo "<h2>" . mf_chamados_esc(__('Relatório de Chamados', 'mafasa')) . "</h2>";
echo "</div>";

echo "<form id='mf-form' method='get' action='chamados.php'>";

echo "<div class='mf-card'>";

echo "<fieldset class='mf-filter-group'><legend>" . mf_chamados_esc(__('Período e entidade', 'mafasa')) . "</legend>";
echo "<div class='mf-grid'>";

echo "<div class='mf-field'>";
echo "<label for='mf-date-from'>" . mf_chamados_esc(__('De', 'mafasa')) . "</label>";
echo "<input type='date' id='mf-date-from' name='" . mf_chamados_esc($dateFromKey) . "' value='" . mf_chamados_esc($dateFrom) . "'>";
echo "</div>";

echo "<div class='mf-field'>";
echo "<label for='mf-date-to'>" . mf_chamados_esc(__('Até', 'mafasa')) . "</label>";
echo "<input type='date' id='mf-date-to' name='" . mf_chamados_esc($dateToKey) . "' value='" . mf_chamados_esc($dateTo) . "'>";
echo "</div>";

echo "<div class='mf-field'>";
echo "<label>" . mf_chamados_esc(__('Atalhos', 'mafasa')) . "</label>";
echo "<div class='mf-quick-ranges'>";
echo "<button type='button' class='mf-btn mf-btn-sm' data-mf-range='today'>" . mf_chamados_esc(__('Hoje', 'mafasa')) . "</button>";
echo "<button type='button' class='mf-btn mf-btn-sm' data-mf-range='7'>" . mf_chamados_esc(__('7 dias', 'mafasa')) . "</button>";
echo "<button type='button' class='mf-btn mf-btn-sm' data-mf-range='30'>" . mf_chamados_esc(__('30 dias', 'mafasa')) . "</button>";
echo "<button type='button' class='mf-btn mf-btn-sm' data-mf-range='month'>" . mf_chamados_esc(__('Mês atual', 'mafasa')) . "</button>";
echo "<button type='button' class='mf-btn mf-btn-sm' data-mf-range='year'>" . mf_chamados_esc(__('Ano atual', 'mafasa')) . "</button>";
echo "</div>";
echo "</div>";

echo "<div class='mf-field'>";
echo "<label>" . mf_chamados_esc(__('Entidade', 'mafasa')) . "</label>";
if (class_exists('Entity') && method_exists('Entity', 'dropdown')) {
    Entity::dropdown([
        'name'                => $entityKey,
        'value'               => $entityValue,
        'display_emptychoice' => true,
        'width'               => '100%',
    ]);
} else {
    echo "<input type='number' min='0' step='1' name='" . mf_chamados_esc($entityKey) . "' value='" . mf_chamados_esc($entityValue) . "'>";
}
echo "</div>";

echo "</div></fieldset>";

echo "<fieldset class='mf-filter-group'><legend>" . mf_chamados_esc(__('Classificação', 'mafasa')) . "</legend>";
echo "<div class='mf-checkgrids'>";

echo "<div class='mf-checkgroup'>";
echo "<div class='mf-checkgroup-title'>" . mf_chamados_esc(__('Status', 'mafasa')) . "</div>";
echo "<div class='mf-checkgrid'>";
foreach ($statusChoices as $value => $label) {
    $checked = in_array((string)$value, $selectedStatus, true) ? ' checked' : '';
    echo "<label class='mf-check'><input type='checkbox' name='" . mf_chamados_esc($statusKey) . "[]' value='"
        . mf_chamados_esc((string)$value) . "'" . $checked . "> " . mf_chamados_esc((string)$label) . "</label>";
}
echo "</div></div>";

echo "<div class='mf-checkgroup'>";
echo "<div class='mf-checkgroup-title'>" . mf_chamados_esc(__('Prioridade', 'mafasa')) . "</div>";
echo "<div class='mf-checkgrid'>";
foreach ($priorityChoices as $value => $label) {
    $checked = in_array((string)$value, $selectedPriority, true) ? ' checked' : '';
    echo "<label class='mf-check'><input type='checkbox' name='" . mf_chamados_esc($priorityKey) . "[]' value='"
        . mf_chamados_esc((string)$value) . "'" . $checked . "> " . mf_chamados_esc((string)$label) . "</label>";
}
echo "</div></div>";

echo "<div class='mf-checkgroup'>";
echo "<div class='mf-checkgroup-title'>" . mf_chamados_esc(__('Tipo', 'mafasa')) . "</div>";
echo "<div class='mf-checkgrid'>";
foreach ($typeChoices as $value => $label) {
    $checked = in_array((string)$value, $selectedType, true) ? ' checked' : '';
    echo "<label class='mf-check'><input type='checkbox' name='" . mf_chamados_esc($typeKey) . "[]' value='"
        . mf_chamados_esc((string)$value) . "'" . $checked . "> " . mf_chamados_esc((string)$label) . "</label>";
}
echo "</div></div>";

echo "</div></fieldset>";

echo "<fieldset class='mf-filter-group'><legend>" . mf_chamados_esc(__('Categoria e atribuição', 'mafasa')) . "</legend>";
echo "<div class='mf-grid'>";

echo "<div class='mf-field'>";
echo "<label>" . mf_chamados_esc(__('Categoria ITIL', 'mafasa')) . "</label>";
if (class_exists('Dropdown') && method_exists('Dropdown', 'show')) {
    Dropdown::show('ITILCategory', [
        'name'                => $categoryKey,
        'value'               => $categoryValue,
        'display_emptychoice' => true,
        'width'               => '100%',
    ]);
} else {
    echo "<input type='number' min='0' step='1' name='" . mf_chamados_esc($categoryKey) . "' value='" . mf_chamados_esc($categoryValue) . "'>";
}
echo "</div>";

echo "<div class='mf-field'>";
echo "<label>" . mf_chamados_esc(__('Técnico atribuído', 'mafasa')) . "</label>";
if (class_exists('Dropdown') && method_exists('Dropdown', 'show')) {
    Dropdown::show('User', [
        'name'                => $techKey,
        'value'               => $techValue,
        'display_emptychoice' => true,
        'width'               => '100%',
    ]);
} else {
    echo "<input type='number' min='0' step='1' name='" . mf_chamados_esc($techKey) . "' value='" . mf_chamados_esc($techValue) . "'>";
}
echo "</div>";

echo "<div class='mf-field'>";
echo "<label>" . mf_chamados_esc(__('Requerente', 'mafasa')) . "</label>";
if (class_exists('Dropdown') && method_exists('Dropdown', 'show')) {
    Dropdown::show('User', [
        'name'                => $requesterKey,
        'value'               => $requesterValue,
        'display_emptychoice' => true,
        'width'               => '100%',
    ]);
} else {
    echo "<input type='number' min='0' step='1' name='" . mf_chamados_esc($requesterKey) . "' value='" . mf_chamados_esc($requesterValue) . "'>";
}
echo "</div>";

echo "<div class='mf-field'>";
echo "<label>" . mf_chamados_esc(__('Grupo atribuído', 'mafasa')) . "</label>";
if (class_exists('Dropdown') && method_exists('Dropdown', 'show')) {
    Dropdown::show('Group', [
        'name'                => $groupKey,
        'value'               => $groupValue,
        'display_emptychoice' => true,
        'width'               => '100%',
    ]);
} else {
    echo "<input type='number' min='0' step='1' name='" . mf_chamados_esc($groupKey) . "' value='" . mf_chamados_esc($groupValue) . "'>";
}
echo "</div>";

echo "</div></fieldset>";

echo "<div class='mf-options'>";
echo "<div class='mf-field mf-field-inline'>";
echo "<label for='mf-top-limit'>" . mf_chamados_esc(__('Top categorias', 'mafasa')) . "</label>";
echo "<input type='number' id='mf-top-limit' name='" . mf_chamados_esc($topKey) . "' min='1' max='50' step='1' value='"
    . mf_chamados_esc((string)$topLimit) . "'>";
echo "</div>";
echo "<label class='mf-check mf-check-details'><input type='checkbox' name='" . mf_chamados_esc($detailsKey)
    . "' value='1'" . ($showDetails ? ' checked' : '') . "> " . mf_chamados_esc(__('Exibir lista detalhada', 'mafasa')) . "</label>";
echo "</div>";

echo "<div class='mf-actions'>";
echo "<button type='submit' class='mf-btn mf-btn-primary'>" . mf_chamados_esc(__('Gerar relatório', 'mafasa')) . "</button>";
echo "<a class='mf-btn' href='chamados.php'>" . mf_chamados_esc(__('Limpar filtros', 'mafasa')) . "</a>";
echo "<a class='mf-btn' target='_blank' rel='noopener' href='print.php?"
    . mf_chamados_esc(mf_chamados_qs(['report' => 'chamados'], ['page'])) . "'>"
    . mf_chamados_esc(__('Imprimir', 'mafasa')) . "</a>";
echo "</div>";

echo "</div>";
echo "</form>";

if (!$ok) {
    echo "<div class='mf-warning' role='alert'>"
        . mf_chamados_esc($error !== '' ? $error : __('Não foi possível gerar o relatório.', 'mafasa'))
        . "</div>";
} else {
    echo "<section class='mf-kpis'>";

    $kpiCards = [
        ['label' => __('Total', 'mafasa'), 'value' => (string)$total],
        ['label' => __('Abertos', 'mafasa'), 'value' => (string)(int)($kpis['open'] ?? 0)],
        ['label' => __('Resolvidos', 'mafasa'), 'value' => (string)(int)($kpis['solved'] ?? 0)],
        ['label' => __('Fechados', 'mafasa'), 'value' => (string)(int)($kpis['closed'] ?? 0)],
    ];
    foreach ($kpiCards as $card) {
        echo "<div class='mf-kpi'>";
        echo "<div class='mf-kpi-label'>" . mf_chamados_esc($card['label']) . "</div>";
        echo "<div class='mf-kpi-value'>" . mf_chamados_esc($card['value']) . "</div>";
        echo "</div>";
    }

    echo "<div class='mf-kpi'>";
    echo "<div class='mf-kpi-label'>" . mf_chamados_esc(__('Tempo médio de atendimento', 'mafasa')) . "</div>";
    echo "<div class='mf-kpi-value mf-kpi-value-sm'>" . mf_chamados_duration($kpis['avg_take'] ?? null) . "</div>";
    echo "</div>";

    echo "<div class='mf-kpi'>";
    echo "<div class='mf-kpi-label'>" . mf_chamados_esc(__('Tempo médio de resolução', 'mafasa')) . "</div>";
    echo "<div class='mf-kpi-value mf-kpi-value-sm'>" . mf_chamados_duration($kpis['avg_solve'] ?? null) . "</div>";
    echo "</div>";

    echo "</section>";

    mf_chamados_breakdown(__('Por status', 'mafasa'), $byStatus, __('Status', 'mafasa'));
    mf_chamados_breakdown(__('Por prioridade', 'mafasa'), $byPriority, __('Prioridade', 'mafasa'));
    mf_chamados_breakdown(__('Por tipo', 'mafasa'), $byType, __('Tipo', 'mafasa'));
    mf_chamados_breakdown(__('Top categorias', 'mafasa'), $byCategory, __('Categoria', 'mafasa'));
    mf_chamados_breakdown(__('Por técnico', 'mafasa'), $byTech, __('Técnico', 'mafasa'));
    mf_chamados_breakdown(__('Por grupo', 'mafasa'), $byGroup, __('Grupo', 'mafasa'));
    mf_chamados_breakdown(__('Por entidade', 'mafasa'), $byEntity, __('Entidade', 'mafasa'));

    echo "<section class='mf-section'>";
    echo "<h3>" . mf_chamados_esc(__('Evolução mensal (12 meses)', 'mafasa')) . "</h3>";
    if ($monthly === []) {
        echo "<div class='mf-empty'>" . mf_chamados_esc(__('Nenhum dado no período.', 'mafasa')) . "</div>";
    } else {
        echo "<div class='mf-table-wrap'><table class='mf-table'><thead><tr>";
        echo "<th>" . mf_chamados_esc(__('Mês', 'mafasa')) . "</th>";
        echo "<th class='mf-num'>" . mf_chamados_esc(__('Abertos', 'mafasa')) . "</th>";
        echo "<th class='mf-num'>" . mf_chamados_esc(__('Resolvidos', 'mafasa')) . "</th>";
        echo "<th class='mf-num'>" . mf_chamados_esc(__('Fechados', 'mafasa')) . "</th>";
        echo "</tr></thead><tbody>";
        foreach ($monthly as $month) {
            if (!is_array($month)) {
                continue;
            }
            echo "<tr>";
            echo "<td>" . mf_chamados_esc((string)($month['label'] ?? '')) . "</td>";
            echo "<td class='mf-num'>" . mf_chamados_esc((string)(int)($month['opened'] ?? 0)) . "</td>";
            echo "<td class='mf-num'>" . mf_chamados_esc((string)(int)($month['solved'] ?? 0)) . "</td>";
            echo "<td class='mf-num'>" . mf_chamados_esc((string)(int)($month['closed'] ?? 0)) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
    }
    echo "</section>";

    if ($showDetails && is_array($details)) {
        $detailRows = (isset($details['rows']) && is_array($details['rows'])) ? $details['rows'] : [];
        $detailTotal = (int)($details['total'] ?? 0);
        $detailPage = max(1, (int)($details['page'] ?? 1));
        $detailMaxPage = max(1, (int)($details['max_page'] ?? 1));

        echo "<section class='mf-section mf-details'>";
        echo "<h3>" . mf_chamados_esc(__('Lista detalhada', 'mafasa')) . "</h3>";
        echo "<div class='mf-result-bar'><span class='mf-page-info'>"
            . mf_chamados_esc(sprintf(__('Página %d de %d (%d chamados)', 'mafasa'), $detailPage, $detailMaxPage, $detailTotal))
            . "</span></div>";

        if ($detailRows === []) {
            echo "<div class='mf-empty'>" . mf_chamados_esc(__('Nenhum chamado encontrado.', 'mafasa')) . "</div>";
        } else {
            echo "<div class='mf-table-wrap mf-matrix-scroll'><table class='mf-table mf-details-table'><thead><tr>";
            $detailHeaders = [
                __('ID', 'mafasa'),
                __('Título', 'mafasa'),
                __('Status', 'mafasa'),
                __('Prioridade', 'mafasa'),
                __('Tipo', 'mafasa'),
                __('Categoria', 'mafasa'),
                __('Técnico', 'mafasa'),
                __('Requerente', 'mafasa'),
                __('Entidade', 'mafasa'),
                __('Abertura', 'mafasa'),
                __('Resolução/Fechamento', 'mafasa'),
            ];
            foreach ($detailHeaders as $header) {
                echo "<th>" . mf_chamados_esc($header) . "</th>";
            }
            echo "</tr></thead><tbody>";

            foreach ($detailRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                echo "<tr>";
                echo "<td class='mf-num'>" . mf_chamados_esc((string)(int)($row['id'] ?? 0)) . "</td>";
                echo "<td>" . mf_chamados_esc((string)($row['name'] ?? '')) . "</td>";
                echo "<td>" . mf_chamados_esc((string)($row['status'] ?? '')) . "</td>";
                echo "<td>" . mf_chamados_esc((string)($row['priority'] ?? '')) . "</td>";
                echo "<td>" . mf_chamados_esc((string)($row['type'] ?? '')) . "</td>";
                echo "<td>" . mf_chamados_esc((string)($row['category'] ?? '')) . "</td>";
                echo "<td>" . mf_chamados_esc((string)($row['tech'] ?? '')) . "</td>";
                echo "<td>" . mf_chamados_esc((string)($row['requester'] ?? '')) . "</td>";
                echo "<td>" . mf_chamados_esc((string)($row['entity'] ?? '')) . "</td>";
                echo "<td>" . mf_chamados_datetime($row['date'] ?? '') . "</td>";
                echo "<td>" . mf_chamados_datetime($row['end_date'] ?? '') . "</td>";
                echo "</tr>";
            }

            echo "</tbody></table></div>";

            echo "<div class='mf-pagination'>";
            if ($detailPage > 1) {
                echo "<a class='mf-btn mf-btn-sm' href='chamados.php?"
                    . mf_chamados_esc(mf_chamados_qs(['page' => $detailPage - 1])) . "'>&laquo; "
                    . mf_chamados_esc(__('Anterior', 'mafasa')) . "</a>";
            }
            if ($detailPage < $detailMaxPage) {
                echo "<a class='mf-btn mf-btn-sm' href='chamados.php?"
                    . mf_chamados_esc(mf_chamados_qs(['page' => $detailPage + 1])) . "'>"
                    . mf_chamados_esc(__('Próxima', 'mafasa')) . " &raquo;</a>";
            }
            echo "</div>";
        }

        echo "</section>";
    }
}

echo "<script>";
?>
(function () {
    var from = document.getElementById('mf-date-from');
    var to = document.getElementById('mf-date-to');
    if (!from || !to) {
        return;
    }

    function pad(value) {
        return value < 10 ? '0' + value : String(value);
    }

    function format(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function applyRange(range) {
        var today = new Date();
        var start = new Date(today.getFullYear(), today.getMonth(), today.getDate());

        if (range === '7') {
            start.setDate(start.getDate() - 6);
        } else if (range === '30') {
            start.setDate(start.getDate() - 29);
        } else if (range === 'month') {
            start = new Date(today.getFullYear(), today.getMonth(), 1);
        } else if (range === 'year') {
            start = new Date(today.getFullYear(), 0, 1);
        }

        from.value = format(start);
        to.value = format(today);
    }

    var buttons = document.querySelectorAll('[data-mf-range]');
    for (var i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', function () {
            applyRange(this.getAttribute('data-mf-range'));
        });
    }
})();
<?php
echo "</script>";
echo "</div>";

Html::footer();
