<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

// Corpo em trait: permite declarar $rightname com o tipo certo por versão do GLPI (ver final do arquivo)
trait PluginKbwizardProgressBody {

    public static function getTypeName($nb = 0) {
        return _n('Progresso', 'Progressos', $nb, 'kbwizard');
    }

    /**
     * Atualiza progresso do usuário
     */
    public static function updateProgress($knowbaseitems_id, $users_id, $current_step, $is_completed = 0) {
        $prog = new self();
        if ($prog->getFromDBByCrit(['knowbaseitems_id' => $knowbaseitems_id, 'users_id' => $users_id])) {
            $prog->update([
                'id' => $prog->getID(),
                'current_step' => $current_step,
                'is_completed' => $is_completed ? 1 : 0
            ]);
        } else {
            $prog->add([
                'knowbaseitems_id' => $knowbaseitems_id,
                'users_id' => $users_id,
                'current_step' => $current_step,
                'is_completed' => $is_completed ? 1 : 0
            ]);
        }
    }

    public static function resetProgress($knowbaseitems_id, $users_id) {
        $prog = new self();
        if ($prog->getFromDBByCrit(['knowbaseitems_id' => $knowbaseitems_id, 'users_id' => $users_id])) {
            $prog->delete(['id' => $prog->getID()]);
        }
    }
}

// GLPI 12 tipou CommonGLPI::$rightname como string; no GLPI 11 ela é sem tipo.
// Tipo de propriedade é invariante no PHP — declara conforme a versão em
// execução, senão dá fatal de compilação e derruba o sistema inteiro.
if (defined('GLPI_VERSION') && version_compare(GLPI_VERSION, '12.0', '>=')) {
    class PluginKbwizardProgress extends CommonDBTM {
        use PluginKbwizardProgressBody;
        public static string $rightname = 'knowbase';
    }
} else {
    class PluginKbwizardProgress extends CommonDBTM {
        use PluginKbwizardProgressBody;
        public static $rightname = 'knowbase';
    }
}
