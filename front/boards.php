<?php
/**
 * Tela de relatório dos quadros Kanpro.
 *
 * Duas visões via GET view= (lista|resumo):
 * - lista: filtros + colunas desmarcáveis + tabela paginada de cartões.
 * - resumo: agrupamentos (quadro, coluna, membro, prazo, chamado, mês,
 *   checklist) com contagens e percentuais.
 *
 * Se o Kanpro não estiver instalado, mostra aviso em vez de quebrar.
 */

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/compat.php';

use GlpiPlugin\Mafasa\BoardsReport;

Session::checkLoginUser();

if (!BoardsReport::canView()) {
    mafasa_display_right_error(__('Você não tem permissão para visualizar quadros Kanpro.', 'mafasa'));
    return;
}

$view = (($_GET['view'] ?? 'lista') === 'resumo') ? 'resumo' : 'lista';
$available = BoardsReport::available();
$filters = BoardsReport::getFilters();
$columns = BoardsReport::getColumns();
$defaultCols = BoardsReport::getDefaultColumns();
$modes = BoardsReport::getSummaryModes();

/** Escapa texto para HTML. */
function mf_brd_esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Query string preservando o GET atual, com overrides e remoções. */
function mf_brd_qs(array $extra = [], array $drop = []): string
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
function mf_brd_cell(string $key, $value): string
{
    $kind = BoardsReport::getColumnKind($key);

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
    if ($kind === 'int') {
        return htmlspecialchars((string)(int)$value);
    }
    return htmlspecialchars((string)$value);
}

// ---- Estado da lista ----
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
    $result = BoardsReport::buildList($_GET, $page, BoardsReport::PAGE_SIZE, $sort, $order);
} else {
    $mode = (string)($_GET['mode'] ?? 'board');
    if (!isset($modes[$mode])) {
        $mode = 'board';
    }
    $result = BoardsReport::buildSummary($mode, $_GET);
}

Html::header(
    __('[URE] Mafasa — Quadros', 'mafasa'),
    $_SERVER['PHP_SELF'],
    'tools',
    \GlpiPlugin\Mafasa\Report::class
);

$webdir = mafasa_web_dir('mafasa');

echo "<link rel='stylesheet' href='" . mf_brd_esc($webdir . '/public/css/mafasa.css') . "'>";

echo "<div class='mf-page'>";
echo "<div class='mf-page-header'>";
echo "<h2>" . mf_brd_esc(__('Relatório de Quadros (Kanpro)', 'mafasa')) . "</h2>";
echo "</div>";

// ---- Navegação entre relatórios ----
echo "<div class='mf-actions mf-nav'>";
if (\GlpiPlugin\Mafasa\Report::canView()) {
    echo "<a class='mf-btn' href='" . mf_brd_esc($webdir . '/front/report.php') . "'>"
        . mf_brd_esc(__('Ativos', 'mafasa')) . "</a>";
}
if (class_exists('Ticket') && Ticket::canView()) {
    echo "<a class='mf-btn' href='" . mf_brd_esc($webdir . '/front/tickets.php') . "'>"
        . mf_brd_esc(__('Chamados', 'mafasa')) . "</a>";
}
echo "<a class='mf-btn mf-btn-primary' href='" . mf_brd_esc($webdir . '/front/boards.php') . "'>"
    . mf_brd_esc(__('Quadros Kanpro', 'mafasa')) . "</a>";
echo "</div>";

if (!$available) {
    echo "<div class='mf-warning' role='alert'>"
        . mf_brd_esc(__('O plugin Kanpro não está instalado (tabelas de quadros ausentes). Os relatórios de quadros ficam indisponíveis.', 'mafasa'))
        . "</div>";
    echo "</div>";
    Html::footer();
    return;
}

// ---- Abas lista/resumo ----
echo "<div class='mf-tabs'>";
echo "<a class='mf-tab" . ($view === 'lista' ? ' mf-tab-active' : '') . "' href='boards.php?"
    . mf_brd_esc(mf_brd_qs(['view' => 'lista'], ['page'])) . "'>"
    . mf_brd_esc(__('Lista detalhada', 'mafasa')) . "</a>";
echo "<a class='mf-tab" . ($view === 'resumo' ? ' mf-tab-active' : '') . "' href='boards.php?"
    . mf_brd_esc(mf_brd_qs(['view' => 'resumo'], ['page'])) . "'>"
    . mf_brd_esc(__('Resumo', 'mafasa')) . "</a>";
echo "</div>";

echo "<form id='mf-form' method='get'>";
echo "<input type='hidden' name='view' value='" . mf_brd_esc($view) . "'>";

// ---- Filtros ----
echo "<fieldset class='mf-filter-group'><legend>" . mf_brd_esc(__('Filtros', 'mafasa')) . "</legend>";
echo "<div class='mf-grid'>";
foreach ($filters as $key => $def) {
    $kind = (string)($def['kind'] ?? 'text');
    $label = (string)($def['label'] ?? $key);
    $raw = $_GET[$key] ?? '';
    $val = is_scalar($raw) ? (string)$raw : '';
    $fid = 'mf-f-' . $key;

    echo "<div class='mf-field'>";
    echo "<label for='" . mf_brd_esc($fid) . "'>" . mf_brd_esc($label) . "</label>";

    if ($kind === 'choices' && !empty($def['choices']) && is_array($def['choices'])) {
        echo "<select id='" . mf_brd_esc($fid) . "' name='" . mf_brd_esc($key) . "'>";
        echo "<option value=''>" . mf_brd_esc(__('Todos', 'mafasa')) . "</option>";
        foreach ($def['choices'] as $optVal => $optLabel) {
            $sel = ((string)$optVal === $val) ? ' selected' : '';
            echo "<option value='" . mf_brd_esc((string)$optVal) . "'" . $sel . ">"
                . mf_brd_esc((string)$optLabel) . "</option>";
        }
        echo "</select>";
    } elseif ($kind === 'date') {
        echo "<input type='date' id='" . mf_brd_esc($fid) . "' name='" . mf_brd_esc($key) . "' value='" . mf_brd_esc($val) . "'>";
    } else {
        echo "<input type='text' id='" . mf_brd_esc($fid) . "' name='" . mf_brd_esc($key) . "' value='" . mf_brd_esc($val) . "'>";
    }

    echo "</div>";
}
echo "</div></fieldset>";

if ($view === 'lista') {
    // ---- Card de colunas ----
    echo "<fieldset class='mf-cols'><legend>" . mf_brd_esc(__('Colunas', 'mafasa')) . "</legend>";
    echo "<div class='mf-cols-actions'>";
    echo "<button type='button' id='mf-cols-all' class='mf-btn mf-btn-sm'>" . mf_brd_esc(__('Marcar tudo', 'mafasa')) . "</button>";
    echo "<button type='button' id='mf-cols-none' class='mf-btn mf-btn-sm'>" . mf_brd_esc(__('Limpar', 'mafasa')) . "</button>";
    echo "<button type='button' id='mf-cols-default' class='mf-btn mf-btn-sm' data-default='"
        . mf_brd_esc(implode(',', $defaultCols)) . "'>" . mf_brd_esc(__('Padrão', 'mafasa')) . "</button>";
    echo "</div>";
    echo "<div class='mf-cols-grid'>";
    foreach ($columns as $colKey => $colLabel) {
        $checked = in_array($colKey, $selectedCols, true) ? ' checked' : '';
        echo "<label class='mf-col'><input type='checkbox' name='cols[]' value='" . mf_brd_esc($colKey)
            . "' data-col='" . mf_brd_esc($colKey) . "'" . $checked . "> " . mf_brd_esc($colLabel) . "</label>";
    }
    echo "</div></fieldset>";
} else {
    // ---- Seletor de modo do resumo ----
    echo "<fieldset class='mf-filter-group'><legend>" . mf_brd_esc(__('Agrupar por', 'mafasa')) . "</legend>";
    echo "<div class='mf-grid'>";
    echo "<div class='mf-field'>";
    echo "<label for='mf-f-mode'>" . mf_brd_esc(__('Modo do resumo', 'mafasa')) . "</label>";
    echo "<select id='mf-f-mode' name='mode'>";
    $currentMode = (string)($_GET['mode'] ?? 'board');
    if (!isset($modes[$currentMode])) {
        $currentMode = 'board';
    }
    foreach ($modes as $modeKey => $modeLabel) {
        $sel = ($modeKey === $currentMode) ? ' selected' : '';
        echo "<option value='" . mf_brd_esc($modeKey) . "'" . $sel . ">" . mf_brd_esc($modeLabel) . "</option>";
    }
    echo "</select>";
    echo "</div>";
    echo "</div></fieldset>";
}

// ---- Ações ----
echo "<div class='mf-actions'>";
echo "<button type='submit' class='mf-btn mf-btn-primary'>" . mf_brd_esc(__('Gerar relatório', 'mafasa')) . "</button>";
echo "<a class='mf-btn' href='boards.php'>" . mf_brd_esc(__('Limpar filtros', 'mafasa')) . "</a>";
echo "<button type='button' id='mf-copy-link' class='mf-btn'>" . mf_brd_esc(__('Copiar link', 'mafasa')) . "</button>";
echo "<a id='mf-print-link' class='mf-btn' target='_blank' data-print='print-boards.php' href='print-boards.php?"
    . mf_brd_esc(mf_brd_qs([], ['page'])) . "'>" . mf_brd_esc(__('Imprimir', 'mafasa')) . "</a>";
echo "</div>";

echo "</form>";

// ---- Resultados ----
$error = isset($result['error']) ? $result['error'] : null;

if ($error) {
    echo "<div class='mf-warning' role='alert'>" . mf_brd_esc((string)$error) . "</div>";
} elseif ($view === 'lista') {
    $resultPage = max(1, (int)($result['page'] ?? 1));
    $maxPage = max(1, (int)($result['max_page'] ?? 1));
    $total = (int)($result['total'] ?? 0);
    $rows = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : [];

    echo "<div class='mf-result-bar'>";
    echo "<span class='mf-page-info'>"
        . mf_brd_esc(sprintf(__('Página %d de %d (%d cartões)', 'mafasa'), $resultPage, $maxPage, $total))
        . "</span>";
    echo "</div>";

    if (empty($rows)) {
        echo "<div class='mf-empty'>" . mf_brd_esc(__('Nenhum cartão encontrado.', 'mafasa')) . "</div>";
    } else {
        echo "<div class='mf-table-wrap'><table class='mf-table' id='mf-table'><thead><tr>";
        foreach ($orderedCols as $colKey) {
            $nextOrder = ($sort === $colKey && $order === 'ASC') ? 'DESC' : 'ASC';
            $href = 'boards.php?' . mf_brd_qs(['sort' => $colKey, 'order' => $nextOrder], ['page']);
            $arrow = ($sort === $colKey) ? ($order === 'ASC' ? ' &#9650;' : ' &#9660;') : '';
            echo "<th data-col='" . mf_brd_esc($colKey) . "'><a href='" . mf_brd_esc($href) . "'>"
                . mf_brd_esc($columns[$colKey]) . $arrow . "</a></th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($rows as $row) {
            echo "<tr>";
            foreach ($orderedCols as $colKey) {
                $value = is_array($row) ? ($row['mf_' . $colKey] ?? null) : null;
                echo "<td data-col='" . mf_brd_esc($colKey) . "'>" . mf_brd_cell($colKey, $value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table></div>";

        echo "<div class='mf-pagination'>";
        if ($resultPage > 1) {
            echo "<a class='mf-btn mf-btn-sm' href='boards.php?" . mf_brd_esc(mf_brd_qs(['page' => $resultPage - 1])) . "'>"
                . mf_brd_esc(__('Anterior', 'mafasa')) . " &laquo;</a>";
        }
        if ($resultPage < $maxPage) {
            echo "<a class='mf-btn mf-btn-sm' href='boards.php?" . mf_brd_esc(mf_brd_qs(['page' => $resultPage + 1])) . "'>"
                . mf_brd_esc(__('Próxima', 'mafasa')) . " &raquo;</a>";
        }
        echo "</div>";
    }
} else {
    // ---- Resumo ----
    $sumRows = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : [];
    $totals = (isset($result['totals']) && is_array($result['totals'])) ? $result['totals'] : [];
    $grand = (int)($totals['grand'] ?? 0);
    $currentMode = (string)($_GET['mode'] ?? 'board');
    if (!isset($modes[$currentMode])) {
        $currentMode = 'board';
    }
    $isChecklist = ($currentMode === 'checklist');

    // Métricas gerais
    echo "<div class='mf-card'>";
    echo "<strong>" . mf_brd_esc(__('Total de cartões', 'mafasa')) . ":</strong> " . mf_brd_esc((string)$grand);
    if (isset($totals['done'])) {
        $pctDone = ($grand > 0) ? round(((int)$totals['done'] * 100) / $grand, 1) : 0;
        echo " &nbsp;|&nbsp; <strong>" . mf_brd_esc(__('Concluídos', 'mafasa')) . ":</strong> "
            . mf_brd_esc((string)(int)$totals['done']) . " (" . mf_brd_esc((string)$pctDone) . "%)";
    }
    echo "</div>";

    if (empty($sumRows)) {
        echo "<div class='mf-empty'>" . mf_brd_esc(__('Nenhum cartão encontrado.', 'mafasa')) . "</div>";
    } else {
        echo "<div class='mf-table-wrap'><table class='mf-table' id='mf-table'><thead><tr>";
        if ($isChecklist) {
            echo "<th>" . mf_brd_esc(__('Item', 'mafasa')) . "</th>";
            echo "<th>" . mf_brd_esc(__('Total de itens', 'mafasa')) . "</th>";
            echo "<th>" . mf_brd_esc(__('Concluídos', 'mafasa')) . "</th>";
            echo "<th>" . mf_brd_esc(__('Percentual', 'mafasa')) . "</th>";
        } else {
            echo "<th>" . mf_brd_esc(__('Grupo', 'mafasa')) . "</th>";
            echo "<th>" . mf_brd_esc(__('Cartões', 'mafasa')) . "</th>";
            echo "<th>" . mf_brd_esc(__('Percentual', 'mafasa')) . "</th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($sumRows as $row) {
            $count = (int)($row['mf_total'] ?? 0);
            echo "<tr>";
            echo "<td>" . mf_brd_esc((string)($row['mf_label'] ?? '—')) . "</td>";
            echo "<td>" . mf_brd_esc((string)$count) . "</td>";
            if ($isChecklist) {
                $checked = (int)($row['mf_checked'] ?? 0);
                $pct = ($count > 0) ? round(($checked * 100) / $count, 1) : 0;
                echo "<td>" . mf_brd_esc((string)$checked) . "</td>";
                echo "<td>" . mf_brd_esc((string)$pct) . "%</td>";
            } else {
                $pct = ($grand > 0) ? round(($count * 100) / $grand, 1) : 0;
                echo "<td>" . mf_brd_esc((string)$pct) . "%</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table></div>";
    }
}

echo "<script src='" . mf_brd_esc($webdir . '/public/js/app.js') . "'></script>";
echo "</div>";

Html::footer();
