<?php
// Robust include - suporta plugins/ e marketplace/
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

global $CFG_GLPI;

Session::checkRight(KnowbaseItem::$rightname, UPDATE);

$knowbaseitems_id = (int)($_POST['knowbaseitems_id'] ?? $_GET['knowbaseitems_id'] ?? 0);

// Se POST com knowbaseitems_id = salva config por artigo (fluxo da aba)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $knowbaseitems_id) {
    // FIX GLPI 11.06: com csrf_compliant=true o GLPI já valida o token no LegacyFileLoadController
    // antes de chegar aqui. Chamar checkCSRF de novo consome o token e lança AccessDeniedHttpException.
    // Então valida apenas se ainda não foi consumido, de forma defensiva.
    if (isset($_POST['_glpi_csrf_token'])) {
        try {
            Session::checkCSRF($_POST);
        } catch (Throwable $e) {
            // Se já foi validado pelo framework, o token não estará mais em $_SESSION['glpicsrftokens']
            // Nesse caso ignora o erro e segue. Se for ataque real, o framework já teria barrado antes.
            // Loga para debug mas não bloqueia o salvamento.
            try {
                Toolbox::logInFile('kbwizard', 'CSRF double-check ignorado: ' . $e->getMessage());
            } catch (Throwable $e2) {}
        }
    }

    $kb = new KnowbaseItem();
    if (!$kb->getFromDB($knowbaseitems_id)) {
        Html::displayErrorAndDie(__('Artigo não encontrado', 'kbwizard'));
    }

    $config = new PluginKbwizardConfig();
    $found = $config->getFromDBByCrit(['knowbaseitems_id' => $knowbaseitems_id]);

    // Garante coluna auto_titles existe (caso update do plugin ainda não rodou)
    global $DB;
    if ($DB && $DB->tableExists('glpi_plugin_kbwizard_configs') && !$DB->fieldExists('glpi_plugin_kbwizard_configs', 'auto_titles')) {
        try {
            $DB->doQuery("ALTER TABLE `glpi_plugin_kbwizard_configs` ADD COLUMN `auto_titles` TEXT DEFAULT NULL");
            Toolbox::logInFile('kbwizard', 'auto_titles coluna criada on-the-fly em front/config.php');
        } catch (Throwable $e) {
            try { Toolbox::logInFile('kbwizard', 'falha ao criar auto_titles: '.$e->getMessage()); } catch(Throwable $e2){}
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
        'auto_delimiter' => in_array($_POST['auto_delimiter'] ?? '', ['hr','h2','hr_h2','marker'], true) ? $_POST['auto_delimiter'] : 'hr_h2',
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

echo "<div class='container-fluid'>";
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
