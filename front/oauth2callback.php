<?php
/**
 * KB Wizard - Callback OAuth 2.0 da conta Google
 *
 * O Google redireciona o navegador do administrador para cá com ?code&state.
 * Validamos o state (CSRF), trocamos o code por refresh_token e voltamos
 * para a tela de configuração do plugin.
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

foreach (['toolbox', 'driveconfig', 'driveexport', 'driveoauth'] as $file) {
    $path = __DIR__ . '/../inc/' . $file . '.class.php';
    if (file_exists($path)) {
        @include_once $path;
    }
}

/**
 * Volta para a tela de configuração com uma mensagem.
 */
function plugin_kbwizard_oauth_redirect(string $message, bool $ok): void
{
    $_SESSION['kbwizard_drive_test'] = ['ok' => $ok, 'message' => $message];

    $rootDoc = rtrim((string)($GLOBALS['CFG_GLPI']['root_doc'] ?? ''), '/');
    $webDir = class_exists('PluginKbwizardToolbox')
        ? PluginKbwizardToolbox::getWebDir()
        : $rootDoc . '/plugins/kbwizard';

    Html::redirect($webDir . '/front/config.php');
    exit;
}

try {
    Session::checkRight('config', UPDATE);

    $state = (string)($_GET['state'] ?? '');
    $expected = (string)($_SESSION['kbwizard_oauth_state'] ?? '');
    unset($_SESSION['kbwizard_oauth_state']);

    if ($expected === '' || $state === '' || !hash_equals($expected, $state)) {
        plugin_kbwizard_oauth_redirect(
            __('Sessão OAuth expirada ou inválida. Clique em "Conectar conta Google" novamente.', 'kbwizard'),
            false
        );
    }

    if (!empty($_GET['error'])) {
        $error = (string)$_GET['error'];
        $description = (string)($_GET['error_description'] ?? '');
        plugin_kbwizard_oauth_redirect(trim($error . ': ' . $description), false);
    }

    $code = (string)($_GET['code'] ?? '');
    if ($code === '') {
        plugin_kbwizard_oauth_redirect(__('O Google não retornou o código de autorização.', 'kbwizard'), false);
    }

    $tokens = PluginKbwizardDriveOAuth::exchangeCode($code);
    $email = PluginKbwizardDriveOAuth::fetchEmail((string)$tokens['access_token']);

    $saved = PluginKbwizardDriveConfig::save([
        'auth_mode'     => 'oauth',
        'refresh_token' => (string)$tokens['refresh_token'],
        'google_email'  => $email,
    ]);

    if (!$saved) {
        plugin_kbwizard_oauth_redirect(__('Não foi possível salvar o refresh_token no banco.', 'kbwizard'), false);
    }

    plugin_kbwizard_oauth_redirect(
        sprintf(__('Conta Google conectada: %s', 'kbwizard'), $email !== '' ? $email : 'Google'),
        true
    );
} catch (Throwable $e) {
    plugin_kbwizard_oauth_redirect($e->getMessage(), false);
}
