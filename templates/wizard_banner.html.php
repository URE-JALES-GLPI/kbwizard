<?php
// $kb, $config, $steps, $currentStep já disponíveis via plugin_kbwizard_post_show_item
if (!isset($steps) || !isset($kb)) return;

$kbId = $kb->getID();
$encodedSteps = htmlspecialchars(json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
$allowJump = (int)($config->fields['allow_jump'] ?? 1);
$showProgress = (int)($config->fields['show_progress'] ?? 1);
$requireSeq = (int)($config->fields['require_sequential'] ?? 0);
$total = count($steps);

// INLINE CSS/JS para não depender de /plugins/kbwizard/... (evita 404 marketplace vs plugins)
// Usa Toolbox com cache para evitar file_get_contents a cada render (melhoria #11)
if (class_exists('PluginKbwizardToolbox')) {
    $cssInline = PluginKbwizardToolbox::getCssInline();
    $jsInline = PluginKbwizardToolbox::getJsInline();
} else {
    $cssInline = '';
    $cssPaths = [__DIR__ . '/../css/kbwizard.css', GLPI_ROOT . '/plugins/kbwizard/css/kbwizard.css', GLPI_ROOT . '/marketplace/kbwizard/css/kbwizard.css'];
    foreach ($cssPaths as $p) { if (is_file($p)) { $cssInline = @file_get_contents($p); break; } }
    $jsInline = '';
    $jsPaths = [__DIR__ . '/../js/kbwizard.js', GLPI_ROOT . '/plugins/kbwizard/js/kbwizard.js', GLPI_ROOT . '/marketplace/kbwizard/js/kbwizard.js'];
    foreach ($jsPaths as $p) { if (is_file($p)) { $jsInline = @file_get_contents($p); break; } }
}
// WebDir para JS fallback (evita lógica duplicada no browser)
$__kbwizardWebDir = class_exists('PluginKbwizardToolbox') ? PluginKbwizardToolbox::getWebDir() : (defined('PLUGIN_KBWIZARD_VERSION') ? plugin_kbwizard_get_webdir() : '');
$__kbwizardRootDoc = class_exists('PluginKbwizardToolbox') ? PluginKbwizardToolbox::getRootDoc() : ($CFG_GLPI['root_doc'] ?? '');
if ($cssInline) {
    // Banner fixo no topo, sem sticky (não acompanha scroll, não sobrepõe menus)
    // Pulse só se usuário não prefere reduced motion
    $cssInline .= "\n#kbwizard-banner{position:relative;top:auto;z-index:1; border-left:5px solid #206bc4; box-shadow:0 4px 16px rgba(32,107,196,.12); margin-bottom:18px;} @media (prefers-reduced-motion: no-preference) { #kbwizard-banner .btn-primary{animation:kbwizard-pulse 2s infinite} @keyframes kbwizard-pulse{0%{box-shadow:0 0 0 0 rgba(32,107,196,.4)}70%{box-shadow:0 0 0 10px rgba(32,107,196,0)}100%{box-shadow:0 0 0 0 rgba(32,107,196,0)}} }";
    echo '<style>'.$cssInline.'</style>';
} else {
    echo '<style>#kbwizard-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px}#kbwizard-modal{background:#fff;border-radius:12px;max-width:1100px;width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.3)} #kbwizard-banner{position:relative;}</style>';
}
?>
<div id="kbwizard-data"
     data-kb-id="<?= (int)$kbId ?>"
     data-steps='<?= $encodedSteps ?>'
     data-current="<?= (int)$currentStep ?>"
     data-allow-jump="<?= $allowJump ?>"
     data-show-progress="<?= $showProgress ?>"
     data-require-seq="<?= $requireSeq ?>"
     data-webdir="<?= htmlspecialchars($__kbwizardWebDir, ENT_QUOTES, 'UTF-8') ?>"
     data-root-doc="<?= htmlspecialchars($__kbwizardRootDoc, ENT_QUOTES, 'UTF-8') ?>"
     style="display:none"></div>

<div id="kbwizard-banner" class="card border-primary mb-3 shadow-sm" role="region" aria-label="<?= __('Guia Passo a Passo', 'kbwizard') ?>">
  <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
      <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:48px;height:48px;" aria-hidden="true">
        <i class="ti ti-list-check" style="font-size:24px"></i>
      </div>
      <div>
        <h4 class="mb-0"><?= __('Guia Passo a Passo', 'kbwizard') ?></h4>
        <small class="text-muted"><?= sprintf(__('Este artigo tem %d passos. Siga no seu ritmo sem se perder!', 'kbwizard'), $total) ?></small>
        <?php if ($requireSeq): ?>
          <small class="d-block text-warning" style="font-size:11px"><i class="ti ti-lock me-1"></i><?= __('Navegação sequencial: conclua cada passo para avançar', 'kbwizard') ?></small>
        <?php elseif (!$allowJump): ?>
          <small class="d-block text-muted" style="font-size:11px"><i class="ti ti-arrow-right me-1"></i><?= __('Avance passo a passo', 'kbwizard') ?></small>
        <?php endif; ?>
      </div>
    </div>
    <div class="d-flex gap-2">
      <button id="kbwizard-start-btn" class="btn btn-primary btn-lg" aria-label="<?= $currentStep > 0 ? sprintf(__('Continuar de onde parei - passo %d de %d', 'kbwizard'), $currentStep+1, $total) : sprintf(__('Iniciar Passo a Passo - %d passos', 'kbwizard'), $total) ?>">
        <i class="ti ti-player-play me-1" aria-hidden="true"></i>
        <?= $currentStep > 0 ? __('Continuar de onde parei', 'kbwizard') . " (".($currentStep+1)."/$total)" : __('Iniciar Passo a Passo', 'kbwizard') ?>
      </button>
      <button id="kbwizard-toggle-original" class="btn btn-outline-secondary" aria-label="<?= __('Rolar até o artigo completo', 'kbwizard') ?>">
        <i class="ti ti-article me-1" aria-hidden="true"></i><?= __('Ver artigo completo', 'kbwizard') ?>
      </button>
    </div>
  </div>
  <?php if ($showProgress && $currentStep > 0): ?>
  <div class="card-footer p-0">
    <div class="progress" style="height:6px" role="progressbar" aria-valuenow="<?= round(($currentStep/$total)*100) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= __('Progresso do guia', 'kbwizard') ?>">
      <div class="progress-bar bg-success" style="width: <?= round(($currentStep/$total)*100) ?>%"></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Overlay do Wizard -->
<div id="kbwizard-overlay" style="display:none" aria-hidden="true">
  <div id="kbwizard-modal" role="dialog" aria-modal="true" aria-labelledby="kbwizard-step-title">
    <div class="kbwizard-header">
      <div class="kbwizard-header-left">
        <span class="kbwizard-badge"><i class="ti ti-list-check" aria-hidden="true"></i> <?= htmlspecialchars($kb->fields['name'], ENT_QUOTES, 'UTF-8') ?></span>
        <span id="kbwizard-step-counter" class="kbwizard-counter" aria-live="polite" aria-atomic="true">1 / <?= $total ?></span>
      </div>
      <div class="kbwizard-header-right">
        <button id="kbwizard-minimize" class="btn btn-sm btn-ghost" title="<?= __('Minimizar', 'kbwizard') ?>" aria-label="<?= __('Minimizar', 'kbwizard') ?>"><i class="ti ti-minus" aria-hidden="true"></i></button>
        <button id="kbwizard-close" class="btn btn-sm btn-ghost" title="<?= __('Fechar', 'kbwizard') ?>" aria-label="<?= __('Fechar guia', 'kbwizard') ?>"><i class="ti ti-x" aria-hidden="true"></i></button>
      </div>
    </div>

    <?php if ($showProgress): ?>
    <div class="kbwizard-progress-wrap" aria-hidden="true">
      <div class="kbwizard-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?= __('Progresso', 'kbwizard') ?>"><div id="kbwizard-progress-fill"></div></div>
      <span id="kbwizard-progress-text" class="kbwizard-progress-text" aria-hidden="true">0%</span>
    </div>
    <?php endif; ?>

    <div class="kbwizard-body">
      <aside class="kbwizard-sidebar" id="kbwizard-sidebar" aria-label="<?= __('Lista de passos', 'kbwizard') ?>">
        <div class="kbwizard-sidebar-title"><?= __('Passos', 'kbwizard') ?></div>
        <ol id="kbwizard-step-list" class="kbwizard-step-list" role="list"></ol>
        <div class="kbwizard-sidebar-hints" style="margin-top:12px;font-size:11px;color:#64748b;line-height:1.4">
          <kbd>←</kbd> <kbd>→</kbd> <?= __('navegar', 'kbwizard') ?><br>
          <kbd>ESC</kbd> <?= __('fechar', 'kbwizard') ?>
        </div>
      </aside>
      <main class="kbwizard-main">
        <h2 id="kbwizard-step-title" class="kbwizard-step-title" tabindex="-1"></h2>
        <div id="kbwizard-step-content" class="kbwizard-step-content"></div>
        <div id="kbwizard-step-feedback" class="kbwizard-feedback" style="display:none" role="status" aria-live="polite"></div>
      </main>
    </div>

    <div class="kbwizard-footer">
      <button id="kbwizard-prev" class="btn btn-outline-secondary" aria-label="<?= __('Passo anterior', 'kbwizard') ?>"><i class="ti ti-arrow-left me-1" aria-hidden="true"></i><?= __('Anterior', 'kbwizard') ?></button>
      <div class="kbwizard-footer-center">
        <button id="kbwizard-exit" class="btn btn-ghost" aria-label="<?= __('Sair do guia', 'kbwizard') ?>"> <?= __('Sair', 'kbwizard') ?></button>
      </div>
      <button id="kbwizard-next" class="btn btn-primary" aria-label="<?= __('Próximo passo', 'kbwizard') ?>"><?= __('Próximo', 'kbwizard') ?> <i class="ti ti-arrow-right ms-1" aria-hidden="true"></i></button>
      <button id="kbwizard-finish" class="btn btn-success" style="display:none" aria-label="<?= __('Concluir guia', 'kbwizard') ?>"><i class="ti ti-check me-1" aria-hidden="true"></i><?= __('Concluir', 'kbwizard') ?></button>
    </div>
  </div>
</div>

<?php
// INLINE JS - garante que KBWizard existe mesmo se /plugins/kbwizard/js/kbwizard.js deu 404
if ($jsInline) {
    echo '<script>'.$jsInline.'</script>';
}
?>
<script>
// Banner polido: move com respeito a viewport e reduced-motion, sem scroll agressivo
(function(){
  function isReducedMotion(){
    try { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch(e){ return false; }
  }
  function isInViewport(el){
    if (!el) return false;
    var rect = el.getBoundingClientRect();
    return rect.top >= 0 && rect.top < window.innerHeight && rect.left >=0;
  }
  function moveBannerToTop(){
    var banner=document.getElementById('kbwizard-banner');
    if(!banner) return;
    var article = document.querySelector('.knowbaseitem, .knowbaseitem__content, main .card, #page .card, .card-body');
    var target = document.querySelector('h1') || document.querySelector('.card-header') || article;
    if(target && target.parentNode){
      if(banner.compareDocumentPosition(target) & Node.DOCUMENT_POSITION_FOLLOWING){
        // já antes
      } else {
        try{
          var container = target.closest('.card') || target.closest('#page') || document.querySelector('#page') || document.body;
          if(container && container.parentNode){
            container.parentNode.insertBefore(banner, container);
          } else {
            document.body.insertBefore(banner, document.body.firstChild);
          }
          console.log('[KBWizard] banner movido para o topo');
        }catch(e){ console.warn('[KBWizard] falha ao mover banner',e); }
      }
    }
    banner.style.display='';
    // Só rola se banner estiver fora da viewport e usuário ainda no topo da página (não interrompe leitura)
    // Evita scroll duplo e respeita reduced-motion
    if (!isInViewport(banner) && window.scrollY < 400) {
      var behavior = isReducedMotion() ? 'auto' : 'smooth';
      try { banner.scrollIntoView({behavior: behavior, block: 'start'}); } catch(e){ banner.scrollIntoView(); }
    }
  }
  function log(msg){ try{ console.log('[KBWizard inline] '+msg); }catch(e){} }
  var _moved = false;
  function tryMoveOnce(){
    if (_moved) return;
    // só move uma vez no load; depois só se tabs AJAX recarregarem
    _moved = true;
    moveBannerToTop();
  }
  setTimeout(function(){
    tryMoveOnce();
    if(typeof KBWizard==='undefined'){
      log('ERRO CRÍTICO: KBWizard ainda undefined mesmo com inline!');
      var btn=document.getElementById('kbwizard-start-btn');
      if(btn) btn.innerHTML+=' <small style="color:#fee;">(JS inline falhou)</small>';
    } else {
      log('KBWizard inline carregado OK, init...');
      try{ if(KBWizard.init) KBWizard.init(); }catch(e){ console.error(e); }
      var d=document.getElementById('kbwizard-data');
      if(d){ try{ var steps=JSON.parse(d.getAttribute('data-steps')||'[]'); log('steps: '+steps.length); }catch(e){ console.error('JSON steps falhou',e); } }
    }
  }, 300);
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', function(){ if(!_moved) tryMoveOnce(); });
  else setTimeout(function(){ if(!_moved) tryMoveOnce(); }, 400);
  // Para tabs AJAX: permite mover novamente mas com throttle
  var _ajaxMoved = false;
  try{ if(typeof jQuery!=='undefined') jQuery(document).on('ajaxComplete', function(){
    if (_ajaxMoved) return;
    _ajaxMoved = true;
    setTimeout(function(){ moveBannerToTop(); _ajaxMoved=false; }, 600);
  }); }catch(e){}
})();
</script>
