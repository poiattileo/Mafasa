<?php
/**
 * Tela de configuração do plugin Mafasa (Task 5).
 *
 * Só admin com direito `config` UPDATE. Salva header_title/footer_text e
 * upload opcional de logo (files/_plugins/mafasa/ com fallback public/img/)
 * no contexto `plugin:mafasa` via GlpiPlugin\Mafasa\Config.
 */

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/compat.php';
require_once __DIR__ . '/../src/Config.php';

use GlpiPlugin\Mafasa\Config as MafasaConfig;

Session::checkLoginUser();

if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
}

global $CFG_GLPI;

$errors = [];
$success = false;

// ---- POST: salvar ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mafasa_check_csrf($_POST);

    $headerTitle = trim((string) ($_POST['header_title'] ?? ''));
    $footerText  = trim((string) ($_POST['footer_text'] ?? ''));
    if ($headerTitle === '') {
        $errors[] = __('O título do cabeçalho não pode ficar vazio.', 'mafasa');
    }
    if (function_exists('mb_strlen')) {
        if (mb_strlen($headerTitle) > 255) {
            $errors[] = __('Título do cabeçalho com mais de 255 caracteres.', 'mafasa');
        }
        if (mb_strlen($footerText) > 255) {
            $errors[] = __('Texto do rodapé com mais de 255 caracteres.', 'mafasa');
        }
    }

    // Upload opcional da logo.
    $newLogoPath = null;
    if (isset($_FILES['logo']) && is_array($_FILES['logo']) && (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['logo'];
        $errCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode !== UPLOAD_ERR_OK) {
            $errors[] = __('Falha no upload da logo (código ', 'mafasa') . $errCode . ').';
        } else {
            $tmp = (string) ($file['tmp_name'] ?? '');
            $size = (int) ($file['size'] ?? 0);
            if (!is_uploaded_file($tmp)) {
                $errors[] = __('Arquivo de upload inválido.', 'mafasa');
            } elseif ($size <= 0 || $size > 2 * 1024 * 1024) {
                $errors[] = __('Logo deve ter até 2 MB.', 'mafasa');
            } else {
                $allowedExt = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
                $origName = (string) ($file['name'] ?? 'logo.png');
                $ext = strtolower((string) pathinfo($origName, PATHINFO_EXTENSION));
                if (!isset($allowedExt[$ext])) {
                    $errors[] = __('Formato de logo inválido (use PNG, JPG, GIF ou WEBP).', 'mafasa');
                } else {
                    $mime = '';
                    try {
                        if (function_exists('mime_content_type')) {
                            $mime = (string) mime_content_type($tmp);
                        }
                    } catch (Throwable $e) {
                        $mime = '';
                    }
                    if ($mime === '' && function_exists('getimagesize')) {
                        $info = @getimagesize($tmp);
                        $mime = is_array($info) && isset($info['mime']) ? (string) $info['mime'] : '';
                    }
                    if ($mime !== '' && $mime !== $allowedExt[$ext]) {
                        $errors[] = __('O conteúdo do arquivo não confere com a extensão.', 'mafasa');
                    } else {
                        // Destino preferido: files/_plugins/mafasa/ (gravável).
                        $destDir = (defined('GLPI_PLUGIN_DOC_DIR') ? (string) GLPI_PLUGIN_DOC_DIR : ((defined('GLPI_ROOT') ? (string) GLPI_ROOT : '') . '/files/_plugins')) . '/mafasa';
                        $destKind = 'files';
                        if (!is_dir($destDir)) {
                            @mkdir($destDir, 0775, true);
                        }
                        if (!is_dir($destDir) || !is_writable($destDir)) {
                            // Fallback: public/img/ dentro do plugin.
                            $destDir = __DIR__ . '/../public/img';
                            $destKind = 'public';
                        }
                        $safeName = 'logo-custom-' . date('Ymd-His') . '.' . $ext;
                        $destFile = rtrim($destDir, '/\\') . '/' . $safeName;
                        if (!@move_uploaded_file($tmp, $destFile)) {
                            $errors[] = __('Não foi possível salvar a logo no servidor.', 'mafasa');
                        } else {
                            @chmod($destFile, 0644);
                            $newLogoPath = ($destKind === 'files') ? $safeName : $safeName;
                        }
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        $toSave = [
            'header_title' => $headerTitle,
            'footer_text'  => $footerText,
        ];
        if ($newLogoPath !== null) {
            $toSave['logo_path'] = $newLogoPath;
        }
        if (MafasaConfig::save($toSave)) {
            $success = true;
        } else {
            $errors[] = __('Não foi possível salvar (Config indisponível?).', 'mafasa');
        }
    }
}

$cfg = MafasaConfig::initDefaults();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$success) {
    // Reexibe o que o usuário digitou quando houve erro.
    $cfg['header_title'] = trim((string) ($_POST['header_title'] ?? $cfg['header_title']));
    $cfg['footer_text']  = trim((string) ($_POST['footer_text'] ?? $cfg['footer_text']));
}

Html::header(
    __('Configuração — Mafasa', 'mafasa'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$csrfToken = mafasa_csrf_token();
$currentLogoFile = MafasaConfig::getLogoFile();
$defaultLogoFile = __DIR__ . '/../public/img/logo-ure-jales.png';
$webdir = mafasa_web_dir('mafasa');

echo "<div class='mf-page'>";
echo "<div class='mf-page-header'>";
echo "<h2>" . htmlspecialchars(__('Configuração — Mafasa', 'mafasa')) . "</h2>";
echo "</div>";

if ($success) {
    echo "<div class='mf-success' role='status'>" . htmlspecialchars(__('Configuração salva com sucesso.', 'mafasa')) . "</div>";
}
foreach ($errors as $err) {
    echo "<div class='mf-warning' role='alert'>" . htmlspecialchars($err) . "</div>";
}

echo "<form class='mf-card' method='post' action='config.php' enctype='multipart/form-data'>";
if ($csrfToken !== '') {
    echo "<input type='hidden' name='_glpi_csrf_token' value='" . htmlspecialchars($csrfToken) . "'>";
}

echo "<label class='mf-field'><span>" . htmlspecialchars(__('Título do cabeçalho', 'mafasa')) . "</span>";
echo "<input type='text' name='header_title' maxlength='255' required value='" . htmlspecialchars($cfg['header_title']) . "'>";
echo "</label>";

echo "<label class='mf-field'><span>" . htmlspecialchars(__('Texto do rodapé', 'mafasa')) . "</span>";
echo "<input type='text' name='footer_text' maxlength='255' value='" . htmlspecialchars($cfg['footer_text']) . "'>";
echo "</label>";

echo "<div class='mf-field'><span>" . htmlspecialchars(__('Logo atual', 'mafasa')) . "</span>";
if ($currentLogoFile !== '' && is_file($currentLogoFile)) {
    $logoUrl = $webdir . '/public/img/' . rawurlencode(basename($currentLogoFile));
    echo "<img src='" . htmlspecialchars($logoUrl) . "' alt='logo' style='max-height:80px'>";
    echo "<div class='mf-hint'>" . htmlspecialchars(basename($currentLogoFile)) . "</div>";
} elseif (is_file($defaultLogoFile)) {
    echo "<img src='" . htmlspecialchars($webdir) . "/public/img/logo-ure-jales.png' alt='logo' style='max-height:80px'>";
    echo "<div class='mf-hint'>" . htmlspecialchars(__('Logo padrão (URE Jales) embutida.', 'mafasa')) . "</div>";
} else {
    echo "<div class='mf-hint'>" . htmlspecialchars(__('Sem logo no disco — o impresso usa texto "URE Jales".', 'mafasa')) . "</div>";
}
echo "</div>";

echo "<label class='mf-field'><span>" . htmlspecialchars(__('Trocar logo (PNG/JPG/GIF/WEBP, até 2 MB)', 'mafasa')) . "</span>";
echo "<input type='file' name='logo' accept='image/png,image/jpeg,image/gif,image/webp'>";
echo "<div class='mf-hint'>" . htmlspecialchars(__('Salva em files/_plugins/mafasa/ (ou public/img/ como fallback). Opcional — vazio mantém a atual.', 'mafasa')) . "</div>";
echo "</label>";

echo "<div class='mf-actions'>";
echo "<button type='submit' class='mf-btn mf-btn-primary'>" . htmlspecialchars(__('Salvar', 'mafasa')) . "</button>";
echo "<a class='mf-btn' href='report.php'>" . htmlspecialchars(__('Voltar aos relatórios', 'mafasa')) . "</a>";
echo "</div>";
echo "</form>";
echo "</div>";

Html::footer();
