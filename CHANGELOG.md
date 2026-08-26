# Changelog

## 1.0.14 - 2026-08-26 Polished (Bugs + UX do modal) — ATUAL
- **Bug crítico**: `require_sequential` agora é lido em `js/kbwizard.js:14` e respeitado em `canGoTo()` — antes o atributo `data-require-seq` era injetado mas ignorado
- **Bug crítico**: `front/config.form.php:3`, `front/config.php:3`, `front/step.form.php:3` com fallback robusto `plugins/` vs `marketplace/` (mesmo padrão de `ajax/get_steps.php:5`)
- **Bug crítico**: `ajax/get_steps.php:82` agora retorna `require_sequential` no JSON para fallback AJAX do `js/kbwizard.js:473`
- **UI Admin**: `inc/config.class.php:143` adicionado toggle “Exigir ordem sequencial” (col `require_sequential` já existia mas sem UI)
- **UX Banner**: `templates/wizard_banner.html.php:17` pulse só com `prefers-reduced-motion: no-preference`; `moveBannerToTop()` agora verifica `isInViewport()` e só rola se `scrollY < 400`, sem duplo `scrollIntoView` — `setup.php:147` e `js/kbwizard.js:473` respeitam `isReducedMotion()`
- **UX Modal**: `js/kbwizard.js:30` focus trap completo (`getFocusable()` + `trapTab()`), `previouslyFocused` restaurado no `close()`, `Home`/`End` para primeiro/último passo, `aria-live` via `#kbwizard-live`, `aria-current="step"`, `tabindex` + `keydown Enter/Space` nos itens laterais, `announce()` a cada troca
- **UX Modal**: `js/kbwizard.js:238` `requireSequential` + `allowJump` combinados corretamente; `renderSidebar()` usa `disabled` + `aria-disabled`; `updateButtons()` com `aria-disabled`/`aria-hidden`
- **UX Feedback**: `js/kbwizard.js:270` conclusão com ícone 🎉, `kbwizard-celebrate` (CSS), botões Recomeçar/Fechar/Imprimir, `window.print()` e `announce()` de sucesso, sidebar marca todos `completed`
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
