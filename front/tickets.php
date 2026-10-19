<?php
/**
 * Tela de relatório de chamados GLPI.
 *
 * Duas visões via GET view= (lista|resumo):
 * - lista: filtros + colunas desmarcáveis + tabela paginada (igual ativos).
 * - resumo: filtros + modo de agregação (status, prioridade, técnico, mês...)
 *   com contagens, percentuais e tempos médios.
 */

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/compat.php';

use GlpiPlugin\Mafasa\TicketsReport;
use GlpiPlugin\Mafasa\BoardsReport;

Session::checkLoginUser();

if (!TicketsReport::canView()) {
    mafasa_display_right_error(__('Você não tem permissão para visualizar chamados.', 'mafasa'));
    return;
}

$view = (($_GET['view'] ?? 'lista') === 'resumo') ? 'resumo' : 'lista';
$filters = TicketsReport::getFilters();
$columns = TicketsReport::getColumns();
$defaultCols = TicketsReport::getDefaultColumns();
$modes = TicketsReport::getSummaryModes();

/** Escapa texto para HTML. */
function mf_tix_esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Query string preservando o GET atual, com overrides e remoções. */
function mf_tix_qs(array $extra = [], array $drop = []): string
{
    $q = $_GET;
    foreach ($drop as $d) {
        unset($q[$d]);
    }
    foreach ($extra as $k => $v) {
        $q[$k] = $v;
    }
    return http_build_query($q);
}

/** Formata valor de célula $row['mf_'.$key] conforme getColumnKind(). */
function mf_tix_cell(string $key, $value): string
{
    $kind = TicketsReport::getColumnKind($key);

    if ($kind === 'bool') {
        if ($value === null || $value === '') {
            return '';
        }
        return ((string)$value === '1') ? __('Sim', 'mafasa') : __('Não', 'mafasa');
    }
    if ($value === null || $value === '') {
        return '';
    }
    if ($kind === 'date') {
        try {
            return htmlspecialchars(Html::convDateTime((string)$value));
        } catch (Throwable $e) {
            return htmlspecialchars((string)$value);
        }
    }
    if ($kind === 'duration') {
        return htmlspecialchars(TicketsReport::formatDuration($value));
    }
    if ($kind === 'int') {
        return htmlspecialchars((string)(int)$value);
    }
    if ($key === 'status') {
        return htmlspecialchars(TicketsReport::summaryLabel('status', $value));
    }
    if ($key === 'type') {
        return htmlspecialchars(TicketsReport::summaryLabel('type', $value));
    }
    if (in_array($key, ['priority', 'urgency', 'impact'], true)) {
        return htmlspecialchars(TicketsReport::summaryLabel($key, $value));
    }
    return htmlspecialchars((string)$value);
}

// ---- Estado da lista (mesma lógica do relatório de ativos) ----
$requestedCols = (array)($_GET['cols'] ?? []);
if (!empty($requestedCols)) {
    $selectedCols = array_values(array_intersect($requestedCols, array_keys($columns)));
} else {
    $selectedCols = $defaultCols;
}
$orderedCols = [];
foreach (array_keys($columns) as $colKey) {
    if (in_array($colKey, $selectedCols, true)) {
        $orderedCols[] = $colKey;
    }
}

$sort = (string)($_GET['sort'] ?? 'id');
if (!array_key_exists($sort, $columns)) {
    $sort = 'id';
}
$order = (strtoupper((string)($_GET['order'] ?? 'ASC')) === 'DESC') ? 'DESC' : 'ASC';
$page = max(1, (int)($_GET['page'] ?? 1));

if ($view === 'lista') {
    $result = TicketsReport::buildList($_GET, $page, TicketsReport::PAGE_SIZE, $sort, $order);
} else {
    $mode = (string)($_GET['mode'] ?? 'status');
    if (!isset($modes[$mode])) {
        $mode = 'status';
    }
    $result = TicketsReport::buildSummary($mode, $_GET);
}

Html::header(
    __('[URE] Mafasa — Chamados', 'mafasa'),
    $_SERVER['PHP_SELF'],
    'tools',
    \GlpiPlugin\Mafasa\Report::class
);

$webdir = mafasa_web_dir('mafasa');

echo "<link rel='stylesheet' href='" . mf_tix_esc($webdir . '/public/css/mafasa.css') . "'>";

echo "<div class='mf-page'>";
echo "<div class='mf-page-header'>";
echo "<h2>" . mf_tix_esc(__('Relatório de Chamados', 'mafasa')) . "</h2>";
echo "</div>";

// ---- Navegação entre relatórios ----
echo "<div class='mf-actions mf-nav'>";
if (\GlpiPlugin\Mafasa\Report::canView()) {
    echo "<a class='mf-btn' href='" . mf_tix_esc($webdir . '/front/report.php') . "'>"
        . mf_tix_esc(__('Ativos', 'mafasa')) . "</a>";
}
echo "<a class='mf-btn mf-btn-primary' href='" . mf_tix_esc($webdir . '/front/tickets.php') . "'>"
    . mf_tix_esc(__('Chamados', 'mafasa')) . "</a>";
if (BoardsReport::available() && BoardsReport::canView()) {
    echo "<a class='mf-btn' href='" . mf_tix_esc($webdir . '/front/boards.php') . "'>"
        . mf_tix_esc(__('Quadros Kanpro', 'mafasa')) . "</a>";
}
echo "</div>";

// ---- Abas lista/resumo ----
echo "<div class='mf-tabs'>";
echo "<a class='mf-tab" . ($view === 'lista' ? ' mf-tab-active' : '') . "' href='tickets.php?"
    . mf_tix_esc(mf_tix_qs(['view' => 'lista'], ['page'])) . "'>"
    . mf_tix_esc(__('Lista detalhada', 'mafasa')) . "</a>";
echo "<a class='mf-tab" . ($view === 'resumo' ? ' mf-tab-active' : '') . "' href='tickets.php?"
    . mf_tix_esc(mf_tix_qs(['view' => 'resumo'], ['page'])) . "'>"
    . mf_tix_esc(__('Resumo', 'mafasa')) . "</a>";
echo "</div>";

echo "<form id='mf-form' method='get'>";
echo "<input type='hidden' name='view' value='" . mf_tix_esc($view) . "'>";

// ---- Filtros ----
echo "<fieldset class='mf-filter-group'><legend>" . mf_tix_esc(__('Filtros', 'mafasa')) . "</legend>";
echo "<div class='mf-grid'>";
foreach ($filters as $key => $def) {
    $kind = (string)($def['kind'] ?? 'text');
    $label = (string)($def['label'] ?? $key);
    $raw = $_GET[$key] ?? '';
    $val = is_scalar($raw) ? (string)$raw : '';
    $fid = 'mf-f-' . $key;

    echo "<div class='mf-field'>";
    echo "<label for='" . mf_tix_esc($fid) . "'>" . mf_tix_esc($label) . "</label>";

    if ($kind === 'choices' && !empty($def['choices']) && is_array($def['choices'])) {
        echo "<select id='" . mf_tix_esc($fid) . "' name='" . mf_tix_esc($key) . "'>";
        echo "<option value=''>" . mf_tix_esc(__('Todos', 'mafasa')) . "</option>";
        foreach ($def['choices'] as $optVal => $optLabel) {
            $sel = ((string)$optVal === $val) ? ' selected' : '';
            echo "<option value='" . mf_tix_esc((string)$optVal) . "'" . $sel . ">"
                . mf_tix_esc((string)$optLabel) . "</option>";
        }
        echo "</select>";
    } elseif ($kind === 'dropdown' && !empty($def['dropdown'])) {
        Dropdown::show((string)$def['dropdown'], [
            'name'                => $key,
            'value'               => $val,
            'display'             => true,
            'display_emptychoice' => true,
            'width'               => '100%',
        ]);
    } elseif ($kind === 'date') {
        echo "<input type='date' id='" . mf_tix_esc($fid) . "' name='" . mf_tix_esc($key) . "' value='" . mf_tix_esc($val) . "'>";
    } else {
        echo "<input type='text' id='" . mf_tix_esc($fid) . "' name='" . mf_tix_esc($key) . "' value='" . mf_tix_esc($val) . "'>";
    }

    echo "</div>";
}
echo "</div></fieldset>";

if ($view === 'lista') {
    // ---- Card de colunas ----
    echo "<fieldset class='mf-cols'><legend>" . mf_tix_esc(__('Colunas', 'mafasa')) . "</legend>";
    echo "<div class='mf-cols-actions'>";
    echo "<button type='button' id='mf-cols-all' class='mf-btn mf-btn-sm'>" . mf_tix_esc(__('Marcar tudo', 'mafasa')) . "</button>";
    echo "<button type='button' id='mf-cols-none' class='mf-btn mf-btn-sm'>" . mf_tix_esc(__('Limpar', 'mafasa')) . "</button>";
    echo "<button type='button' id='mf-cols-default' class='mf-btn mf-btn-sm' data-default='"
        . mf_tix_esc(implode(',', $defaultCols)) . "'>" . mf_tix_esc(__('Padrão', 'mafasa')) . "</button>";
    echo "</div>";
    echo "<div class='mf-cols-grid'>";
    foreach ($columns as $colKey => $colLabel) {
        $checked = in_array($colKey, $selectedCols, true) ? ' checked' : '';
        echo "<label class='mf-col'><input type='checkbox' name='cols[]' value='" . mf_tix_esc($colKey)
            . "' data-col='" . mf_tix_esc($colKey) . "'" . $checked . "> " . mf_tix_esc($colLabel) . "</label>";
    }
    echo "</div></fieldset>";
} else {
    // ---- Seletor de modo do resumo ----
    echo "<fieldset class='mf-filter-group'><legend>" . mf_tix_esc(__('Agrupar por', 'mafasa')) . "</legend>";
    echo "<div class='mf-grid'>";
    echo "<div class='mf-field'>";
    echo "<label for='mf-f-mode'>" . mf_tix_esc(__('Modo do resumo', 'mafasa')) . "</label>";
    echo "<select id='mf-f-mode' name='mode'>";
    $currentMode = (string)($_GET['mode'] ?? 'status');
    if (!isset($modes[$currentMode])) {
        $currentMode = 'status';
    }
    foreach ($modes as $modeKey => $modeLabel) {
        $sel = ($modeKey === $currentMode) ? ' selected' : '';
        echo "<option value='" . mf_tix_esc($modeKey) . "'" . $sel . ">" . mf_tix_esc($modeLabel) . "</option>";
    }
    echo "</select>";
    echo "</div>";
    echo "</div></fieldset>";
}

// ---- Ações ----
echo "<div class='mf-actions'>";
echo "<button type='submit' class='mf-btn mf-btn-primary'>" . mf_tix_esc(__('Gerar relatório', 'mafasa')) . "</button>";
echo "<a class='mf-btn' href='tickets.php'>" . mf_tix_esc(__('Limpar filtros', 'mafasa')) . "</a>";
echo "<button type='button' id='mf-copy-link' class='mf-btn'>" . mf_tix_esc(__('Copiar link', 'mafasa')) . "</button>";
echo "<a id='mf-print-link' class='mf-btn' target='_blank' data-print='print-tickets.php' href='print-tickets.php?"
    . mf_tix_esc(mf_tix_qs([], ['page'])) . "'>" . mf_tix_esc(__('Imprimir', 'mafasa')) . "</a>";
echo "</div>";

echo "</form>";

// ---- Resultados ----
$error = isset($result['error']) ? $result['error'] : null;

if ($error) {
    echo "<div class='mf-warning' role='alert'>" . mf_tix_esc((string)$error) . "</div>";
} elseif ($view === 'lista') {
    $resultPage = max(1, (int)($result['page'] ?? 1));
    $maxPage = max(1, (int)($result['max_page'] ?? 1));
    $total = (int)($result['total'] ?? 0);
    $rows = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : [];

    echo "<div class='mf-result-bar'>";
    echo "<span class='mf-page-info'>"
        . mf_tix_esc(sprintf(__('Página %d de %d (%d chamados)', 'mafasa'), $resultPage, $maxPage, $total))
        . "</span>";
    echo "</div>";

    if (empty($rows)) {
        echo "<div class='mf-empty'>" . mf_tix_esc(__('Nenhum chamado encontrado.', 'mafasa')) . "</div>";
    } else {
        echo "<div class='mf-table-wrap'><table class='mf-table' id='mf-table'><thead><tr>";
        foreach ($orderedCols as $colKey) {
            $nextOrder = ($sort === $colKey && $order === 'ASC') ? 'DESC' : 'ASC';
            $href = 'tickets.php?' . mf_tix_qs(['sort' => $colKey, 'order' => $nextOrder], ['page']);
            $arrow = ($sort === $colKey) ? ($order === 'ASC' ? ' &#9650;' : ' &#9660;') : '';
            echo "<th data-col='" . mf_tix_esc($colKey) . "'><a href='" . mf_tix_esc($href) . "'>"
                . mf_tix_esc($columns[$colKey]) . $arrow . "</a></th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($rows as $row) {
            echo "<tr>";
            foreach ($orderedCols as $colKey) {
                $value = is_array($row) ? ($row['mf_' . $colKey] ?? null) : null;
                echo "<td data-col='" . mf_tix_esc($colKey) . "'>" . mf_tix_cell($colKey, $value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table></div>";

        echo "<div class='mf-pagination'>";
        if ($resultPage > 1) {
            echo "<a class='mf-btn mf-btn-sm' href='tickets.php?" . mf_tix_esc(mf_tix_qs(['page' => $resultPage - 1])) . "'>&laquo; "
                . mf_tix_esc(__('Anterior', 'mafasa')) . "</a>";
        }
        if ($resultPage < $maxPage) {
            echo "<a class='mf-btn mf-btn-sm' href='tickets.php?" . mf_tix_esc(mf_tix_qs(['page' => $resultPage + 1])) . "'>"
                . mf_tix_esc(__('Próxima', 'mafasa')) . " &raquo;</a>";
        }
        echo "</div>";
    }
} else {
    // ---- Resumo ----
    $sumRows = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : [];
    $totals = (isset($result['totals']) && is_array($result['totals'])) ? $result['totals'] : [];
    $grand = (int)($totals['grand'] ?? 0);
    $currentMode = (string)($_GET['mode'] ?? 'status');
    if (!isset($modes[$currentMode])) {
        $currentMode = 'status';
    }

    // Métricas gerais
    echo "<div class='mf-card'>";
    echo "<strong>" . mf_tix_esc(__('Total de chamados', 'mafasa')) . ":</strong> " . mf_tix_esc((string)$grand);
    if (isset($totals['avg_first']) && $totals['avg_first'] !== null) {
        echo " &nbsp;|&nbsp; <strong>" . mf_tix_esc(__('Tempo médio de 1ª resposta', 'mafasa')) . ":</strong> "
            . mf_tix_esc(TicketsReport::formatDuration($totals['avg_first']));
    }
    if (isset($totals['avg_resolution']) && $totals['avg_resolution'] !== null) {
        echo " &nbsp;|&nbsp; <strong>" . mf_tix_esc(__('Tempo médio de resolução', 'mafasa')) . ":</strong> "
            . mf_tix_esc(TicketsReport::formatDuration($totals['avg_resolution']));
    }
    echo "</div>";

    if (empty($sumRows)) {
        echo "<div class='mf-empty'>" . mf_tix_esc(__('Nenhum chamado encontrado.', 'mafasa')) . "</div>";
    } else {
        echo "<div class='mf-table-wrap'><table class='mf-table' id='mf-table'><thead><tr>";
        echo "<th>" . mf_tix_esc(__('Grupo', 'mafasa')) . "</th>";
        echo "<th>" . mf_tix_esc(__('Chamados', 'mafasa')) . "</th>";
        echo "<th>" . mf_tix_esc(__('Percentual', 'mafasa')) . "</th>";
        echo "<th>" . mf_tix_esc(__('1ª resposta (média)', 'mafasa')) . "</th>";
        echo "<th>" . mf_tix_esc(__('Resolução (média)', 'mafasa')) . "</th>";
        echo "</tr></thead><tbody>";
        foreach ($sumRows as $row) {
            $count = (int)($row['mf_total'] ?? 0);
            $pct = ($grand > 0) ? round(($count * 100) / $grand, 1) : 0;
            echo "<tr>";
            echo "<td>" . mf_tix_esc((string)($row['mf_label'] ?? '—')) . "</td>";
            echo "<td>" . mf_tix_esc((string)$count) . "</td>";
            echo "<td>" . mf_tix_esc((string)$pct) . "%</td>";
            echo "<td>" . mf_tix_esc(TicketsReport::formatDuration($row['mf_avg_first'] ?? null)) . "</td>";
            echo "<td>" . mf_tix_esc(TicketsReport::formatDuration($row['mf_avg_resolution'] ?? null)) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
    }
}

echo "<script src='" . mf_tix_esc($webdir . '/public/js/app.js') . "'></script>";
echo "</div>";

Html::footer();
