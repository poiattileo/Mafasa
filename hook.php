<?php
/**
 * hook.php - install / uninstall do plugin Mafasa
 */

use GlpiPlugin\Mafasa\Install;

/**
 * Install hook
 * @return bool
 */
function plugin_mafasa_install(): bool
{
    include_once __DIR__ . '/src/Install.php';
    return Install::install();
}

/**
 * Uninstall hook
 * @return bool
 */
function plugin_mafasa_uninstall(): bool
{
    include_once __DIR__ . '/src/Install.php';
    return Install::uninstall();
}
