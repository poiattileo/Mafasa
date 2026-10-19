<?php
/**
 * Impressão do relatório dos quadros Kanpro.
 *
 * Recebe os mesmos GET da tela boards.php. view=lista (padrão) imprime a
 * tabela de cartões até BoardsReport::PRINT_LIMIT; view=resumo imprime a
 * tabela agregada do modo escolhido (mode=).
 */

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/compat.php';
require_once __DIR__ . '/../src/Config.php';

use GlpiPlugin\Mafasa\BoardsReport;
use GlpiPlugin\Mafasa\Config as MafasaConfig;

Session::checkLoginUser();

if (!BoardsReport::canView()) {
    mafasa_display_right_error(__('Você não tem permissão para visualizar quadros Kanpro.', 'mafasa'));
    return;
}

$available = BoardsReport::available();
$view = (($_GET['view'] ?? 'lista') === 'resumo') ? 'resumo' : 'lista';
$filters = BoardsReport::getFilters();
$columns = BoardsReport::getColumns();
$defaultCols = BoardsReport::getDefaultColumns();
$modes = BoardsReport::getSummaryModes();

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

// ---- Busca (mesmos GET da tela; guard retorna erro quando Kanpro ausente) ----
if ($view === 'resumo') {
    $mode = (string)($_GET['mode'] ?? 'board');
    if (!isset($modes[$mode])) {
        $mode = 'board';
    }
    $result = BoardsReport::buildSummary($mode, $_GET);
} else {
    $mode = null;
    $sort = (string)($_GET['sort'] ?? 'id');
    $order = (strtoupper((string)($_GET['order'] ?? 'ASC')) === 'DESC') ? 'DESC' : 'ASC';
    $result = BoardsReport::buildList($_GET, 1, BoardsReport::PRINT_LIMIT, $sort, $order);
}

$total     = ($view === 'resumo')
    ? (int)($result['totals']['grand'] ?? 0)
    : (int)($result['total'] ?? 0);
$rows      = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : [];
$totals    = (isset($result['totals']) && is_array($result['totals'])) ? $result['totals'] : [];
$error     = $result['error'] ?? null;
$truncated = ($view !== 'resumo' && $total > BoardsReport::PRINT_LIMIT);

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
    $filterSummary[] = $label . ': ' . BoardsReport::describeFilterValue($key, $raw);
}

/** Escapa texto para HTML. */
function mf_pbrd_esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Formata o valor da célula `$row['mf_'.$key']` conforme getColumnKind(). */
function mf_pbrd_cell(string $key, $value): string
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

$webdir = mafasa_web_dir('mafasa');
$cssUrl = $webdir . '/public/css/mafasa-print.css';
$cssPath = __DIR__ . '/../public/css/mafasa-print.css';
if (is_file($cssPath)) {
    $cssUrl .= '?v=' . filemtime($cssPath);
}
$backQs = http_build_query($_GET);
$pageTitle = ($view === 'resumo')
    ? __('[URE] Mafasa — Quadros (Resumo)', 'mafasa')
    : __('[URE] Mafasa — Quadros', 'mafasa');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo mf_pbrd_esc($pageTitle); ?></title>
<link rel="stylesheet" href="<?php echo mf_pbrd_esc($cssUrl); ?>">
</head>
<body>
<div class="mf-print-actions no-print">
    <button type="button" class="mf-print-btn" onclick="window.print()"><?php echo mf_pbrd_esc(__('Imprimir / Salvar PDF', 'mafasa')); ?></button>
    <a class="mf-print-btn mf-print-btn-secondary" href="boards.php?<?php echo mf_pbrd_esc($backQs); ?>"><?php echo mf_pbrd_esc(__('Voltar ao relatório', 'mafasa')); ?></a>
</div>

<header class="mf-print-header">
    <div class="mf-print-brand">
        <?php if ($logoUrl !== '') { ?>
            <img class="mf-print-logo" src="<?php echo mf_pbrd_esc($logoUrl); ?>" alt="<?php echo mf_pbrd_esc($headerTitle); ?>">
        <?php } else { ?>
            <div class="mf-print-logo-fallback"><?php echo mf_pbrd_esc($headerTitle); ?></div>
        <?php } ?>
    </div>
    <div class="mf-print-title">
        <h1><?php echo mf_pbrd_esc($pageTitle); ?></h1>
        <div class="mf-print-meta">
            <span><?php echo mf_pbrd_esc(sprintf(__('Gerado em %s', 'mafasa'), $generatedAt)); ?></span>
        </div>
        <div class="mf-print-meta">
            <span><?php echo mf_pbrd_esc(sprintf(__('%d registro(s)', 'mafasa'), $total)); ?></span>
            <?php if ($view === 'resumo' && $mode !== null) { ?>
                <span><?php echo mf_pbrd_esc(__('Agrupamento', 'mafasa') . ': ' . ($modes[$mode] ?? $mode)); ?></span>
            <?php } ?>
            <?php if (!empty($filterSummary)) { ?>
                <span><?php echo mf_pbrd_esc(__('Filtros', 'mafasa') . ': ' . implode(' | ', $filterSummary)); ?></span>
            <?php } else { ?>
                <span><?php echo mf_pbrd_esc(__('Filtros: nenhum (todos os registros)', 'mafasa')); ?></span>
            <?php } ?>
        </div>
    </div>
</header>

<?php if (!empty($error)) { ?>
    <div class="mf-print-warning" role="alert"><?php echo mf_pbrd_esc((string)$error); ?></div>
<?php } ?>

<?php if ($truncated && empty($error)) { ?>
    <div class="mf-print-warning" role="alert">
        <?php echo mf_pbrd_esc(sprintf(__('Exibindo os primeiros %d de %d registros — refine os filtros.', 'mafasa'), (int)BoardsReport::PRINT_LIMIT, $total)); ?>
    </div>
<?php } ?>

<main>
    <?php if ($error) { ?>
        <p class="mf-print-empty"><?php echo mf_pbrd_esc(__('Não foi possível gerar o relatório.', 'mafasa')); ?></p>
    <?php } elseif (empty($rows)) { ?>
        <p class="mf-print-empty"><?php echo mf_pbrd_esc(__('Nenhum registro encontrado.', 'mafasa')); ?></p>
    <?php } elseif ($view === 'resumo') { ?>
        <p class="mf-print-empty">
            <strong><?php echo mf_pbrd_esc(__('Total de cartões', 'mafasa')); ?>:</strong> <?php echo mf_pbrd_esc((string)$total); ?>
            <?php if (isset($totals['done'])) {
                $pctDone = ($total > 0) ? round(((int)$totals['done'] * 100) / $total, 1) : 0; ?>
                &nbsp;|&nbsp; <strong><?php echo mf_pbrd_esc(__('Concluídos', 'mafasa')); ?>:</strong>
                <?php echo mf_pbrd_esc((string)(int)$totals['done']); ?> (<?php echo mf_pbrd_esc((string)$pctDone); ?>%)
            <?php } ?>
        </p>
        <?php $isChecklist = ($mode === 'checklist'); ?>
        <table class="mf-print-table">
            <thead>
                <tr>
                    <?php if ($isChecklist) { ?>
                        <th><?php echo mf_pbrd_esc(__('Item', 'mafasa')); ?></th>
                        <th><?php echo mf_pbrd_esc(__('Total de itens', 'mafasa')); ?></th>
                        <th><?php echo mf_pbrd_esc(__('Concluídos', 'mafasa')); ?></th>
                        <th><?php echo mf_pbrd_esc(__('Percentual', 'mafasa')); ?></th>
                    <?php } else { ?>
                        <th><?php echo mf_pbrd_esc(__('Grupo', 'mafasa')); ?></th>
                        <th><?php echo mf_pbrd_esc(__('Cartões', 'mafasa')); ?></th>
                        <th><?php echo mf_pbrd_esc(__('Percentual', 'mafasa')); ?></th>
                    <?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) {
                    $count = (int)($row['mf_total'] ?? 0); ?>
                    <tr>
                        <td><?php echo mf_pbrd_esc((string)($row['mf_label'] ?? '—')); ?></td>
                        <td><?php echo mf_pbrd_esc((string)$count); ?></td>
                        <?php if ($isChecklist) {
                            $checked = (int)($row['mf_checked'] ?? 0);
                            $pct = ($count > 0) ? round(($checked * 100) / $count, 1) : 0; ?>
                            <td><?php echo mf_pbrd_esc((string)$checked); ?></td>
                            <td><?php echo mf_pbrd_esc((string)$pct); ?>%</td>
                        <?php } else {
                            $pct = ($total > 0) ? round(($count * 100) / $total, 1) : 0; ?>
                            <td><?php echo mf_pbrd_esc((string)$pct); ?>%</td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } else { ?>
        <table class="mf-print-table">
            <thead>
                <tr>
                    <?php foreach ($orderedCols as $colKey) { ?>
                        <th><?php echo mf_pbrd_esc($columns[$colKey]); ?></th>
                    <?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) { ?>
                    <tr>
                        <?php foreach ($orderedCols as $colKey) { ?>
                            <td><?php echo mf_pbrd_cell($colKey, is_array($row) ? ($row['mf_' . $colKey] ?? null) : null); ?></td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</main>

<footer class="mf-print-footer">
    <span><?php echo mf_pbrd_esc($footerText); ?></span>
    <span><?php echo mf_pbrd_esc(sprintf(__('%d registro(s)', 'mafasa'), $total)); ?><?php echo $truncated ? mf_pbrd_esc(sprintf(__(' (primeiros %d exibidos)', 'mafasa'), (int)BoardsReport::PRINT_LIMIT)) : ''; ?></span>
</footer>
</body>
</html>
