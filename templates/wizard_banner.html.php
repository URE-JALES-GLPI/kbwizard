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
$cssInline = '';
$cssPaths = [__DIR__ . '/../css/kbwizard.css', GLPI_ROOT . '/plugins/kbwizard/css/kbwizard.css', GLPI_ROOT . '/marketplace/kbwizard/css/kbwizard.css'];
foreach ($cssPaths as $p) { if (is_file($p)) { $cssInline = @file_get_contents($p); break; } }
$jsInline = '';
$jsPaths = [__DIR__ . '/../js/kbwizard.js', GLPI_ROOT . '/plugins/kbwizard/js/kbwizard.js', GLPI_ROOT . '/marketplace/kbwizard/js/kbwizard.js'];
foreach ($jsPaths as $p) { if (is_file($p)) { $jsInline = @file_get_contents($p); break; } }
if ($cssInline) {
    // Banner fixo no topo, sem sticky (não acompanha scroll, não sobrepõe menus)
    $cssInline .= "\n#kbwizard-banner{position:relative;top:auto;z-index:1; border-left:5px solid #206bc4; box-shadow:0 4px 16px rgba(32,107,196,.12); margin-bottom:18px;} #kbwizard-banner .btn-primary{animation:kbwizard-pulse 2s infinite} @keyframes kbwizard-pulse{0%{box-shadow:0 0 0 0 rgba(32,107,196,.4)}70%{box-shadow:0 0 0 10px rgba(32,107,196,0)}100%{box-shadow:0 0 0 0 rgba(32,107,196,0)}}";
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
     style="display:none"></div>

<div id="kbwizard-banner" class="card border-primary mb-3 shadow-sm">
  <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
      <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:48px;height:48px;">
        <i class="ti ti-list-check" style="font-size:24px"></i>
      </div>
      <div>
        <h4 class="mb-0"><?= __('Guia Passo a Passo', 'kbwizard') ?></h4>
        <small class="text-muted"><?= sprintf(__('Este artigo tem %d passos. Siga no seu ritmo sem se perder!', 'kbwizard'), $total) ?></small>
      </div>
    </div>
    <div class="d-flex gap-2">
      <button id="kbwizard-start-btn" class="btn btn-primary btn-lg">
        <i class="ti ti-player-play me-1"></i>
        <?= $currentStep > 0 ? __('Continuar de onde parei', 'kbwizard') . " (".($currentStep+1)."/$total)" : __('Iniciar Passo a Passo', 'kbwizard') ?>
      </button>
      <button id="kbwizard-toggle-original" class="btn btn-outline-secondary">
        <i class="ti ti-article me-1"></i><?= __('Ver artigo completo', 'kbwizard') ?>
      </button>
    </div>
  </div>
  <?php if ($showProgress && $currentStep > 0): ?>
  <div class="card-footer p-0">
    <div class="progress" style="height:6px">
      <div class="progress-bar bg-success" role="progressbar" style="width: <?= round(($currentStep/$total)*100) ?>%"></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Overlay do Wizard -->
<div id="kbwizard-overlay" style="display:none">
  <div id="kbwizard-modal" role="dialog" aria-modal="true">
    <div class="kbwizard-header">
      <div class="kbwizard-header-left">
        <span class="kbwizard-badge"><i class="ti ti-list-check"></i> <?= htmlspecialchars($kb->fields['name'], ENT_QUOTES, 'UTF-8') ?></span>
        <span id="kbwizard-step-counter" class="kbwizard-counter">1 / <?= $total ?></span>
      </div>
      <div class="kbwizard-header-right">
        <button id="kbwizard-minimize" class="btn btn-sm btn-ghost" title="<?= __('Minimizar', 'kbwizard') ?>"><i class="ti ti-minus"></i></button>
        <button id="kbwizard-close" class="btn btn-sm btn-ghost" title="<?= __('Fechar', 'kbwizard') ?>"><i class="ti ti-x"></i></button>
      </div>
    </div>

    <?php if ($showProgress): ?>
    <div class="kbwizard-progress-wrap">
      <div class="kbwizard-progress-bar"><div id="kbwizard-progress-fill"></div></div>
      <span id="kbwizard-progress-text" class="kbwizard-progress-text">0%</span>
    </div>
    <?php endif; ?>

    <div class="kbwizard-body">
      <aside class="kbwizard-sidebar" id="kbwizard-sidebar">
        <div class="kbwizard-sidebar-title"><?= __('Passos', 'kbwizard') ?></div>
        <ol id="kbwizard-step-list" class="kbwizard-step-list"></ol>
      </aside>
      <main class="kbwizard-main">
        <h2 id="kbwizard-step-title" class="kbwizard-step-title"></h2>
        <div id="kbwizard-step-content" class="kbwizard-step-content"></div>
        <div id="kbwizard-step-feedback" class="kbwizard-feedback" style="display:none"></div>
      </main>
    </div>

    <div class="kbwizard-footer">
      <button id="kbwizard-prev" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i><?= __('Anterior', 'kbwizard') ?></button>
      <div class="kbwizard-footer-center">
        <button id="kbwizard-exit" class="btn btn-ghost"> <?= __('Sair', 'kbwizard') ?></button>
      </div>
      <button id="kbwizard-next" class="btn btn-primary"><?= __('Próximo', 'kbwizard') ?> <i class="ti ti-arrow-right ms-1"></i></button>
      <button id="kbwizard-finish" class="btn btn-success" style="display:none"><i class="ti ti-check me-1"></i><?= __('Concluir', 'kbwizard') ?></button>
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
// Move banner para ANTES do começo do artigo (bem visível no topo)
(function(){
  function moveBannerToTop(){
    var banner=document.getElementById('kbwizard-banner');
    if(!banner) return;
    // Tenta achar o container do artigo - GLPI 11 usa .knowbaseitem, .card, #main, .asset
    var article = document.querySelector('.knowbaseitem, .knowbaseitem__content, main .card, #page .card, .card-body');
    // Procura o título do artigo (h1) ou o primeiro .card
    var target = document.querySelector('h1') || document.querySelector('.card-header') || article;
    if(target && target.parentNode){
      // Se banner já está antes, não move
      if(banner.compareDocumentPosition(target) & Node.DOCUMENT_POSITION_FOLLOWING){
        // banner está antes do target, já está no topo
      } else {
        // move banner para antes do artigo
        try{
          // Tenta inserir antes do primeiro .card ou do h1
          var container = target.closest('.card') || target.closest('#page') || document.querySelector('#page') || document.body;
          if(container && container.parentNode){
            container.parentNode.insertBefore(banner, container);
          } else {
            document.body.insertBefore(banner, document.body.firstChild);
          }
          console.log('[KBWizard] banner movido para o topo antes do artigo');
        }catch(e){ console.warn('[KBWizard] falha ao mover banner',e); }
      }
    }
    // Garante visível no viewport no load (scroll suave para topo se usuário veio de lista)
    // Não força scroll, apenas garante que banner não está escondido no fim da página
    banner.style.display='';
    banner.scrollIntoView({behavior:'smooth', block:'start'});
    setTimeout(function(){ banner.scrollIntoView({behavior:'smooth', block:'start'}); }, 800);
  }
  function log(msg){ try{ console.log('[KBWizard inline] '+msg); }catch(e){} }
  setTimeout(function(){
    moveBannerToTop();
    if(typeof KBWizard==='undefined'){
      log('ERRO CRÍTICO: KBWizard ainda undefined mesmo com inline! Verifique file_get_contents');
      var btn=document.getElementById('kbwizard-start-btn');
      if(btn) btn.innerHTML+=' <small style="color:#fee;">(JS inline falhou)</small>';
    } else {
      log('KBWizard inline carregado OK, init...');
      try{ if(KBWizard.init) KBWizard.init(); }catch(e){ console.error(e); }
      var d=document.getElementById('kbwizard-data');
      if(d){ try{ var steps=JSON.parse(d.getAttribute('data-steps')||'[]'); log('steps: '+steps.length); }catch(e){ console.error('JSON steps falhou',e); } }
    }
  }, 300);
  // Também tenta mover quando tabs AJAX carregam
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', moveBannerToTop);
  else setTimeout(moveBannerToTop, 400);
  try{ if(typeof jQuery!=='undefined') jQuery(document).on('ajaxComplete', function(){ setTimeout(moveBannerToTop, 300); }); }catch(e){}
})();
</script>
