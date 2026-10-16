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

    // Registra a classe do menu (precisa herdar CommonGLPI p/ aparecer)
    Plugin::registerClass(\GlpiPlugin\Mafasa\Report::class);

    // CSRF compliance - no GLPI 12 o hook foi removido (CheckCsrfListener cuida);
    // mantém true apenas no GLPI <12 para proteger POST.
    if (!mafasa_is_glpi12_plus()) {
        $PLUGIN_HOOKS['csrf_compliant']['mafasa'] = true;
    }

    // Menu - apenas em Ferramentas (evita duplicar em Plug-ins).
    // O submenu por categoria vem de Report::getMenuContent()['options'] —
    // os hooks menu_entry/submenu_entry do GLPI 10 não existem mais no 11/12.
    $PLUGIN_HOOKS['menu_toadd']['mafasa'] = [
        'tools' => \GlpiPlugin\Mafasa\Report::class,
    ];
    $PLUGIN_HOOKS['config_page']['mafasa'] = 'front/config.php';

    // CSS - relativo deixa GLPI resolver marketplace/plugins corretamente.
    // NOTA: sem ?v= manual — o GLPI já anexa ?v=<versão do plugin> sozinho.
    $PLUGIN_HOOKS['add_css']['mafasa'] = [
        'public/css/mafasa.css'
    ];
}
