<?php
/**
 * KB Wizard - Proxy temporário de imagens para o Google Drive
 *
 * O Google precisa baixar as imagens do artigo sem sessão para embutí-las no
 * Google Doc. Em vez de expor documentos, aceitamos apenas URLs assinadas
 * (HMAC, curta duração) geradas no momento da sincronização, e somente para
 * documentos realmente vinculados ao artigo informado.
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
    exit;
}

foreach (['toolbox', 'driveconfig', 'driveexport'] as $file) {
    $path = __DIR__ . '/../inc/' . $file . '.class.php';
    if (file_exists($path)) {
        @include_once $path;
    }
}

function plugin_kbwizard_driveimage_fail(int $status): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo $status === 403 ? 'Forbidden' : 'Not found';
    exit;
}

try {
    $docId = (int)($_GET['docid'] ?? 0);
    $kbId  = (int)($_GET['kb'] ?? 0);
    $exp   = (int)($_GET['exp'] ?? 0);
    $sig   = (string)($_GET['sig'] ?? '');

    if (!PluginKbwizardDriveExport::verifyDocToken($docId, $kbId, $exp, $sig)) {
        plugin_kbwizard_driveimage_fail(403);
    }

    global $DB;
    if (!$DB instanceof DBmysql) {
        plugin_kbwizard_driveimage_fail(404);
    }

    // O documento precisa estar anexado ao artigo; nada de ID solto.
    $linked = false;
    foreach ($DB->request([
        'FROM'  => 'glpi_documents_items',
        'WHERE' => [
            'documents_id' => $docId,
            'itemtype'     => 'KnowbaseItem',
            'items_id'     => $kbId,
        ],
        'LIMIT' => 1,
    ]) as $row) {
        $linked = true;
        break;
    }
    if (!$linked) {
        plugin_kbwizard_driveimage_fail(403);
    }

    $doc = null;
    foreach ($DB->request([
        'FROM'  => 'glpi_documents',
        'WHERE' => ['id' => $docId],
        'LIMIT' => 1,
    ]) as $row) {
        $doc = $row;
        break;
    }
    if ($doc === null || empty($doc['filepath'])) {
        plugin_kbwizard_driveimage_fail(404);
    }

    $root = rtrim(str_replace('\\', '/', (string)GLPI_DOC_DIR), '/');
    $real = realpath($root . '/' . ltrim((string)$doc['filepath'], '/\\'));
    $normalized = $real !== false ? str_replace('\\', '/', $real) : false;
    if ($normalized === false || strpos($normalized, $root . '/') !== 0 || !is_file($normalized)) {
        plugin_kbwizard_driveimage_fail(404);
    }
    $path = $normalized;

    $ext = strtolower(pathinfo((string)($doc['filename'] ?? $path), PATHINFO_EXTENSION));
    $types = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
    ];
    if (!isset($types[$ext])) {
        // SVG e afins ficam de fora por segurança (XSS em conteúdo ativo).
        plugin_kbwizard_driveimage_fail(403);
    }

    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: inline; filename="' . rawurlencode(basename($path)) . '"');
    header('Cache-Control: private, max-age=1800');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');

    readfile($path);
    exit;
} catch (Throwable $e) {
    if (class_exists('PluginKbwizardToolbox')) {
        PluginKbwizardToolbox::log('driveimage erro: ' . $e->getMessage());
    }
    plugin_kbwizard_driveimage_fail(500);
}
