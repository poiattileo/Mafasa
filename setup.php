<?php
/**
 * Plugin Mafasa - Relatórios de Ativos (URE Jales)
 * Author: Leonardo Poiatti Fação
 * License: GPLv2+
 */

require_once __DIR__ . '/src/compat.php';

define('PLUGIN_MAFASA_VERSION', '1.0.0');
define('PLUGIN_MAFASA_MIN_GLPI', '11.0.0');
define('PLUGIN_MAFASA_MAX_GLPI', '12.0.99');
define('PLUGIN_MAFASA_NAMESPACE', 'GlpiPlugin\\Mafasa');

/**
 * @return array
 */
function plugin_version_mafasa(): array
{
    return [
        'name'           => 'Mafasa - Relatórios de Ativos',
        'version'        => PLUGIN_MAFASA_VERSION,
        'author'         => 'Leonardo Poiatti Fação',
        'license'        => 'GPLv2+',
        'homepage'       => 'https://github.com/poiattileo/Mafasa',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_MAFASA_MIN_GLPI,
                'max' => PLUGIN_MAFASA_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.2',
            ]
        ]
    ];
}

function plugin_mafasa_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_MAFASA_MIN_GLPI, '<')) {
        echo "Este plugin requer GLPI >= " . PLUGIN_MAFASA_MIN_GLPI;
        return false;
    }
    return true;
}

function plugin_mafasa_check_config(bool $verbose = false): bool
{
    return true;
}

/**
 * Inicialização do plugin (PSR-4)
 */
function plugin_init_mafasa(): void
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    $plugin = new Plugin();
    if (!$plugin->isActivated('mafasa')) {
        return;
    }

    // Autoload via composer se existir
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    // CSRF compliance - no GLPI 12 o hook foi removido (CheckCsrfListener cuida);
    // mantém true apenas no GLPI <12 para proteger POST.
    if (!mafasa_is_glpi12_plus()) {
        $PLUGIN_HOOKS['csrf_compliant']['mafasa'] = true;
    }

    // Menu - apenas em Ferramentas (evita duplicar em Plug-ins)
    $PLUGIN_HOOKS['menu_toadd']['mafasa'] = [
        'tools' => 'GlpiPlugin\\Mafasa\\Report',
    ];
    $PLUGIN_HOOKS['menu_entry']['mafasa'] = 'front/report.php';

    // Usa mafasa_web_dir para suportar plugins/ ou marketplace/ sem duplicar root_doc.
    // No GLPI 12 Plugin::getWebDir foi removido — helper faz fallback via root_doc.
    $mafasa_webdir = mafasa_web_dir('mafasa');
    // Submenu espera caminho sem root_doc (GLPI prepend root_doc internamente), então remove se já contém
    $mafasa_webdir_noroot = $mafasa_webdir;
    if (isset($CFG_GLPI['root_doc']) && $CFG_GLPI['root_doc'] !== '' && str_starts_with($mafasa_webdir, $CFG_GLPI['root_doc'])) {
        $mafasa_webdir_noroot = substr($mafasa_webdir, strlen($CFG_GLPI['root_doc']));
        if ($mafasa_webdir_noroot === '' || $mafasa_webdir_noroot[0] !== '/') {
            $mafasa_webdir_noroot = '/' . ltrim($mafasa_webdir_noroot, '/');
        }
    }
    $PLUGIN_HOOKS['submenu_entry']['mafasa']['report'] = [
        'title' => 'Relatórios de Ativos',
        'page'  => $mafasa_webdir_noroot . '/front/report.php',
        'links' => [
            'search' => $mafasa_webdir_noroot . '/front/report.php',
        ]
    ];
    // Submenu por categoria (Task 2): uma entrada por tipo de ativo.
    $mafasa_report_class = 'GlpiPlugin\\Mafasa\\Report';
    $mafasa_report_file = __DIR__ . '/src/Report.php';
    if (!class_exists($mafasa_report_class) && is_file($mafasa_report_file)) {
        require_once $mafasa_report_file;
    }
    if (class_exists($mafasa_report_class)) {
        foreach ($mafasa_report_class::getCategories() as $mafasa_itemtype => $mafasa_label) {
            $mafasa_slug = 'report_' . strtolower((string) $mafasa_itemtype);
            $mafasa_page = $mafasa_webdir_noroot . '/front/report.php?type=' . rawurlencode((string) $mafasa_itemtype);
            $PLUGIN_HOOKS['submenu_entry']['mafasa'][$mafasa_slug] = [
                'title' => $mafasa_label,
                'page'  => $mafasa_page,
                'links' => [
                    'search' => $mafasa_page,
                ]
            ];
        }
    }
    $PLUGIN_HOOKS['config_page']['mafasa'] = 'front/config.php';

    // CSS - relativo deixa GLPI resolver marketplace/plugins corretamente.
    // NOTA: sem ?v= manual — o GLPI já anexa ?v=<versão do plugin> sozinho.
    $PLUGIN_HOOKS['add_css']['mafasa'] = [
        'public/css/mafasa.css'
    ];
}
