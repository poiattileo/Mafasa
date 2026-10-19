<?php
/**
 * Tela de relatório de ativos (v2).
 *
 * Estado 100% GET, compartilhável: types[], filtros, cols[], page, sort, order.
 * A busca SEMPRE roda na carga (sem gate). Sem direito/tipos => erro de direito.
 */

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/compat.php';

use GlpiPlugin\Mafasa\Report;

Session::checkLoginUser();

$tokens = Report::normalizeTypes($_GET);
if (empty($tokens) || !Report::canView()) {
    mafasa_display_right_error(__('Você não tem permissão para acessar relatórios de ativos.', 'mafasa'));
    return;
}

$catalog = Report::getTypeCatalog();
$filters = Report::getFilters($tokens);
$columns = Report::getColumns($tokens);
$defaultCols = Report::getDefaultColumns($tokens);

// ---- Colunas selecionadas (cols[] não-vazio => interseção; senão padrão) ----
$requestedCols = (array)($_GET['cols'] ?? []);
if (!empty($requestedCols)) {
    $selectedCols = array_values(array_intersect($requestedCols, array_keys($columns)));
} else {
    $selectedCols = $defaultCols;
}

// Ordem canônica §5: reordena as colunas selecionadas conforme getColumns().
$orderedCols = [];
foreach (array_keys($columns) as $colKey) {
    if (in_array($colKey, $selectedCols, true)) {
        $orderedCols[] = $colKey;
    }
}

// ---- sort / order (validados como em buildSearch) ----
$sort = (string)($_GET['sort'] ?? 'id');
if (!array_key_exists($sort, $columns)) {
    $sort = 'id';
}
$order = (strtoupper((string)($_GET['order'] ?? 'ASC')) === 'DESC') ? 'DESC' : 'ASC';
$page = max(1, (int)($_GET['page'] ?? 1));

// ---- Busca SEMPRE na carga ----
$result = Report::buildSearch($tokens, $_GET, $page, Report::PAGE_SIZE, $sort, $order);

$resultPage = max(1, (int)($result['page'] ?? 1));
$maxPage    = max(1, (int)($result['max_page'] ?? 1));
$total      = (int)($result['total'] ?? 0);
$rows       = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : [];
$hasError   = !empty($result['error']);

/** Escapa texto para HTML. */
function mf_report_esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Query string preservando o GET atual, com overrides e remoções. */
function mf_report_qs(array $extra = [], array $drop = []): string
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

/** Formata o valor da célula `$row['mf_'.$key']` conforme getColumnKind(). */
function mf_report_cell(string $key, $value): string
{
    $kind = Report::getColumnKind($key);

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

Html::header(
    __('[URE] Mafasa — Relatórios de Ativos', 'mafasa'),
    $_SERVER['PHP_SELF'],
    'tools',
    \GlpiPlugin\Mafasa\Report::class
);

$webdir = mafasa_web_dir('mafasa');

echo "<link rel='stylesheet' href='" . mf_report_esc($webdir . '/public/css/mafasa.css') . "'>";

echo "<div class='mf-page'>";
echo "<div class='mf-page-header'>";
echo "<h2>" . mf_report_esc(__('Relatório de Ativos', 'mafasa')) . "</h2>";
echo "</div>";

echo "<form id='mf-form' method='get'>";

// ---- Card de tipos (mestre + grade) ----
echo "<div class='mf-card'>";
echo "<label class='mf-types-all'><input type='checkbox' id='mf-types-all'> "
    . mf_report_esc(__('Todos os ativos', 'mafasa')) . "</label>";
echo "<div class='mf-types'>";
foreach ($catalog as $entry) {
    if (empty($entry['viewable'])) {
        continue;
    }
    $token = (string)($entry['token'] ?? '');
    if ($token === '') {
        continue;
    }
    $checked = in_array($token, $tokens, true) ? ' checked' : '';
    $icon = (string)($entry['icon'] ?? '');
    echo "<label class='mf-type'>";
    echo "<input type='checkbox' name='types[]' value='" . mf_report_esc($token) . "'" . $checked . ">";
    if ($icon !== '') {
        echo " <i class='" . mf_report_esc($icon) . "'></i>";
    }
    echo " <span>" . mf_report_esc($entry['label'] ?? $token) . "</span>";
    echo "</label>";
}
echo "</div>";
echo "</div>";

// ---- Filtros (ordem congelada §4) ----
echo "<fieldset class='mf-filter-group'><legend>" . mf_report_esc(__('Filtros', 'mafasa')) . "</legend>";
echo "<div class='mf-grid'>";
foreach ($filters as $key => $def) {
    $kind  = (string)($def['kind'] ?? 'text');
    $label = (string)($def['label'] ?? $key);
    $raw   = $_GET[$key] ?? '';
    $val   = is_scalar($raw) ? (string)$raw : '';
    $fid   = 'mf-f-' . $key;

    echo "<div class='mf-field'>";
    echo "<label for='" . mf_report_esc($fid) . "'>" . mf_report_esc($label) . "</label>";

    if ($kind === 'dropdown') {
        if (!empty($def['choices']) && is_array($def['choices'])) {
            Dropdown::showFromArray($key, $def['choices'], [
                'value'               => $val,
                'display'             => true,
                'display_emptychoice' => true,
                'width'               => '100%',
            ]);
        } elseif (!empty($def['dropdown'])) {
            Dropdown::show((string)$def['dropdown'], [
                'name'                => $key,
                'value'               => $val,
                'display'             => true,
                'display_emptychoice' => true,
                'width'               => '100%',
            ]);
        }
    } elseif ($kind === 'bool') {
        echo "<select id='" . mf_report_esc($fid) . "' name='" . mf_report_esc($key) . "'>";
        echo "<option value=''" . ($val === '' ? ' selected' : '') . ">" . mf_report_esc(__('Todos', 'mafasa')) . "</option>";
        echo "<option value='1'" . ($val === '1' ? ' selected' : '') . ">" . mf_report_esc(__('Sim', 'mafasa')) . "</option>";
        echo "<option value='0'" . ($val === '0' ? ' selected' : '') . ">" . mf_report_esc(__('Não', 'mafasa')) . "</option>";
        echo "</select>";
    } elseif ($kind === 'date') {
        echo "<input type='date' id='" . mf_report_esc($fid) . "' name='" . mf_report_esc($key) . "' value='" . mf_report_esc($val) . "'>";
    } elseif ($kind === 'int') {
        echo "<input type='number' step='1' id='" . mf_report_esc($fid) . "' name='" . mf_report_esc($key) . "' value='" . mf_report_esc($val) . "'>";
    } else {
        echo "<input type='text' id='" . mf_report_esc($fid) . "' name='" . mf_report_esc($key) . "' value='" . mf_report_esc($val) . "'>";
    }

    echo "</div>";
}
echo "</div></fieldset>";

// ---- Card de colunas ----
echo "<fieldset class='mf-cols'><legend>" . mf_report_esc(__('Colunas', 'mafasa')) . "</legend>";
echo "<div class='mf-cols-actions'>";
echo "<button type='button' id='mf-cols-all' class='mf-btn mf-btn-sm'>" . mf_report_esc(__('Marcar tudo', 'mafasa')) . "</button>";
echo "<button type='button' id='mf-cols-none' class='mf-btn mf-btn-sm'>" . mf_report_esc(__('Limpar', 'mafasa')) . "</button>";
echo "<button type='button' id='mf-cols-default' class='mf-btn mf-btn-sm' data-default='"
    . mf_report_esc(implode(',', $defaultCols)) . "'>" . mf_report_esc(__('Padrão', 'mafasa')) . "</button>";
echo "</div>";
echo "<div class='mf-cols-grid'>";
foreach ($columns as $colKey => $colLabel) {
    $checked = in_array($colKey, $selectedCols, true) ? ' checked' : '';
    echo "<label class='mf-col'><input type='checkbox' name='cols[]' value='" . mf_report_esc($colKey)
        . "' data-col='" . mf_report_esc($colKey) . "'" . $checked . "> " . mf_report_esc($colLabel) . "</label>";
}
echo "</div></fieldset>";

// ---- Ações ----
echo "<div class='mf-actions'>";
echo "<button type='submit' class='mf-btn mf-btn-primary'>" . mf_report_esc(__('Gerar relatório', 'mafasa')) . "</button>";
echo "<a class='mf-btn' href='report.php'>" . mf_report_esc(__('Limpar filtros', 'mafasa')) . "</a>";
echo "<button type='button' id='mf-copy-link' class='mf-btn'>" . mf_report_esc(__('Copiar link', 'mafasa')) . "</button>";
echo "<a id='mf-print-link' class='mf-btn' target='_blank' href='print.php?"
    . mf_report_esc(mf_report_qs([], ['page'])) . "'>" . mf_report_esc(__('Imprimir', 'mafasa')) . "</a>";
echo "</div>";

echo "</form>";

// ---- Resultados ----
if ($hasError) {
    echo "<div class='mf-warning' role='alert'>" . mf_report_esc((string)$result['error']) . "</div>";
} else {
    echo "<div class='mf-result-bar'>";
    echo "<span class='mf-page-info'>"
        . mf_report_esc(sprintf(__('Página %d de %d (%d registros)', 'mafasa'), $resultPage, $maxPage, $total))
        . "</span>";
    echo "</div>";

    if (empty($rows)) {
        echo "<div class='mf-empty'>" . mf_report_esc(__('Nenhum registro encontrado.', 'mafasa')) . "</div>";
    } else {
        echo "<div class='mf-table-wrap'><table class='mf-table' id='mf-table'><thead><tr>";
        foreach ($orderedCols as $colKey) {
            $nextOrder = ($sort === $colKey && $order === 'ASC') ? 'DESC' : 'ASC';
            $href = 'report.php?' . mf_report_qs(['sort' => $colKey, 'order' => $nextOrder], ['page']);
            $arrow = ($sort === $colKey) ? ($order === 'ASC' ? ' &#9650;' : ' &#9660;') : '';
            echo "<th data-col='" . mf_report_esc($colKey) . "'><a href='" . mf_report_esc($href) . "'>"
                . mf_report_esc($columns[$colKey]) . $arrow . "</a></th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($rows as $row) {
            echo "<tr>";
            foreach ($orderedCols as $colKey) {
                $value = is_array($row) ? ($row['mf_' . $colKey] ?? null) : null;
                echo "<td data-col='" . mf_report_esc($colKey) . "'>" . mf_report_cell($colKey, $value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table></div>";

        echo "<div class='mf-pagination'>";
        if ($resultPage > 1) {
            echo "<a class='mf-btn mf-btn-sm' href='report.php?" . mf_report_esc(mf_report_qs(['page' => $resultPage - 1])) . "'>&laquo; "
                . mf_report_esc(__('Anterior', 'mafasa')) . "</a>";
        }
        if ($resultPage < $maxPage) {
            echo "<a class='mf-btn mf-btn-sm' href='report.php?" . mf_report_esc(mf_report_qs(['page' => $resultPage + 1])) . "'>"
                . mf_report_esc(__('Próxima', 'mafasa')) . " &raquo;</a>";
        }
        echo "</div>";
    }
}

echo "<script src='" . mf_report_esc($webdir . '/public/js/app.js') . "'></script>";
echo "</div>";

Html::footer();
