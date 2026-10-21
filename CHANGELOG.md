# Changelog

## 1.2.0 - 2026-10-21 Exportação em PDF — ATUAL
- **Feature**: novo botão **Exportar PDF** no banner do artigo (`templates/wizard_banner.html.php`, `public/js/kbwizard.js` no fallback AJAX) que abre `front/export.php?knowbaseitems_id=X` em nova aba
- **Feature**: `front/export.php` renderiza o **HTML integral do artigo** (campo `answer`) em página limpa, com permissão `knowbase`, e dispara o diálogo de impressão — o PDF é salvo pelo navegador via **Salvar como PDF**
- **Fix acentos**: página de exportação usa `header('Content-Type: text/html; charset=UTF-8')` + `<meta charset="utf-8">` e fontes do sistema; acentuação, imagens e formatação saem exatamente como no artigo
- **Separação**: `inc/step.class.php:stripStepMarkers()` remove **somente** as linhas `---PASSO---` (aceita 2/3 traços, en/em dash e entidades HTML, dentro de `<p>/<div>/<h*>/<li>`) — nenhum outro conteúdo é alterado
- **Docs**: `README.md` com seção de exportação para PDF/NotebookLM

## 1.1.1 - 2026-10-15 Hotfix fatal GLPI 12
- **Bug**: no GLPI 12 o core tipou `CommonGLPI::$rightname` como `string` e as 3 classes declaravam sem tipo → fatal de compilação em toda página (`Type of PluginKbwizardConfig::$rightname must be string (as in class CommonGLPI)`)
- **Fix**: corpo das classes em trait (`inc/config.class.php`, `inc/step.class.php`, `inc/progress.class.php`) + declaração condicional de `$rightname` (`string` no GLPI 12, sem tipo no GLPI 11) — dual 11/12 preservado, valor `knowbase` intacto
- **Compat**: `setup.php:13` bump `1.1.0 → 1.1.1`, `composer.json:6` alinhado

## 1.1.0 - 2026-10-15 Migração GLPI 12
- **Compat**: `setup.php:13` bump `1.0.22 → 1.1.0`, requirements GLPI min `11.0.0` / max `12.0.99`, PHP `>= 8.2`; `composer.json:6` alinhado
- **Compat GLPI 12 CSRF**: `setup.php:59` hook `csrf_compliant` registrado apenas em GLPI < 12 (no 12 o CheckCsrfListener valida headers); validação manual em `front/config.php`, `front/step.form.php` e `ajax/progress.php` virou defensiva (`method_exists('Session', 'checkCSRF')`)
- **Compat GLPI 12 webdir**: removidas chamadas a `Plugin::getWebDir()` (API removida no 12) em `inc/config.class.php`, `inc/step.class.php`; webdir deriva de Toolbox/root_doc
- **Assets**: `css/` e `js/` movidos para `public/css/` e `public/js/` (exigência GLPI 11+); `setup.php:70` hooks `add_css`/`add_javascript` registram caminhos relativos (`css/kbwizard.css`, `js/kbwizard.js`); fallback inline no banner tenta primeiro o caminho do router e depois o caminho com `/public/` explícito
- **Compat GLPI 12 erro fatal**: `Html::displayErrorAndDie()` (removida no 12) substituída por mensagem + redirect em `front/config.php` e `front/step.form.php`
- **Migração**: `setup.php:76` auto-migração silenciosa em plugin_init garante tabelas/colunas na primeira carga se o update foi por cópia de arquivos
- **DB**: `hook.php` CREATE TABLE usa charset/collation padrão da conexão (`DBConnection::getDefaultCharset`/`getDefaultCollation`) em vez de utf8mb4 fixo; removido bloco morto "2.0.0 checkpoint"
- **Auditoria**: assinaturas de métodos em `inc/*.class.php` verificadas — nenhuma incompatibilidade encontrada (parâmetros já compatíveis com pais tipados)
- **Sem mudança de comportamento**: wizard (split, passos, progresso) intacto; autoload legado (`inc/`) mantido — migração PSR-4 não necessária

## 1.0.22 - 2026-10-05 Fix banner lateral gigante
- **Bug**: em 1 artigo o banner ficava na direita esticado acompanhando o artigo em vez de em cima. Causa: `templates/wizard_banner.html.php` usava `querySelector('h1') || .card-header` genérico — se o primeiro h1/card era da navbar/lateral, o banner era inserido antes do card errado dentro de um parent `flex-row` e esticava na vertical. Sem `width:100%` nada impedia
- **Fix**: `css/kbwizard.css:2` `#kbwizard-banner` agora `display:block;width:100%;flex:0 0 100%;flex-basis:100%;grid-column:1/-1` + enforcement inline no template (sobrevive a cache velho)
- **Fix**: `templates/wizard_banner.html.php:150` novo `enforceBannerFullWidth()` + `findArticleCard()` — procura irmão anterior `.card` do artigo, depois seletores específicos `.knowbaseitem-answer/.knowbaseitem`, fallback prepend em `main/#page`; nunca mais `h1` genérico primeiro
- **Fix**: `js/kbwizard.js:injectBanner` mesmo alvo determinístico + estilos inline anti-flex para o fallback AJAX
- **Compat**: `setup.php:8` bump `1.0.21 → 1.0.22`, `composer.json:6` alinhado (alinha com header JS que já dizia 1.0.22)

## 1.0.21 - 2026-08-26 Fix corte tablet
- **Fix**: `css/kbwizard.css:340` tablet `768px` overlay agora `align-items:flex-start` + `overflow-y:auto` e modal `calc(100dvh - 16px)` com `margin:auto 0`, `header/progress/footer` `flex-shrink:0`, `body` flex, sidebar `120px` — botão fechar e header não cortam mais; `480px` ajustado para `dvh` e `42vw` badge
- **Compat**: `setup.php:8` bump `1.0.20 → 1.0.21`, `composer.json:6` alinhado

## 1.0.20 - 2026-08-26 Responsivo tablet/mobile
- **Fix**: `css/kbwizard.css:331` reescrita responsiva: `@media (max-width:1024px)` modal `96vw` + sidebar `220px`, `@media (max-width:768px)` overlay `8px` + `env(safe-area)`, modal `96vh`, sidebar horizontal com `scroll-snap` + `touch`, header/footer `44px` touch, banner empilha e botões `flex:1`, `@media (max-width:480px)` fontes e paddings reduzidos — proporções corrigidas em tablet
- **Compat**: `setup.php:8` bump `1.0.19 → 1.0.20`, `composer.json:6` alinhado

## 1.0.19 - 2026-08-26 Criterio apenas marcador
- **Simplificação**: `inc/config.class.php:196` campo “Critério automático” agora só exibe `Marcador ---PASSO---` (hidden `auto_delimiter=marker` + badge ativo), `hook.php:20` default `marker`, `front/config.php:80` força `marker`, `inc/config.class.php:98` default `marker` — página mais limpa, sem confusão entre `hr`/`h2`/`hr_h2`
- **Compat**: `hook.php:128` migration `1.0.19` normaliza `auto_delimiter` existentes para `marker` e garante `auto_titles`; fallback universal em `inc/step.class.php:170` mantém compatibilidade com artigos antigos em `<hr>`/`<h2>`
- **Compat**: `setup.php:8` bump `1.0.18 → 1.0.19`, `composer.json:6` alinhado

## 1.0.18 - 2026-08-26 Criterio automatico robusto
- **Fix**: `inc/step.class.php:170` fallback universal entre `---PASSO---`, `<hr>`, `<h2>` para qualquer `auto_delimiter` escolhido — se critério não gerar >=2 passos, tenta os outros automaticamente. Resolve caso “só funciona em linha horizontal” mesmo com `---PASSO---` nos artigos
- **UI**: `inc/config.class.php:200` rótulos: `hr_h2` agora `(recomendado - detecta todos)` como primeira opção, `hr` e `marker` sem “(recomendado)” confuso, hint explica fallback
- **Compat**: `setup.php:8` bump `1.0.17 → 1.0.18`, `composer.json:6` alinhado

## 1.0.17 - 2026-08-26 Fix titulos editaveis nao aplicados
- **Bug**: `auto_titles` salvos mas não exibidos no wizard porque `fieldExists` cacheado e coluna `auto_titles` ainda não criada (plugin não atualizado via UI). `front/config.php:48` agora cria coluna on-the-fly `ALTER TABLE` se faltar e só inclui `auto_titles` se coluna existe; `inc/step.class.php:58` cria coluna on-the-fly e aplica overrides sem depender de `fieldExists` cache, loga `auto_titles aplicados`
- **Compat**: `setup.php:8` bump `1.0.16 → 1.0.17`, `composer.json:6` alinhado

## 1.0.16 - 2026-08-26 Titulos em modal flutuante
- **UX**: `inc/config.class.php:215` títulos saem do card inline e vão para botão `Editar títulos das etapas (N)` + modal flutuante `kbwizard-titles-modal` (overlay fixo, `position:fixed`, `backdrop-filter`, inputs continuam dentro do `<form>`), evita página grande; `inc/config.class.php:307` JS abre/fecha modal (click overlay, `ESC`, `Fechar`), toggle junto com `split_mode`
- **Compat**: `setup.php:8` bump `1.0.15 → 1.0.16`, `composer.json:6` alinhado

## 1.0.15 - 2026-08-26 Titulos editaveis por passo (auto)
- **Novo**: `hook.php:23` coluna `auto_titles` TEXT (JSON) em `glpi_plugin_kbwizard_configs` + migration `1.0.15`
- **Admin**: `inc/config.class.php:101` decode `auto_titles`, `inc/config.class.php:215` card “Títulos de cada etapa (editável)” com `input name='auto_titles[idx]'` (placeholder = título automático extraído de `---PASSO---`, valor = personalizado), `inc/config.class.php:277` toggle mostra/esconde junto com delimitador
- **Save**: `front/config.php:48` sanitiza `auto_titles[]` (trim, 255, só não-vazios) e salva JSON
- **Render**: `inc/step.class.php:56` `getStepsForItem()` aplica overrides `auto_titles[idx]` sobre `parseAnswerToSteps()` para modo auto (mantém conteúdo, só troca título)
- **Preview**: `inc/config.class.php:261` lista mostra badge `personalizado` vs `#n` e excerpt
- **Compat**: `setup.php:8` bump `1.0.14 → 1.0.15`, `composer.json:6` alinhado

## 1.0.14 - 2026-08-26 Polished (Bugs + UX do modal)
- **Bug crítico**: `require_sequential` agora é lido em `js/kbwizard.js:14` e respeitado em `canGoTo()` — antes o atributo `data-require-seq` era injetado mas ignorado
- **Bug crítico**: `front/config.form.php:3`, `front/config.php:3`, `front/step.form.php:3` com fallback robusto `plugins/` vs `marketplace/` (mesmo padrão de `ajax/get_steps.php:5`)
- **Bug crítico**: `ajax/get_steps.php:82` agora retorna `require_sequential` no JSON para fallback AJAX do `js/kbwizard.js:473`
- **UI Admin**: `inc/config.class.php:143` adicionado toggle “Exigir ordem sequencial” (col `require_sequential` já existia mas sem UI)
- **UX Banner**: `templates/wizard_banner.html.php:17` pulse só com `prefers-reduced-motion: no-preference`; `moveBannerToTop()` agora verifica `isInViewport()` e só rola se `scrollY < 400`, sem duplo `scrollIntoView` — `setup.php:147` e `js/kbwizard.js:473` respeitam `isReducedMotion()`
- **UX Modal**: `js/kbwizard.js:30` focus trap completo (`getFocusable()` + `trapTab()`), `previouslyFocused` restaurado no `close()`, `Home`/`End` para primeiro/último passo, `aria-live` via `#kbwizard-live`, `aria-current="step"`, `tabindex` + `keydown Enter/Space` nos itens laterais, `announce()` a cada troca
- **UX Modal**: `js/kbwizard.js:238` `requireSequential` + `allowJump` combinados corretamente; `renderSidebar()` usa `disabled` + `aria-disabled`; `updateButtons()` com `aria-disabled`/`aria-hidden`
- **UX Feedback**: `js/kbwizard.js:270` conclusão com ícone 🎉, `kbwizard-celebrate` (CSS), botões Recomeçar/Fechar e `announce()` de sucesso, sidebar marca todos `completed` (Imprimir removido a pedido)
- **Acessibilidade**: `css/kbwizard.css:182` `:focus-visible`, `prefers-reduced-motion`, `forced-colors`, `print` media, `kbd` styling, `visually-hidden` helper; `templates/wizard_banner.html.php:36` `role="region"` + `aria-label`, `aria-live` no contador, `aria-hidden` no overlay, hints `<kbd>←</kbd><kbd>→</kbd>` no sidebar
- **Compat**: `setup.php:8` bump para `1.0.14`, `composer.json:6` alinhado, `templates/wizard_banner.html.php:19` inline CSS com media query

## 1.0.13 - 2026-08-26
- Inline CSS/JS fallback para evitar 404 `plugins` vs `marketplace` (`setup.php:69`, `templates/wizard_banner.html.php:13`)
- Banner com pulse e `moveBannerToTop()` (versão anterior agressiva)

## 1.0.1 - 2026-08-25 Fix carregamento infinito
- `setup.php:76` try/catch + `tableExists` antes de queries
- `js/kbwizard.js:352` removido `MutationObserver` pesado, substituído por `setInterval` 800ms + `ajaxComplete`
- `inc/config.class.php:28` + `inc/step.class.php:18` guard `tableExists`
- `ajax/progress.php:1` retorna JSON 403 em vez de HTML em falha CSRF, fallback `localStorage`
- `hook.php:15` `Migration` defensivo, `setup.php:60` `add_css`/`add_javascript` string em vez de array

## 1.0.0 - 2026-08-25
- Versão inicial para GLPI 11.06
- Modo automático (hr, h2, hr_h2, marker) e manual
- Modal wizard com progresso, navegação, persistência por usuário
- Aba de configuração dentro do KnowbaseItem
- Tabelas: configs, steps, progress
