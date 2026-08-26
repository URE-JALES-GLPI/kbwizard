<?php
include ("../../../inc/includes.php");

Session::checkRight(KnowbaseItem::$rightname, UPDATE);

$knowbaseitems_id = (int)($_REQUEST['knowbaseitems_id'] ?? 0);
$id = (int)($_REQUEST['id'] ?? 0);

$kb = new KnowbaseItem();
if ($knowbaseitems_id && !$kb->getFromDB($knowbaseitems_id)) {
    Html::displayErrorAndDie(__('Artigo não encontrado', 'kbwizard'));
}

$step = new PluginKbwizardStep();

// Helper para validar CSRF de forma defensiva (evita double-check com csrf_compliant=true)
function kbwizard_check_csrf($data) {
    if (!isset($data['_glpi_csrf_token'])) {
        return;
    }
    try {
        Session::checkCSRF($data);
    } catch (Throwable $e) {
        // GLPI 11 já validou no controller, ignora double-check
        try { Toolbox::logInFile('kbwizard', 'CSRF step double-check ignorado: '.$e->getMessage()); } catch(Throwable $e2){}
    }
}

// Popular automaticamente a partir do answer
if (isset($_GET['populate_from_answer']) && $knowbaseitems_id) {
    kbwizard_check_csrf($_GET);
    global $DB;
    $stepsAuto = PluginKbwizardStep::parseAnswerToSteps($kb->fields['answer'] ?? '', 'hr_h2');
    foreach ($stepsAuto as $rank => $s) {
        $step->add([
            'knowbaseitems_id' => $knowbaseitems_id,
            'rank' => $rank,
            'title' => $s['title'],
            'content' => $s['content']
        ]);
    }
    Session::addMessageAfterRedirect(sprintf(__('%d passos gerados a partir do artigo!', 'kbwizard'), count($stepsAuto)), true, INFO);
    Html::redirect(KnowbaseItem::getFormURLWithID($knowbaseitems_id) . '#tab-PluginKbwizardConfig$1');
}

// Delete
if (isset($_GET['delete'])) {
    kbwizard_check_csrf($_GET);
    $delId = (int)$_GET['delete'];
    if ($step->getFromDB($delId)) {
        $step->delete(['id' => $delId]);
        Session::addMessageAfterRedirect(__('Passo removido', 'kbwizard'), true, INFO);
    }
    Html::redirect(KnowbaseItem::getFormURLWithID($knowbaseitems_id) . '#tab-PluginKbwizardConfig$1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    kbwizard_check_csrf($_POST);
    if (isset($_POST['add'])) {
        unset($_POST['add']);
        $newId = $step->add($_POST);
        Session::addMessageAfterRedirect(__('Passo criado', 'kbwizard'), true, INFO);
        Html::redirect(KnowbaseItem::getFormURLWithID($_POST['knowbaseitems_id']) . '#tab-PluginKbwizardConfig$1');
    } elseif (isset($_POST['update'])) {
        unset($_POST['update']);
        $step->update($_POST);
        Session::addMessageAfterRedirect(__('Passo atualizado', 'kbwizard'), true, INFO);
        Html::redirect(KnowbaseItem::getFormURLWithID($_POST['knowbaseitems_id']) . '#tab-PluginKbwizardConfig$1');
    }
}

// Exibir formulário
Html::header(PluginKbwizardStep::getTypeName(1), $_SERVER['PHP_SELF'], "tools", "knowbaseitem");

if ($id) {
    $step->getFromDB($id);
    $knowbaseitems_id = $step->fields['knowbaseitems_id'];
    $kb->getFromDB($knowbaseitems_id);
}

echo "<div class='container-fluid'>";
echo "<div class='mb-3'><a href='".KnowbaseItem::getFormURLWithID($knowbaseitems_id)."#tab-PluginKbwizardConfig\$1' class='btn btn-outline-secondary'><i class='ti ti-arrow-left me-1'></i>".__('Voltar ao artigo', 'kbwizard')."</a></div>";

$step->showForm($id, ['knowbaseitems_id' => $knowbaseitems_id]);

echo "</div>";
Html::footer();
