<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKbwizardStep extends CommonDBTM {

    public static $rightname = 'knowbase';

    public static function getTypeName($nb = 0) {
        return _n('Passo', 'Passos', $nb, 'kbwizard');
    }

    public static function getIcon() {
        return "ti ti-list-numbers";
    }

    /**
     * Retorna passos para um artigo, resolvendo modo auto/manual
     * @return array [['title'=>..., 'content'=>...], ...]
     */
    public function getStepsForItem($knowbaseitems_id, $answer = '', $split_mode = 'auto', $delimiter = 'hr_h2') {
        global $DB;

        // FIX: se tabela não existe, retorna parse automático sem query (evita fatal e loader infinito)
        if (!$DB || !$DB->tableExists(self::getTable())) {
            return self::parseAnswerToSteps($answer, $delimiter);
        }

        // Se manual e houver passos cadastrados, retorna eles
        if ($split_mode === 'manual') {
            try {
                $iterator = $DB->request([
                    'FROM'  => self::getTable(),
                    'WHERE' => ['knowbaseitems_id' => $knowbaseitems_id],
                    'ORDER' => ['rank ASC', 'id ASC']
                ]);
                $steps = [];
                foreach ($iterator as $row) {
                    $steps[] = [
                        'id'      => $row['id'],
                        'title'   => $row['title'] ?: sprintf(__('Passo %d', 'kbwizard'), $row['rank'] + 1),
                        'content' => $row['content'],
                        'rank'    => $row['rank']
                    ];
                }
                if (count($steps) > 0) {
                    return $steps;
                }
            } catch (Throwable $e) {
                // fallback para auto
            }
            // fallback para auto se manual vazio ou erro
        }

        // Modo automático: parseia o answer e aplica títulos editáveis (auto_titles)
        $steps = self::parseAnswerToSteps($answer, $delimiter);
        // Aplica overrides de título editáveis se existirem em glpi_plugin_kbwizard_configs.auto_titles
        try {
            if ($DB && $DB->tableExists('glpi_plugin_kbwizard_configs') && $DB->fieldExists('glpi_plugin_kbwizard_configs', 'auto_titles')) {
                $conf = new PluginKbwizardConfig();
                if ($conf->getFromDBByCrit(['knowbaseitems_id' => $knowbaseitems_id])) {
                    $raw = $conf->fields['auto_titles'] ?? '';
                    if (!empty($raw)) {
                        $overrides = json_decode($raw, true);
                        if (is_array($overrides)) {
                            foreach ($steps as $idx => &$st) {
                                if (isset($overrides[$idx]) && trim((string)$overrides[$idx]) !== '') {
                                    $st['title'] = trim((string)$overrides[$idx]);
                                }
                            }
                            unset($st);
                        }
                    }
                }
            }
        } catch (Throwable $e) {}
        return $steps;
    }

    /**
     * Converte o HTML do campo answer em passos
     */
    public static function parseAnswerToSteps($answer, $delimiter = 'hr_h2') {
        if (empty(trim((string)$answer))) {
            return [];
        }

        $answer = trim((string)$answer);
        // Log para debug (aparece em files/_log/kbwizard.log quando preview mostra 1 passo)
        // Não quebra se log falhar
        try {
            if (class_exists('Toolbox') && mb_strlen($answer) < 10000) {
                $hasHr = preg_match('/<hr[^>]*\/?>/i', $answer) ? 'SIM' : 'NAO';
                $hasMarker = (stripos($answer, 'PASSO') !== false) ? 'SIM' : 'NAO';
                $hasH2 = preg_match('/<h2[^>]*>/i', $answer) ? 'SIM' : 'NAO';
                // Só loga quando for chamado da preview (evita spam)
                // Toolbox::logInFile('kbwizard', "parse delimiter=$delimiter hasHr=$hasHr hasMarker=$hasMarker hasH2=$hasH2 len=".mb_strlen($answer)." snippet=".mb_substr(strip_tags($answer),0,150));
            }
        } catch (Throwable $e) {}

        // Normaliza marcadores que vêm embrulhados em <p> ou com &nbsp;
        // Ex: <p>---PASSO---</p> ou <p> ---PASSO--- </p> ou --- PASSO ---
        $normalizedMarker = preg_replace('/<[^>]*>\s*---\s*PASSO\s*---\s*<[^>]*>/i', '---PASSO---', $answer);
        // Também normaliza &mdash; etc
        $normalizedMarker = str_replace(['&mdash;', '&#45;', '&#8212;'], '-', $normalizedMarker);

        $chunks = [];

        if ($delimiter === 'marker') {
            // Tenta com normalizado primeiro, depois com original, com regex robusto
            // Aceita 2 ou 3 traços, com ou sem espaços: ---PASSO---, --PASSO--, --- PASSO ---
            $parts = preg_split('/-{2,3}\s*PASSO\s*-{2,3}/i', $normalizedMarker);
            if (count($parts) < 2) {
                $parts = preg_split('/-{2,3}\s*PASSO\s*-{2,3}/i', $answer);
            }
            $chunks = $parts;
        } elseif ($delimiter === 'hr') {
            // Robusto para <hr>, <hr/>, <hr />, <hr style="...">, <hr class="...">
            $parts = preg_split('/<hr\b[^>]*\/?>/i', $answer);
            $chunks = $parts;
            // Fallback: se não achou, tenta marker também (usuário pode ter digitado ---PASSO--- mesmo com hr selecionado)
            if (count($chunks) < 2 && stripos($answer, 'PASSO') !== false) {
                $parts2 = preg_split('/-{2,3}\s*PASSO\s*-{2,3}/i', $normalizedMarker);
                if (count($parts2) >= 2) $chunks = $parts2;
            }
        } elseif ($delimiter === 'h2') {
            $parts = preg_split('/(?=<h2[^>]*>)/i', $answer);
            $chunks = array_filter($parts, fn($p) => trim((string)$p) !== '');
            $chunks = array_values($chunks);
        } else { // hr_h2 - modo padrão mais inteligente: tenta hr, depois marker, depois h2
            if (preg_match('/<hr\b[^>]*\/?>/i', $answer)) {
                $chunks = preg_split('/<hr\b[^>]*\/?>/i', $answer);
            } elseif (preg_match('/-{2,3}\s*PASSO\s*-{2,3}/i', $normalizedMarker)) {
                $chunks = preg_split('/-{2,3}\s*PASSO\s*-{2,3}/i', $normalizedMarker);
            } elseif (preg_match('/<h2[^>]*>/i', $answer)) {
                $parts = preg_split('/(?=<h2[^>]*>)/i', $answer);
                $chunks = array_filter($parts, fn($p) => trim((string)$p) !== '');
                $chunks = array_values($chunks);
            } else {
                if (stripos($normalizedMarker, 'PASSO') !== false) {
                    $chunks = preg_split('/-{2,3}\s*PASSO\s*-{2,3}/i', $normalizedMarker);
                } else {
                    if (mb_strlen(strip_tags($answer)) > 2000) {
                        if (preg_match('/<h3[^>]*>/i', $answer)) {
                            $parts = preg_split('/(?=<h3[^>]*>)/i', $answer);
                            $chunks = array_values(array_filter($parts, fn($p) => trim((string)$p) !== ''));
                        } else {
                            $chunks = [$answer];
                        }
                    } else {
                        $chunks = [$answer];
                    }
                }
            }
        }

        $chunks = array_values(array_filter($chunks, fn($c) => trim(strip_tags((string)$c, '<br><p><a><ul><ol><li><code><pre><img><strong><em><b><i>')) !== '' || trim((string)$c) !== ''));
        if (count($chunks) <= 1) {
            $title = self::extractTitle($chunks[0] ?? $answer, 0);
            return [[
                'id' => 0,
                'title' => $title,
                'content' => $chunks[0] ?? $answer,
                'rank' => 0
            ]];
        }

        $steps = [];
        foreach ($chunks as $idx => $chunk) {
            $chunk = trim((string)$chunk);
            if ($chunk === '') continue;
            $title = self::extractTitle($chunk, $idx);
            $steps[] = [
                'id' => $idx,
                'title' => $title,
                'content' => $chunk,
                'rank' => $idx
            ];
        }
        return $steps;
    }

    private static function extractTitle($html, $idx) {
        $html = (string)$html;
        if (preg_match('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/i', $html, $m)) {
            return trim(strip_tags($m[1]));
        }
        if (preg_match('/^\s*<p[^>]*>\s*<(strong|b)>(.*?)<\/(strong|b)>/i', $html, $m)) {
            $t = trim(strip_tags($m[2]));
            if (mb_strlen($t) < 80) return $t;
        }
        $text = trim(strip_tags($html));
        $words = preg_split('/\s+/', $text);
        $short = implode(' ', array_slice($words, 0, 6));
        if (mb_strlen($short) > 3) {
            return $short . (count($words) > 6 ? '...' : '');
        }
        return sprintf(__('Passo %d', 'kbwizard'), $idx + 1);
    }

    // UI para listagem e CRUD de passos manuais
    public function showStepsForItem(KnowbaseItem $kb) {
        global $DB, $CFG_GLPI;

        $knowbaseitems_id = $kb->getID();

        if (!Session::haveRight(KnowbaseItem::$rightname, UPDATE)) {
            return;
        }
        if (!$DB || !$DB->tableExists(self::getTable())) {
            echo "<div class='alert alert-warning'>".__('Tabela de passos não encontrada. Reinstale o plugin.', 'kbwizard')."</div>";
            return;
        }

        // Resolve WebDir com fallback
        $webDir = '';
        try {
            $webDir = Plugin::getWebDir('kbwizard');
        } catch (Throwable $e) {
            $webDir = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard';
            if (!is_dir(GLPI_ROOT . '/plugins/kbwizard')) {
                $webDir = ($CFG_GLPI['root_doc'] ?? '') . '/marketplace/kbwizard';
            }
        }

        echo "<div class='card mt-3'>";
        echo "<div class='card-header d-flex justify-content-between align-items-center'>";
        echo "<h4 class='mb-0'><i class='ti ti-list-numbers me-2'></i>".__('Passos Manuais', 'kbwizard')."</h4>";
        echo "<small class='text-muted'>".__('Só usado se o modo for “Manual”.', 'kbwizard')."</small>";
        echo "</div>";

        echo "<div class='card-body p-0'>";
        echo "<div class='table-responsive'>";
        echo "<table class='table table-hover mb-0'>";
        echo "<thead><tr><th style='width:60px'>#</th><th>".__('Título', 'kbwizard')."</th><th>".__('Conteúdo', 'kbwizard')."</th><th style='width:140px'>".__('Ações', 'kbwizard')."</th></tr></thead><tbody>";

        try {
            $iterator = $DB->request([
                'FROM' => self::getTable(),
                'WHERE' => ['knowbaseitems_id' => $knowbaseitems_id],
                'ORDER' => ['rank ASC', 'id ASC']
            ]);
            $count = 0;
            foreach ($iterator as $row) {
                $count++;
                $id = $row['id'];
                $title = htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8');
                $excerpt = mb_strimwidth(strip_tags($row['content'] ?? ''), 0, 100, '...');
                echo "<tr>";
                echo "<td>".(int)$row['rank']." <small class='text-muted'>#$id</small></td>";
                echo "<td>".($title ?: "<em class='text-muted'>".sprintf(__('Passo %d', 'kbwizard'), $row['rank']+1)."</em>")."</td>";
                echo "<td><small>".htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8')."</small></td>";
                echo "<td>";
                echo "<a href='".$webDir."/front/step.form.php?id=$id&knowbaseitems_id=$knowbaseitems_id' class='btn btn-sm btn-outline-primary me-1' title='".__('Editar')."'><i class='ti ti-pencil'></i></a>";
                echo "<a href='".$webDir."/front/step.form.php?delete=$id&knowbaseitems_id=$knowbaseitems_id&_glpi_csrf_token=".Session::getNewCSRFToken()."' class='btn btn-sm btn-outline-danger' onclick=\"return confirm('".__('Confirma exclusão?', 'kbwizard')."')\" title='".__('Excluir')."'><i class='ti ti-trash'></i></a>";
                echo "</td>";
                echo "</tr>";
            }
            if ($count === 0) {
                echo "<tr><td colspan='4' class='text-center text-muted py-3'>".__('Nenhum passo manual cadastrado. Clique em Adicionar.', 'kbwizard')."</td></tr>";
            }
        } catch (Throwable $e) {
            echo "<tr><td colspan='4' class='text-danger'>Erro: ".htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')."</td></tr>";
        }
        echo "</tbody></table></div></div>";

        echo "<div class='card-footer'>";
        echo "<a href='".$webDir."/front/step.form.php?knowbaseitems_id=$knowbaseitems_id' class='btn btn-success'><i class='ti ti-plus me-1'></i>".__('Adicionar passo', 'kbwizard')."</a> ";
        echo "<a href='".$webDir."/front/step.form.php?populate_from_answer=$knowbaseitems_id&_glpi_csrf_token=".Session::getNewCSRFToken()."' class='btn btn-outline-secondary' title='".__('Gera passos automaticamente a partir do conteúdo atual do artigo', 'kbwizard')."'><i class='ti ti-wand me-1'></i>".__('Gerar a partir do artigo', 'kbwizard')."</a>";
        echo "</div></div>";
    }

    public function prepareInputForAdd($input) {
        if (!isset($input['rank']) || $input['rank'] === '') {
            global $DB;
            try {
                if ($DB && $DB->tableExists(self::getTable())) {
                    $max = $DB->request([
                        'SELECT' => ['MAX' => 'rank AS maxrank'],
                        'FROM' => self::getTable(),
                        'WHERE' => ['knowbaseitems_id' => $input['knowbaseitems_id']]
                    ])->current();
                    $input['rank'] = (int)($max['maxrank'] ?? -1) + 1;
                } else {
                    $input['rank'] = 0;
                }
            } catch (Throwable $e) {
                $input['rank'] = 0;
            }
        }
        return $input;
    }

    public function showForm($ID, array $options = []) {
        global $CFG_GLPI;
        $knowbaseitems_id = $options['knowbaseitems_id'] ?? $this->fields['knowbaseitems_id'] ?? 0;
        if ($ID > 0) {
            $this->getFromDB($ID);
            $knowbaseitems_id = $this->fields['knowbaseitems_id'];
        }

        $kb = new KnowbaseItem();
        $kbName = '';
        if ($knowbaseitems_id && $kb->getFromDB($knowbaseitems_id)) {
            $kbName = $kb->fields['name'];
        }

        $title = $this->fields['title'] ?? '';
        $content = $this->fields['content'] ?? '';
        $rank = $this->fields['rank'] ?? '';

        // WebDir fallback
        $webDir = '';
        try {
            $webDir = Plugin::getWebDir('kbwizard');
        } catch (Throwable $e) {
            $webDir = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard';
        }

        echo "<form method='post' action='".$webDir."/front/step.form.php'>";
        // FIX GLPI 11.06: input cru para CSRF (Html::hidden com csrf_compliant causa double-check)
        echo '<input type="hidden" name="_glpi_csrf_token" value="'.Session::getNewCSRFToken().'" />';
        if ($ID > 0) {
            echo '<input type="hidden" name="id" value="'.(int)$ID.'" />';
        }
        echo '<input type="hidden" name="knowbaseitems_id" value="'.(int)$knowbaseitems_id.'" />';

        echo "<div class='card'>";
        echo "<div class='card-header'><h3>".($ID ? __('Editar passo', 'kbwizard') : __('Novo passo', 'kbwizard'))." <small class='text-muted'>".htmlspecialchars($kbName, ENT_QUOTES, 'UTF-8')." (#$knowbaseitems_id)</small></h3></div>";
        echo "<div class='card-body'>";

        echo "<div class='row g-3'>";
        echo "<div class='col-md-2'>";
        echo "<label class='form-label'>".__('Ordem', 'kbwizard')."</label>";
        echo "<input type='number' name='rank' class='form-control' value='".(int)$rank."' min='0' placeholder='0'>";
        echo "<small class='form-hint'>".__('Menor aparece primeiro', 'kbwizard')."</small>";
        echo "</div>";
        echo "<div class='col-md-10'>";
        echo "<label class='form-label'>".__('Título do passo', 'kbwizard')." <span class='text-muted'>(".__('ex: Instalar driver', 'kbwizard').")</span></label>";
        echo "<input type='text' name='title' class='form-control' value=\"".htmlspecialchars($title, ENT_QUOTES, 'UTF-8')."\" maxlength='255' required placeholder='".__('Ex: Passo 1 - Acessar o painel', 'kbwizard')."'>";
        echo "</div>";

        echo "<div class='col-12'>";
        echo "<label class='form-label'>".__('Conteúdo', 'kbwizard')."</label>";
        $rand = mt_rand();
        echo "<textarea name='content' id='kbwizard_content_$rand' class='form-control' rows='12' style='min-height:260px'>".htmlspecialchars($content, ENT_QUOTES, 'UTF-8')."</textarea>";
        echo Html::scriptBlock("
            (function(){
                var ta = document.getElementById('kbwizard_content_$rand');
                if(!ta) return;
                // Tenta ativar tinyMCE do GLPI se existir, mas sem quebrar
                try {
                    if (typeof tinymce !== 'undefined' && tinymce.remove) {
                        try { tinymce.remove('#kbwizard_content_$rand'); } catch(e){}
                        setTimeout(function(){
                            try {
                                tinymce.init(Object.assign({}, (window.tinymce && window.tinymce.settings) ? window.tinymce.settings : {}, {
                                    selector: '#kbwizard_content_$rand',
                                    menubar: false,
                                    plugins: 'link lists table code image',
                                    toolbar: 'undo redo | bold italic | bullist numlist | link table | code'
                                }));
                            } catch(e){ console.warn('[kbwizard] tinymce init falhou', e); }
                        }, 300);
                    }
                } catch(e){ console.warn(e); }
            })();
        ");
        echo "<small class='form-hint'>".__('Pode usar imagens, listas, código, links. Seja objetivo: um passo = uma ação.', 'kbwizard')."</small>";
        echo "</div>";

        echo "</div>";
        echo "</div>";
        echo "<div class='card-footer d-flex gap-2'>";
        if ($ID) {
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>".__('Atualizar', 'kbwizard')."</button>";
        } else {
            echo "<button type='submit' name='add' value='1' class='btn btn-primary'><i class='ti ti-plus me-1'></i>".__('Criar', 'kbwizard')."</button>";
        }
        echo "<a href='".KnowbaseItem::getFormURLWithID($knowbaseitems_id)."#tab-PluginKbwizardConfig\$1' class='btn btn-outline-secondary'>".__('Cancelar', 'kbwizard')."</a>";
        echo "</div>";
        echo "</div>";
        echo "</form>";

        return true;
    }

    public function getSearchOptions() {
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => __('Passos', 'kbwizard')];
        $tab[] = ['id' => '1', 'table' => $this->getTable(), 'field' => 'title', 'name' => __('Título', 'kbwizard'), 'datatype' => 'string'];
        $tab[] = ['id' => '2', 'table' => $this->getTable(), 'field' => 'rank', 'name' => __('Ordem', 'kbwizard'), 'datatype' => 'integer'];
        $tab[] = ['id' => '3', 'table' => $this->getTable(), 'field' => 'knowbaseitems_id', 'name' => __('Artigo', 'kbwizard'), 'datatype' => 'integer'];
        return $tab;
    }
}

