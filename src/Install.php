<?php
namespace GlpiPlugin\Mafasa;

use Config;

class Install
{
    public static function install(): bool
    {
        global $DB;

        try {
            if (!defined('GLPI_PLUGIN_DOC_DIR')) {
                define('GLPI_PLUGIN_DOC_DIR', GLPI_ROOT . '/files/_plugins');
            }

            // v1 sem tabelas próprias: executa o SQL (só comentário) de forma idempotente.
            $sqlFile = __DIR__ . '/../install/mysql/plugin_mafasa_empty.sql';
            if (is_file($sqlFile)) {
                self::executeSqlFile($DB, $sqlFile);
            }

            // Pasta de upload (ex.: logo enviada pela config)
            $uploadDir = (defined('GLPI_PLUGIN_DOC_DIR') ? GLPI_PLUGIN_DOC_DIR : GLPI_ROOT . '/files/_plugins') . '/mafasa';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            if (is_dir($uploadDir)) {
                chmod($uploadDir, 0775);
            }

            Config::setConfigurationValues('plugin:mafasa', ['installed' => date('Y-m-d H:i:s')]);

            return true;
        } catch (\Throwable $e) {
            $msg = "[mafasa] Erro na instalação: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine();
            error_log($msg);
            echo $msg;
            return false;
        }
    }

    public static function uninstall(): bool
    {
        global $DB;
        try {
            $DB->doQuery("DELETE FROM `glpi_configs` WHERE `context`='plugin:mafasa'");
            return true;
        } catch (\Throwable $e) {
            error_log("[mafasa] uninstall erro: " . $e->getMessage());
            return false;
        }
    }

    private static function executeSqlFile($DB, string $sqlFile): void
    {
        $sql = (string) file_get_contents($sqlFile);
        $sql = str_replace("\r\n", "\n", $sql);
        $lines = explode("\n", $sql);
        $clean = "";
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) {
                continue;
            }
            $clean .= $line . "\n";
        }
        $queries = array_filter(array_map('trim', explode(";", $clean)));
        foreach ($queries as $q) {
            if ($q === '') {
                continue;
            }
            $DB->doQuery($q);
        }
    }
}
