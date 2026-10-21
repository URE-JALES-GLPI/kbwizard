<?php
/**
 * KB Wizard - Exportação do artigo para PDF
 *
 * Renderiza o HTML integral do artigo (campo answer) em uma página limpa de
 * impressão, removendo somente as linhas de separador ---PASSO---.
 * O PDF é gerado pelo próprio navegador (Imprimir > Salvar como PDF), o que
 * preserva acentuação, imagens e demais formatações.
 *
 * Compatível com GLPI 11/12 em plugins/ ou marketplace/.
 */

$candidates = [
    __DIR__ . "/../../../inc/includes.php",
    __DIR__ . "/../../../../inc/includes.php",
    dirname(__DIR__, 3) . "/inc/includes.php",
];
$inc = null;
foreach ($candidates as $c) {
    if (file_exists($c)) { $inc = $c; break; }
}

if ($inc) {
    include($inc);
} else {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><p>GLPI includes not found.</p></html>';
    exit;
}

// Toolbox disponível após includes.php
if (file_exists(__DIR__ . '/../inc/toolbox.class.php') && !class_exists('PluginKbwizardToolbox', false)) {
    @include_once __DIR__ . '/../inc/toolbox.class.php';
}

/**
 * Encerra a requisição com uma página de erro simples (sem layout do GLPI).
 */
function plugin_kbwizard_export_fail(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
        . '<title>KB Wizard</title>'
        . '<style>body{font-family:"Segoe UI",Roboto,Arial,sans-serif;margin:48px;color:#1f2937}'
        . 'h1{font-size:20px;margin:0 0 8px}p{color:#4b5563}</style></head><body>'
        . '<h1>Não foi possível exportar o artigo</h1>'
        . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</body></html>';
    exit;
}

// Fora do try/catch: em GLPI 12 o checkLoginUser lança RedirectException,
// que deve chegar ao kernel para redirecionar ao login (não virar erro 500).
Session::checkLoginUser();
if (!KnowbaseItem::canView()) {
    plugin_kbwizard_export_fail(403, __('Sem permissão para visualizar este artigo.', 'kbwizard'));
}

try {
    $kbId = (int)($_GET['knowbaseitems_id'] ?? $_GET['id'] ?? 0);
    if (!$kbId) {
        plugin_kbwizard_export_fail(400, __('Artigo não informado.', 'kbwizard'));
    }

    $kb = new KnowbaseItem();
    if (!$kb->getFromDB($kbId)) {
        plugin_kbwizard_export_fail(404, __('Artigo não encontrado.', 'kbwizard'));
    }
    // Respeita restrições de entidade/visibilidade do item
    if (method_exists($kb, 'canViewItem') && !$kb->canViewItem()) {
        plugin_kbwizard_export_fail(403, __('Sem permissão para visualizar este artigo.', 'kbwizard'));
    }

    $kbTitle     = trim((string)($kb->fields['name'] ?? '')) ?: sprintf(__('Artigo %d', 'kbwizard'), $kbId);
    $articleHtml = (string)($kb->fields['answer'] ?? '');

    // Remove apenas os separadores de passo, preservando o restante do HTML
    if (!class_exists('PluginKbwizardStep')) {
        $stepFile = __DIR__ . '/../inc/step.class.php';
        if (is_file($stepFile)) {
            require_once $stepFile;
        }
    }
    if (class_exists('PluginKbwizardStep')) {
        $articleHtml = PluginKbwizardStep::stripStepMarkers($articleHtml);
    }

    // Higiene: scripts do artigo não têm função na página de impressão
    $articleHtml = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $articleHtml) ?? $articleHtml;

    // Data de exportação no formato local do GLPI (fallback seguro)
    try {
        $exportedAt = Html::convDateTime(date('Y-m-d H:i:s'));
    } catch (Throwable $e) {
        $exportedAt = date('d/m/Y H:i');
    }

    // Base para URLs relativas do artigo (ex.: src="front/document.send.php?...")
    $rootDoc = class_exists('PluginKbwizardToolbox')
        ? PluginKbwizardToolbox::getRootDoc()
        : (string)($GLOBALS['CFG_GLPI']['root_doc'] ?? '');

    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $template = __DIR__ . '/../templates/export_print.html.php';
    if (!is_file($template)) {
        foreach ([
            GLPI_ROOT . '/plugins/kbwizard/templates/export_print.html.php',
            GLPI_ROOT . '/marketplace/kbwizard/templates/export_print.html.php',
        ] as $fallback) {
            if (is_file($fallback)) { $template = $fallback; break; }
        }
    }
    if (!is_file($template)) {
        plugin_kbwizard_export_fail(500, __('Template de exportação não encontrado. Reinstale o plugin.', 'kbwizard'));
    }

    include $template;
} catch (Throwable $e) {
    if (class_exists('PluginKbwizardToolbox')) {
        PluginKbwizardToolbox::log('export.php erro: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    } else {
        try { Toolbox::logInFile('kbwizard', 'export.php erro: ' . $e->getMessage() . "\n" . $e->getTraceAsString()); } catch (Throwable $e2) {}
    }
    plugin_kbwizard_export_fail(500, __('Erro interno ao gerar a exportação.', 'kbwizard'));
}
