<?php
/**
 * Impressão do relatório de ativos (v2).
 *
 * Recebe os mesmos GET da tela (types[], filtros, cols[]) e monta uma página
 * standalone para impressão/PDF com cabeçalho institucional (Config). Busca
 * full-data com teto Report::PRINT_LIMIT + aviso de truncamento.
 */

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/compat.php';
require_once __DIR__ . '/../src/Config.php';

use GlpiPlugin\Mafasa\Report;
use GlpiPlugin\Mafasa\Config as MafasaConfig;

Session::checkLoginUser();

$tokens = Report::normalizeTypes($_GET);
if (empty($tokens) || !Report::canView()) {
    mafasa_display_right_error(__('Você não tem permissão para acessar relatórios de ativos.', 'mafasa'));
    return;
}

$filters = Report::getFilters($tokens);
$columns = Report::getColumns($tokens);
$defaultCols = Report::getDefaultColumns($tokens);

// ---- Colunas (mesma lógica da tela) ----
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

// ---- Busca full-data (page 1, PRINT_LIMIT) ----
$result = Report::buildSearch($tokens, $_GET, 1, Report::PRINT_LIMIT, 'id', 'ASC');

$total     = (int)($result['total'] ?? 0);
$rows      = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : [];
$error     = $result['error'] ?? null;
$truncated = ($total > Report::PRINT_LIMIT);

// ---- Branding / metadados ----
$logoUrl     = MafasaConfig::getLogoUrl();
$headerTitle = MafasaConfig::getHeaderTitle();
$footerText  = MafasaConfig::getFooterText();
$generatedAt = Html::convDateTime(date('Y-m-d H:i:s'));

// ---- Resumo de filtros ativos (ordem congelada §4) ----
$filterSummary = [];
foreach ($filters as $key => $def) {
    if (!array_key_exists($key, $_GET)) {
        continue;
    }
    $raw = $_GET[$key];
    if (is_array($raw)) {
        continue;
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        continue;
    }
    $label = (string)($def['label'] ?? $key);
    $filterSummary[] = $label . ': ' . Report::describeFilterValue($key, $raw);
}

/** Escapa texto para HTML. */
function mf_print_esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Formata o valor da célula `$row['mf_'.$key']` conforme getColumnKind(). */
function mf_print_cell(string $key, $value): string
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

$webdir = Plugin::getWebDir('mafasa');
$cssUrl = $webdir . '/public/css/mafasa-print.css';
$backQs = http_build_query($_GET);
$pageTitle = ($headerTitle !== '') ? $headerTitle : __('Relatório de Ativos', 'mafasa');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo mf_print_esc($pageTitle); ?></title>
<link rel="stylesheet" href="<?php echo mf_print_esc($cssUrl); ?>">
</head>
<body>
<div class="mf-print-actions no-print">
    <button type="button" class="mf-print-btn" onclick="window.print()"><?php echo mf_print_esc(__('Imprimir / Salvar PDF', 'mafasa')); ?></button>
    <a class="mf-print-btn mf-print-btn-secondary" href="report.php?<?php echo mf_print_esc($backQs); ?>"><?php echo mf_print_esc(__('Voltar ao relatório', 'mafasa')); ?></a>
</div>

<header class="mf-print-header">
    <div class="mf-print-brand">
        <?php if ($logoUrl !== '') { ?>
            <img class="mf-print-logo" src="<?php echo mf_print_esc($logoUrl); ?>" alt="<?php echo mf_print_esc($headerTitle); ?>">
        <?php } else { ?>
            <div class="mf-print-logo-fallback"><?php echo mf_print_esc($headerTitle); ?></div>
        <?php } ?>
    </div>
    <div class="mf-print-title">
        <h1><?php echo mf_print_esc($headerTitle); ?></h1>
        <div class="mf-print-meta">
            <span><?php echo mf_print_esc(sprintf(__('Gerado em %s', 'mafasa'), $generatedAt)); ?></span>
        </div>
        <div class="mf-print-meta">
            <span><?php echo mf_print_esc(sprintf(__('%d registro(s)', 'mafasa'), $total)); ?></span>
            <?php if (!empty($filterSummary)) { ?>
                <span><?php echo mf_print_esc(__('Filtros', 'mafasa') . ': ' . implode(' | ', $filterSummary)); ?></span>
            <?php } else { ?>
                <span><?php echo mf_print_esc(__('Filtros: nenhum (todos os registros)', 'mafasa')); ?></span>
            <?php } ?>
        </div>
    </div>
</header>

<?php if (!empty($error)) { ?>
    <div class="mf-print-warning" role="alert"><?php echo mf_print_esc((string)$error); ?></div>
<?php } ?>

<?php if ($truncated && empty($error)) { ?>
    <div class="mf-print-warning" role="alert">
        <?php echo mf_print_esc(sprintf(__('Exibindo os primeiros %d de %d registros — refine os filtros.', 'mafasa'), (int)Report::PRINT_LIMIT, $total)); ?>
    </div>
<?php } ?>

<main>
    <?php if ($error) { ?>
        <p class="mf-print-empty"><?php echo mf_print_esc(__('Não foi possível gerar o relatório.', 'mafasa')); ?></p>
    <?php } elseif (empty($rows)) { ?>
        <p class="mf-print-empty"><?php echo mf_print_esc(__('Nenhum registro encontrado.', 'mafasa')); ?></p>
    <?php } else { ?>
        <table class="mf-print-table">
            <thead>
                <tr>
                    <?php foreach ($orderedCols as $colKey) { ?>
                        <th><?php echo mf_print_esc($columns[$colKey]); ?></th>
                    <?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) { ?>
                    <tr>
                        <?php foreach ($orderedCols as $colKey) { ?>
                            <td><?php echo mf_print_cell($colKey, is_array($row) ? ($row['mf_' . $colKey] ?? null) : null); ?></td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</main>

<footer class="mf-print-footer">
    <span><?php echo mf_print_esc($footerText); ?></span>
    <span><?php echo mf_print_esc(sprintf(__('%d registro(s)', 'mafasa'), $total)); ?><?php echo $truncated ? mf_print_esc(sprintf(__(' (primeiros %d exibidos)', 'mafasa'), (int)Report::PRINT_LIMIT)) : ''; ?></span>
</footer>
</body>
</html>
