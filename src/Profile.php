<?php
/**
 * Aba "Relatórios" na tela de Perfis do GLPI — permissão de acesso ao plugin.
 *
 * Um único direito, Sim/Não: plugin_mafasa_access (padrão: Não / 0).
 * Registrada em setup.php via Plugin::registerClass(..., ['addtabon' => 'Profile']).
 *
 * Fluxo de gravação (core GLPI 11/12, sem código nosso):
 *   front/profile.form.php (POST update) → Profile::update()
 *   → Profile::prepareInputForUpdate() lê $_POST['_plugin_mafasa_access']
 *   → ProfileRight::updateProfileRights() grava em glpi_profilerights.
 *
 * Compat: GLPI 11.0.0–12.0.99, PHP >= 8.2.
 */

namespace GlpiPlugin\Mafasa;

use ProfileRight;

class Profile extends \Profile
{
    /** Rótulo exibido na aba e no título da matriz. */
    private const TAB_LABEL = 'Relatórios';

    /**
     * Nome do direito em glpi_profilerights (ver compat.php).
     */
    private static function rightName(): string
    {
        return defined('MAFASA_RIGHT_ACCESS') ? (string) MAFASA_RIGHT_ACCESS : 'plugin_mafasa_access';
    }

    public static function getTypeName($nb = 0)
    {
        return self::TAB_LABEL;
    }

    /**
     * Nome da aba em Configurar → Perfis.
     */
    public function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile) {
            return self::TAB_LABEL;
        }
        return '';
    }

    /**
     * Conteúdo da aba: matriz de direitos com uma única opção (Sim).
     */
    public static function displayTabContentForItem(\CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof \Profile) {
            return false;
        }
        $profiles_id = (int) $item->getID();
        if ($profiles_id <= 0) {
            return false;
        }

        // Registra o direito como existente e cria a linha do perfil (valor 0).
        self::ensureRightExists();
        self::addDefaultProfileInfos($profiles_id, [self::rightName() => 0]);

        $prof = new self();
        $prof->showForm($profiles_id);

        return true;
    }

    /**
     * Direitos exibidos na matriz (uma coluna: Sim).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getAllRights(): array
    {
        $read = defined('READ') ? (int) READ : 1;

        return [
            [
                'itemtype' => self::class,
                'label'    => 'Acesso aos relatórios (Ativos, Chamados e Quadros Kanpro)',
                'field'    => self::rightName(),
                'rights'   => [$read => 'Sim'],
            ],
        ];
    }

    /**
     * Formulário do direito. Posta para front/profile.form.php (core).
     */
    public function showForm($profiles_id = 0, $openform = true, $closeform = true)
    {
        global $CFG_GLPI;

        if (!\Session::haveRight('profile', READ)) {
            \mafasa_display_right_error();
            return;
        }

        $canedit = (bool) \Session::haveRight('profile', UPDATE);
        $profiles_id = (int) $profiles_id;

        $profile = new \Profile();
        if ($profiles_id <= 0 || !$profile->getFromDB($profiles_id)) {
            return;
        }

        $root = (isset($CFG_GLPI['root_doc']) && is_string($CFG_GLPI['root_doc'])) ? $CFG_GLPI['root_doc'] : '';

        echo "<div class='spaced'>";
        if ($openform) {
            echo "<form method='post' action='" . htmlspecialchars($root . '/front/profile.form.php', ENT_QUOTES, 'UTF-8') . "'>";
        }

        // displayRightsChoiceMatrix() imprime a matriz (Twig) e devolve um int.
        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::TAB_LABEL,
        ]);

        if ($canedit) {
            echo \Html::hidden('id', ['value' => $profiles_id]);
            echo "<div class='center mt-2'>";
            echo \Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>";
        }

        if ($openform) {
            // closeForm() já injeta o _glpi_csrf_token.
            if (method_exists('Html', 'closeForm')) {
                \Html::closeForm();
            } else {
                echo "<input type='hidden' name='_glpi_csrf_token' value='"
                    . htmlspecialchars(function_exists('mafasa_csrf_token') ? mafasa_csrf_token() : '', ENT_QUOTES, 'UTF-8')
                    . "' /></form>";
            }
        }
        echo "</div>";
    }

    /**
     * Garante que o direito conste em glpi_profilerights para todos os perfis.
     * O próprio core faz isso ao renderizar a matriz, mas aqui garantimos antes.
     */
    public static function ensureRightExists(): void
    {
        if (!class_exists('ProfileRight')
            || !method_exists('ProfileRight', 'getAllPossibleRights')
            || !method_exists('ProfileRight', 'addProfileRights')) {
            return;
        }
        $name = self::rightName();
        try {
            $available = ProfileRight::getAllPossibleRights();
            if (!is_array($available) || !isset($available[$name])) {
                ProfileRight::addProfileRights([$name]);
            }
        } catch (\Throwable $e) {
            // sem o registro o direito apenas não é gravado pelo core
        }
    }

    /**
     * Cria a linha do direito para um perfil, se ainda não existir.
     *
     * @param array<string,int> $rights
     */
    public static function addDefaultProfileInfos(int $profiles_id, array $rights): void
    {
        global $DB;

        if ($profiles_id <= 0 || !isset($DB) || !is_object($DB)) {
            return;
        }

        foreach ($rights as $name => $value) {
            try {
                $exists = countElementsInTable('glpi_profilerights', [
                    'profiles_id' => $profiles_id,
                    'name'        => $name,
                ]);
                if ($exists > 0) {
                    continue;
                }
                $DB->insert('glpi_profilerights', [
                    'profiles_id' => $profiles_id,
                    'name'        => $name,
                    'rights'      => (int) $value,
                ]);
                if (isset($_SESSION['glpiactiveprofile']['id'])
                    && (int) $_SESSION['glpiactiveprofile']['id'] === $profiles_id) {
                    $_SESSION['glpiactiveprofile'][$name] = (int) $value;
                }
            } catch (\Throwable $e) {
                // ignora: o direito fica como "Não" até ser salvo pela tela
            }
        }
    }

    /**
     * Perfil novo: o direito nasce como "Não".
     */
    public static function createFirstAccess($profiles_id): void
    {
        self::ensureRightExists();
        self::addDefaultProfileInfos((int) $profiles_id, [self::rightName() => 0]);
    }
}
