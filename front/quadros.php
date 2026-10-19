<?php
/**
 * Tela do relatório de quadros do kanpro.
 *
 * Estado 100% GET, compartilhável: entidade, quadros, categorias,
 * incluir arquivados, período de criação e lista detalhada.
 */

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/compat.php';
require_once __DIR__ . '/../src/BoardReport.php';

use GlpiPlugin\Mafasa\BoardReport;

Session::checkLoginUser();

/** Escapa texto para HTML. */
function mf_quadros_esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Query string preservando o GET atual, com overrides e remoções. */
function mf_quadros_qs(array $extra = [], array $drop = []): string
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

/** Valor escalar de um filtro. */
function mf_quadros_value(array $filters, string $key, $default = '')
{
    if (!array_key_exists($key, $filters)) {
        return $default;
    }
    $value = $filters[$key];
    if (is_scalar($value)) {
        return $value;
    }
    if (is_array($value)) {
        $first = reset($value);
        return is_scalar($first) ? $first : $default;
    }
    return $default;
}

/** Lista de valores (checkboxes múltiplos). */
function mf_quadros_list(array $filters, string $key): array
{
    if (!array_key_exists($key, $filters)) {
        return [];
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

/** Data/hora para exibição, tolerante a valores já formatados. */
function mf_quadros_datetime($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    try {
        return mf_quadros_esc(Html::convDateTime((string)$value));
    } catch (Throwable $e) {
        return mf_quadros_esc((string)$value);
    }
}

$webdir = mafasa_web_dir('mafasa');

if (!BoardReport::isAvailable()) {
    Html::header(
        __('[URE] Mafasa — Quadros Kanpro', 'mafasa'),
        $_SERVER['PHP_SELF'],
        'tools',
        \GlpiPlugin\Mafasa\Report::class
    );

    echo "<link rel='stylesheet' href='" . mf_quadros_esc($webdir . '/public/css/mafasa.css') . "'>";
    echo "<link rel='stylesheet' href='" . mf_quadros_esc($webdir . '/public/css/mafasa-quadros.css') . "'>";

    echo "<div class='mf-page'>";
    echo "<div class='mf-warning' role='alert'>"
        . mf_quadros_esc(__('O plugin Kanpro não está ativo ou as tabelas necessárias não existem. Ative o Kanpro para usar o relatório de quadros.', 'mafasa'))
        . "</div>";
    echo "</div>";

    Html::footer();
    return;
}

if (!BoardReport::canView()) {
    mafasa_display_right_error(__('Você não tem permissão para acessar relatórios de quadros.', 'mafasa'));
    return;
}

$filters = BoardReport::getDefaults($_GET);
$data = BoardReport::buildReport($filters);

$entityValue = (string)mf_quadros_value($filters, 'entities_id', '');
$dateFrom = (string)mf_quadros_value($filters, 'date_from', '');
$dateTo = (string)mf_quadros_value($filters, 'date_to', '');
$includeArchived = (int)mf_quadros_value($filters, 'include_archived', 0) === 1;
$showDetailsRaw = $filters['show_details'] ?? ($_GET['show_details'] ?? 0);
$showDetails = is_scalar($showDetailsRaw) && (int)$showDetailsRaw === 1;

$selectedBoards = mf_quadros_list($filters, 'boards_id');
$selectedCategories = mf_quadros_list($filters, 'list_type');

$boardChoicesFilters = $filters;
$boardChoicesFilters['boards_id'] = [];
$boards = BoardReport::getBoards($boardChoicesFilters);
$categoryChoices = BoardReport::getCategoryChoices();

$ok = (bool)($data['ok'] ?? false);
$error = (string)($data['error'] ?? '');

$kpis = (isset($data['kpis']) && is_array($data['kpis'])) ? $data['kpis'] : [];
$byBoard = (isset($data['by_board']) && is_array($data['by_board'])) ? $data['by_board'] : [];
$byCategory = (isset($data['by_category']) && is_array($data['by_category'])) ? $data['by_category'] : [];
$byList = (isset($data['by_list']) && is_array($data['by_list'])) ? $data['by_list'] : [];
$matrix = (isset($data['matrix']) && is_array($data['matrix'])) ? $data['matrix'] : [];

$matrixCategories = (isset($matrix['categories']) && is_array($matrix['categories'])) ? $matrix['categories'] : [];
$matrixRows = (isset($matrix['rows']) && is_array($matrix['rows'])) ? $matrix['rows'] : [];
$matrixTotals = (isset($matrix['totals']) && is_array($matrix['totals'])) ? $matrix['totals'] : [];
$matrixGrand = (int)($matrix['grand'] ?? 0);

$details = null;
if ($ok && $showDetails) {
    $detailsPageRequest = max(1, (int)($_GET['page'] ?? 1));
    $details = BoardReport::getDetails($filters, $detailsPageRequest, 50);
}

Html::header(
    __('[URE] Mafasa — Quadros Kanpro', 'mafasa'),
    $_SERVER['PHP_SELF'],
    'tools',
    \GlpiPlugin\Mafasa\Report::class
);

echo "<link rel='stylesheet' href='" . mf_quadros_esc($webdir . '/public/css/mafasa.css') . "'>";
echo "<link rel='stylesheet' href='" . mf_quadros_esc($webdir . '/public/css/mafasa-quadros.css') . "'>";

echo "<div class='mf-page'>";
echo "<div class='mf-page-header'>";
echo "<h2>" . mf_quadros_esc(__('Relatório de Quadros Kanpro', 'mafasa')) . "</h2>";
echo "</div>";

echo "<form id='mf-form' method='get' action='quadros.php'>";

echo "<div class='mf-card'>";

echo "<fieldset class='mf-filter-group'><legend>" . mf_quadros_esc(__('Entidade e período', 'mafasa')) . "</legend>";
echo "<div class='mf-grid'>";

echo "<div class='mf-field'>";
echo "<label>" . mf_quadros_esc(__('Entidade', 'mafasa')) . "</label>";
if (class_exists('Entity') && method_exists('Entity', 'dropdown')) {
    Entity::dropdown([
        'name'                => 'entities_id',
        'value'               => $entityValue,
        'display_emptychoice' => true,
        'width'               => '100%',
    ]);
} else {
    echo "<input type='number' min='0' step='1' name='entities_id' value='" . mf_quadros_esc($entityValue) . "'>";
}
echo "</div>";

echo "<div class='mf-field'>";
echo "<label for='mf-date-from'>" . mf_quadros_esc(__('Criação de', 'mafasa')) . "</label>";
echo "<input type='date' id='mf-date-from' name='date_from' value='" . mf_quadros_esc($dateFrom) . "'>";
echo "</div>";

echo "<div class='mf-field'>";
echo "<label for='mf-date-to'>" . mf_quadros_esc(__('Criação até', 'mafasa')) . "</label>";
echo "<input type='date' id='mf-date-to' name='date_to' value='" . mf_quadros_esc($dateTo) . "'>";
echo "</div>";

echo "</div></fieldset>";

echo "<fieldset class='mf-filter-group'><legend>" . mf_quadros_esc(__('Quadros', 'mafasa')) . "</legend>";
if ($boards === []) {
    echo "<div class='mf-empty'>" . mf_quadros_esc(__('Nenhum quadro visível para os filtros atuais.', 'mafasa')) . "</div>";
} else {
    echo "<div class='mf-checkgrid'>";
    foreach ($boards as $boardId => $boardName) {
        $checked = in_array((string)$boardId, $selectedBoards, true) ? ' checked' : '';
        echo "<label class='mf-check'><input type='checkbox' name='boards_id[]' value='"
            . mf_quadros_esc((string)$boardId) . "'" . $checked . "> "
            . mf_quadros_esc((string)$boardName) . "</label>";
    }
    echo "</div>";
    echo "<p class='mf-hint'>" . mf_quadros_esc(__('Nenhum quadro selecionado = todos os quadros visíveis.', 'mafasa')) . "</p>";
}
echo "</fieldset>";

echo "<fieldset class='mf-filter-group'><legend>" . mf_quadros_esc(__('Categorias', 'mafasa')) . "</legend>";
if ($categoryChoices === []) {
    echo "<div class='mf-empty'>" . mf_quadros_esc(__('Nenhuma categoria disponível.', 'mafasa')) . "</div>";
} else {
    echo "<div class='mf-checkgrid'>";
    foreach ($categoryChoices as $categoryKey => $categoryLabel) {
        $checked = in_array((string)$categoryKey, $selectedCategories, true) ? ' checked' : '';
        echo "<label class='mf-check'><input type='checkbox' name='list_type[]' value='"
            . mf_quadros_esc((string)$categoryKey) . "'" . $checked . "> "
            . mf_quadros_esc((string)$categoryLabel) . "</label>";
    }
    echo "</div>";
    echo "<p class='mf-hint'>" . mf_quadros_esc(__('Nenhuma categoria selecionada = todas as categorias.', 'mafasa')) . "</p>";
}
echo "</fieldset>";

echo "<div class='mf-options'>";
echo "<label class='mf-check mf-check-details'><input type='checkbox' name='include_archived' value='1'"
    . ($includeArchived ? ' checked' : '') . "> "
    . mf_quadros_esc(__('Incluir arquivados (cards, listas e quadros)', 'mafasa')) . "</label>";
echo "<label class='mf-check mf-check-details'><input type='checkbox' name='show_details' value='1'"
    . ($showDetails ? ' checked' : '') . "> "
    . mf_quadros_esc(__('Exibir lista detalhada de cards', 'mafasa')) . "</label>";
echo "</div>";

echo "<div class='mf-actions'>";
echo "<button type='submit' class='mf-btn mf-btn-primary'>" . mf_quadros_esc(__('Gerar relatório', 'mafasa')) . "</button>";
echo "<a class='mf-btn' href='quadros.php'>" . mf_quadros_esc(__('Limpar filtros', 'mafasa')) . "</a>";
echo "<a class='mf-btn' target='_blank' rel='noopener' href='print.php?"
    . mf_quadros_esc(mf_quadros_qs(['report' => 'quadros'], ['page'])) . "'>"
    . mf_quadros_esc(__('Imprimir', 'mafasa')) . "</a>";
echo "</div>";

echo "</div>";
echo "</form>";

if (!$ok) {
    echo "<div class='mf-warning' role='alert'>"
        . mf_quadros_esc($error !== '' ? $error : __('Não foi possível gerar o relatório.', 'mafasa'))
        . "</div>";
} else {
    echo "<section class='mf-kpis'>";

    $kpiCards = [
        ['label' => __('Total de cards', 'mafasa'), 'value' => (int)($kpis['cards'] ?? 0)],
        ['label' => __('Cards com chamado', 'mafasa'), 'value' => (int)($kpis['linked'] ?? 0)],
        ['label' => __('Manutenção', 'mafasa'), 'value' => (int)($kpis['maintenance'] ?? 0)],
        ['label' => __('Arquivados', 'mafasa'), 'value' => (int)($kpis['archived'] ?? 0)],
        ['label' => __('Quadros', 'mafasa'), 'value' => (int)($kpis['boards'] ?? count($byBoard))],
    ];
    foreach ($kpiCards as $card) {
        echo "<div class='mf-kpi'>";
        echo "<div class='mf-kpi-label'>" . mf_quadros_esc($card['label']) . "</div>";
        echo "<div class='mf-kpi-value'>" . mf_quadros_esc((string)$card['value']) . "</div>";
        echo "</div>";
    }

    echo "</section>";

    echo "<section class='mf-section'>";
    echo "<h3>" . mf_quadros_esc(__('Por quadro', 'mafasa')) . "</h3>";
    if ($byBoard === []) {
        echo "<div class='mf-empty'>" . mf_quadros_esc(__('Nenhum quadro encontrado.', 'mafasa')) . "</div>";
    } else {
        echo "<div class='mf-table-wrap'><table class='mf-table'><thead><tr>";
        echo "<th>" . mf_quadros_esc(__('Quadro', 'mafasa')) . "</th>";
        echo "<th class='mf-num'>" . mf_quadros_esc(__('Cards', 'mafasa')) . "</th>";
        echo "<th class='mf-num'>" . mf_quadros_esc(__('Com chamado', 'mafasa')) . "</th>";
        echo "<th class='mf-num'>" . mf_quadros_esc(__('Manutenção', 'mafasa')) . "</th>";
        echo "<th class='mf-num'>" . mf_quadros_esc(__('Arquivados', 'mafasa')) . "</th>";
        echo "</tr></thead><tbody>";
        foreach ($byBoard as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo "<tr>";
            echo "<td>" . mf_quadros_esc((string)($row['name'] ?? '')) . "</td>";
            echo "<td class='mf-num'>" . mf_quadros_esc((string)(int)($row['cards'] ?? 0)) . "</td>";
            echo "<td class='mf-num'>" . mf_quadros_esc((string)(int)($row['linked'] ?? 0)) . "</td>";
            echo "<td class='mf-num'>" . mf_quadros_esc((string)(int)($row['maintenance'] ?? 0)) . "</td>";
            echo "<td class='mf-num'>" . mf_quadros_esc((string)(int)($row['archived'] ?? 0)) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
    }
    echo "</section>";

    echo "<section class='mf-section'>";
    echo "<h3>" . mf_quadros_esc(__('Matriz quadro × categoria', 'mafasa')) . "</h3>";
    if ($matrixRows === [] || $matrixCategories === []) {
        echo "<div class='mf-empty'>" . mf_quadros_esc(__('Nenhum dado para a matriz.', 'mafasa')) . "</div>";
    } else {
        echo "<div class='mf-table-wrap mf-matrix-scroll'>";
        echo "<table class='mf-table mf-matrix'><thead><tr>";
        echo "<th class='mf-matrix-board'>" . mf_quadros_esc(__('Quadro', 'mafasa')) . "</th>";
        foreach ($matrixCategories as $category) {
            if (!is_array($category)) {
                continue;
            }
            echo "<th class='mf-num'>" . mf_quadros_esc((string)($category['label'] ?? '')) . "</th>";
        }
        echo "<th class='mf-num'>" . mf_quadros_esc(__('Total', 'mafasa')) . "</th>";
        echo "</tr></thead><tbody>";

        foreach ($matrixRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = (isset($row['cells']) && is_array($row['cells'])) ? $row['cells'] : [];
            echo "<tr>";
            echo "<td class='mf-matrix-board'>" . mf_quadros_esc((string)($row['board'] ?? '')) . "</td>";
            foreach ($matrixCategories as $category) {
                if (!is_array($category)) {
                    continue;
                }
                $categoryKey = (string)($category['key'] ?? '');
                echo "<td class='mf-num'>" . mf_quadros_esc((string)(int)($cells[$categoryKey] ?? 0)) . "</td>";
            }
            echo "<td class='mf-num'>" . mf_quadros_esc((string)(int)($row['total'] ?? 0)) . "</td>";
            echo "</tr>";
        }

        echo "</tbody><tfoot><tr>";
        echo "<td class='mf-matrix-board'>" . mf_quadros_esc(__('Total', 'mafasa')) . "</td>";
        foreach ($matrixCategories as $category) {
            if (!is_array($category)) {
                continue;
            }
            $categoryKey = (string)($category['key'] ?? '');
            echo "<td class='mf-num'>" . mf_quadros_esc((string)(int)($matrixTotals[$categoryKey] ?? 0)) . "</td>";
        }
        echo "<td class='mf-num'>" . mf_quadros_esc((string)$matrixGrand) . "</td>";
        echo "</tr></tfoot></table></div>";
    }
    echo "</section>";

    echo "<section class='mf-section'>";
    echo "<h3>" . mf_quadros_esc(__('Por categoria', 'mafasa')) . "</h3>";
    if ($byCategory === []) {
        echo "<div class='mf-empty'>" . mf_quadros_esc(__('Nenhuma categoria encontrada.', 'mafasa')) . "</div>";
    } else {
        echo "<div class='mf-table-wrap'><table class='mf-table'><thead><tr>";
        echo "<th>" . mf_quadros_esc(__('Categoria', 'mafasa')) . "</th>";
        echo "<th class='mf-num'>" . mf_quadros_esc(__('Cards', 'mafasa')) . "</th>";
        echo "<th class='mf-num'>" . mf_quadros_esc(__('Percentual', 'mafasa')) . "</th>";
        echo "</tr></thead><tbody>";
        foreach ($byCategory as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pct = $row['pct'] ?? 0;
            $pctText = is_numeric($pct)
                ? number_format((float)$pct, 1, ',', '.') . '%'
                : (string)$pct;
            echo "<tr>";
            echo "<td>" . mf_quadros_esc((string)($row['label'] ?? '')) . "</td>";
            echo "<td class='mf-num'>" . mf_quadros_esc((string)(int)($row['count'] ?? 0)) . "</td>";
            echo "<td class='mf-num'>" . mf_quadros_esc($pctText) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
    }
    echo "</section>";

    echo "<section class='mf-section'>";
    echo "<h3>" . mf_quadros_esc(__('Por lista', 'mafasa')) . "</h3>";
    if ($byList === []) {
        echo "<div class='mf-empty'>" . mf_quadros_esc(__('Nenhuma lista encontrada.', 'mafasa')) . "</div>";
    } else {
        echo "<div class='mf-table-wrap'><table class='mf-table'><thead><tr>";
        echo "<th>" . mf_quadros_esc(__('Quadro', 'mafasa')) . "</th>";
        echo "<th>" . mf_quadros_esc(__('Lista', 'mafasa')) . "</th>";
        echo "<th>" . mf_quadros_esc(__('Categoria', 'mafasa')) . "</th>";
        echo "<th class='mf-num'>" . mf_quadros_esc(__('Cards', 'mafasa')) . "</th>";
        echo "</tr></thead><tbody>";
        foreach ($byList as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo "<tr>";
            echo "<td>" . mf_quadros_esc((string)($row['board'] ?? '')) . "</td>";
            echo "<td>" . mf_quadros_esc((string)($row['list'] ?? '')) . "</td>";
            echo "<td>" . mf_quadros_esc((string)($row['category'] ?? '')) . "</td>";
            echo "<td class='mf-num'>" . mf_quadros_esc((string)(int)($row['count'] ?? 0)) . "</td>";
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
        echo "<h3>" . mf_quadros_esc(__('Lista detalhada', 'mafasa')) . "</h3>";
        echo "<div class='mf-result-bar'><span class='mf-page-info'>"
            . mf_quadros_esc(sprintf(__('Página %d de %d (%d cards)', 'mafasa'), $detailPage, $detailMaxPage, $detailTotal))
            . "</span></div>";

        if ($detailRows === []) {
            echo "<div class='mf-empty'>" . mf_quadros_esc(__('Nenhum card encontrado.', 'mafasa')) . "</div>";
        } else {
            echo "<div class='mf-table-wrap mf-matrix-scroll'><table class='mf-table mf-details-table'><thead><tr>";
            $detailHeaders = [
                __('ID', 'mafasa'),
                __('Nome', 'mafasa'),
                __('Quadro', 'mafasa'),
                __('Lista', 'mafasa'),
                __('Categoria', 'mafasa'),
                __('Chamado', 'mafasa'),
                __('Status do chamado', 'mafasa'),
                __('Responsável', 'mafasa'),
                __('Criação', 'mafasa'),
            ];
            foreach ($detailHeaders as $header) {
                echo "<th>" . mf_quadros_esc($header) . "</th>";
            }
            echo "</tr></thead><tbody>";

            foreach ($detailRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $ticketId = (int)($row['tickets_id'] ?? 0);
                $ticketText = $ticketId > 0 ? (string)$ticketId : '—';
                $statusText = (string)($row['chamado_status'] ?? '');
                if ($statusText === '') {
                    $statusText = '—';
                }
                $owner = (string)($row['owner'] ?? '');
                if ($owner === '') {
                    $owner = '—';
                }

                echo "<tr>";
                echo "<td class='mf-num'>" . mf_quadros_esc((string)(int)($row['id'] ?? 0)) . "</td>";
                echo "<td>" . mf_quadros_esc((string)($row['name'] ?? '')) . "</td>";
                echo "<td>" . mf_quadros_esc((string)($row['board'] ?? '')) . "</td>";
                echo "<td>" . mf_quadros_esc((string)($row['list'] ?? '')) . "</td>";
                echo "<td>" . mf_quadros_esc((string)($row['category'] ?? '')) . "</td>";
                echo "<td class='mf-num'>" . mf_quadros_esc($ticketText) . "</td>";
                echo "<td>" . mf_quadros_esc($statusText) . "</td>";
                echo "<td>" . mf_quadros_esc($owner) . "</td>";
                echo "<td>" . mf_quadros_datetime($row['date_creation'] ?? '') . "</td>";
                echo "</tr>";
            }

            echo "</tbody></table></div>";

            echo "<div class='mf-pagination'>";
            if ($detailPage > 1) {
                echo "<a class='mf-btn mf-btn-sm' href='quadros.php?"
                    . mf_quadros_esc(mf_quadros_qs(['page' => $detailPage - 1])) . "'>&laquo; "
                    . mf_quadros_esc(__('Anterior', 'mafasa')) . "</a>";
            }
            if ($detailPage < $detailMaxPage) {
                echo "<a class='mf-btn mf-btn-sm' href='quadros.php?"
                    . mf_quadros_esc(mf_quadros_qs(['page' => $detailPage + 1])) . "'>"
                    . mf_quadros_esc(__('Próxima', 'mafasa')) . " &raquo;</a>";
            }
            echo "</div>";
        }

        echo "</section>";
    }
}

echo "</div>";

Html::footer();
