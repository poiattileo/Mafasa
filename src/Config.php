<?php
/**
 * Configuração do plugin Mafasa (Task 5).
 *
 * Guarda em `glpi_configs` no contexto `plugin:mafasa` via
 * `Config::setConfigurationValues()` / `Config::getConfigurationValues()`
 * (com fallback por chave via `getConfigurationValue()` para GLPI 11/12).
 *
 * Chaves v1: header_title, footer_text, logo_path.
 */

namespace GlpiPlugin\Mafasa;

use Config as GlpiConfig;

class Config
{
    public const CONTEXT = 'plugin:mafasa';

    public const DEFAULT_HEADER_TITLE = 'Relatório de Ativos — URE Jales';
    public const DEFAULT_FOOTER_TEXT = 'Mafasa — Relatórios de Ativos (URE Jales)';

    /**
     * Valores padrão (logo_path vazio = usa public/img/logo-ure-jales.png).
     *
     * @return array{header_title: string, footer_text: string, logo_path: string}
     */
    public static function getDefaults(): array
    {
        return [
            'header_title' => self::DEFAULT_HEADER_TITLE,
            'footer_text'  => self::DEFAULT_FOOTER_TEXT,
            'logo_path'    => '',
        ];
    }

    /**
     * Lê tudo do contexto `plugin:mafasa` mesclado com os padrões.
     *
     * @return array{header_title: string, footer_text: string, logo_path: string}
     */
    public static function getAll(): array
    {
        $defaults = self::getDefaults();
        $stored = [];

        try {
            if (class_exists(GlpiConfig::class) && method_exists(GlpiConfig::class, 'getConfigurationValues')) {
                $vals = GlpiConfig::getConfigurationValues(self::CONTEXT);
                if (is_array($vals)) {
                    $stored = $vals;
                }
            } elseif (class_exists(GlpiConfig::class) && method_exists(GlpiConfig::class, 'getConfigurationValue')) {
                // Fallback por chave (algumas versões expõem só o getter singular).
                foreach ($defaults as $key => $def) {
                    try {
                        // Assinatura GLPI: getConfigurationValue(string $context, string $name).
                        $val = GlpiConfig::getConfigurationValue(self::CONTEXT, $key);
                        if ($val !== null && $val !== false) {
                            $stored[$key] = $val;
                        }
                    } catch (\Throwable $e) {
                        // ignora esta chave e segue
                    }
                }
            }
        } catch (\Throwable $e) {
            $stored = [];
        }

        $out = $defaults;
        foreach ($defaults as $key => $def) {
            if (isset($stored[$key]) && is_scalar($stored[$key]) && trim((string) $stored[$key]) !== '') {
                $out[$key] = (string) $stored[$key];
            } elseif ($key === 'logo_path' && isset($stored[$key]) && is_scalar($stored[$key])) {
                // logo_path pode ser '' de propósito (usa logo padrão embutida).
                $out[$key] = (string) $stored[$key];
            }
        }

        return $out;
    }

    /**
     * Um valor só.
     */
    public static function get(string $key, string $default = ''): string
    {
        $all = self::getAll();
        if (array_key_exists($key, $all)) {
            return (string) $all[$key];
        }
        $defaults = self::getDefaults();
        return (string) ($defaults[$key] ?? $default);
    }

    /**
     * Título do cabeçalho do impresso (cai para o padrão quando vazio).
     */
    public static function getHeaderTitle(): string
    {
        $value = trim(self::get('header_title', ''));
        return $value !== '' ? $value : self::DEFAULT_HEADER_TITLE;
    }

    /**
     * Rodapé do impresso (cai para o padrão quando vazio).
     */
    public static function getFooterText(): string
    {
        $value = trim(self::get('footer_text', ''));
        return $value !== '' ? $value : self::DEFAULT_FOOTER_TEXT;
    }

    /**
     * Garante que os padrões existam (chamado no install e na tela de config).
     *
     * @return array{header_title: string, footer_text: string, logo_path: string}
     */
    public static function initDefaults(): array
    {
        $current = self::getAll();

        try {
            if (class_exists(GlpiConfig::class) && method_exists(GlpiConfig::class, 'setConfigurationValues')) {
                // Só grava o que ainda não existe, para não sobrescrever ajuste do admin.
                $missing = [];
                $raw = [];
                try {
                    $vals = GlpiConfig::getConfigurationValues(self::CONTEXT);
                    if (is_array($vals)) {
                        $raw = $vals;
                    }
                } catch (\Throwable $e) {
                    $raw = [];
                }
                foreach (self::getDefaults() as $key => $def) {
                    if (!array_key_exists($key, $raw)) {
                        $missing[$key] = $def;
                    }
                }
                if (!empty($missing)) {
                    GlpiConfig::setConfigurationValues(self::CONTEXT, $missing);
                }
            }
        } catch (\Throwable $e) {
            // sem GLPI carregado (ex.: php -l) — retorna os mesclados em memória
        }

        return $current;
    }

    /**
     * Salva chaves permitidas (header_title, footer_text, logo_path).
     */
    public static function save(array $values): bool
    {
        $allowed = ['header_title', 'footer_text', 'logo_path'];
        $toSave = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $values) && is_scalar($values[$key])) {
                $v = trim((string) $values[$key]);
                if ($key !== 'logo_path' && function_exists('mb_substr')) {
                    $v = mb_substr($v, 0, 255);
                } elseif ($key !== 'logo_path') {
                    $v = substr($v, 0, 255);
                } else {
                    $v = substr($v, 0, 500);
                }
                $toSave[$key] = $v;
            }
        }
        if (empty($toSave)) {
            return false;
        }

        try {
            if (class_exists(GlpiConfig::class) && method_exists(GlpiConfig::class, 'setConfigurationValues')) {
                GlpiConfig::setConfigurationValues(self::CONTEXT, $toSave);
                return true;
            }
        } catch (\Throwable $e) {
            error_log('[mafasa] Config::save erro: ' . $e->getMessage());
            return false;
        }

        return false;
    }

    /**
     * Caminho físico da logo configurada (upload em files/_plugins/mafasa/
     * ou public/img/). Retorna '' se não houver (caller usa a padrão embutida).
     */
    public static function getLogoFile(): string
    {
        $rel = trim(self::get('logo_path', ''));
        if ($rel === '') {
            return '';
        }
        // Evita path traversal: só basename + subdir conhecida.
        $rel = str_replace('\\', '/', $rel);
        $rel = ltrim($rel, '/');

        $candidates = [];
        if (defined('GLPI_PLUGIN_DOC_DIR')) {
            $candidates[] = (string) GLPI_PLUGIN_DOC_DIR . '/mafasa/' . basename($rel);
        } elseif (defined('GLPI_ROOT')) {
            $candidates[] = (string) GLPI_ROOT . '/files/_plugins/mafasa/' . basename($rel);
        }
        $candidates[] = __DIR__ . '/../public/img/' . basename($rel);
        $candidates[] = __DIR__ . '/../' . basename($rel);

        foreach ($candidates as $file) {
            if ($file !== '' && is_file($file)) {
                return $file;
            }
        }

        return '';
    }

    /**
     * URL/URI da logo do relatório.
     *
     * Prioriza a logo enviada (files/_plugins/mafasa/, storage v1) convertida em
     * data URI base64 (mime por extensão: png/jpg/jpeg/gif/svg; ignora arquivos
     * maiores que 2 MB). Sem upload válido, cai para a logo padrão embutida
     * public/img/logo-ure-jales.png quando ela existir; senão retorna ''.
     */
    public static function getLogoUrl(): string
    {
        $file = self::getLogoFile();
        if ($file !== '' && is_file($file)) {
            $size = filesize($file);
            if ($size !== false && $size > 0 && $size <= 2 * 1024 * 1024) {
                $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
                $mimes = [
                    'png'  => 'image/png',
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'gif'  => 'image/gif',
                    'svg'  => 'image/svg+xml',
                ];
                if (isset($mimes[$ext])) {
                    $data = file_get_contents($file);
                    if ($data !== false && $data !== '') {
                        return 'data:' . $mimes[$ext] . ';base64,' . base64_encode($data);
                    }
                }
            }
        }

        $php = function_exists('mafasa_php_dir') ? (string) mafasa_php_dir('mafasa') : __DIR__ . '/..';
        $defaultFile = rtrim($php, '/\\') . '/public/img/logo-ure-jales.png';
        if (is_file($defaultFile)) {
            $web = function_exists('mafasa_web_dir') ? (string) mafasa_web_dir('mafasa') : '';
            return $web . '/public/img/logo-ure-jales.png';
        }

        return '';
    }
}
