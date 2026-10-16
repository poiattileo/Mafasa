<?php
/**
 * Compatibilidade Mafasa entre GLPI 11 e GLPI 12.
 *
 * Espelha Protocolo src/compat.php trocando o prefixo para mafasa_.
 * Helpers guardados por function_exists/method_exists para funcionar nas duas
 * versões. No GLPI 12: Plugin::getWebDir() foi removido e o CSRF passou a ser
 * validado pelo CheckCsrfListener do core (sem token manual).
 */

if (!function_exists('mafasa_glpi_version')) {
    function mafasa_glpi_version(): string
    {
        return defined('GLPI_VERSION') ? (string) GLPI_VERSION : '';
    }
}

if (!function_exists('mafasa_glpi_major')) {
    function mafasa_glpi_major(): int
    {
        $v = mafasa_glpi_version();
        if ($v === '') {
            return 0;
        }
        return (int) explode('.', $v)[0];
    }
}

if (!function_exists('mafasa_is_glpi12_plus')) {
    function mafasa_is_glpi12_plus(): bool
    {
        return mafasa_glpi_major() >= 12;
    }
}

if (!function_exists('mafasa_web_dir')) {
    /**
     * URL base do plugin (ex.: "/glpi/plugins/mafasa" ou "/glpi/marketplace/mafasa").
     * GLPI <=11: usa Plugin::getWebDir(); GLPI 12 (método removido): monta via root_doc.
     * Suporta plugins/ ou marketplace/ sem duplicar root_doc.
     */
    function mafasa_web_dir(string $plugin = 'mafasa'): string
    {
        if (method_exists('Plugin', 'getWebDir')) {
            try {
                return (string) Plugin::getWebDir($plugin);
            } catch (\Throwable $e) {
                // cai para o fallback abaixo
            }
        }
        global $CFG_GLPI;
        $root = (is_array($CFG_GLPI) && isset($CFG_GLPI['root_doc'])) ? (string) $CFG_GLPI['root_doc'] : '';
        // Tenta detectar marketplace vs plugins pelo disco quando possível
        if (defined('GLPI_ROOT')) {
            $base = (string) GLPI_ROOT;
            if (is_dir($base . '/marketplace/' . $plugin)) {
                return $root . '/marketplace/' . $plugin;
            }
        }
        return $root . '/plugins/' . $plugin;
    }
}

if (!function_exists('mafasa_php_dir')) {
    /**
     * Caminho físico do plugin. GLPI <=11: usa Plugin::getPhpDir(); GLPI 12: GLPI_ROOT/plugins.
     */
    function mafasa_php_dir(string $plugin = 'mafasa'): string
    {
        if (method_exists('Plugin', 'getPhpDir')) {
            try {
                return (string) Plugin::getPhpDir($plugin);
            } catch (\Throwable $e) {
            }
        }
        if (defined('GLPI_ROOT')) {
            $base = (string) GLPI_ROOT;
            if (is_dir($base . '/marketplace/' . $plugin)) {
                return $base . '/marketplace/' . $plugin;
            }
            return $base . '/plugins/' . $plugin;
        }
        return __DIR__ . '/..';
    }
}

if (!function_exists('mafasa_csrf_token')) {
    /**
     * Token CSRF da página. No GLPI 12 não há token (retorna ''); o CSRF é
     * tratado pelo listener do core. No GLPI 11 mantém o comportamento atual.
     */
    function mafasa_csrf_token(): string
    {
        if (mafasa_is_glpi12_plus()) {
            return '';
        }
        if (method_exists('Session', 'getNewCSRFToken')) {
            try {
                return (string) Session::getNewCSRFToken();
            } catch (\Throwable $e) {
                return '';
            }
        }
        return '';
    }
}

if (!function_exists('mafasa_check_csrf')) {
    /**
     * Validação CSRF explícita. No GLPI 12 é no-op (o CheckCsrfListener cuida);
     * no GLPI 11 delega para Session::checkCSRF() preservando o token.
     */
    function mafasa_check_csrf(array $data): void
    {
        if (mafasa_is_glpi12_plus()) {
            return;
        }
        Session::checkCSRF($data, true);
    }
}

if (!function_exists('mafasa_validate_csrf')) {
    /**
     * Wrapper de Session::validateCSRF() com gate de versão.
     * No GLPI 12 sempre retorna true (listener do core valida).
     */
    function mafasa_validate_csrf(array $data): bool
    {
        if (mafasa_is_glpi12_plus()) {
            return true;
        }
        if (method_exists('Session', 'validateCSRF')) {
            try {
                return (bool) Session::validateCSRF($data);
            } catch (\Throwable $e) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('mafasa_display_right_error')) {
    /**
     * Exibe o erro de acesso negado de forma compatível entre GLPI 11 e 12.
     * GLPI 11: Html::displayRightError() (aceita mensagem adicional e encerra).
     * GLPI 12: a Html do core não expõe mais o método -> lança
     * AccessDeniedHttpException; sem ela, responde 403 e encerra.
     */
    function mafasa_display_right_error(string $additional = ''): void
    {
        if (method_exists('Html', 'displayRightError')) {
            Html::displayRightError($additional);
            return;
        }
        if (class_exists('\\Glpi\\Exception\\Http\\AccessDeniedHttpException')) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException($additional);
        }
        http_response_code(403);
        if ($additional !== '') {
            echo htmlspecialchars($additional, ENT_QUOTES, 'UTF-8');
        }
        exit;
    }
}

if (!function_exists('mafasa_can_view_all_entities')) {
    /**
     * Usuário logado enxerga todas as entidades?
     */
    function mafasa_can_view_all_entities(): bool
    {
        if (!class_exists('Session')) {
            return false;
        }
        if (!method_exists('Session', 'getLoginUserID') || !method_exists('Session', 'canViewAllEntities')) {
            return false;
        }
        try {
            return (bool) (Session::getLoginUserID() && Session::canViewAllEntities());
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('mafasa_visible_entities')) {
    /**
     * Entidades visíveis para o usuário. [] quando vê todas; senão a lista de
     * IDs ativos (inclui 0, que é a entidade raiz), sem duplicatas.
     *
     * @return array<int,int>
     */
    function mafasa_visible_entities(): array
    {
        if (mafasa_can_view_all_entities()) {
            return [];
        }
        $raw = $_SESSION['glpiactiveentities'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $id = (int) $value;
            if ($id < 0) {
                continue;
            }
            if (!in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return array_values($out);
    }
}

if (!function_exists('mafasa_entity_sql')) {
    /**
     * Cláusula de restrição por entidade para o alias informado.
     * - vê todas as entidades: ''
     * - lista vazia (fail-closed): ' AND 1=0'
     * - senão: ' AND `alias`.`entities_id` IN (0,1,...)'
     */
    function mafasa_entity_sql(string $alias = 't'): string
    {
        if (mafasa_can_view_all_entities()) {
            return '';
        }
        $entities = mafasa_visible_entities();
        if ($entities === []) {
            return ' AND 1=0';
        }
        $alias = str_replace('`', '', $alias);
        $ids = array_map('intval', $entities);
        return ' AND `' . $alias . '`.`entities_id` IN (' . implode(',', $ids) . ')';
    }
}
