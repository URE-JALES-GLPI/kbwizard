/**
 * KB Wizard - Lógica do Passo a Passo - FIX 1.0.1
 * Corrige carregamento infinito: sem MutationObserver pesado, init defensivo
 */
var KBWizard = (function () {
  'use strict';

  var steps = [];
  var current = 0;
  var total = 0;
  var kbId = 0;
  var allowJump = true;
  var showProgress = true;
  var overlay = null;
  var progressFill = null;
  var progressText = null;
  var stepCounter = null;
  var stepTitle = null;
  var stepContent = null;
  var stepList = null;
  var nextBtn = null, prevBtn = null, finishBtn = null, exitBtn = null;
  var _inited = false;
  var _bound = false;

  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

  function log() {
    try { console.log.apply(console, ['[KBWizard]'].concat([].slice.call(arguments))); } catch(e){}
  }

  function init() {
    if (_inited) return;
    var dataEl = qs('#kbwizard-data');
    if (!dataEl) {
      // Não é artigo com wizard, silencioso
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

    if (!_bound) {
      bindEvents();
      _bound = true;
    }
    renderSidebar();
    _inited = true;
    log('init ok', {kbId: kbId, total: total, current: current});
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
          ans.scrollIntoView({ behavior: 'smooth', block: 'start' });
          var oldBg = ans.style.background;
          ans.style.transition = 'background .6s';
          ans.style.background = '#fef9c3';
          setTimeout(function(){ ans.style.background = oldBg; }, 1200);
        }
      });
    }
    if (nextBtn) nextBtn.addEventListener('click', next);
    if (prevBtn) prevBtn.addEventListener('click', prev);
    if (finishBtn) finishBtn.addEventListener('click', finish);

    document.addEventListener('keydown', function (e) {
      if (!overlay || overlay.style.display === 'none') return;
      if (e.key === 'Escape') close();
      if (e.key === 'ArrowRight') next();
      if (e.key === 'ArrowLeft') prev();
    });

    if (overlay) {
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) close();
      });
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

  function renderSidebar() {
    if (!stepList) return;
    stepList.innerHTML = '';
    for (var idx=0; idx<steps.length; idx++) {
      (function(s, i){
        var li = document.createElement('li');
        li.setAttribute('data-idx', i);
        if (i === current) li.classList.add('active');
        if (i < current) li.classList.add('completed');
        li.innerHTML = '<span class="kbwizard-step-num">' + (i + 1) + '</span><span class="kbwizard-step-label">' + escapeHtml(s.title) + '</span>';
        if (allowJump) {
          li.addEventListener('click', function(){ goTo(i); });
          li.title = 'Ir para passo ' + (i + 1);
          li.style.cursor = 'pointer';
        } else {
          if (i > current) {
            li.style.opacity = '0.55';
            li.style.cursor = 'not-allowed';
          } else {
            li.addEventListener('click', function(){ goTo(i); });
            li.style.cursor = 'pointer';
          }
        }
        stepList.appendChild(li);
      })(steps[idx], idx);
    }
  }

  function renderStep() {
    if (!steps[current]) return;
    var s = steps[current];
    if (stepTitle) stepTitle.textContent = s.title || ('Passo ' + (current + 1));
    if (stepContent) {
      stepContent.innerHTML = s.content;
      // links externos em nova aba - defensivo
      try {
        var links = stepContent.querySelectorAll('a');
        for (var i=0;i<links.length;i++) {
          var a = links[i];
          if (a.hostname && a.hostname !== location.hostname) {
            a.setAttribute('target', '_blank');
            a.setAttribute('rel', 'noopener');
          }
        }
      } catch(e){}
      stepContent.scrollTop = 0;
      var main = qs('.kbwizard-main');
      if (main) main.scrollTop = 0;
    }
    if (stepCounter) stepCounter.textContent = (current + 1) + ' / ' + total;
    updateProgress();
    updateButtons();
    renderSidebar();
    // salva progresso mas não bloqueia render
    try { saveProgress(false); } catch(e){ console.warn(e); }
  }

  function updateProgress() {
    var pct = Math.round(((current + 1) / total) * 100);
    if (progressFill) progressFill.style.width = pct + '%';
    if (progressText) progressText.textContent = pct + '%';
  }

  function updateButtons() {
    if (prevBtn) prevBtn.disabled = current === 0;
    if (nextBtn) {
      if (current === total - 1) {
        nextBtn.style.display = 'none';
        if (finishBtn) finishBtn.style.display = 'inline-flex';
      } else {
        nextBtn.style.display = 'inline-flex';
        if (finishBtn) finishBtn.style.display = 'none';
      }
    }
  }

  function open() {
    if (!overlay) {
      console.error('[KBWizard] overlay não encontrado ao abrir');
      return;
    }
    // Garante que init ocorreu (caso banner injetado via AJAX de aba)
    if (!_inited) init();
    overlay.style.display = 'flex';
    try { document.body.classList.add('kbwizard-open'); } catch(e){}
    document.body.style.overflow = 'hidden';
    renderStep();
    if (nextBtn) try{ nextBtn.focus(); }catch(e){}
  }

  function close() {
    if (!overlay) return;
    overlay.style.display = 'none';
    try { document.body.classList.remove('kbwizard-open'); } catch(e){}
    document.body.style.overflow = '';
  }

  var _navLock = false;
  function next() {
    if (_navLock) return;
    _navLock = true;
    setTimeout(function(){ _navLock=false; }, 350);
    if (current < total - 1) {
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
    if (!allowJump && idx > current) return;
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
      feedback.innerHTML = '<div><strong style="font-size:18px"><i class="ti ti-confetti" style="margin-right:6px"></i>Parabéns! Você concluiu todos os ' + total + ' passos!</strong><div style="margin-top:6px;opacity:.85">Guia finalizado com sucesso. O que deseja fazer agora?</div></div><div class="kbwizard-feedback-actions"><button class="btn btn-outline-success" onclick="KBWizard.reset()"><i class="ti ti-refresh" style="margin-right:4px"></i>Recomeçar</button><button class="btn btn-success" onclick="KBWizard.close()"><i class="ti ti-check" style="margin-right:4px"></i>Fechar</button></div>';
      // Garante que a box verde não fica atrás do conteúdo: esconde conteúdo longo atrás e rola para feedback
      if (stepContent) stepContent.style.opacity = '0.35';
      if (stepTitle) stepTitle.textContent = '✓ Concluído';
      if (main) { main.scrollTop = main.scrollHeight; setTimeout(function(){ try{ main.scrollTo({top: main.scrollHeight, behavior: 'smooth'}); }catch(e){ main.scrollTop = main.scrollHeight; } }, 100); }
    }
    if (finishBtn) finishBtn.style.display = 'none';
    if (nextBtn) nextBtn.style.display = 'none';
    if (prevBtn) prevBtn.style.display = 'none';
    // Atualiza sidebar para mostrar todos completos
    renderSidebar();
  }

  function saveProgress(isCompleted) {
    if (!kbId) return;
    var csrf = getCsrfToken();
    if (!csrf) {
      // sem token, tenta salvar em localStorage como fallback
      try { localStorage.setItem('kbwizard_'+kbId, current + (isCompleted?':done':'')); } catch(e){}
      return;
    }
    var formData = new FormData();
    formData.append('knowbaseitems_id', kbId);
    formData.append('current_step', current);
    formData.append('is_completed', isCompleted ? '1' : '0');
    formData.append('_glpi_csrf_token', csrf);

    var finalUrl = getPluginAjaxUrl();
    // Não logar erro se 404/instalação em subpasta - apenas warn
    fetch(finalUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r){
        if (!r.ok) throw new Error('HTTP '+r.status);
        return r.json().catch(function(){ return {}; });
      })
      .then(function(d){ log('progress salvo', d); })
      .catch(function(e){ console.warn('[KBWizard] saveProgress falhou', e.message, finalUrl); });
  }

  function reset() {
    current = 0;
    var feedback = qs('#kbwizard-step-feedback');
    if (feedback) feedback.style.display = 'none';
    if (stepContent) stepContent.style.opacity = '1';
    if (stepTitle) stepTitle.textContent = '';
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
    // FIX: prioriza CFG_GLPI.root_doc oficial do GLPI 11
    try {
      if (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.root_doc) {
        return CFG_GLPI.root_doc + '/plugins/kbwizard/ajax/progress.php';
      }
      if (typeof GLPI_CFG !== 'undefined' && GLPI_CFG.root_doc) {
        return GLPI_CFG.root_doc + '/plugins/kbwizard/ajax/progress.php';
      }
    } catch(e){}
    // Detecta via <script> ou <link> do GLPI para achar prefixo
    var scripts = document.querySelectorAll('script[src*="/plugins/"], script[src*="/marketplace/"]');
    for (var i=0;i<scripts.length;i++) {
      var src = scripts[i].getAttribute('src') || '';
      var m = src.match(/^(\/[^\/]+)?\/(plugins|marketplace)\//);
      if (m) {
        var prefix = m[1] || '';
        // testa se kbwizard está no mesmo plugins/marketplace
        if (src.indexOf('kbwizard') !== -1) {
          return prefix + m[0].replace(/\/$/, '') + '/kbwizard/ajax/progress.php'.replace('//','/');
        }
      }
    }
    if (location.pathname.indexOf('/glpi/') !== -1) return '/glpi/plugins/kbwizard/ajax/progress.php';
    if (location.pathname.indexOf('/marketplace/') !== -1) {
      // Tenta marketplace
      var base = location.pathname.split('/marketplace')[0];
      // fallback para plugins
      return (base || '') + '/plugins/kbwizard/ajax/progress.php';
    }
    if (location.pathname.indexOf('/front/') !== -1) {
      var prefix2 = location.pathname.split('/front')[0];
      // Se prefix2 contém /marketplace, ajusta
      if (prefix2.indexOf('marketplace') !== -1) {
        return prefix2.replace(/\/plugins\/.*/, '/plugins') + '/kbwizard/ajax/progress.php';
      }
      return prefix2 + '/plugins/kbwizard/ajax/progress.php';
    }
    return '/plugins/kbwizard/ajax/progress.php';
  }

  function getCsrfToken() {
    var meta = document.querySelector('meta[name="glpi_csrf_token"]');
    if (meta && meta.content) return meta.content;
    var input = document.querySelector('input[name="_glpi_csrf_token"]');
    if (input && input.value) return input.value;
    // GLPI 11 usa input[name="_glpi_csrf_token"] dentro do header, mas também window
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

  return { init: init, open: open, close: close, next: next, prev: prev, goTo: goTo, reset: reset, saveProgress: saveProgress };
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
    try {
      if (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.root_doc) return CFG_GLPI.root_doc;
      if (typeof GLPI_CFG !== 'undefined' && GLPI_CFG.root_doc) return GLPI_CFG.root_doc;
    } catch(e){}
    var scripts = document.querySelectorAll('script[src*="/plugins/"], script[src*="/marketplace/"]');
    for (var i=0;i<scripts.length;i++) {
      var src = scripts[i].getAttribute('src') || '';
      var m = src.match(/^(\/[^\/]+)?\/(plugins|marketplace)\//);
      if (m && src.indexOf('kbwizard') !== -1) {
        return (m[1]||'');
      }
    }
    if (location.pathname.indexOf('/glpi/') !== -1) return '/glpi';
    if (location.pathname.indexOf('/front/') !== -1) return location.pathname.split('/front')[0];
    return '';
  }

  function fetchAndInjectBanner() {
    // Só tenta se estiver numa página de artigo da base
    if (location.pathname.indexOf('knowbaseitem') === -1) return;
    if (document.getElementById('kbwizard-data')) return; // já injetado via PHP
    var kbId = getKbIdFromUrl();
    if (!kbId) return;
    // Evita loop
    if (window._kbwizardFetching) return;
    window._kbwizardFetching = true;
    var base = getAjaxBase();
    // tenta plugins e marketplace
    var urls = [base + '/plugins/kbwizard/ajax/get_steps.php?knowbaseitems_id='+kbId, base + '/marketplace/kbwizard/ajax/get_steps.php?knowbaseitems_id='+kbId];
    // remove duplicado // 
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
    // Cria estrutura mínima do banner + data + overlay (copia do wizard_banner.html.php)
    var existing = document.getElementById('kbwizard-data');
    if (existing) return;
    // Encontra container para injetar: tenta .card ou #page ou body
    var anchor = document.querySelector('.knowbaseitem, .card-body, #page, main');
    if (!anchor) anchor = document.body;

    var total = data.steps.length;
    var current = data.current || 0;
    // Cria data
    var dataDiv = document.createElement('div');
    dataDiv.id = 'kbwizard-data';
    dataDiv.style.display = 'none';
    dataDiv.setAttribute('data-kb-id', data.kb_id);
    dataDiv.setAttribute('data-steps', JSON.stringify(data.steps));
    dataDiv.setAttribute('data-current', current);
    dataDiv.setAttribute('data-allow-jump', data.allow_jump ? '1':'0');
    dataDiv.setAttribute('data-show-progress', data.show_progress ? '1':'0');
    dataDiv.setAttribute('data-require-seq', '0');
    document.body.appendChild(dataDiv);

    // Cria banner (simplificado)
    var banner = document.createElement('div');
    banner.id = 'kbwizard-banner';
    banner.className = 'card border-primary mb-3 shadow-sm';
    banner.innerHTML = '<div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">'
      + '<div class="d-flex align-items-center gap-3"><div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:48px;height:48px;"><i class="ti ti-list-check" style="font-size:24px"></i></div><div><h4 class="mb-0">Guia Passo a Passo</h4><small class="text-muted">Este artigo tem '+total+' passos. Siga no seu ritmo sem se perder!</small></div></div>'
      + '<div class="d-flex gap-2"><button id="kbwizard-start-btn" class="btn btn-primary btn-lg"><i class="ti ti-player-play me-1"></i>'+ (current>0 ? 'Continuar de onde parei ('+(current+1)+'/'+total+')' : 'Iniciar Passo a Passo') +'</button><button id="kbwizard-toggle-original" class="btn btn-outline-secondary"><i class="ti ti-article me-1"></i>Ver artigo completo</button></div></div>'
      + (data.show_progress && current>0 ? '<div class="card-footer p-0"><div class="progress" style="height:6px"><div class="progress-bar bg-success" style="width:'+Math.round((current/total)*100)+'%"></div></div></div>' : '');
    // Insere antes do anchor ou no topo da página
    var target = document.querySelector('.knowbaseitem, #main, .card');
    if (target && target.parentNode) target.parentNode.insertBefore(banner, target);
    else document.body.insertBefore(banner, document.body.firstChild);

    // Cria overlay se não existe (copia estrutura)
    if (!document.getElementById('kbwizard-overlay')) {
      var overlay = document.createElement('div');
      overlay.id = 'kbwizard-overlay';
      overlay.style.display = 'none';
      overlay.innerHTML = '<div id="kbwizard-modal" role="dialog" aria-modal="true">'
        + '<div class="kbwizard-header"><div class="kbwizard-header-left"><span class="kbwizard-badge"><i class="ti ti-list-check"></i> '+escapeHtml(data.kb_name)+'</span><span id="kbwizard-step-counter" class="kbwizard-counter">1 / '+total+'</span></div><div class="kbwizard-header-right"><button id="kbwizard-minimize" class="btn btn-sm btn-ghost" title="Minimizar"><i class="ti ti-minus"></i></button><button id="kbwizard-close" class="btn btn-sm btn-ghost" title="Fechar"><i class="ti ti-x"></i></button></div></div>'
        + (data.show_progress ? '<div class="kbwizard-progress-wrap"><div class="kbwizard-progress-bar"><div id="kbwizard-progress-fill"></div></div><span id="kbwizard-progress-text" class="kbwizard-progress-text">0%</span></div>' : '')
        + '<div class="kbwizard-body"><aside class="kbwizard-sidebar" id="kbwizard-sidebar"><div class="kbwizard-sidebar-title">Passos</div><ol id="kbwizard-step-list" class="kbwizard-step-list"></ol></aside><main class="kbwizard-main"><h2 id="kbwizard-step-title" class="kbwizard-step-title"></h2><div id="kbwizard-step-content" class="kbwizard-step-content"></div><div id="kbwizard-step-feedback" class="kbwizard-feedback" style="display:none"></div></main></div>'
        + '<div class="kbwizard-footer"><button id="kbwizard-prev" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Anterior</button><div class="kbwizard-footer-center"><button id="kbwizard-exit" class="btn btn-ghost">Sair</button></div><button id="kbwizard-next" class="btn btn-primary">Próximo <i class="ti ti-arrow-right ms-1"></i></button><button id="kbwizard-finish" class="btn btn-success" style="display:none"><i class="ti ti-check me-1"></i>Concluir</button></div></div>';
      document.body.appendChild(overlay);
    }
    function escapeHtml(str){ var d=document.createElement('div'); d.textContent=str||''; return d.innerHTML; }
    // Re-init
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
