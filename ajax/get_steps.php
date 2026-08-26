<?php
// Endpoint para fallback JS - retorna passos se banner PHP não foi injetado
// Suporta plugins/ e marketplace/

$candidates = [
    __DIR__ . "/../../../inc/includes.php",
    __DIR__ . "/../../../../inc/includes.php",
    dirname(__DIR__, 3) . "/inc/includes.php",
];
$inc = null;
foreach ($candidates as $c) {
    if (file_exists($c)) { $inc = $c; break; }
}
if ($inc) {
    include($inc);
} else {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'GLPI includes not found']);
    exit;
}

header('Content-Type: application/json');

try {
    Session::checkLoginUser();
    // Knowbase pode ser vista por usuários com direito knowbase ou FAQ
    if (!KnowbaseItem::canView()) {
        http_response_code(403);
        echo json_encode(['error' => 'sem permissão knowbase']);
        exit;
    }

    $kbId = (int)($_GET['knowbaseitems_id'] ?? $_GET['id'] ?? 0);
    if (!$kbId) {
        http_response_code(400);
        echo json_encode(['error' => 'missing id']);
        exit;
    }

    global $DB;
    if (!$DB || !$DB->tableExists('glpi_plugin_kbwizard_configs')) {
        echo json_encode(['active' => false, 'reason' => 'tabela configs não existe']);
        exit;
    }

    $kb = new KnowbaseItem();
    if (!$kb->getFromDB($kbId)) {
        http_response_code(404);
        echo json_encode(['error' => 'artigo não encontrado']);
        exit;
    }

    $config = new PluginKbwizardConfig();
    if (!$config->getFromDBByCrit(['knowbaseitems_id' => $kbId])) {
        echo json_encode(['active' => false, 'reason' => 'sem config']);
        exit;
    }
    if (empty($config->fields['is_active'])) {
        echo json_encode(['active' => false, 'reason' => 'is_active=0']);
        exit;
    }

    $stepMgr = new PluginKbwizardStep();
    $answer = $kb->fields['answer'] ?? '';
    $steps = $stepMgr->getStepsForItem($kbId, $answer, $config->fields['split_mode'] ?? 'auto', $config->fields['auto_delimiter'] ?? 'hr_h2');

    if (!is_array($steps) || count($steps) < 2) {
        echo json_encode(['active' => false, 'reason' => 'menos de 2 passos', 'count' => count($steps)]);
        exit;
    }

    $current = 0;
    $userId = Session::getLoginUserID();
    if ($userId && $DB->tableExists('glpi_plugin_kbwizard_progress')) {
        $prog = new PluginKbwizardProgress();
        if ($prog->getFromDBByCrit(['knowbaseitems_id' => $kbId, 'users_id' => $userId])) {
            $current = (int)($prog->fields['current_step'] ?? 0);
        }
    }

    echo json_encode([
        'active' => true,
        'kb_id' => $kbId,
        'kb_name' => $kb->fields['name'] ?? '',
        'steps' => $steps,
        'current' => $current,
        'allow_jump' => (int)($config->fields['allow_jump'] ?? 1),
        'show_progress' => (int)($config->fields['show_progress'] ?? 1),
        'require_sequential' => (int)($config->fields['require_sequential'] ?? 0),
        'total' => count($steps)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    try { Toolbox::logInFile('kbwizard', 'get_steps erro: '.$e->getMessage()."\n".$e->getTraceAsString()); } catch(Throwable $e2){}
    echo json_encode(['error' => $e->getMessage()]);
}
