<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKbwizardProgress extends CommonDBTM {

    public static $rightname = 'knowbase';

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
