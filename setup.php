<?php
/**
 * KB Wizard - Passo a Passo para Base de Conhecimento
 * GLPI 11.0.6 - v1.0.8 MODO NORMAL (sem FORCE, com INLINE CSS/JS fix 404)
 */

if (!defined('PLUGIN_KBWIZARD_VERSION')) {
    define('PLUGIN_KBWIZARD_VERSION', '1.0.13');
}
if (!defined('PLUGIN_KBWIZARD_MIN_GLPI')) {
    define('PLUGIN_KBWIZARD_MIN_GLPI', '11.0');
}
if (!defined('PLUGIN_KBWIZARD_MAX_GLPI')) {
    define('PLUGIN_KBWIZARD_MAX_GLPI', '11.1');
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
    try {
        $phpDir = Plugin::getPhpDir('kbwizard');
        if (strpos($phpDir, 'marketplace') !== false) { global $CFG_GLPI; return ($CFG_GLPI['root_doc'] ?? '') . '/marketplace/kbwizard'; }
        if (strpos($phpDir, 'plugins') !== false) { global $CFG_GLPI; return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard'; }
    } catch (Throwable $e) {}
    if (defined('GLPI_ROOT')) {
        if (is_file(GLPI_ROOT . '/marketplace/kbwizard/css/kbwizard.css')) { global $CFG_GLPI; return ($CFG_GLPI['root_doc'] ?? '') . '/marketplace/kbwizard'; }
        if (is_file(GLPI_ROOT . '/plugins/kbwizard/css/kbwizard.css')) { global $CFG_GLPI; return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard'; }
    }
    global $CFG_GLPI; return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard';
}

function plugin_init_kbwizard() {
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['kbwizard'] = true;
    Plugin::registerClass('PluginKbwizardConfig', ['addtabon' => 'KnowbaseItem']);
    Plugin::registerClass('PluginKbwizardStep');
    Plugin::registerClass('PluginKbwizardProgress');
    $webDir = plugin_kbwizard_get_webdir();
    $PLUGIN_HOOKS['add_css']['kbwizard'] = $webDir . '/css/kbwizard.css';
    $PLUGIN_HOOKS['add_javascript']['kbwizard'] = $webDir . '/js/kbwizard.js';
    $PLUGIN_HOOKS['post_show_item']['kbwizard'] = 'plugin_kbwizard_post_show_item';
    $PLUGIN_HOOKS['post_show_tab']['kbwizard'] = 'plugin_kbwizard_post_show_item';
    $PLUGIN_HOOKS['config_page']['kbwizard'] = 'front/config.php';
}

function plugin_kbwizard_post_show_item($params) {
    try {
        // Fallback CSS/JS inline se add_css deu 404 (plugins vs marketplace)
        $isKbPage = false;
        if (isset($params['item']) && ($params['item'] instanceof KnowbaseItem)) $isKbPage = true;
        else if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'knowbaseitem') !== false) $isKbPage = true;
        if ($isKbPage) {
            $webDir = plugin_kbwizard_get_webdir();
            $otherDir = (strpos($webDir, 'marketplace') !== false) ? str_replace('marketplace','plugins',$webDir) : str_replace('plugins','marketplace',$webDir);
            echo Html::scriptBlock("
                (function(){
                    var webDir='". $webDir ."',otherDir='". $otherDir ."';
                    function ensureCss(){
                        var has=false; for(var i=0;i<document.styleSheets.length;i++){ try{ if(document.styleSheets[i].href && document.styleSheets[i].href.indexOf('kbwizard.css')!==-1) has=true; }catch(e){} }
                        if(!has){ var l=document.createElement('link'); l.rel='stylesheet'; l.href=webDir+'/css/kbwizard.css'; l.onerror=function(){ var l2=document.createElement('link'); l2.rel='stylesheet'; l2.href=otherDir+'/css/kbwizard.css'; document.head.appendChild(l2); }; document.head.appendChild(l); }
                    }
                    function ensureJs(){
                        if(typeof KBWizard==='undefined'){ var s=document.createElement('script'); s.src=webDir+'/js/kbwizard.js'; s.onerror=function(){ var s2=document.createElement('script'); s2.src=otherDir+'/js/kbwizard.js'; document.head.appendChild(s2); }; document.head.appendChild(s); }
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
        if (function_exists('error_log')) error_log('[kbwizard] post_show_item erro: ' . $e->getMessage());
        try { if (class_exists('Toolbox')) Toolbox::logInFile('kbwizard', 'post_show_item erro: '.$e->getMessage()."\n".$e->getTraceAsString()); } catch(Throwable $e2){}
    }
}
