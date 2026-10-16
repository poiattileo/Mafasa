<?php
/**
 * Impressão do relatório de ativos (Task 4).
 *
 * Endpoint dedicado full-data: recebe os mesmos GET da tela
 * (type, f_*, cols[]), re-checa login/canView/entidade, busca todos
 * com teto PRINT_LIMIT (5000) + aviso se estourar.
 *
 * HTML próprio mínimo (sem Html::header/footer do GLPI).
 * Logo com fallback em texto "URE Jales" se o arquivo sumir.
 */

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/compat.php';

use GlpiPlugin\Mafasa\Report;

Session::checkLoginUser();

global $DB, $CFG_GLPI;

// ---- Tipo / categoria (mesmo padrão de front/report.php) ----
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

// ---- Colunas: só as marcadas em cols[]; padrão = todas ----
if (isset($_GET['cols']) && is_array($_GET['cols'])) {
    $selectedCols = array_values(array_intersect((array)$_GET['cols'], array_keys($columns)));
    if (empty($selectedCols)) {
        $selectedCols = array_keys($columns);
    }
} else {
    $selectedCols = array_keys($columns);
}

// ---- Filtros saneados + WHERE ----
$built = Report::buildWhere($type, $_GET);
$whereSql = $built['sql'];
$clean = $built['params'];

// ---- Entidade ativa (espelha front/report.php) ----
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

// ---- Busca full-data com teto ----
$printLimit = Report::PRINT_LIMIT;
$total = 0;
$rows = [];
$truncated = false;
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
$truncated = ($total > $printLimit);
try {
    $it = $DB->request([
        'SELECT' => ['*'],
        'FROM'   => $table,
        'WHERE'  => [new \Glpi\DBAL\QueryExpression($whereSql . $entitySql)],
        'ORDER'  => ['id ASC'],
        'LIMIT'  => $printLimit,
    ]);
    foreach ($it as $r) {
        $rows[] = $r;
    }
} catch (Throwable $e) {
    $rows = [];
}

/**
 * Valor legível de célula (espelha mf_report_cell de report.php).
 */
function mf_print_cell(string $type, string $field, array $filters, $value): string
{
    if ($value === null || $value === '') {
        return '<span class="mf-print-empty">—</span>';
    }
    $def = $filters[$field] ?? null;
    $kind = is_array($def) ? ($def['kind'] ?? 'text') : 'text';
    if ($kind === 'bool') {
        return ((int)$value === 1) ? 'Sim' : 'Não';
    }
    if (in_array($field, ['date_creation', 'date_mod'], true)) {
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

// ---- Metadados do cabeçalho ----
$webdir = mafasa_web_dir('mafasa');
$cssUrl = $webdir . '/public/css/mafasa-print.css';
$logoFile = __DIR__ . '/../public/img/logo-ure-jales.png';
$logoUrl = $webdir . '/public/img/logo-ure-jales.png';
$hasLogo = is_file($logoFile);

$now = date('d/m/Y H:i');

$userName = '';
try {
    if (isset($_SESSION['glpiname']) && trim((string)$_SESSION['glpiname']) !== '') {
        $userName = trim((string)$_SESSION['glpiname']);
    } elseif (method_exists(Session::class, 'getLoginUserID')) {
        $uid = (int)Session::getLoginUserID();
        if ($uid > 0 && class_exists('User')) {
            $u = new User();
            if ($u->getFromDB($uid)) {
                $userName = trim((string)($u->fields['name'] ?? ''));
                $real = trim((string)($u->fields['realname'] ?? ''));
                $first = trim((string)($u->fields['firstname'] ?? ''));
                if ($real !== '' || $first !== '') {
                    $userName = trim($first . ' ' . $real);
                }
            }
        }
        if ($userName === '') {
            $userName = 'ID ' . $uid;
        }
    }
} catch (Throwable $e) {
    $userName = '';
}
if ($userName === '') {
    $userName = '—';
}

$entityName = '';
try {
    if (isset($_SESSION['glpiactive_entity_name']) && trim((string)$_SESSION['glpiactive_entity_name']) !== '') {
        $entityName = trim((string)$_SESSION['glpiactive_entity_name']);
    } elseif (!empty($activeEntities) && class_exists('Dropdown') && method_exists('Dropdown', 'getDropdownName')) {
        $names = [];
        foreach ($activeEntities as $eid) {
            try {
                $nm = Dropdown::getDropdownName('glpi_entities', (int)$eid);
                if (is_string($nm) && $nm !== '') {
                    $names[] = $nm;
                }
            } catch (Throwable $e) {
                // ignora esta entidade
            }
        }
        if (!empty($names)) {
            $entityName = implode(', ', $names);
        }
    }
} catch (Throwable $e) {
    $entityName = '';
}
if ($entityName === '') {
    $entityName = $hasAllEntities ? 'Todas' : '—';
}

// Resumo dos filtros aplicados (label: valor).
$filterSummary = [];
foreach ($clean as $fname => $fval) {
    $flabel = $filters[$fname]['label'] ?? $fname;
    $filterSummary[] = $flabel . ': ' . (string)$fval;
}

$backQs = http_build_query($_GET);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars(sprintf('Relatório de Ativos — %s', $label)); ?></title>
<link rel="stylesheet" href="<?php echo htmlspecialchars($cssUrl); ?>">
</head>
<body>
<div class="mf-print-actions no-print">
    <button type="button" class="mf-print-btn" onclick="window.print()">Imprimir / Salvar PDF</button>
    <a class="mf-print-btn mf-print-btn-secondary" href="report.php?<?php echo htmlspecialchars($backQs); ?>">Voltar ao relatório</a>
</div>

<header class="mf-print-header">
    <div class="mf-print-brand">
        <?php if ($hasLogo) { ?>
            <img class="mf-print-logo" src="<?php echo htmlspecialchars($logoUrl); ?>" alt="URE Jales">
        <?php } else { ?>
            <div class="mf-print-logo-fallback">URE Jales</div>
        <?php } ?>
    </div>
    <div class="mf-print-title">
        <h1>Relatório de Ativos — <?php echo htmlspecialchars($label); ?></h1>
        <div class="mf-print-meta">
            <span>Gerado em <?php echo htmlspecialchars($now); ?></span>
            <span>Usuário: <?php echo htmlspecialchars($userName); ?></span>
            <span>Entidade: <?php echo htmlspecialchars($entityName); ?></span>
        </div>
        <div class="mf-print-meta">
            <span>Total: <?php echo (int)$total; ?> item(ns)</span>
            <?php if (!empty($filterSummary)) { ?>
                <span>Filtros: <?php echo htmlspecialchars(implode(' | ', $filterSummary)); ?></span>
            <?php } else { ?>
                <span>Filtros: nenhum (todos os itens)</span>
            <?php } ?>
        </div>
    </div>
</header>

<?php if ($typeInvalid) { ?>
    <div class="mf-print-warning" role="alert">
        Categoria "<?php echo htmlspecialchars($rawType); ?>" inválida — mostrando <?php echo htmlspecialchars($label); ?>.
    </div>
<?php } ?>

<?php if ($truncated) { ?>
    <div class="mf-print-warning" role="alert">
        Resultado com <?php echo (int)$total; ?> itens — exibindo os primeiros <?php echo (int)$printLimit; ?>.
        Refine os filtros para ver o restante.
    </div>
<?php } ?>

<main>
    <?php if (empty($rows)) { ?>
        <p class="mf-print-empty">Nenhum item encontrado para os filtros.</p>
    <?php } else { ?>
        <table class="mf-print-table">
            <thead>
                <tr>
                    <?php foreach ($selectedCols as $cfield) { ?>
                        <th><?php echo htmlspecialchars($columns[$cfield]); ?></th>
                    <?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) { ?>
                    <tr>
                        <?php foreach ($selectedCols as $cfield) {
                            $col = Report::getFieldColumn($type, $cfield);
                            $val = $row[$col] ?? $row[$cfield] ?? null;
                        ?>
                            <td><?php echo mf_print_cell($type, $cfield, $filters, $val); ?></td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</main>

<footer class="mf-print-footer">
    <span>Mafasa — Relatórios de Ativos (URE Jales)</span>
    <span>Total: <?php echo (int)$total; ?> item(ns)<?php echo $truncated ? ' (primeiros ' . (int)$printLimit . ' exibidos)' : ''; ?></span>
    <span class="mf-print-page-num"></span>
</footer>
</body>
</html>
