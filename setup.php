<?php
/**
 * KB Wizard - Passo a Passo para Base de Conhecimento
 * GLPI 11.0 a 12.0 - v1.4.0
 */

// Carrega Toolbox central (evita divergência plugins/marketplace); fallback silencioso se GLPI ainda não carregou
if (is_file(__DIR__ . '/inc/toolbox.class.php')) {
    require_once __DIR__ . '/inc/toolbox.class.php';
}

if (!defined('PLUGIN_KBWIZARD_VERSION')) {
    define('PLUGIN_KBWIZARD_VERSION', '1.5.0');
}
if (!defined('PLUGIN_KBWIZARD_MIN_GLPI')) {
    define('PLUGIN_KBWIZARD_MIN_GLPI', '11.0.0');
}
if (!defined('PLUGIN_KBWIZARD_MAX_GLPI')) {
    define('PLUGIN_KBWIZARD_MAX_GLPI', '12.0.99');
}

function plugin_version_kbwizard() {
    return [
        'name'           => 'KB Wizard - Passo a Passo',
        'version'        => PLUGIN_KBWIZARD_VERSION,
        'author'         => 'GPLI - KB Wizard',
        'license'        => 'GPLv3+',
        'homepage'       => 'https://github.com/gpli/kbwizard',
        'requirements'   => [
            'glpi' => ['min' => PLUGIN_KBWIZARD_MIN_GLPI, 'max' => PLUGIN_KBWIZARD_MAX_GLPI],
            'php'  => ['min' => '8.2']
        ]
    ];
}
function plugin_kbwizard_check_prerequisites() { return version_compare(PHP_VERSION, '8.2.0', '>='); }
function plugin_kbwizard_check_config($verbose=false){ global $DB; if($verbose && $DB && !$DB->tableExists('glpi_plugin_kbwizard_configs')){ echo __('Tabelas não encontradas. Reinstale.', 'kbwizard'); return false; } return true; }

function plugin_kbwizard_get_webdir() {
    if (class_exists('PluginKbwizardToolbox')) {
        return PluginKbwizardToolbox::getWebDir();
    }
    // Fallback se Toolbox ainda não carregado (instalação antiga)
    try {
        $phpDir = Plugin::getPhpDir('kbwizard');
        if (strpos($phpDir, 'marketplace') !== false) { global $CFG_GLPI; return ($CFG_GLPI['root_doc'] ?? '') . '/marketplace/kbwizard'; }
        if (strpos($phpDir, 'plugins') !== false) { global $CFG_GLPI; return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard'; }
    } catch (Throwable $e) {}
    if (defined('GLPI_ROOT')) {
        if (is_file(GLPI_ROOT . '/marketplace/kbwizard/public/css/kbwizard.css') || is_file(GLPI_ROOT . '/marketplace/kbwizard/css/kbwizard.css')) { global $CFG_GLPI; return ($CFG_GLPI['root_doc'] ?? '') . '/marketplace/kbwizard'; }
        if (is_file(GLPI_ROOT . '/plugins/kbwizard/public/css/kbwizard.css') || is_file(GLPI_ROOT . '/plugins/kbwizard/css/kbwizard.css')) { global $CFG_GLPI; return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard'; }
    }
    global $CFG_GLPI; return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard';
}

function plugin_init_kbwizard() {
    global $PLUGIN_HOOKS;
    // Hook csrf_compliant removido no GLPI 12 (CheckCsrfListener valida
    // headers Sec-Fetch-Site/Origin); registra apenas no GLPI < 12
    if (defined('GLPI_VERSION') ? version_compare(GLPI_VERSION, '12.0', '<') : true) {
        $PLUGIN_HOOKS['csrf_compliant']['kbwizard'] = true;
    }
    Plugin::registerClass('PluginKbwizardConfig', ['addtabon' => 'KnowbaseItem']);
    Plugin::registerClass('PluginKbwizardStep');
    Plugin::registerClass('PluginKbwizardProgress');
    if (class_exists('PluginKbwizardToolbox')) {
        Plugin::registerClass('PluginKbwizardToolbox');
    }
    // Assets ficam em public/ (exigência GLPI 11+); caminho registrado NÃO inclui
    // "public/" — o GLPI resolve 'css/kbwizard.css' -> public/css/kbwizard.css
    $PLUGIN_HOOKS['add_css']['kbwizard'] = 'css/kbwizard.css';
    $PLUGIN_HOOKS['add_javascript']['kbwizard'] = 'js/kbwizard.js';
    $PLUGIN_HOOKS['post_show_item']['kbwizard'] = 'plugin_kbwizard_post_show_item';
    $PLUGIN_HOOKS['post_show_tab']['kbwizard'] = 'plugin_kbwizard_post_show_item';
    $PLUGIN_HOOKS['config_page']['kbwizard'] = 'front/config.php';

    // Sincronização com Google Drive: marca artigos/passos alterados como pendentes.
    $PLUGIN_HOOKS['item_add']['kbwizard'] = [
        'KnowbaseItem'       => 'plugin_kbwizard_item_changed',
        'PluginKbwizardStep' => 'plugin_kbwizard_item_changed',
    ];
    $PLUGIN_HOOKS['item_update']['kbwizard'] = [
        'KnowbaseItem'       => 'plugin_kbwizard_item_changed',
        'PluginKbwizardStep' => 'plugin_kbwizard_item_changed',
    ];
    $PLUGIN_HOOKS['item_purge']['kbwizard'] = [
        'KnowbaseItem' => 'plugin_kbwizard_item_purged',
    ];

    // Auto-migração silenciosa: protege updates por cópia de arquivos que nunca
    // passam por Configurar > Plugins > Atualizar. Não usa Migration aqui para
    // não interferir no fluxo normal de instalação.
    try {
        if (isset($GLOBALS['DB']) && $GLOBALS['DB'] instanceof DBmysql) {
            $db = $GLOBALS['DB'];
            $charset = DBConnection::getDefaultCharset();
            $collation = DBConnection::getDefaultCollation();
            $cfgTable = 'glpi_plugin_kbwizard_configs';
            if (!$db->tableExists($cfgTable)) {
                try {
                    $db->doQuery("CREATE TABLE IF NOT EXISTS `$cfgTable` (
                        `id` int unsigned NOT NULL AUTO_INCREMENT,
                        `knowbaseitems_id` int unsigned NOT NULL,
                        `is_active` tinyint(1) NOT NULL DEFAULT 0,
                        `split_mode` varchar(20) NOT NULL DEFAULT 'auto',
                        `auto_delimiter` varchar(20) NOT NULL DEFAULT 'marker',
                        `show_progress` tinyint(1) NOT NULL DEFAULT 1,
                        `allow_jump` tinyint(1) NOT NULL DEFAULT 1,
                        `require_sequential` tinyint(1) NOT NULL DEFAULT 0,
                        `auto_titles` text,
                        `drive_sync` tinyint(1) NOT NULL DEFAULT 0,
                        `date_creation` timestamp NULL DEFAULT NULL,
                        `date_mod` timestamp NULL DEFAULT NULL,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `knowbaseitems_id` (`knowbaseitems_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
                } catch (Throwable $e) {}
            } else {
                if (!$db->fieldExists($cfgTable, 'allow_jump')) {
                    try { $db->doQuery("ALTER TABLE `$cfgTable` ADD COLUMN `allow_jump` tinyint(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
                }
                if (!$db->fieldExists($cfgTable, 'show_progress')) {
                    try { $db->doQuery("ALTER TABLE `$cfgTable` ADD COLUMN `show_progress` tinyint(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
                }
                if (!$db->fieldExists($cfgTable, 'require_sequential')) {
                    try { $db->doQuery("ALTER TABLE `$cfgTable` ADD COLUMN `require_sequential` tinyint(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
                }
                if (!$db->fieldExists($cfgTable, 'auto_titles')) {
                    try { $db->doQuery("ALTER TABLE `$cfgTable` ADD COLUMN `auto_titles` text"); } catch (Throwable $e) {}
                }
                if (!$db->fieldExists($cfgTable, 'drive_sync')) {
                    try { $db->doQuery("ALTER TABLE `$cfgTable` ADD COLUMN `drive_sync` tinyint(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
                }
                if (!$db->indexExists($cfgTable, 'knowbaseitems_id')) {
                    try { $db->doQuery("ALTER TABLE `$cfgTable` ADD UNIQUE KEY `knowbaseitems_id` (`knowbaseitems_id`)"); } catch (Throwable $e) {}
                }
            }
            $stepsTable = 'glpi_plugin_kbwizard_steps';
            if (!$db->tableExists($stepsTable)) {
                try {
                    $db->doQuery("CREATE TABLE IF NOT EXISTS `$stepsTable` (
                        `id` int unsigned NOT NULL AUTO_INCREMENT,
                        `knowbaseitems_id` int unsigned NOT NULL,
                        `rank` int NOT NULL DEFAULT 0,
                        `title` varchar(255) NOT NULL DEFAULT '',
                        `content` mediumtext,
                        `date_creation` timestamp NULL DEFAULT NULL,
                        `date_mod` timestamp NULL DEFAULT NULL,
                        PRIMARY KEY (`id`),
                        KEY `knowbaseitems_id` (`knowbaseitems_id`),
                        KEY `rank` (`rank`)
                    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
                } catch (Throwable $e) {}
            }
            $progTable = 'glpi_plugin_kbwizard_progress';
            if (!$db->tableExists($progTable)) {
                try {
                    $db->doQuery("CREATE TABLE IF NOT EXISTS `$progTable` (
                        `id` int unsigned NOT NULL AUTO_INCREMENT,
                        `knowbaseitems_id` int unsigned NOT NULL,
                        `users_id` int unsigned NOT NULL,
                        `current_step` int NOT NULL DEFAULT 0,
                        `is_completed` tinyint(1) NOT NULL DEFAULT 0,
                        `date_creation` timestamp NULL DEFAULT NULL,
                        `date_mod` timestamp NULL DEFAULT NULL,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `unicity` (`knowbaseitems_id`,`users_id`),
                        KEY `users_id` (`users_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
                } catch (Throwable $e) {}
            }
        }
    } catch (Throwable $e) {}
}

function plugin_kbwizard_post_show_item($params) {
    try {
        // Fallback CSS/JS inline se add_css deu 404 (plugins vs marketplace)
        $isKbPage = false;
        if (isset($params['item']) && ($params['item'] instanceof KnowbaseItem)) $isKbPage = true;
        else if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'knowbaseitem') !== false) $isKbPage = true;
        if ($isKbPage) {
            if (class_exists('PluginKbwizardToolbox')) {
                $webDir = PluginKbwizardToolbox::getWebDir();
                $otherDir = PluginKbwizardToolbox::getOtherWebDir();
                $suffix = PluginKbwizardToolbox::getVersionedSuffix();
            } else {
                $webDir = plugin_kbwizard_get_webdir();
                $otherDir = (strpos($webDir, 'marketplace') !== false) ? str_replace('marketplace','plugins',$webDir) : str_replace('plugins','marketplace',$webDir);
                $suffix = '?v=' . PLUGIN_KBWIZARD_VERSION;
            }
            // Usa json_encode para escapar corretamente (evita XSS se root_doc contiver aspas)
            $webDirJs = json_encode($webDir, JSON_UNESCAPED_SLASHES);
            $otherDirJs = json_encode($otherDir, JSON_UNESCAPED_SLASHES);
            $suffixJs = json_encode($suffix, JSON_UNESCAPED_SLASHES);
            echo Html::scriptBlock("
                (function(){
                    var webDir=".$webDirJs.",otherDir=".$otherDirJs.",suffix=".$suffixJs.";
                    function ensureCss(){
                        var has=false; for(var i=0;i<document.styleSheets.length;i++){ try{ if(document.styleSheets[i].href && document.styleSheets[i].href.indexOf('kbwizard.css')!==-1) has=true; }catch(e){} }
                        if(!has){ var urls=[webDir+'/css/kbwizard.css'+suffix,webDir+'/public/css/kbwizard.css'+suffix,otherDir+'/css/kbwizard.css'+suffix,otherDir+'/public/css/kbwizard.css'+suffix]; var i=0; function next(){ if(i>=urls.length) return; var l=document.createElement('link'); l.rel='stylesheet'; l.href=urls[i++]; l.onerror=function(){ next(); }; document.head.appendChild(l); } next(); }
                    }
                    function ensureJs(){
                        if(typeof KBWizard==='undefined'){ var urls=[webDir+'/js/kbwizard.js'+suffix,webDir+'/public/js/kbwizard.js'+suffix,otherDir+'/js/kbwizard.js'+suffix,otherDir+'/public/js/kbwizard.js'+suffix]; var i=0; function next(){ if(i>=urls.length) return; var s=document.createElement('script'); s.src=urls[i++]; s.onerror=function(){ next(); }; document.head.appendChild(s); } next(); }
                    }
                    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', function(){ ensureCss(); ensureJs(); }); else { ensureCss(); ensureJs(); }
                })();
            ");
        }

        if (!isset($params['item']) || !($params['item'] instanceof KnowbaseItem)) return;
        /** @var KnowbaseItem $kb */
        $kb = $params['item'];
        if ($kb->isNewItem()) return;
        global $DB;
        if (!$DB || !$DB->tableExists('glpi_plugin_kbwizard_configs') || !$DB->tableExists('glpi_plugin_kbwizard_steps')) return;

        $config = new PluginKbwizardConfig();
        if (!$config->getFromDBByCrit(['knowbaseitems_id' => $kb->getID()])) return;
        if (empty($config->fields['is_active'])) return;
        if (!KnowbaseItem::canView()) return;

        $stepMgr = new PluginKbwizardStep();
        $answer = $kb->fields['answer'] ?? '';
        $split_mode = $config->fields['split_mode'] ?? 'auto';
        $delimiter  = $config->fields['auto_delimiter'] ?? 'hr_h2';
        $steps = $stepMgr->getStepsForItem($kb->getID(), $answer, $split_mode, $delimiter);
        if (!is_array($steps) || count($steps) < 2) return;

        $currentStep = 0;
        try {
            $progress = new PluginKbwizardProgress();
            $userId = Session::getLoginUserID();
            if ($userId && $DB->tableExists('glpi_plugin_kbwizard_progress') && $progress->getFromDBByCrit(['knowbaseitems_id' => $kb->getID(), 'users_id' => $userId])) {
                $currentStep = (int)($progress->fields['current_step'] ?? 0);
                if ($currentStep < 0) $currentStep = 0;
                if ($currentStep >= count($steps)) $currentStep = count($steps) - 1;
            }
        } catch (Throwable $e) { $currentStep = 0; }

        $tmpl = __DIR__ . '/templates/wizard_banner.html.php';
        if (file_exists($tmpl)) { include $tmpl; return; }
        if (defined('GLPI_ROOT')) {
            foreach ([GLPI_ROOT . '/plugins/kbwizard/templates/wizard_banner.html.php', GLPI_ROOT . '/marketplace/kbwizard/templates/wizard_banner.html.php'] as $f) {
                if (file_exists($f)) { include $f; return; }
            }
        }
    } catch (Throwable $e) {
        if (class_exists('PluginKbwizardToolbox')) {
            PluginKbwizardToolbox::log('post_show_item erro: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        } else {
            if (function_exists('error_log')) error_log('[kbwizard] post_show_item erro: ' . $e->getMessage());
            try { if (class_exists('Toolbox')) Toolbox::logInFile('kbwizard', 'post_show_item erro: '.$e->getMessage()."\n".$e->getTraceAsString()); } catch(Throwable $e2){}
        }
    }
}

/**
 * Hook item_add/item_update: artigo ou passo manual alterado entra na fila
 * de sincronização do Google Drive. Nunca deve derrubar a requisição.
 *
 * @param mixed $item
 */
function plugin_kbwizard_item_changed($item) {
    try {
        if (!is_object($item)) {
            return;
        }
        if ($item instanceof KnowbaseItem) {
            $kbId = (int)$item->getID();
            if ($kbId > 0) {
                PluginKbwizardDriveSync::markDirty($kbId, 'artigo alterado');
            }
            return;
        }
        if ($item instanceof PluginKbwizardStep) {
            PluginKbwizardDriveSync::markDirtyByStep($item);
        }
    } catch (Throwable $e) {
        if (class_exists('PluginKbwizardToolbox')) {
            PluginKbwizardToolbox::log('item_changed hook erro: ' . $e->getMessage());
        }
    }
}

/**
 * Hook item_purge: remove os arquivos espelho do Drive.
 *
 * @param mixed $item
 */
function plugin_kbwizard_item_purged($item) {
    try {
        if ($item instanceof KnowbaseItem) {
            $kbId = (int)$item->getID();
            if ($kbId > 0) {
                PluginKbwizardDriveSync::purgeItem($kbId);
            }
        }
    } catch (Throwable $e) {
        if (class_exists('PluginKbwizardToolbox')) {
            PluginKbwizardToolbox::log('item_purged hook erro: ' . $e->getMessage());
        }
    }
}
