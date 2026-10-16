<?php
/**
 * Tela de relatório de ativos (Task 3).
 *
 * GET compartilhável: type, f_*, cols[], page, sort, order.
 * Tipo inválido cai para Computer com aviso (nunca fatal).
 * Direitos: Itemtype::canView() nativo + entidade ativa.
 */

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/compat.php';

use GlpiPlugin\Mafasa\Report;

Session::checkLoginUser();

global $DB, $CFG_GLPI;

// ---- Tipo / categoria ----
$rawType = trim((string)($_GET['type'] ?? ''));
$type = Report::normalizeType($rawType);
$typeInvalid = ($rawType !== '');
if ($typeInvalid) {
    $found = false;
    foreach (Report::getCategories() as $it => $lbl) {
        if (strcasecmp($it, $rawType) === 0) {
            $found = true;
            break;
        }
    }
    $typeInvalid = !$found;
}
$itemtype = $type;
$label = Report::getLabel($type);
$table = Report::getTable($type);

// ---- Direitos (spec §11.2: sem canView => erro de direito) ----
if (class_exists($itemtype) && method_exists($itemtype, 'canView')) {
    if (!$itemtype::canView()) {
        Html::displayRightError();
    }
}

$filters = Report::getFilters($type);
$columns = Report::getColumns($type);
$defaultCols = Report::getDefaultColumns($type);

// ---- Colunas selecionadas: todas marcadas por padrão ----
if (isset($_GET['cols']) && is_array($_GET['cols'])) {
    $selectedCols = array_values(array_intersect((array)$_GET['cols'], array_keys($columns)));
    if (empty($selectedCols)) {
        $selectedCols = array_keys($columns);
    }
} else {
    $selectedCols = array_keys($columns);
}

// ---- Sort / order / page ----
$sort = (string)($_GET['sort'] ?? 'id');
if (!isset($columns[$sort])) {
    $sort = 'id';
}
$order = strtoupper((string)($_GET['order'] ?? 'ASC'));
if ($order !== 'ASC' && $order !== 'DESC') {
    $order = 'ASC';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = Report::PAGE_SIZE;
$start = ($page - 1) * $limit;

// ---- Gerar? (botão Gerar, paginação, link compartilhado com filtros ou cols) ----
$doSearch = isset($_GET['gerar']) || isset($_GET['page']) || isset($_GET['cols']);
if (!$doSearch) {
    foreach ($_GET as $k => $v) {
        if (str_starts_with((string)$k, 'f_') && trim((string)$v) !== '') {
            $doSearch = true;
            break;
        }
    }
}

// Valores saneados para reexibir no form (buildWhere já escapa SQL via quoteValue/cast).
$built = Report::buildWhere($type, $_GET);
$whereSql = $built['sql'];
$clean = $built['params'];

// ---- Entidade ativa (espelha Protocolo dashboard) ----
$activeEntities = $_SESSION['glpiactiveentities'] ?? ($_SESSION['glpiactive_entity'] ?? []);
if (!is_array($activeEntities)) {
    $activeEntities = [$activeEntities];
}
$activeEntities = array_values(array_filter(array_map('intval', $activeEntities), function ($v) {
    return $v > 0;
}));
$hasAllEntities = false;
try {
    $hasAllEntities = method_exists(Session::class, 'haveAccessToAllEntities') && Session::haveAccessToAllEntities();
} catch (Throwable $e) {
    $hasAllEntities = false;
}
$entitySql = '';
if (!$hasAllEntities && !empty($activeEntities)) {
    $entitySql = ' AND `entities_id` IN (' . implode(',', $activeEntities) . ')';
}

// ---- Busca paginada ----
$total = 0;
$rows = [];
if ($doSearch) {
    try {
        $countIt = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => $table,
            'WHERE' => [new \Glpi\DBAL\QueryExpression($whereSql . $entitySql)],
        ]);
        foreach ($countIt as $r) {
            $total = (int)($r['cpt'] ?? 0);
        }
    } catch (Throwable $e) {
        $total = 0;
    }
    $maxPage = max(1, (int)ceil($total / $limit));
    if ($page > $maxPage) {
        $page = $maxPage;
        $start = ($page - 1) * $limit;
    }
    try {
        $sortCol = Report::getFieldColumn($type, $sort);
        $it = $DB->request([
            'SELECT' => ['*'],
            'FROM'   => $table,
            'WHERE'  => [new \Glpi\DBAL\QueryExpression($whereSql . $entitySql)],
            'ORDER'  => [$sortCol . ' ' . $order],
            'LIMIT'  => $limit,
            'START'  => $start,
        ]);
        foreach ($it as $r) {
            $rows[] = $r;
        }
    } catch (Throwable $e) {
        $rows = [];
    }
}

/**
 * Valor legível de célula: FK vira nome via Dropdown, bool vira Sim/Não.
 */
function mf_report_cell(string $type, string $field, array $filters, $value): string
{
    if ($value === null || $value === '') {
        return '<span style="color:#9ca3af">—</span>';
    }
    $def = $filters[$field] ?? null;
    $kind = is_array($def) ? ($def['kind'] ?? 'text') : 'text';
    if ($kind === 'bool') {
        return ((int)$value === 1) ? 'Sim' : 'Não';
    }
    if (in_array($field, ['date_creation', 'date_mod'], true) || ($def !== null && $kind === 'date')) {
        try {
            $h = Html::convDateTime((string)$value);
            if ($h !== '') {
                return htmlspecialchars($h);
            }
        } catch (Throwable $e) {
            // cai para o texto puro abaixo
        }
        return htmlspecialchars((string)$value);
    }
    if ($def !== null && $kind === 'int' && is_numeric($value) && (int)$value > 0) {
        $col = $def['column'] ?? $field;
        // Coluna real "xyz_id" => tabela "glpi_xyzs".
        $fkTable = 'glpi_' . substr($col, 0, -3);
        if ($field === 'users_id' || $col === 'users_id') {
            $fkTable = 'glpi_users';
        }
        try {
            if (class_exists('Dropdown') && method_exists('Dropdown', 'getDropdownName')) {
                $nm = Dropdown::getDropdownName($fkTable, (int)$value);
                if (is_string($nm) && $nm !== '' && $nm !== (string)(int)$value) {
                    return htmlspecialchars($nm);
                }
            }
        } catch (Throwable $e) {
            // fallback: id puro
        }
        return htmlspecialchars((string)$value);
    }
    $s = (string)$value;
    if (function_exists('mb_strlen') && mb_strlen($s) > 120) {
        $s = mb_substr($s, 0, 120) . '…';
    } elseif (strlen($s) > 120) {
        $s = substr($s, 0, 120) . '…';
    }
    return htmlspecialchars($s);
}

/** Agrupa filtros nas 3 seções da tela. */
function mf_report_section(string $field): string
{
    if (str_starts_with($field, 'date_')) {
        return 'dates';
    }
    $loc = ['entities_id', 'locations_id', 'groups_id', 'users_id', 'groups_id_tech',
        'networks_id', 'domains_id', 'dcrooms_id', 'softwarecategories_id'];
    if (in_array($field, $loc, true)) {
        return 'location';
    }
    return 'ident';
}

/** Monta query string preservando filtros + colunas. */
function mf_report_qs(array $extra = []): string
{
    $q = $_GET;
    foreach ($extra as $k => $v) {
        $q[$k] = $v;
    }
    return http_build_query($q);
}

Html::header(
    sprintf(__('Relatório de Ativos — %s', 'mafasa'), $label),
    $_SERVER['PHP_SELF'],
    'tools',
    $itemtype
);

$webdir = mafasa_web_dir('mafasa');
$jsUrl = $webdir . '/public/js/app.js';

echo "<div class='mf-page'>";
echo "<div class='mf-page-header'>";
echo "<h2>" . sprintf(htmlspecialchars(__('Relatório de Ativos — %s', 'mafasa')), htmlspecialchars($label)) . "</h2>";
echo "<span class='mf-badge'>" . (int)$total . " " . htmlspecialchars(__('itens', 'mafasa')) . "</span>";
echo "</div>";

// Abas por categoria
echo "<nav class='mf-tabs' aria-label='" . htmlspecialchars(__('Categorias', 'mafasa')) . "'>";
foreach (Report::getCategories() as $it => $lbl) {
    $cls = ($it === $type) ? 'mf-tab mf-tab-active' : 'mf-tab';
    echo "<a class='" . $cls . "' href='report.php?" . http_build_query(['type' => $it]) . "'>" . htmlspecialchars($lbl) . "</a>";
}
echo "</nav>";

if ($typeInvalid) {
    echo "<div class='mf-warning' role='alert'>";
    echo htmlspecialchars(sprintf(__('Categoria "%s" inválida — mostrando %s.', 'mafasa'), $rawType, $label));
    echo "</div>";
}

// ---- Formulário de filtros ----
echo "<form id='mf-form' class='mf-card' method='get' action='report.php'>";
echo "<input type='hidden' name='type' value='" . htmlspecialchars($type) . "'>";
echo "<input type='hidden' name='sort' value='" . htmlspecialchars($sort) . "'>";
echo "<input type='hidden' name='order' value='" . htmlspecialchars($order) . "'>";

$groups = ['ident' => [], 'location' => [], 'dates' => []];
foreach ($filters as $fname => $fdef) {
    $groups[mf_report_section($fname)][] = [$fname, $fdef];
}
$titles = [
    'ident'    => __('Identificação', 'mafasa'),
    'location' => __('Localização / Responsáveis', 'mafasa'),
    'dates'    => __('Datas', 'mafasa'),
];
echo "<div class='mf-filters'>";
foreach ($groups as $gkey => $items) {
    if (empty($items)) {
        continue;
    }
    echo "<fieldset class='mf-filter-group'><legend>" . htmlspecialchars($titles[$gkey]) . "</legend>";
    echo "<div class='mf-grid'>";
    foreach ($items as [$fname, $fdef]) {
        $flabel = $fdef['label'] ?? $fname;
        $fkind = $fdef['kind'] ?? 'text';
        $fnameAttr = 'f_' . $fname;
        $cur = $clean[$fname] ?? '';
        echo "<label class='mf-field'><span>" . htmlspecialchars($flabel) . "</span>";
        if ($fkind === 'bool') {
            $curS = (string)$cur;
            echo "<select name='" . htmlspecialchars($fnameAttr) . "'>";
            echo "<option value=''" . ($curS === '' ? ' selected' : '') . ">" . htmlspecialchars(__('Todos', 'mafasa')) . "</option>";
            echo "<option value='1'" . ($curS === '1' ? ' selected' : '') . ">" . htmlspecialchars(__('Sim', 'mafasa')) . "</option>";
            echo "<option value='0'" . ($curS === '0' || $curS === 0 ? ' selected' : '') . ">" . htmlspecialchars(__('Não', 'mafasa')) . "</option>";
            echo "</select>";
        } elseif ($fkind === 'date') {
            echo "<input type='date' name='" . htmlspecialchars($fnameAttr) . "' value='" . htmlspecialchars((string)$cur) . "'>";
        } elseif ($fkind === 'int') {
            echo "<input type='number' min='1' step='1' name='" . htmlspecialchars($fnameAttr) . "' value='" . htmlspecialchars((string)$cur) . "' placeholder='ID'>";
        } else {
            echo "<input type='text' maxlength='255' name='" . htmlspecialchars($fnameAttr) . "' value='" . htmlspecialchars((string)$cur) . "'>";
        }
        echo "</label>";
    }
    echo "</div></fieldset>";
}
echo "</div>";

// ---- Painel de colunas (dentro do mesmo form para GET compartilhável) ----
echo "<fieldset class='mf-cols'><legend>" . htmlspecialchars(__('Colunas', 'mafasa')) . "</legend>";
echo "<div class='mf-cols-actions'>";
echo "<button type='button' id='mf-cols-all' class='mf-btn mf-btn-sm'>" . htmlspecialchars(__('Marcar tudo', 'mafasa')) . "</button>";
echo "<button type='button' id='mf-cols-none' class='mf-btn mf-btn-sm'>" . htmlspecialchars(__('Limpar', 'mafasa')) . "</button>";
echo "<button type='button' id='mf-cols-default' class='mf-btn mf-btn-sm' data-default='" . htmlspecialchars(implode(',', $defaultCols)) . "'>" . htmlspecialchars(__('Padrão', 'mafasa')) . "</button>";
echo "</div>";
echo "<div class='mf-cols-grid'>";
foreach ($columns as $cfield => $clabel) {
    $checked = in_array($cfield, $selectedCols, true) ? ' checked' : '';
    echo "<label class='mf-col'><input type='checkbox' name='cols[]' value='" . htmlspecialchars($cfield) . "' data-col='" . htmlspecialchars($cfield) . "'" . $checked . "> " . htmlspecialchars($clabel) . "</label>";
}
echo "</div></fieldset>";

echo "<div class='mf-actions'>";
echo "<button type='submit' name='gerar' value='1' class='mf-btn mf-btn-primary'>" . htmlspecialchars(__('Gerar', 'mafasa')) . "</button>";
echo "<a class='mf-btn' href='report.php?" . http_build_query(['type' => $type]) . "'>" . htmlspecialchars(__('Limpar filtros', 'mafasa')) . "</a>";
echo "<button type='button' id='mf-copy-link' class='mf-btn'>" . htmlspecialchars(__('Copiar link', 'mafasa')) . "</button>";
if ($doSearch) {
    echo "<a id='mf-print-link' class='mf-btn' target='_blank' href='print.php?" . htmlspecialchars(mf_report_qs()) . "'>" . htmlspecialchars(__('Imprimir', 'mafasa')) . "</a>";
}
echo "</div>";
echo "</form>";

// ---- Resultados ----
if ($doSearch) {
    $maxPage = max(1, (int)ceil($total / $limit));
    echo "<div class='mf-result-bar'>";
    echo "<span class='mf-badge'>" . sprintf(htmlspecialchars(__('%d resultado(s)', 'mafasa')), (int)$total) . "</span>";
    echo "<span class='mf-page-info'>" . sprintf(htmlspecialchars(__('Página %d de %d', 'mafasa')), (int)$page, (int)$maxPage) . "</span>";
    echo "</div>";

    if (empty($rows)) {
        echo "<div class='mf-empty'>" . htmlspecialchars(__('Nenhum item encontrado para os filtros.', 'mafasa')) . "</div>";
    } else {
        echo "<div class='mf-table-wrap'><table class='mf-table' id='mf-table'><thead><tr>";
        foreach ($selectedCols as $cfield) {
            $nextOrder = ($sort === $cfield && $order === 'ASC') ? 'DESC' : 'ASC';
            $href = 'report.php?' . mf_report_qs(['sort' => $cfield, 'order' => $nextOrder, 'page' => 1]);
            $arrow = ($sort === $cfield) ? ($order === 'ASC' ? ' ▲' : ' ▼') : '';
            echo "<th data-col='" . htmlspecialchars($cfield) . "'><a href='" . htmlspecialchars($href) . "'>" . htmlspecialchars($columns[$cfield]) . htmlspecialchars($arrow) . "</a></th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($rows as $row) {
            echo "<tr>";
            foreach ($selectedCols as $cfield) {
                $col = Report::getFieldColumn($type, $cfield);
                $val = $row[$col] ?? $row[$cfield] ?? null;
                echo "<td data-col='" . htmlspecialchars($cfield) . "'>" . mf_report_cell($type, $cfield, $filters, $val) . "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table></div>";

        // Paginação
        echo "<div class='mf-pagination'>";
        if ($page > 1) {
            echo "<a class='mf-btn mf-btn-sm' href='report.php?" . htmlspecialchars(mf_report_qs(['page' => $page - 1])) . "'>&laquo; " . htmlspecialchars(__('Anterior', 'mafasa')) . "</a>";
        }
        if ($page < $maxPage) {
            echo "<a class='mf-btn mf-btn-sm' href='report.php?" . htmlspecialchars(mf_report_qs(['page' => $page + 1])) . "'>" . htmlspecialchars(__('Próxima', 'mafasa')) . " &raquo;</a>";
        }
        echo "</div>";
    }
}

echo "<script src='" . htmlspecialchars($jsUrl) . "'></script>";
echo "</div>";

Html::footer();
