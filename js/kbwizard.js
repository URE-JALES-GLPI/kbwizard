/**
 * KB Wizard - Lógica do Passo a Passo - v1.0.22 toolbox central
 * Fix: require_sequential, focus trap, aria, reduced-motion, finish UX + DRY webdir
 */
// Helpers centralizados (usados por KBWizard e fallback) - evitam duplicação plugins/marketplace
function kbwizardGetRootDoc() {
  try {
    var el = document.getElementById('kbwizard-data');
    if (el) {
      var rd = el.getAttribute('data-root-doc');
      if (rd !== null) return rd;
    }
    if (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.root_doc) return CFG_GLPI.root_doc;
    if (typeof GLPI_CFG !== 'undefined' && GLPI_CFG.root_doc) return GLPI_CFG.root_doc;
  } catch(e){}
  var scripts = document.querySelectorAll('script[src*="/plugins/"], script[src*="/marketplace/"]');
  for (var i=0;i<scripts.length;i++) {
    var src = scripts[i].getAttribute('src') || '';
    // Captura prefixo antes de /plugins ou /marketplace
    var m = src.match(/^(\/[^\/]+)?\/(plugins|marketplace)\//);
    if (m && src.indexOf('kbwizard') !== -1) return (m[1]||'');
  }
  if (location.pathname.indexOf('/glpi/') !== -1) return '/glpi';
  if (location.pathname.indexOf('/front/') !== -1) return location.pathname.split('/front')[0].split('/plugins')[0].split('/marketplace')[0] || '';
  return '';
}
function kbwizardGetWebDir() {
  try {
    var el2 = document.getElementById('kbwizard-data');
    if (el2) {
      var wd = el2.getAttribute('data-webdir');
      if (wd) return wd;
    }
    var scripts2 = document.querySelectorAll('script[src*="kbwizard"]');
    for (var j=0;j<scripts2.length;j++) {
      var s = scripts2[j].getAttribute('src') || '';
      var m2 = s.match(/^(\/[^\/]+)?\/(plugins|marketplace)\/kbwizard\//);
      if (m2) return (m2[1]||'') + '/' + m2[2] + '/kbwizard';
    }
  } catch(e){}
  return kbwizardGetRootDoc() + '/plugins/kbwizard';
}
function kbwizardGetOtherWebDir(webDir) {
  if (!webDir) webDir = kbwizardGetWebDir();
  if (webDir.indexOf('marketplace') !== -1) return webDir.replace('marketplace','plugins');
  return webDir.replace('plugins','marketplace');
}
var KBWizard = (function () {
  'use strict';

  var steps = [];
  var current = 0;
  var total = 0;
  var kbId = 0;
  var allowJump = true;
  var requireSequential = false;
  var showProgress = true;
  var overlay = null;
  var progressFill = null;
  var progressText = null;
  var stepCounter = null;
  var stepTitle = null;
  var stepContent = null;
  var stepList = null;
  var nextBtn = null, prevBtn = null, finishBtn = null, exitBtn = null;
  var liveRegion = null;
  var _inited = false;
  var _bound = false;
  var previouslyFocused = null;
  var _navLock = false;

  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

  function isReducedMotion() {
    try { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch(e){ return false; }
  }

  function log() {
    try { console.log.apply(console, ['[KBWizard]'].concat([].slice.call(arguments))); } catch(e){}
  }

  function announce(msg) {
    if (!liveRegion) {
      liveRegion = qs('#kbwizard-live');
      if (!liveRegion) {
        liveRegion = document.createElement('div');
        liveRegion.id = 'kbwizard-live';
        liveRegion.setAttribute('aria-live', 'polite');
        liveRegion.setAttribute('aria-atomic', 'true');
        liveRegion.className = 'visually-hidden';
        liveRegion.style.cssText = 'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;';
        document.body.appendChild(liveRegion);
      }
    }
    liveRegion.textContent = '';
    setTimeout(function(){ liveRegion.textContent = msg; }, 60);
  }

  function init() {
    if (_inited) return;
    var dataEl = qs('#kbwizard-data');
    if (!dataEl) {
      return;
    }
    try {
      var raw = dataEl.getAttribute('data-steps') || '[]';
      steps = JSON.parse(raw);
    } catch (e) {
      console.error('[KBWizard] falha ao parsear steps', e);
      steps = [];
    }
    total = steps.length;
    if (total < 2) {
      var banner = qs('#kbwizard-banner');
      if (banner) banner.style.display = 'none';
      log('menos de 2 passos, wizard desabilitado', total);
      _inited = true;
      return;
    }

    kbId = parseInt(dataEl.getAttribute('data-kb-id') || '0', 10);
    current = parseInt(dataEl.getAttribute('data-current') || '0', 10);
    if (isNaN(current) || current < 0) current = 0;
    if (current >= total) current = total - 1;
    allowJump = dataEl.getAttribute('data-allow-jump') === '1';
    showProgress = dataEl.getAttribute('data-show-progress') === '1';
    requireSequential = dataEl.getAttribute('data-require-seq') === '1';

    overlay = qs('#kbwizard-overlay');
    progressFill = qs('#kbwizard-progress-fill');
    progressText = qs('#kbwizard-progress-text');
    stepCounter = qs('#kbwizard-step-counter');
    stepTitle = qs('#kbwizard-step-title');
    stepContent = qs('#kbwizard-step-content');
    stepList = qs('#kbwizard-step-list');
    nextBtn = qs('#kbwizard-next');
    prevBtn = qs('#kbwizard-prev');
    finishBtn = qs('#kbwizard-finish');
    exitBtn = qs('#kbwizard-exit');

    if (!overlay || !stepContent) {
      console.error('[KBWizard] overlay ou stepContent não encontrado');
      return;
    }

    // Ensure modal has proper aria
    var modal = qs('#kbwizard-modal');
    if (modal) {
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      if (stepTitle) modal.setAttribute('aria-labelledby', 'kbwizard-step-title');
    }

    if (!_bound) {
      bindEvents();
      _bound = true;
    }
    renderSidebar();
    // Ensure live region exists early
    announce('');
    _inited = true;
    log('init ok', {kbId: kbId, total: total, current: current, allowJump: allowJump, requireSequential: requireSequential});
  }

  function bindEvents() {
    var startBtn = qs('#kbwizard-start-btn');
    var closeBtn = qs('#kbwizard-close');
    var minimizeBtn = qs('#kbwizard-minimize');
    var toggleOriginalBtn = qs('#kbwizard-toggle-original');

    if (startBtn) startBtn.addEventListener('click', open);
    if (closeBtn) closeBtn.addEventListener('click', close);
    if (minimizeBtn) minimizeBtn.addEventListener('click', close);
    if (exitBtn) exitBtn.addEventListener('click', close);
    if (toggleOriginalBtn) {
      toggleOriginalBtn.addEventListener('click', function () {
        var ans = findAnswerContainer();
        if (ans) {
          var behavior = isReducedMotion() ? 'auto' : 'smooth';
          try { ans.scrollIntoView({ behavior: behavior, block: 'start' }); } catch(e){ ans.scrollIntoView(); }
          var oldBg = ans.style.background;
          ans.style.transition = isReducedMotion() ? 'none' : 'background .6s';
          ans.style.background = '#fef9c3';
          setTimeout(function(){ ans.style.background = oldBg; }, 1200);
        }
      });
    }
    if (nextBtn) nextBtn.addEventListener('click', next);
    if (prevBtn) prevBtn.addEventListener('click', prev);
    if (finishBtn) finishBtn.addEventListener('click', finish);

    document.addEventListener('keydown', onKeyDown);
    if (overlay) {
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) close();
      });
    }
  }

  function onKeyDown(e) {
    if (!overlay || overlay.style.display === 'none') return;
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key === 'ArrowRight') { e.preventDefault(); next(); return; }
    if (e.key === 'ArrowLeft') { e.preventDefault(); prev(); return; }
    if (e.key === 'Home') { e.preventDefault(); goTo(0); return; }
    if (e.key === 'End') { e.preventDefault(); goTo(total - 1); return; }
    // Focus trap: Tab
    if (e.key === 'Tab') trapTab(e);
  }

  function getFocusable() {
    if (!overlay) return [];
    var sel = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    var nodes = qsa(sel, overlay);
    // filter visible
    return nodes.filter(function(el){ return el.offsetParent !== null || el === document.activeElement; });
  }

  function trapTab(e) {
    var focusable = getFocusable();
    if (focusable.length === 0) { e.preventDefault(); return; }
    var first = focusable[0];
    var last = focusable[focusable.length - 1];
    if (e.shiftKey) {
      if (document.activeElement === first) { e.preventDefault(); last.focus(); }
    } else {
      if (document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }

  function findAnswerContainer() {
    var cands = qsa('.knowbaseitem-answer, .answer, [id*="answer"], .card-body');
    if (cands.length === 0) {
      var banner = qs('#kbwizard-banner');
      if (banner && banner.nextElementSibling) return banner.nextElementSibling;
    }
    for (var i=0;i<cands.length;i++) {
      var el = cands[i];
      if (el.textContent && el.textContent.trim().length > 80) return el;
    }
    return cands[0] || null;
  }

  function canGoTo(idx) {
    if (idx < 0 || idx >= total) return false;
    if (requireSequential) {
      // só pode avançar 1 por vez, mas pode voltar livremente
      if (idx > current + 1) return false;
    }
    if (!allowJump && !requireSequential) {
      if (idx > current) return false;
    }
    return true;
  }

  function renderSidebar() {
    if (!stepList) return;
    stepList.innerHTML = '';
    stepList.setAttribute('role', 'list');
    for (var idx=0; idx<steps.length; idx++) {
      (function(s, i){
        var li = document.createElement('li');
        li.setAttribute('role', 'listitem');
        li.setAttribute('data-idx', i);
        li.setAttribute('tabindex', canGoTo(i) ? '0' : '-1');
        li.setAttribute('aria-current', i === current ? 'step' : 'false');
        if (i === current) li.classList.add('active');
        if (i < current) li.classList.add('completed');
        var disabled = !canGoTo(i);
        if (disabled) li.classList.add('disabled');
        li.innerHTML = '<span class="kbwizard-step-num" aria-hidden="true">' + (i + 1) + '</span><span class="kbwizard-step-label">' + escapeHtml(s.title) + '</span>';
        if (disabled) {
          li.style.opacity = '0.55';
          li.style.cursor = 'not-allowed';
          li.setAttribute('aria-disabled', 'true');
          li.title = requireSequential ? 'Conclua o passo atual para avançar' : 'Navegação sequencial';
        } else {
          li.title = 'Ir para passo ' + (i + 1) + (i === current ? ' (atual)' : '');
          li.style.cursor = 'pointer';
          li.addEventListener('click', function(){ goTo(i); });
          li.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); goTo(i); }
          });
        }
        stepList.appendChild(li);
      })(steps[idx], idx);
    }
  }

  function renderStep() {
    if (!steps[current]) return;
    var s = steps[current];
    if (stepTitle) {
      stepTitle.textContent = s.title || ('Passo ' + (current + 1));
      stepTitle.setAttribute('tabindex', '-1');
    }
    if (stepContent) {
      stepContent.innerHTML = s.content;
      try {
        var links = stepContent.querySelectorAll('a');
        for (var i=0;i<links.length;i++) {
          var a = links[i];
          if (a.hostname && a.hostname !== location.hostname) {
            a.setAttribute('target', '_blank');
            a.setAttribute('rel', 'noopener');
          }
        }
        // images: add loading lazy and alt fallback
        var imgs = stepContent.querySelectorAll('img');
        for (var j=0;j<imgs.length;j++) {
          if (!imgs[j].getAttribute('alt')) imgs[j].setAttribute('alt', '');
          imgs[j].setAttribute('loading', 'lazy');
        }
      } catch(e){}
      stepContent.scrollTop = 0;
      var main = qs('.kbwizard-main');
      if (main) main.scrollTop = 0;
    }
    if (stepCounter) {
      stepCounter.textContent = (current + 1) + ' / ' + total;
      stepCounter.setAttribute('aria-label', 'Passo ' + (current + 1) + ' de ' + total);
    }
    updateProgress();
    updateButtons();
    renderSidebar();
    // hide feedback if navigating back
    var feedback = qs('#kbwizard-step-feedback');
    if (feedback) feedback.style.display = 'none';
    if (stepContent) stepContent.style.opacity = '1';
    try { saveProgress(false); } catch(e){ console.warn(e); }
    // accessibility announce
    var pct = Math.round(((current + 1) / total) * 100);
    announce((s.title || ('Passo ' + (current+1))) + '. Passo ' + (current+1) + ' de ' + total + '. ' + pct + ' por cento concluído.');
    // Move focus to title for screen readers (without scrolling)
    try { if (stepTitle) stepTitle.focus({preventScroll:true}); } catch(e){ try{ stepTitle.focus(); }catch(e2){} }
  }

  function updateProgress() {
    var pct = Math.round(((current + 1) / total) * 100);
    if (progressFill) {
      progressFill.style.width = pct + '%';
      progressFill.setAttribute('aria-valuenow', String(pct));
      progressFill.setAttribute('aria-valuemin', '0');
      progressFill.setAttribute('aria-valuemax', '100');
    }
    if (progressText) {
      progressText.textContent = pct + '%';
      progressText.setAttribute('aria-label', pct + ' por cento');
    }
    var bar = qs('.kbwizard-progress-bar');
    if (bar) bar.setAttribute('aria-label', 'Progresso ' + pct + '%');
  }

  function updateButtons() {
    if (prevBtn) {
      prevBtn.disabled = current === 0;
      prevBtn.setAttribute('aria-disabled', prevBtn.disabled ? 'true' : 'false');
    }
    if (nextBtn) {
      var isLast = current === total - 1;
      nextBtn.style.display = isLast ? 'none' : 'inline-flex';
      nextBtn.setAttribute('aria-hidden', isLast ? 'true' : 'false');
      if (finishBtn) {
        finishBtn.style.display = isLast ? 'inline-flex' : 'none';
        finishBtn.setAttribute('aria-hidden', isLast ? 'false' : 'true');
      }
    }
    // also update finish visibility depends on completed state
    var feedback = qs('#kbwizard-step-feedback');
    if (feedback && feedback.style.display !== 'none') {
      if (nextBtn) nextBtn.style.display = 'none';
      if (finishBtn) finishBtn.style.display = 'none';
      if (prevBtn) prevBtn.style.display = 'none';
    }
  }

  function open() {
    if (!overlay) {
      console.error('[KBWizard] overlay não encontrado ao abrir');
      return;
    }
    if (!_inited) init();
    previouslyFocused = document.activeElement;
    overlay.style.display = 'flex';
    overlay.setAttribute('aria-hidden', 'false');
    try { document.body.classList.add('kbwizard-open'); } catch(e){}
    document.body.style.overflow = 'hidden';
    // ensure inert background for screen readers
    try {
      var page = qs('#page') || qs('main') || document.body;
      if (page && page !== document.body) page.setAttribute('aria-hidden', 'false');
    } catch(e){}
    renderStep();
    // focus first actionable element
    setTimeout(function(){
      var focusable = getFocusable();
      var target = nextBtn && nextBtn.style.display !== 'none' ? nextBtn : (finishBtn && finishBtn.style.display !== 'none' ? finishBtn : focusable[0]);
      try { if (target) target.focus(); } catch(e){}
    }, 80);
  }

  function close() {
    if (!overlay) return;
    overlay.style.display = 'none';
    overlay.setAttribute('aria-hidden', 'true');
    try { document.body.classList.remove('kbwizard-open'); } catch(e){}
    document.body.style.overflow = '';
    // restore focus
    try { if (previouslyFocused && previouslyFocused.focus) previouslyFocused.focus(); } catch(e){}
    previouslyFocused = null;
  }

  function next() {
    if (_navLock) return;
    _navLock = true;
    setTimeout(function(){ _navLock=false; }, 350);
    if (current < total - 1) {
      // enforce requireSequential: only +1 allowed, next respects it anyway
      if (!canGoTo(current+1)) { _navLock=false; return; }
      current++;
      renderStep();
    } else {
      _navLock=false;
    }
  }

  function prev() {
    if (_navLock) return;
    _navLock = true;
    setTimeout(function(){ _navLock=false; }, 350);
    if (current > 0) {
      current--;
      renderStep();
    } else {
      _navLock=false;
    }
  }

  function goTo(idx) {
    if (!canGoTo(idx)) return;
    if (idx < 0 || idx >= total) return;
    current = idx;
    renderStep();
  }

  function finish() {
    try { saveProgress(true); } catch(e){}
    var feedback = qs('#kbwizard-step-feedback');
    var main = qs('.kbwizard-main');
    if (feedback) {
      feedback.style.display = 'flex';
      feedback.setAttribute('role', 'status');
      feedback.setAttribute('aria-live', 'polite');
      feedback.innerHTML = '<div class="kbwizard-feedback-icon" aria-hidden="true">🎉</div>'
        + '<div><strong style="font-size:18px"><i class="ti ti-confetti" style="margin-right:6px" aria-hidden="true"></i>Parabéns! Você concluiu todos os ' + total + ' passos!</strong>'
        + '<div style="margin-top:6px;opacity:.85">Guia finalizado com sucesso. Seu progresso foi salvo.</div>'
        + '<div class="kbwizard-feedback-hint" style="margin-top:8px;font-size:13px;opacity:.75">Dica: use <kbd>ESC</kbd> para fechar ou recomece para revisar.</div></div>'
        + '<div class="kbwizard-feedback-actions">'
        + '<button class="btn btn-outline-success" onclick="KBWizard.reset()" aria-label="Recomeçar do primeiro passo"><i class="ti ti-refresh" style="margin-right:4px" aria-hidden="true"></i>Recomeçar</button>'
        + '<button class="btn btn-success" onclick="KBWizard.close()" aria-label="Fechar guia"><i class="ti ti-check" style="margin-right:4px" aria-hidden="true"></i>Fechar</button>'
        + '</div>';
      if (stepContent) stepContent.style.opacity = '0.35';
      if (stepTitle) stepTitle.textContent = '✓ Concluído';
      if (main) {
        var behavior = isReducedMotion() ? 'auto' : 'smooth';
        try { main.scrollTo({top: main.scrollHeight, behavior: behavior}); } catch(e){ main.scrollTop = main.scrollHeight; }
      }
      announce('Guia concluído! Você finalizou todos os ' + total + ' passos. Parabéns!');
      // confetti: add class to trigger CSS animation if not reduced motion
      if (!isReducedMotion()) {
        feedback.classList.add('kbwizard-celebrate');
        setTimeout(function(){ feedback.classList.remove('kbwizard-celebrate'); }, 1800);
      }
    }
    if (finishBtn) finishBtn.style.display = 'none';
    if (nextBtn) nextBtn.style.display = 'none';
    if (prevBtn) prevBtn.style.display = 'none';
    renderSidebar();
    // mark all as completed visually
    if (stepList) {
      var items = qsa('li', stepList);
      items.forEach(function(li){ li.classList.add('completed'); });
    }
  }

  function saveProgress(isCompleted) {
    if (!kbId) return;
    var csrf = getCsrfToken();
    if (!csrf) {
      try { localStorage.setItem('kbwizard_'+kbId, current + (isCompleted?':done':'')); } catch(e){}
      return;
    }
    var formData = new FormData();
    formData.append('knowbaseitems_id', kbId);
    formData.append('current_step', current);
    formData.append('is_completed', isCompleted ? '1' : '0');
    formData.append('_glpi_csrf_token', csrf);

    var finalUrl = getPluginAjaxUrl();
    var fallbackUrl = (function(){ try { return kbwizardGetOtherWebDir(finalUrl.replace('/ajax/progress.php','')) + '/ajax/progress.php'; } catch(e){ return null; }})();
    fetch(finalUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r){
        if (!r.ok) throw new Error('HTTP '+r.status);
        return r.json().catch(function(){ return {}; });
      })
      .then(function(d){ log('progress salvo', d); })
      .catch(function(e){
        // Tenta fallback marketplace/plugins antes de localStorage
        if (fallbackUrl && fallbackUrl !== finalUrl) {
          fetch(fallbackUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function(r2){ if(!r2.ok) throw new Error('HTTP '+r2.status); return r2.json().catch(function(){return {};}); })
            .then(function(d2){ log('progress salvo via fallback', d2); })
            .catch(function(e2){ console.warn('[KBWizard] saveProgress falhou', e.message, finalUrl, e2.message, fallbackUrl); try { localStorage.setItem('kbwizard_'+kbId, current + (isCompleted?':done':'')); } catch(e3){} });
        } else {
          console.warn('[KBWizard] saveProgress falhou', e.message, finalUrl); try { localStorage.setItem('kbwizard_'+kbId, current + (isCompleted?':done':'')); } catch(e2){}
        }
      });
  }

  function reset() {
    current = 0;
    var feedback = qs('#kbwizard-step-feedback');
    if (feedback) { feedback.style.display = 'none'; feedback.innerHTML=''; }
    if (stepContent) stepContent.style.opacity = '1';
    renderStep();
    var csrf = getCsrfToken();
    if (!csrf) {
      try { localStorage.removeItem('kbwizard_'+kbId); } catch(e){}
      return;
    }
    var fd = new FormData();
    fd.append('knowbaseitems_id', kbId);
    fd.append('action', 'reset');
    fd.append('_glpi_csrf_token', csrf);
    fetch(getPluginAjaxUrl(), { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function(){});
  }

  function getPluginAjaxUrl() {
    // Centralizado via helpers (lê data-webdir quando disponível para evitar 404 plugins vs marketplace)
    try {
      return kbwizardGetWebDir() + '/ajax/progress.php';
    } catch(e) {
      return '/plugins/kbwizard/ajax/progress.php';
    }
  }

  function getCsrfToken() {
    var meta = document.querySelector('meta[name="glpi_csrf_token"]');
    if (meta && meta.content) return meta.content;
    var input = document.querySelector('input[name="_glpi_csrf_token"]');
    if (input && input.value) return input.value;
    try {
      if (typeof window !== 'undefined' && window.glpiCsrfToken) return window.glpiCsrfToken;
    } catch(e){}
    return '';
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  return { init: init, open: open, close: close, next: next, prev: prev, goTo: goTo, reset: reset, saveProgress: saveProgress, _canGoTo: canGoTo, _isReducedMotion: isReducedMotion };
})();

// Inicialização simples e segura - com fallback AJAX se hook PHP não disparou (banner sumido)
(function () {
  function tryInit() {
    try {
      if (document.getElementById('kbwizard-data')) {
        KBWizard.init();
        return true;
      }
      return false;
    } catch(e){ console.error('[KBWizard] tryInit erro', e); return false; }
  }

  function getKbIdFromUrl() {
    try {
      var m = location.search.match(/[?&](?:id|knowbaseitems_id)=(\d+)/);
      if (m) return parseInt(m[1],10);
      var pathM = location.pathname.match(/knowbaseitem\.form\.php\/(\d+)/);
      if (pathM) return parseInt(pathM[1],10);
    } catch(e){}
    return 0;
  }

  function getAjaxBase() {
    try { return kbwizardGetRootDoc(); } catch(e){ return ''; }
  }

  function fetchAndInjectBanner() {
    if (location.pathname.indexOf('knowbaseitem') === -1) return;
    if (document.getElementById('kbwizard-data')) return;
    var kbId = getKbIdFromUrl();
    if (!kbId) return;
    if (window._kbwizardFetching) return;
    window._kbwizardFetching = true;
    // Usa helper centralizado; tenta webDir preciso primeiro, depois fallback genérico
    var base = getAjaxBase();
    var webDir = '';
    try { webDir = kbwizardGetWebDir(); } catch(e){}
    var urls = [];
    if (webDir) {
      urls.push(webDir + '/ajax/get_steps.php?knowbaseitems_id='+kbId);
      var other = kbwizardGetOtherWebDir(webDir);
      if (other !== webDir) urls.push(other + '/ajax/get_steps.php?knowbaseitems_id='+kbId);
    }
    urls.push(base + '/plugins/kbwizard/ajax/get_steps.php?knowbaseitems_id='+kbId);
    urls.push(base + '/marketplace/kbwizard/ajax/get_steps.php?knowbaseitems_id='+kbId);
    urls = urls.filter(function(v,i,a){ return a.indexOf(v)===i; });
    function tryFetch(idx) {
      if (idx >= urls.length) { window._kbwizardFetching = false; return; }
      fetch(urls[idx], {credentials:'same-origin'})
        .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
        .then(function(data){
          if (!data || !data.active || !data.steps || data.steps.length < 2) {
            console.log('[KBWizard] fallback: artigo sem wizard ativo', data);
            window._kbwizardFetching = false;
            return;
          }
          console.log('[KBWizard] fallback AJAX injetando banner', data);
          injectBanner(data);
          window._kbwizardFetching = false;
        })
        .catch(function(e){
          console.warn('[KBWizard] fallback fetch falhou, tentando próximo', urls[idx], e.message);
          tryFetch(idx+1);
        });
    }
    tryFetch(0);
  }

  function injectBanner(data) {
    var existing = document.getElementById('kbwizard-data');
    if (existing) return;
    var total = data.steps.length;
    var current = data.current || 0;
    var dataDiv = document.createElement('div');
    dataDiv.id = 'kbwizard-data';
    dataDiv.style.display = 'none';
    dataDiv.setAttribute('data-kb-id', data.kb_id);
    dataDiv.setAttribute('data-steps', JSON.stringify(data.steps));
    dataDiv.setAttribute('data-current', current);
    dataDiv.setAttribute('data-allow-jump', data.allow_jump ? '1':'0');
    dataDiv.setAttribute('data-show-progress', data.show_progress ? '1':'0');
    dataDiv.setAttribute('data-require-seq', data.require_sequential ? '1' : (data.require_seq ? '1' : '0'));
    try { dataDiv.setAttribute('data-webdir', kbwizardGetWebDir()); } catch(e){}
    try { dataDiv.setAttribute('data-root-doc', kbwizardGetRootDoc()); } catch(e){}
    document.body.appendChild(dataDiv);

    var banner = document.createElement('div');
    banner.id = 'kbwizard-banner';
    banner.className = 'card border-primary mb-3 shadow-sm';
    banner.setAttribute('role', 'region');
    banner.setAttribute('aria-label', 'Guia passo a passo');
    banner.innerHTML = '<div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">'
      + '<div class="d-flex align-items-center gap-3"><div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:48px;height:48px;" aria-hidden="true"><i class="ti ti-list-check" style="font-size:24px"></i></div><div><h4 class="mb-0">Guia Passo a Passo</h4><small class="text-muted">Este artigo tem '+total+' passos. Siga no seu ritmo sem se perder!</small></div></div>'
      + '<div class="d-flex gap-2"><button id="kbwizard-start-btn" class="btn btn-primary btn-lg" aria-label="'+ (current>0 ? 'Continuar de onde parou, passo '+(current+1)+' de '+total : 'Iniciar passo a passo, '+total+' passos') +'"><i class="ti ti-player-play me-1" aria-hidden="true"></i>'+ (current>0 ? 'Continuar de onde parei ('+(current+1)+'/'+total+')' : 'Iniciar Passo a Passo') +'</button><button id="kbwizard-toggle-original" class="btn btn-outline-secondary"><i class="ti ti-article me-1" aria-hidden="true"></i>Ver artigo completo</button></div></div>'
      + (data.show_progress && current>0 ? '<div class="card-footer p-0"><div class="progress" style="height:6px" role="progressbar" aria-valuenow="'+Math.round((current/total)*100)+'" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width:'+Math.round((current/total)*100)+'%"></div></div></div>' : '');
    var target = document.querySelector('.knowbaseitem, #main, .card');
    if (target && target.parentNode) target.parentNode.insertBefore(banner, target);
    else document.body.insertBefore(banner, document.body.firstChild);

    if (!document.getElementById('kbwizard-overlay')) {
      var overlay = document.createElement('div');
      overlay.id = 'kbwizard-overlay';
      overlay.style.display = 'none';
      overlay.setAttribute('aria-hidden', 'true');
      overlay.innerHTML = '<div id="kbwizard-modal" role="dialog" aria-modal="true" aria-labelledby="kbwizard-step-title">'
        + '<div class="kbwizard-header"><div class="kbwizard-header-left"><span class="kbwizard-badge"><i class="ti ti-list-check" aria-hidden="true"></i> '+escapeHtml(data.kb_name)+'</span><span id="kbwizard-step-counter" class="kbwizard-counter" aria-live="polite">1 / '+total+'</span></div><div class="kbwizard-header-right"><button id="kbwizard-minimize" class="btn btn-sm btn-ghost" title="Minimizar" aria-label="Minimizar"><i class="ti ti-minus" aria-hidden="true"></i></button><button id="kbwizard-close" class="btn btn-sm btn-ghost" title="Fechar" aria-label="Fechar guia"><i class="ti ti-x" aria-hidden="true"></i></button></div></div>'
        + (data.show_progress ? '<div class="kbwizard-progress-wrap" aria-hidden="true"><div class="kbwizard-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"><div id="kbwizard-progress-fill"></div></div><span id="kbwizard-progress-text" class="kbwizard-progress-text">0%</span></div>' : '')
        + '<div class="kbwizard-body"><aside class="kbwizard-sidebar" id="kbwizard-sidebar" aria-label="Lista de passos"><div class="kbwizard-sidebar-title">Passos</div><ol id="kbwizard-step-list" class="kbwizard-step-list" role="list"></ol><div class="kbwizard-sidebar-hints" style="margin-top:12px;font-size:11px;color:#64748b;line-height:1.4"><kbd>←</kbd> <kbd>→</kbd> navegar<br><kbd>ESC</kbd> fechar</div></aside><main class="kbwizard-main"><h2 id="kbwizard-step-title" class="kbwizard-step-title" tabindex="-1"></h2><div id="kbwizard-step-content" class="kbwizard-step-content"></div><div id="kbwizard-step-feedback" class="kbwizard-feedback" style="display:none" role="status" aria-live="polite"></div></main></div>'
        + '<div class="kbwizard-footer"><button id="kbwizard-prev" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Anterior</button><div class="kbwizard-footer-center"><button id="kbwizard-exit" class="btn btn-ghost">Sair</button></div><button id="kbwizard-next" class="btn btn-primary">Próximo <i class="ti ti-arrow-right ms-1" aria-hidden="true"></i></button><button id="kbwizard-finish" class="btn btn-success" style="display:none"><i class="ti ti-check me-1" aria-hidden="true"></i>Concluir</button></div></div>';
      document.body.appendChild(overlay);
    }
    function escapeHtml(str){ var d=document.createElement('div'); d.textContent=str||''; return d.innerHTML; }
    setTimeout(function(){ KBWizard.init(); }, 100);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ if(!tryInit()) fetchAndInjectBanner(); });
  } else {
    if(!tryInit()) fetchAndInjectBanner();
  }
  var checks = 0;
  var interval = setInterval(function(){
    checks++;
    if (tryInit()) { clearInterval(interval); return; }
    if (checks % 3 === 0) fetchAndInjectBanner();
    if (checks > 15) clearInterval(interval);
  }, 800);

  try {
    if (typeof jQuery !== 'undefined') {
      jQuery(document).on('ajaxComplete', function(){ setTimeout(function(){ if(!tryInit()) fetchAndInjectBanner(); }, 300); });
    }
  } catch(e){}
})();
