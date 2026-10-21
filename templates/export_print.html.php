<?php
/**
 * KB Wizard - Layout de impressão/exportação em PDF
 *
 * Variáveis esperadas: $kbTitle, $articleHtml, $exportedAt (opcional), $kbId.
 * Página autônoma (sem header/footer do GLPI) para o navegador gerar um PDF
 * limpo, preservando acentos, imagens e a formatação original do artigo.
 */
if (!isset($articleHtml) || !isset($kbTitle)) {
    return;
}

$kbTitle     = (string)$kbTitle;
$articleHtml = (string)$articleHtml;
$exportedAt  = $exportedAt ?? date('d/m/Y H:i');
$kbId        = (int)($kbId ?? 0);
$rootDoc     = rtrim((string)($rootDoc ?? ''), '/') . '/';

$lang = 'pt-BR';
try {
    if (!empty($_SESSION['glpilanguage'])) {
        $lang = str_replace('_', '-', (string)$_SESSION['glpilanguage']);
    }
} catch (Throwable $e) {
    $lang = 'pt-BR';
}

$docTitle = $kbTitle . ' - Passo a Passo';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<base href="<?= htmlspecialchars($rootDoc, ENT_QUOTES, 'UTF-8') ?>">
<title><?= htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  @page {
    size: A4;
    margin: 16mm 14mm 16mm 14mm;
  }

  * { box-sizing: border-box; }

  html, body {
    margin: 0;
    padding: 0;
    background: #fff;
  }

  body {
    color: #111827;
    font-family: "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "DejaVu Sans", sans-serif;
    font-size: 12pt;
    line-height: 1.55;
    /* Preserva cores de destaque/tabelas ao imprimir */
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  .kbwizard-print-toolbar {
    position: sticky;
    top: 0;
    z-index: 10;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 18px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
  }

  .kbwizard-print-toolbar strong { font-size: 14px; }
  .kbwizard-print-toolbar small { display: block; color: #64748b; font-size: 12px; }

  .kbwizard-print-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: 0;
    border-radius: 6px;
    background: #206bc4;
    color: #fff;
    font: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
  }

  .kbwizard-print-btn:hover { background: #1a5aa6; }

  .kbwizard-print-page {
    max-width: 190mm;
    margin: 0 auto;
    padding: 18px 6mm 40px;
  }

  .kbwizard-print-title {
    margin: 0 0 4px;
    font-size: 22pt;
    line-height: 1.25;
    color: #0f172a;
  }

  .kbwizard-print-meta {
    margin: 0 0 22px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e2e8f0;
    color: #64748b;
    font-size: 9.5pt;
  }

  /* Conteúdo original do artigo */
  .kbwizard-article { overflow-wrap: break-word; }
  .kbwizard-article > :first-child { margin-top: 0; }
  .kbwizard-article h1 { font-size: 20pt; }
  .kbwizard-article h2 { font-size: 16pt; }
  .kbwizard-article h3 { font-size: 14pt; }
  .kbwizard-article h4 { font-size: 12.5pt; }
  .kbwizard-article h5,
  .kbwizard-article h6 { font-size: 11.5pt; }
  .kbwizard-article h1,
  .kbwizard-article h2,
  .kbwizard-article h3,
  .kbwizard-article h4,
  .kbwizard-article h5,
  .kbwizard-article h6 {
    margin: 1.1em 0 0.45em;
    line-height: 1.3;
    color: #0f172a;
    break-after: avoid;
  }

  .kbwizard-article p { margin: 0 0 0.75em; }
  .kbwizard-article ul,
  .kbwizard-article ol { margin: 0 0 0.85em; padding-left: 1.5em; }
  .kbwizard-article li { margin: 0.2em 0; }

  .kbwizard-article img {
    max-width: 100%;
    height: auto;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    break-inside: avoid;
  }

  .kbwizard-article table {
    width: 100%;
    margin: 0 0 1em;
    border-collapse: collapse;
    font-size: 10.5pt;
    break-inside: avoid;
  }

  .kbwizard-article th,
  .kbwizard-article td {
    padding: 6px 8px;
    border: 1px solid #cbd5e1;
    text-align: left;
    vertical-align: top;
  }

  .kbwizard-article th { background: #f1f5f9; }

  .kbwizard-article pre {
    margin: 0 0 1em;
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    background: #f8fafc;
    font-family: ui-monospace, SFMono-Regular, "Cascadia Mono", Consolas, "DejaVu Sans Mono", monospace;
    font-size: 9.5pt;
    line-height: 1.45;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    break-inside: avoid;
  }

  .kbwizard-article code {
    font-family: ui-monospace, SFMono-Regular, "Cascadia Mono", Consolas, "DejaVu Sans Mono", monospace;
    font-size: 0.92em;
  }

  .kbwizard-article pre code { font-size: inherit; }

  .kbwizard-article blockquote {
    margin: 0 0 1em;
    padding: 6px 14px;
    border-left: 3px solid #cbd5e1;
    color: #475569;
    break-inside: avoid;
  }

  .kbwizard-article hr {
    margin: 1.2em 0;
    border: 0;
    border-top: 1px solid #e2e8f0;
  }

  .kbwizard-article a { color: #0b5ed7; }

  /* Classes utilitárias mais comuns do editor do GLPI */
  .kbwizard-article .text-center { text-align: center; }
  .kbwizard-article .text-start { text-align: left; }
  .kbwizard-article .text-end { text-align: right; }
  .kbwizard-article .text-danger { color: #b91c1c; }
  .kbwizard-article .text-success { color: #15803d; }
  .kbwizard-article .text-warning { color: #b45309; }
  .kbwizard-article .text-muted { color: #64748b; }
  .kbwizard-article .fw-bold,
  .kbwizard-article .fw-semibold { font-weight: 600; }
  .kbwizard-article .fst-italic { font-style: italic; }
  .kbwizard-article .text-decoration-underline { text-decoration: underline; }

  @media print {
    .kbwizard-print-toolbar { display: none !important; }
    .kbwizard-print-page { max-width: none; padding: 0; }
    .kbwizard-article a { color: inherit; text-decoration: underline; }
  }
</style>
</head>
<body>

<div class="kbwizard-print-toolbar" role="region" aria-label="<?= htmlspecialchars(__('Exportação em PDF', 'kbwizard'), ENT_QUOTES, 'UTF-8') ?>">
  <div>
    <strong><?= htmlspecialchars($kbTitle, ENT_QUOTES, 'UTF-8') ?></strong>
    <small><?= htmlspecialchars(sprintf(__('Exportado do GLPI em %s. Na janela de impressão, escolha “Salvar como PDF”.', 'kbwizard'), $exportedAt), ENT_QUOTES, 'UTF-8') ?></small>
  </div>
  <button type="button" class="kbwizard-print-btn" onclick="window.print()">
    <?= htmlspecialchars(__('Imprimir / Salvar PDF', 'kbwizard'), ENT_QUOTES, 'UTF-8') ?>
  </button>
</div>

<main class="kbwizard-print-page">
  <h1 class="kbwizard-print-title"><?= htmlspecialchars($kbTitle, ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="kbwizard-print-meta">
    <?= htmlspecialchars(sprintf(__('Guia passo a passo · exportado em %s', 'kbwizard'), $exportedAt), ENT_QUOTES, 'UTF-8') ?>
  </p>

  <article class="kbwizard-article">
    <?= $articleHtml ?>
  </article>
</main>

<script>
(function () {
  'use strict';

  // Abre o diálogo de impressão automaticamente (o usuário confirma "Salvar como PDF").
  // Aguarda o load para que imagens do artigo já estejam renderizadas.
  var printed = false;

  // Força carregamento das imagens: o print pode ocorrer antes do lazy load
  try {
    var imgs = document.querySelectorAll('.kbwizard-article img');
    for (var i = 0; i < imgs.length; i++) {
      imgs[i].setAttribute('loading', 'eager');
    }
  } catch (e) { /* segue sem alterar */ }

  function openPrintDialog() {
    if (printed) { return; }
    printed = true;
    try { window.print(); } catch (e) { /* botão continua disponível */ }
  }

  if (document.readyState === 'complete') {
    setTimeout(openPrintDialog, 400);
  } else {
    window.addEventListener('load', function () {
      setTimeout(openPrintDialog, 400);
    });
  }
})();
</script>

</body>
</html>
