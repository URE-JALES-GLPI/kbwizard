<?php
/**
 * Configuração do Wizard por KnowbaseItem
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKbwizardConfig extends CommonDBTM {

    public static $rightname = 'knowbase';

    public static function getTypeName($nb = 0) {
        return _n('KB Wizard', 'KB Wizard', $nb, 'kbwizard');
    }

    public static function getIcon() {
        return "ti ti-list-check";
    }

    // --- Tab handling ---
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof KnowbaseItem) {
            if (Session::haveRight(KnowbaseItem::$rightname, UPDATE)) {
                // FIX: não quebrar se tabelas ainda não existem (plugin instalado mas não ativado)
                global $DB;
                $nb = 0;
                if (isset($_SESSION['glpishow_count_on_tabs']) && $_SESSION['glpishow_count_on_tabs']) {
                    try {
                        if ($DB && $DB->tableExists('glpi_plugin_kbwizard_steps') && $item->getID() > 0) {
                            $step = new PluginKbwizardStep();
                            // Usa answer vazio para contar apenas manuais? Melhor contar manuais direto para não pesar
                            // Conta direto na tabela para evitar parse
                            $nb = countElementsInTable(PluginKbwizardStep::getTable(), ['knowbaseitems_id' => $item->getID()]);
                        }
                    } catch (Throwable $e) {
                        $nb = 0;
                    }
                }
                return [1 => self::createTabEntry(__('Passo a Passo', 'kbwizard'), $nb, $item::getType())];
            }
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item instanceof KnowbaseItem) {
            global $DB;
            // FIX: verifica tabela existe antes de qualquer DB call
            if (!$DB || !$DB->tableExists('glpi_plugin_kbwizard_configs')) {
                echo "<div class='alert alert-warning'>".__('Tabelas do KB Wizard não encontradas. Vá em Configurar > Plugins e clique em Instalar/Ativar novamente.', 'kbwizard')."</div>";
                return false;
            }

            $config = new self();
            $id = $item->getID();
            // FIX: não usar getFromDB($id) pois id != knowbaseitems_id - usar direto getFromDBByCrit
            $found = false;
            try {
                $found = $config->getFromDBByCrit(['knowbaseitems_id' => $id]);
            } catch (Throwable $e) {
                $found = false;
            }
            if (!$found) {
                // Criar inicial em memória (não no DB ainda)
                $config->fields = [
                    'id' => 0,
                    'knowbaseitems_id' => $id,
                    'is_active' => 0,
                    'split_mode' => 'auto',
                    'auto_delimiter' => 'hr_h2',
                    'show_progress' => 1,
                    'allow_jump' => 1,
                    'require_sequential' => 0,
                    'auto_titles' => ''
                ];
            }
            self::showConfigForm($item, $config);
            // Também mostrar lista de passos manuais se modo manual
            try {
                $step = new PluginKbwizardStep();
                $step->showStepsForItem($item);
            } catch (Throwable $e) {
                echo "<div class='alert alert-danger'>Erro ao carregar passos: ".htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')."</div>";
            }
            return true;
        }
        return false;
    }

    public static function showConfigForm(KnowbaseItem $kb, PluginKbwizardConfig $config) {
        global $CFG_GLPI;

        $id = $kb->getID();
        $isActive = (int)($config->fields['is_active'] ?? 0);
        $splitMode = $config->fields['split_mode'] ?? 'auto';
        $delimiter = $config->fields['auto_delimiter'] ?? 'hr_h2';
        $showProgress = (int)($config->fields['show_progress'] ?? 1);
        $allowJump = (int)($config->fields['allow_jump'] ?? 1);
        $requireSeq = (int)($config->fields['require_sequential'] ?? 0);
        $autoTitlesRaw = $config->fields['auto_titles'] ?? '';
        $autoTitles = [];
        if (!empty($autoTitlesRaw)) {
            try {
                $decoded = json_decode($autoTitlesRaw, true);
                if (is_array($decoded)) $autoTitles = $decoded;
            } catch (Throwable $e) { $autoTitles = []; }
        }
        // Prévia para inputs editáveis (modo auto)
        $kbAnswer = $kb->fields['answer'] ?? '';
        $rawPreviewSteps = [];
        $countPreview = 0;
        try {
            $rawPreviewSteps = PluginKbwizardStep::parseAnswerToSteps($kbAnswer, $delimiter);
            $countPreview = count($rawPreviewSteps);
            // Aplica overrides só para contagem? Mantém raw para placeholder
        } catch (Throwable $e) { $rawPreviewSteps = []; $countPreview = 0; }

        // Resolve WebDir com fallback para evitar fatal se plugin não encontrado
        $webDir = '';
        try {
            $webDir = Plugin::getWebDir('kbwizard');
        } catch (Throwable $e) {
            $webDir = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard';
            if (!is_dir(GLPI_ROOT . '/plugins/kbwizard')) {
                $webDir = ($CFG_GLPI['root_doc'] ?? '') . '/marketplace/kbwizard';
            }
        }

        echo "<form method='post' action='".$webDir."/front/config.php' data-submit-once>";
        // FIX GLPI 11.06: Html::hidden com array falhava em tabs AJAX; usa input cru compatível com csrf_compliant
        echo '<input type="hidden" name="_glpi_csrf_token" value="'.Session::getNewCSRFToken().'" />';
        echo '<input type="hidden" name="knowbaseitems_id" value="'.(int)$id.'" />';
        echo '<input type="hidden" name="id" value="'.(int)($config->fields['id'] ?? 0).'" />';

        echo "<div class='card mb-3'>";
        echo "<div class='card-header'><h3><i class='ti ti-list-check me-2'></i>".__('Configuração do Passo a Passo', 'kbwizard')."</h3></div>";
        echo "<div class='card-body'>";

        echo "<div class='row g-3'>";
        // Ativar
        echo "<div class='col-md-3'>";
        echo "<label class='form-check form-switch'>";
        echo "<input type='hidden' name='is_active' value='0'>";
        echo "<input class='form-check-input' type='checkbox' name='is_active' value='1' ".($isActive ? 'checked' : '').">";
        echo "<span class='form-check-label'>".__('Ativar modo Passo a Passo neste artigo', 'kbwizard')."</span>";
        echo "</label>";
        echo "<small class='form-hint'>".__('Quando ativo, o leitor verá o botão “Iniciar Passo a Passo” no topo do artigo.', 'kbwizard')."</small>";
        echo "</div>";

        // Mostrar progresso
        echo "<div class='col-md-3'>";
        echo "<label class='form-check form-switch'>";
        echo "<input type='hidden' name='show_progress' value='0'>";
        echo "<input class='form-check-input' type='checkbox' name='show_progress' value='1' ".($showProgress ? 'checked' : '').">";
        echo "<span class='form-check-label'>".__('Barra de progresso', 'kbwizard')."</span>";
        echo "</label>";
        echo "</div>";

        // Permitir pular
        echo "<div class='col-md-3'>";
        echo "<label class='form-check form-switch'>";
        echo "<input type='hidden' name='allow_jump' value='0'>";
        echo "<input class='form-check-input' type='checkbox' name='allow_jump' value='1' ".($allowJump ? 'checked' : '').">";
        echo "<span class='form-check-label'>".__('Permitir navegar livremente', 'kbwizard')."</span>";
        echo "</label>";
        echo "<small class='form-hint'>".__('Se desativo, o leitor só avança sequencialmente.', 'kbwizard')."</small>";
        echo "</div>";

        // Exigir sequencial
        echo "<div class='col-md-3'>";
        echo "<label class='form-check form-switch'>";
        echo "<input type='hidden' name='require_sequential' value='0'>";
        echo "<input class='form-check-input' type='checkbox' name='require_sequential' value='1' ".($requireSeq ? 'checked' : '').">";
        echo "<span class='form-check-label'>".__('Exigir ordem sequencial', 'kbwizard')."</span>";
        echo "</label>";
        echo "<small class='form-hint'>".__('Se ativo, só libera o próximo passo após ver o atual.', 'kbwizard')."</small>";
        echo "</div>";

        // Modo de divisão
        echo "<div class='col-md-6'>";
        echo "<label class='form-label'>".__('Modo de divisão', 'kbwizard')."</label>";
        echo "<select name='split_mode' class='form-select' id='kbwizard_split_mode'>";
        $modes = [
            'auto'   => __('Automático - quebra pelo conteúdo do artigo', 'kbwizard'),
            'manual' => __('Manual - passos cadastrados abaixo', 'kbwizard')
        ];
        foreach ($modes as $k => $v) {
            $sel = ($k === $splitMode) ? 'selected' : '';
            echo "<option value='$k' $sel>$v</option>";
        }
        echo "</select>";
        echo "</div>";

        // Delimitador auto
        echo "<div class='col-md-6' id='kbwizard_delimiter_group'>";
        echo "<label class='form-label'>".__('Critério automático', 'kbwizard')."</label>";
        echo "<select name='auto_delimiter' class='form-select'>";
        $delims = [
            'hr'     => __('Linha horizontal <hr> (recomendado)', 'kbwizard'),
            'h2'     => __('Título <h2>', 'kbwizard'),
            'hr_h2'  => __('<hr> ou <h2> (padrão)', 'kbwizard'),
            'marker' => __('Marcador ---PASSO--- no texto', 'kbwizard')
        ];
        foreach ($delims as $k => $v) {
            $sel = ($k === $delimiter) ? 'selected' : '';
            echo "<option value='$k' $sel>$v</option>";
        }
        echo "</select>";
        echo "<small class='form-hint'>".__('Dica: no editor, insira uma linha horizontal onde cada passo deve terminar. Ou escreva <code>---PASSO---</code> para o modo marcador.', 'kbwizard')." </small>";
        echo "</div>";

        // Títulos editáveis por passo (modo auto) - titles puxados de ---PASSO--- ficam editáveis
        if ($splitMode === 'auto' && $countPreview > 0) {
            echo "<div class='col-12' id='kbwizard_titles_group'>";
            echo "<div class='card border-0 bg-light p-3 mt-2'>";
            echo "<label class='form-label mb-1'><i class='ti ti-edit me-1'></i>".__('Títulos de cada etapa (editável)', 'kbwizard')."</label>";
            echo "<small class='form-hint mb-2 d-block'>".__('Este é o título que hoje é puxado automaticamente do começo da sessão (ex: primeiras palavras ou &lt;h2&gt;). Edite abaixo para personalizar. Deixe vazio para manter o automático.', 'kbwizard')."</small>";
            foreach ($rawPreviewSteps as $idx => $s) {
                $num = $idx + 1;
                $autoTitle = $s['title'] ?? '';
                $custom = $autoTitles[$idx] ?? '';
                $excerpt = mb_strimwidth(strip_tags($s['content'] ?? ''), 0, 80, '...');
                echo "<div class='mb-2'>";
                echo "<label class='form-label small mb-1'>".sprintf(__('Passo %d', 'kbwizard'), $num)." <span class='text-muted'>— ".htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8')."</span></label>";
                echo "<div class='input-group input-group-sm'>";
                echo "<span class='input-group-text' style='min-width:42px'>#$num</span>";
                echo "<input type='text' name='auto_titles[$idx]' class='form-control' value=\"".htmlspecialchars($custom, ENT_QUOTES, 'UTF-8')."\" maxlength='255' placeholder=\"".htmlspecialchars($autoTitle, ENT_QUOTES, 'UTF-8')."\">";
                echo "</div>";
                if (!empty($custom)) {
                    echo "<small class='text-success' style='font-size:11px'><i class='ti ti-check me-1'></i>".__('Título personalizado', 'kbwizard')." — ".__('automático', 'kbwizard').": \"".htmlspecialchars($autoTitle, ENT_QUOTES, 'UTF-8')."\"</small>";
                }
                echo "</div>";
            }
            echo "</div>";
            echo "</div>";
        } elseif ($splitMode === 'auto' && $countPreview === 0) {
            echo "<div class='col-12' id='kbwizard_titles_group' style='display:none'></div>";
        }

        echo "</div>"; // row
        echo "</div>"; // card-body
        echo "<div class='card-footer d-flex justify-content-between'>";
        echo "<button type='submit' name='save_config' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>".__('Salvar', 'kbwizard')."</button>";

        // Preview count (já calculado em cima)
        $previewSteps = $rawPreviewSteps;
        // Se tiver overrides, aplica para contagem final (mesmo count)
        echo "<span class='text-muted align-self-center'>".sprintf(__('Prévia: %d passo(s) detectado(s)', 'kbwizard'), $countPreview)."</span>";
        echo "</div>";
        echo "</div>";
        echo "</form>";

        // Se modo auto, mostrar prévia dos passos
        if ($splitMode === 'auto') {
            echo "<div class='card'>";
            echo "<div class='card-header'>".__('Prévia dos Passos (modo automático)', 'kbwizard')."</div>";
            echo "<div class='card-body p-0'>";
            echo "<div class='list-group list-group-flush'>";
            foreach ($previewSteps as $idx => $s) {
                $num = $idx + 1;
                $autoTitle = $s['title'];
                $finalTitle = (!empty($autoTitles[$idx])) ? $autoTitles[$idx] : $autoTitle;
                $isCustom = !empty($autoTitles[$idx]);
                $contentStrip = strip_tags($s['content']);
                $excerpt = mb_strimwidth($contentStrip, 0, 120, '...');
                echo "<div class='list-group-item'>";
                echo "<div class='d-flex justify-content-between'><strong>Passo $num: ".htmlspecialchars($finalTitle, ENT_QUOTES, 'UTF-8')."</strong><span class='badge ".($isCustom ? 'bg-success' : 'bg-secondary')."'>".($isCustom ? __('personalizado', 'kbwizard') : "#$num")."</span></div>";
                if ($isCustom) {
                    echo "<small class='text-muted'>".__('Automático', 'kbwizard').": \"".htmlspecialchars($autoTitle, ENT_QUOTES, 'UTF-8')."\" — ".htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8')."</small>";
                } else {
                    echo "<small class='text-muted'>".htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8')."</small>";
                }
                echo "</div>";
            }
            if ($countPreview === 0) {
                echo "<div class='list-group-item text-muted'>".__('Nenhum separador encontrado. Adicione <hr> ou use o modo manual.', 'kbwizard')."</div>";
            }
            echo "</div></div></div>";
        }

        // JS toggle - sem optional chaining para compatibilidade GLPI loader (evita erro em browsers antigos da base)
        echo Html::scriptBlock("
            (function(){
                var sel = document.getElementById('kbwizard_split_mode');
                var grp = document.getElementById('kbwizard_delimiter_group');
                var titles = document.getElementById('kbwizard_titles_group');
                if(sel && grp){
                    sel.addEventListener('change', function(e){
                        var isManual = (e.target.value === 'manual');
                        grp.style.display = isManual ? 'none' : 'block';
                        if(titles) titles.style.display = isManual ? 'none' : 'block';
                    });
                    if(sel.value === 'manual'){
                        grp.style.display='none';
                        if(titles) titles.style.display='none';
                    }
                }
            })();
        ");
    }

    public function isActiveFor($knowbaseitems_id) {
        try {
            if ($this->getFromDBByCrit(['knowbaseitems_id' => $knowbaseitems_id])) {
                return (bool)$this->fields['is_active'];
            }
        } catch (Throwable $e) {}
        return false;
    }
}

