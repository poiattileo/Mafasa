<?php
/**
 * Impressão do relatório de chamados.
 *
 * Recebe os mesmos GET da tela tickets.php. view=lista (padrão) imprime a
 * tabela detalhada até TicketsReport::PRINT_LIMIT; view=resumo imprime a
 * tabela agregada do modo escolhido (mode=).
 */

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/compat.php';
require_once __DIR__ . '/../src/Config.php';

use GlpiPlugin\Mafasa\TicketsReport;
use GlpiPlugin\Mafasa\Config as MafasaConfig;

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

if ($view === 'resumo') {
    $mode = (string)($_GET['mode'] ?? 'status');
    if (!isset($modes[$mode])) {
        $mode = 'status';
    }
    $result = TicketsReport::buildSummary($mode, $_GET);
} else {
    $mode = null;
    $result = TicketsReport::buildList($_GET, 1, TicketsReport::PRINT_LIMIT, (string)($_GET['sort'] ?? 'id'), (string)($_GET['order'] ?? 'ASC'));
}

$total     = ($view === 'resumo')
    ? (int)($result['totals']['grand'] ?? 0)
    : (int)($result['total'] ?? 0);
$rows      = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : [];
$totals    = (isset($result['totals']) && is_array($result['totals'])) ? $result['totals'] : [];
$error     = $result['error'] ?? null;
$truncated = ($view !== 'resumo' && $total > TicketsReport::PRINT_LIMIT);

// ---- Branding / metadados ----
$logoUrl     = MafasaConfig::getLogoUrl();
$headerTitle = MafasaConfig::getHeaderTitle();
$footerText  = MafasaConfig::getFooterText();
$generatedAt = Html::convDateTime(date('Y-m-d H:i:s'));

// ---- Resumo de filtros ativos ----
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
    $filterSummary[] = $label . ': ' . TicketsReport::describeFilterValue($key, $raw);
}

/** Escapa texto para HTML. */
function mf_ptix_esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Formata o valor da célula `$row['mf_'.$key']` conforme getColumnKind(). */
function mf_ptix_cell(string $key, $value): string
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

$webdir = mafasa_web_dir('mafasa');
$cssUrl = $webdir . '/public/css/mafasa-print.css';
$cssPath = __DIR__ . '/../public/css/mafasa-print.css';
if (is_file($cssPath)) {
    $cssUrl .= '?v=' . filemtime($cssPath);
}
$backQs = http_build_query($_GET);
$pageTitle = ($view === 'resumo')
    ? __('[URE] Mafasa — Chamados (Resumo)', 'mafasa')
    : __('[URE] Mafasa — Chamados', 'mafasa');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo mf_ptix_esc($pageTitle); ?></title>
<link rel="stylesheet" href="<?php echo mf_ptix_esc($cssUrl); ?>">
</head>
<body>
<div class="mf-print-actions no-print">
    <button type="button" class="mf-print-btn" onclick="window.print()"><?php echo mf_ptix_esc(__('Imprimir / Salvar PDF', 'mafasa')); ?></button>
    <a class="mf-print-btn mf-print-btn-secondary" href="tickets.php?<?php echo mf_ptix_esc($backQs); ?>"><?php echo mf_ptix_esc(__('Voltar ao relatório', 'mafasa')); ?></a>
</div>

<header class="mf-print-header">
    <div class="mf-print-brand">
        <?php if ($logoUrl !== '') { ?>
            <img class="mf-print-logo" src="<?php echo mf_ptix_esc($logoUrl); ?>" alt="<?php echo mf_ptix_esc($headerTitle); ?>">
        <?php } else { ?>
            <div class="mf-print-logo-fallback"><?php echo mf_ptix_esc($headerTitle); ?></div>
        <?php } ?>
    </div>
    <div class="mf-print-title">
        <h1><?php echo mf_ptix_esc($pageTitle); ?></h1>
        <div class="mf-print-meta">
            <span><?php echo mf_ptix_esc(sprintf(__('Gerado em %s', 'mafasa'), $generatedAt)); ?></span>
        </div>
        <div class="mf-print-meta">
            <span><?php echo mf_ptix_esc(sprintf(__('%d registro(s)', 'mafasa'), $total)); ?></span>
            <?php if ($view === 'resumo' && $mode !== null) { ?>
                <span><?php echo mf_ptix_esc(__('Agrupamento', 'mafasa') . ': ' . ($modes[$mode] ?? $mode)); ?></span>
            <?php } ?>
            <?php if (!empty($filterSummary)) { ?>
                <span><?php echo mf_ptix_esc(__('Filtros', 'mafasa') . ': ' . implode(' | ', $filterSummary)); ?></span>
            <?php } else { ?>
                <span><?php echo mf_ptix_esc(__('Filtros: nenhum (todos os registros)', 'mafasa')); ?></span>
            <?php } ?>
        </div>
    </div>
</header>

<?php if (!empty($error)) { ?>
    <div class="mf-print-warning" role="alert"><?php echo mf_ptix_esc((string)$error); ?></div>
<?php } ?>

<?php if ($truncated && empty($error)) { ?>
    <div class="mf-print-warning" role="alert">
        <?php echo mf_ptix_esc(sprintf(__('Exibindo os primeiros %d de %d registros — refine os filtros.', 'mafasa'), (int)TicketsReport::PRINT_LIMIT, $total)); ?>
    </div>
<?php } ?>

<main>
    <?php if ($error) { ?>
        <p class="mf-print-empty"><?php echo mf_ptix_esc(__('Não foi possível gerar o relatório.', 'mafasa')); ?></p>
    <?php } elseif (empty($rows)) { ?>
        <p class="mf-print-empty"><?php echo mf_ptix_esc(__('Nenhum registro encontrado.', 'mafasa')); ?></p>
    <?php } elseif ($view === 'resumo') { ?>
        <?php if (isset($totals['avg_first']) || isset($totals['avg_resolution'])) { ?>
            <p class="mf-print-empty">
                <strong><?php echo mf_ptix_esc(__('Total de chamados', 'mafasa')); ?>:</strong> <?php echo mf_ptix_esc((string)$total); ?>
                <?php if (isset($totals['avg_first']) && $totals['avg_first'] !== null) { ?>
                    &nbsp;|&nbsp; <strong><?php echo mf_ptix_esc(__('Tempo médio de 1ª resposta', 'mafasa')); ?>:</strong>
                    <?php echo mf_ptix_esc(TicketsReport::formatDuration($totals['avg_first'])); ?>
                <?php } ?>
                <?php if (isset($totals['avg_resolution']) && $totals['avg_resolution'] !== null) { ?>
                    &nbsp;|&nbsp; <strong><?php echo mf_ptix_esc(__('Tempo médio de resolução', 'mafasa')); ?>:</strong>
                    <?php echo mf_ptix_esc(TicketsReport::formatDuration($totals['avg_resolution'])); ?>
                <?php } ?>
            </p>
        <?php } ?>
        <table class="mf-print-table">
            <thead>
                <tr>
                    <th><?php echo mf_ptix_esc(__('Grupo', 'mafasa')); ?></th>
                    <th><?php echo mf_ptix_esc(__('Chamados', 'mafasa')); ?></th>
                    <th><?php echo mf_ptix_esc(__('Percentual', 'mafasa')); ?></th>
                    <th><?php echo mf_ptix_esc(__('1ª resposta (média)', 'mafasa')); ?></th>
                    <th><?php echo mf_ptix_esc(__('Resolução (média)', 'mafasa')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) {
                    $count = (int)($row['mf_total'] ?? 0);
                    $pct = ($total > 0) ? round(($count * 100) / $total, 1) : 0; ?>
                    <tr>
                        <td><?php echo mf_ptix_esc((string)($row['mf_label'] ?? '—')); ?></td>
                        <td><?php echo mf_ptix_esc((string)$count); ?></td>
                        <td><?php echo mf_ptix_esc((string)$pct); ?>%</td>
                        <td><?php echo mf_ptix_esc(TicketsReport::formatDuration($row['mf_avg_first'] ?? null)); ?></td>
                        <td><?php echo mf_ptix_esc(TicketsReport::formatDuration($row['mf_avg_resolution'] ?? null)); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } else { ?>
        <table class="mf-print-table">
            <thead>
                <tr>
                    <?php foreach ($orderedCols as $colKey) { ?>
                        <th><?php echo mf_ptix_esc($columns[$colKey]); ?></th>
                    <?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) { ?>
                    <tr>
                        <?php foreach ($orderedCols as $colKey) { ?>
                            <td><?php echo mf_ptix_cell($colKey, is_array($row) ? ($row['mf_' . $colKey] ?? null) : null); ?></td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</main>

<footer class="mf-print-footer">
    <span><?php echo mf_ptix_esc($footerText); ?></span>
    <span><?php echo mf_ptix_esc(sprintf(__('%d registro(s)', 'mafasa'), $total)); ?><?php echo $truncated ? mf_ptix_esc(sprintf(__(' (primeiros %d exibidos)', 'mafasa'), (int)TicketsReport::PRINT_LIMIT)) : ''; ?></span>
</footer>
</body>
</html>
