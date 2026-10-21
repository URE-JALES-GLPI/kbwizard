<?php
// Robust include - suporta plugins/ e marketplace/ (tenta Toolbox após GLPI)
$inc = null;
$candidates = [
    __DIR__ . "/../../../inc/includes.php",
    __DIR__ . "/../../../../inc/includes.php",
    dirname(__DIR__, 3) . "/inc/includes.php",
];
foreach ($candidates as $c) { if (file_exists($c)) { $inc = $c; break; } }
if ($inc) {
    include($inc);
} else {
    include("../../../inc/includes.php");
}
// Toolbox disponível após includes.php
if (file_exists(__DIR__ . '/../inc/toolbox.class.php') && !class_exists('PluginKbwizardToolbox', false)) {
    @include_once __DIR__ . '/../inc/toolbox.class.php';
}

global $CFG_GLPI;

$knowbaseitems_id = (int)($_POST['knowbaseitems_id'] ?? $_GET['knowbaseitems_id'] ?? 0);

if ($knowbaseitems_id) {
    Session::checkRight(KnowbaseItem::$rightname, UPDATE);
} else {
    // Página global (Configurar > Plugins > KB Wizard): só administradores.
    Session::checkRight('config', UPDATE);
}

/**
 * Configuração global da sincronização com o Google Drive.
 * Salva os campos e executa a ação escolhida (testar / sincronizar / reprocessar).
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$knowbaseitems_id) {
    // CSRF defensivo dual GLPI 11/12 (mesmo padrão do restante do arquivo).
    if (isset($_POST['_glpi_csrf_token']) && method_exists('Session', 'checkCSRF')) {
        try {
            Session::checkCSRF($_POST);
        } catch (Throwable $e) {
            if (class_exists('PluginKbwizardToolbox')) {
                PluginKbwizardToolbox::log('CSRF double-check drive ignorado: ' . $e->getMessage());
            }
        }
    }

    if (function_exists('plugin_kbwizard_create_drive_tables')) {
        plugin_kbwizard_create_drive_tables();
        PluginKbwizardDriveConfig::resetCache();
    }

    $webDir = class_exists('PluginKbwizardToolbox')
        ? PluginKbwizardToolbox::getWebDir()
        : (($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard');
    $redirect = $webDir . '/front/config.php';

    $current = PluginKbwizardDriveConfig::get();
    $input = [
        'is_active'       => !empty($_POST['is_active']),
        'shared_drive_id' => trim((string)($_POST['shared_drive_id'] ?? '')),
        'folder_id'       => trim((string)($_POST['folder_id'] ?? '')),
        'folder_name'     => trim((string)($_POST['folder_name'] ?? '')),
        'export_doc'      => !empty($_POST['export_doc']),
        'export_pdf'      => !empty($_POST['export_pdf']),
        'include_images'  => !empty($_POST['include_images']),
        'sa_filename'     => (string)($current['sa_filename'] ?? ''),
    ];

    // Upload do JSON da service account (fica fora do webroot).
    if (!empty($_FILES['sa_json']['tmp_name']) && is_uploaded_file($_FILES['sa_json']['tmp_name'])) {
        $raw = (string)file_get_contents($_FILES['sa_json']['tmp_name']);
        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key']) || empty($json['token_uri'])) {
            Session::addMessageAfterRedirect(
                __('O arquivo enviado não é um JSON válido de service account.', 'kbwizard'),
                true,
                ERROR
            );
        } else {
            $dir = PluginKbwizardDriveConfig::dataDir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $target = PluginKbwizardDriveConfig::serviceAccountPath();
            if (@file_put_contents($target, $raw) === false) {
                Session::addMessageAfterRedirect(
                    __('Não foi possível gravar a credencial em files/_plugins/kbwizard.', 'kbwizard'),
                    true,
                    ERROR
                );
            } else {
                @chmod($target, 0600);
                $input['sa_filename'] = basename($target);
                Session::addMessageAfterRedirect(__('Credencial da service account salva.', 'kbwizard'), true, INFO);
            }
        }
    }

    if (!empty($_POST['remove_sa'])) {
        $target = PluginKbwizardDriveConfig::serviceAccountPath();
        if (is_file($target)) {
            @unlink($target);
        }
        $input['sa_filename'] = '';
        Session::addMessageAfterRedirect(__('Credencial removida.', 'kbwizard'), true, INFO);
    }

    PluginKbwizardDriveConfig::save($input);

    if (isset($_POST['test_drive'])) {
        $client = PluginKbwizardDrive::fromConfig();
        if ($client === null) {
            $res = ['ok' => false, 'message' => __('Salve a service account e o ID do Shared Drive antes de testar.', 'kbwizard')];
        } else {
            $res = $client->testConnection();
            if (!empty($res['ok']) && !empty($res['folder_id'])) {
                PluginKbwizardDriveConfig::setFolderId((string)$res['folder_id']);
            }
        }
        $_SESSION['kbwizard_drive_test'] = $res;
    } elseif (isset($_POST['sync_all'])) {
        $queued = PluginKbwizardDriveSync::markAllDirty();
        Session::addMessageAfterRedirect(
            sprintf(__('%d artigo(s) enfileirado(s) para sincronização.', 'kbwizard'), $queued),
            true,
            INFO
        );
    } elseif (isset($_POST['retry_errors'])) {
        $retried = PluginKbwizardDriveSync::retryErrors();
        Session::addMessageAfterRedirect(
            sprintf(__('%d erro(s) reenfileirado(s).', 'kbwizard'), $retried),
            true,
            INFO
        );
    } else {
        Session::addMessageAfterRedirect(__('Configuração do Google Drive salva.', 'kbwizard'), true, INFO);
    }

    Html::redirect($redirect);
    exit;
}

// Se POST com knowbaseitems_id = salva config por artigo (fluxo da aba)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $knowbaseitems_id) {
    // CSRF defensivo dual GLPI 11/12: no 11 o LegacyFileLoadController já valida o token
    // (hook csrf_compliant) antes de chegar aqui; no 12 o hook foi removido e o
    // CheckCsrfListener valida headers Sec-Fetch-Site/Origin. A validação manual abaixo
    // roda apenas se o método existir (deprecated mas presente no 12) e ignora o
    // double-check de token já consumido pelo framework.
    if (isset($_POST['_glpi_csrf_token']) && method_exists('Session', 'checkCSRF')) {
        try {
            Session::checkCSRF($_POST);
        } catch (Throwable $e) {
            // Se já foi validado pelo framework, o token não estará mais em $_SESSION['glpicsrftokens']
            // Nesse caso ignora o erro e segue. Se for ataque real, o framework já teria barrado antes.
            // Loga para debug mas não bloqueia o salvamento.
            if (class_exists('PluginKbwizardToolbox')) PluginKbwizardToolbox::log('CSRF double-check ignorado: ' . $e->getMessage());
            else try { Toolbox::logInFile('kbwizard', 'CSRF double-check ignorado: ' . $e->getMessage()); } catch (Throwable $e2) {}
        }
    }

    $kb = new KnowbaseItem();
    if (!$kb->getFromDB($knowbaseitems_id)) {
        // API de erro fatal do Html removida no GLPI 12 — redireciona com mensagem (vale em 11 e 12).
        Session::addMessageAfterRedirect(__('Artigo não encontrado', 'kbwizard'), true, ERROR);
        Html::redirect(($CFG_GLPI['root_doc'] ?? '') . '/front/knowbaseitem.php');
        exit;
    }

    $config = new PluginKbwizardConfig();
    $found = $config->getFromDBByCrit(['knowbaseitems_id' => $knowbaseitems_id]);

    // Garante coluna auto_titles existe (caso update do plugin ainda não rodou) - com cache via Toolbox log
    global $DB;
    if ($DB && $DB->tableExists('glpi_plugin_kbwizard_configs') && !$DB->fieldExists('glpi_plugin_kbwizard_configs', 'auto_titles')) {
        try {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kbwizard_configs` ADD COLUMN `auto_titles` TEXT DEFAULT NULL");
            if (class_exists('PluginKbwizardToolbox')) PluginKbwizardToolbox::log('auto_titles coluna criada on-the-fly em front/config.php');
            else Toolbox::logInFile('kbwizard', 'auto_titles coluna criada on-the-fly em front/config.php');
        } catch (Throwable $e) {
            if (class_exists('PluginKbwizardToolbox')) PluginKbwizardToolbox::log('falha ao criar auto_titles: '.$e->getMessage());
            else try { Toolbox::logInFile('kbwizard', 'falha ao criar auto_titles: '.$e->getMessage()); } catch(Throwable $e2){}
        }
    }

    // Títulos editáveis por passo (modo auto) — auto_titles[0], auto_titles[1] ...
    $autoTitlesRaw = $_POST['auto_titles'] ?? [];
    $autoTitlesJson = '';
    if (is_array($autoTitlesRaw) && !empty($autoTitlesRaw)) {
        $clean = [];
        foreach ($autoTitlesRaw as $idx => $t) {
            $t = trim((string)$t);
            if ($t === '') continue;
            if (mb_strlen($t) > 255) $t = mb_substr($t, 0, 255);
            $clean[(int)$idx] = $t;
        }
        if (!empty($clean)) {
            $autoTitlesJson = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    $hasAutoTitles = $DB && $DB->tableExists('glpi_plugin_kbwizard_configs') && $DB->fieldExists('glpi_plugin_kbwizard_configs', 'auto_titles');
    $input = [
        'knowbaseitems_id' => $knowbaseitems_id,
        'is_active' => (int)($_POST['is_active'] ?? 0),
        'split_mode' => ($_POST['split_mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto',
        'auto_delimiter' => 'marker',
        'show_progress' => (int)($_POST['show_progress'] ?? 1),
        'allow_jump' => (int)($_POST['allow_jump'] ?? 1),
        'require_sequential' => (int)($_POST['require_sequential'] ?? 0),
    ];
    if ($hasAutoTitles) $input['auto_titles'] = $autoTitlesJson;

    if ($found) {
        $input['id'] = $config->getID();
        $config->update($input);
    } else {
        $config->add($input);
    }

    Session::addMessageAfterRedirect(__('Configuração salva!', 'kbwizard'), true, INFO);
    Html::redirect(KnowbaseItem::getFormURLWithID($knowbaseitems_id) . '#tab-PluginKbwizardConfig$1');
    exit;
}

// Caso GET sem id = página global de ajuda (config_page)
Html::header(__('KB Wizard - Passo a Passo', 'kbwizard'), $_SERVER['PHP_SELF'], 'config', 'plugin');

// Garante tabelas do Drive (instalações antigas / cópia de arquivos).
if (function_exists('plugin_kbwizard_create_drive_tables')) {
    plugin_kbwizard_create_drive_tables();
    PluginKbwizardDriveConfig::resetCache();
}

$driveCfg     = PluginKbwizardDriveConfig::get();
$driveStats   = PluginKbwizardDriveSync::stats();
$driveHasSa   = PluginKbwizardDriveConfig::hasServiceAccount();
$driveSaEmail = '';
if ($driveHasSa) {
    $sa = PluginKbwizardDriveConfig::loadServiceAccount();
    $driveSaEmail = (string)($sa['client_email'] ?? '');
}
$driveTest = null;
if (isset($_SESSION['kbwizard_drive_test'])) {
    $driveTest = $_SESSION['kbwizard_drive_test'];
    unset($_SESSION['kbwizard_drive_test']);
}
$driveWebDir    = class_exists('PluginKbwizardToolbox')
    ? PluginKbwizardToolbox::getWebDir()
    : (($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard');
$driveActionUrl = $driveWebDir . '/front/config.php';
$driveDataDir   = PluginKbwizardDriveConfig::dataDir();
$driveTemplate  = __DIR__ . '/../templates/config_drive.html.php';

echo "<div class='container-fluid'>";

if (is_file($driveTemplate)) {
    include $driveTemplate;
}

echo "<div class='card'>";
echo "<div class='card-header'><h3><i class='ti ti-list-check me-2'></i>".__('KB Wizard — Ajuda', 'kbwizard')."</h3></div>";
echo "<div class='card-body'>";
echo "<p>".__('Este plugin não possui configuração global. A configuração é feita <strong>dentro de cada artigo</strong> da Base de Conhecimento, na aba <em>Passo a Passo</em>.', 'kbwizard')."</p>";
echo "<ul>";
echo "<li>".__('1. No menu lateral vá em <strong>Ferramentas > Base de conhecimento</strong> (em alguns perfis aparece como <em>Assistência > Base de conhecimento</em>).', 'kbwizard')."</li>";
echo "<li>".__('2. Na lista que abrir, clique no <strong>título do artigo</strong> que quer transformar em passo a passo.', 'kbwizard')."</li>";
echo "<li>".__('3. Dentro do artigo clique na aba <strong>Passo a Passo</strong> <i class="ti ti-list-check"></i> no topo. Se não aparecer, seu perfil precisa de <em>Atualizar</em> em Base de conhecimento e é preciso limpar o cache.', 'kbwizard')."</li>";
echo "<li>".__('4. Marque <strong>Ativar modo Passo a Passo</strong>, escolha <em>Automático</em> (com <code>&lt;hr&gt;</code> ou <code>---PASSO---</code>) ou <em>Manual</em> e clique em <strong>Salvar</strong>.', 'kbwizard')."</li>";
echo "</ul>";
echo "<p><a href='".($CFG_GLPI['root_doc'] ?? '')."/front/knowbaseitem.php' class='btn btn-primary'><i class='ti ti-books me-1'></i>".__('Ir para Base de Conhecimento', 'kbwizard')."</a></p>";
echo "<hr>";
echo "<h4>".__('Dica de escrita', 'kbwizard')."</h4>";
echo "<p>".__('No editor do artigo, insira uma <strong>linha horizontal</strong> (botão HR) onde cada passo deve terminar. Ou escreva <code>---PASSO---</code> numa linha separada.', 'kbwizard')."</p>";
echo "<pre style='background:#f8fafc;padding:12px;border-radius:8px'>&lt;h2&gt;Passo 1 - Acessar&lt;/h2&gt;\n&lt;p&gt;Faça...&lt;/p&gt;\n&lt;hr&gt;\n&lt;h2&gt;Passo 2 - Configurar&lt;/h2&gt;\n&lt;p&gt;Configure...&lt;/p&gt;</pre>";
echo "</div>";
echo "</div>";
echo "</div>";

Html::footer();
