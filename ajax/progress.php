<?php
// FIX 1.0.1 - defensivo, retorna JSON mesmo com erro para não quebrar fetch e evitar loader infinito

// Tenta incluir GLPI - suporta plugins/ e marketplace/ (centralizado via Toolbox quando disponível)
$inc = null;
$candidates = [
    __DIR__ . "/../../../inc/includes.php",
    __DIR__ . "/../../../../inc/includes.php",
    dirname(__DIR__, 3) . "/inc/includes.php",
];
foreach ($candidates as $c) {
    if (file_exists($c)) { $inc = $c; break; }
}
if ($inc) {
    include($inc);
} else {
    // Fallback via Toolbox se GLPI_ROOT já definido ou tenta resolver
    $toolboxInc = __DIR__ . '/../inc/toolbox.class.php';
    if (file_exists($toolboxInc)) {
        // Toolbox precisa de GLPI_ROOT, tenta deduzir
        if (!defined('GLPI_ROOT')) {
            $guess = dirname(__DIR__, 3);
            if (is_file($guess . '/inc/includes.php')) define('GLPI_ROOT', $guess);
        }
        if (defined('GLPI_ROOT')) {
            @include_once $toolboxInc;
            if (class_exists('PluginKbwizardToolbox')) {
                $inc2 = PluginKbwizardToolbox::getIncPathFrom(__DIR__);
                if ($inc2 && file_exists($inc2)) { $inc = $inc2; include($inc); }
            }
        }
    }
}
if (!$inc || !file_exists($inc)) {
    http_response_code(500);
    header('Content-Type: application/json');
    // Não vaza candidates em produção (apenas loga)
    if (isset($candidates)) {
        error_log('[kbwizard] GLPI includes not found candidates: ' . json_encode($candidates));
    }
    echo json_encode(['error' => 'GLPI includes not found']);
    exit;
}

header('Content-Type: application/json');

try {
    Session::checkLoginUser();

    if (!isset($_POST['knowbaseitems_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'missing knowbaseitems_id']);
        exit;
    }

    // Verifica CSRF mas retorna JSON amigável em vez de die HTML que quebraria o loader
    try {
        Session::checkCSRF($_POST);
    } catch (Throwable $e) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF invalid', 'msg' => $e->getMessage()]);
        exit;
    }

    global $DB;
    if (!$DB || !$DB->tableExists('glpi_plugin_kbwizard_progress')) {
        http_response_code(500);
        echo json_encode(['error' => 'tabela glpi_plugin_kbwizard_progress não existe - reinstale o plugin']);
        exit;
    }

    $kbId = (int)$_POST['knowbaseitems_id'];
    $userId = Session::getLoginUserID();
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'not logged']);
        exit;
    }
    $current = (int)($_POST['current_step'] ?? 0);
    $completed = (int)($_POST['is_completed'] ?? 0);
    $action = $_POST['action'] ?? 'update';

    if ($action === 'reset') {
        PluginKbwizardProgress::resetProgress($kbId, $userId);
        echo json_encode(['ok' => true, 'reset' => true]);
        exit;
    }

    PluginKbwizardProgress::updateProgress($kbId, $userId, $current, $completed);
    echo json_encode(['ok' => true, 'current_step' => $current, 'is_completed' => $completed]);
} catch (Throwable $e) {
    http_response_code(500);
    // Loga detalhe apenas em arquivo, não vaza trace para o cliente (segurança)
    if (class_exists('PluginKbwizardToolbox')) {
        PluginKbwizardToolbox::log('ajax progress erro: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    } else {
        try { Toolbox::logInFile('kbwizard', 'ajax progress erro: ' . $e->getMessage() . "\n" . $e->getTraceAsString()); } catch(Throwable $e2){}
        error_log('[kbwizard] ajax progress erro: ' . $e->getMessage());
    }
    echo json_encode(['error' => 'Erro interno ao salvar progresso']);
}
