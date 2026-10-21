<?php
/**
 * KB Wizard - OAuth 2.0 para conta Google comum (Gmail)
 *
 * Alternativa à service account para instalações sem Google Workspace.
 * O plugin age como o próprio usuário (refresh_token salvo no banco) e usa
 * o escopo drive.file: só enxerga os arquivos que ele mesmo criou.
 *
 * Requisito no Google Cloud: cliente OAuth do tipo "Web application" com o
 * redirect URI exibido na tela de configuração. O app pode ficar "Em
 * produção" sem verificação, pois drive.file não é escopo sensível.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKbwizardDriveOAuth
{
    public const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    public const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    public const DEVICE_URL   = 'https://oauth2.googleapis.com/device/code';

    /** drive.file = não sensível (sem verificação) e limitado aos arquivos do app. */
    public const SCOPE = 'openid email https://www.googleapis.com/auth/drive.file';

    /**
     * URL de callback registrada no Google Cloud.
     */
    public static function redirectUri(): string
    {
        if (!class_exists('PluginKbwizardDriveExport')) {
            return '';
        }

        $rootDoc = rtrim((string)($GLOBALS['CFG_GLPI']['root_doc'] ?? ''), '/');
        $webDir = class_exists('PluginKbwizardToolbox')
            ? PluginKbwizardToolbox::getWebDir()
            : $rootDoc . '/plugins/kbwizard';

        // absoluteUrl() cuida de não duplicar o root_doc (baseUrl já o inclui).
        return PluginKbwizardDriveExport::absoluteUrl($webDir . '/front/oauth2callback.php');
    }

    /**
     * Monta a URL de consentimento.
     */
    public static function authUrl(string $state): string
    {
        $cfg = PluginKbwizardDriveConfig::get();
        $params = [
            'client_id'             => trim((string)($cfg['client_id'] ?? '')),
            'redirect_uri'          => self::redirectUri(),
            'response_type'         => 'code',
            'scope'                 => self::SCOPE,
            'access_type'           => 'offline',
            'prompt'                => 'consent',
            'include_granted_scopes' => 'true',
            'state'                 => $state,
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Troca o "code" por access_token + refresh_token.
     * @return array<string,mixed>
     */
    public static function exchangeCode(string $code): array
    {
        $cfg = PluginKbwizardDriveConfig::get();
        $clientId = trim((string)($cfg['client_id'] ?? ''));
        $clientSecret = trim((string)($cfg['client_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException(__('Client ID/secret não configurados.', 'kbwizard'));
        }

        $json = self::postForm(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => self::redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($json['refresh_token'])) {
            throw new RuntimeException(__(
                'O Google não retornou refresh_token. Remova o acesso do app em myaccount.google.com/permissions e conecte novamente.',
                'kbwizard'
            ));
        }
        if (empty($json['access_token'])) {
            throw new RuntimeException(__('O Google não retornou access_token.', 'kbwizard'));
        }
        return $json;
    }

    /**
     * Lê o e-mail da conta conectada.
     */
    public static function fetchEmail(string $accessToken): string
    {
        try {
            $json = self::getJson(self::USERINFO_URL, $accessToken);
            return (string)($json['email'] ?? '');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Renova o access_token a partir do refresh_token.
     * @return array<string,mixed>
     */
    public static function refresh(string $clientId, string $clientSecret, string $refreshToken): array
    {
        return self::postForm(self::TOKEN_URL, [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);
    }

    /**
     * Inicia o device flow ("TVs and limited input devices").
     * Não usa redirect URI: funciona em GLPI interno, sem HTTPS público.
     * @return array{device_code:string,user_code:string,url:string,expires_in:int,interval:int}
     */
    public static function deviceStart(): array
    {
        $cfg = PluginKbwizardDriveConfig::get();
        $clientId = trim((string)($cfg['client_id'] ?? ''));
        if ($clientId === '') {
            throw new RuntimeException(__('Informe o Client ID antes de gerar o código.', 'kbwizard'));
        }

        $json = self::postForm(self::DEVICE_URL, [
            'client_id' => $clientId,
            'scope'     => self::SCOPE,
        ]);

        $deviceCode = (string)($json['device_code'] ?? '');
        $userCode   = (string)($json['user_code'] ?? '');
        if ($deviceCode === '' || $userCode === '') {
            throw new RuntimeException(__('O Google não retornou o código do dispositivo.', 'kbwizard'));
        }

        return [
            'device_code' => $deviceCode,
            'user_code'   => $userCode,
            'url'         => (string)($json['verification_url'] ?? $json['verification_uri'] ?? 'https://www.google.com/device'),
            'expires_in'  => (int)($json['expires_in'] ?? 1800),
            'interval'    => max(5, (int)($json['interval'] ?? 5)),
        ];
    }

    /**
     * Consulta o estado do device flow. Retorna os tokens quando autorizado.
     * @return array{pending:bool,tokens?:array<string,mixed>}
     */
    public static function devicePoll(string $deviceCode): array
    {
        $cfg = PluginKbwizardDriveConfig::get();
        [$status, $json] = self::postFormRaw(self::TOKEN_URL, [
            'client_id'     => trim((string)($cfg['client_id'] ?? '')),
            'client_secret' => trim((string)($cfg['client_secret'] ?? '')),
            'device_code'   => $deviceCode,
            'grant_type'    => 'urn:ietf:params:oauth:grant-type:device_code',
        ]);

        if ($status >= 200 && $status < 300 && !empty($json['refresh_token'])) {
            return ['pending' => false, 'tokens' => $json];
        }

        $error = (string)($json['error'] ?? '');
        if ($error === 'authorization_pending' || $error === 'slow_down') {
            return ['pending' => true];
        }

        $message = (string)($json['error_description'] ?? $error);
        if ($error === 'access_denied') {
            $message = __('Acesso negado na conta Google.', 'kbwizard');
        } elseif ($error === 'expired_token') {
            $message = __('O código expirou. Gere um novo.', 'kbwizard');
        } elseif ($message === '') {
            $message = sprintf(__('Erro HTTP %d ao falar com o Google.', 'kbwizard'), $status);
        }
        throw new RuntimeException($message, $status);
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private static function postForm(string $url, array $params): array
    {
        [$status, $json] = self::postFormRaw($url, $params);
        if ($status < 200 || $status >= 300) {
            $message = (string)($json['error_description'] ?? $json['error'] ?? '');
            if ($message === '') {
                $message = sprintf(__('Erro HTTP %d ao falar com o Google.', 'kbwizard'), $status);
            }
            throw new RuntimeException($message, $status);
        }
        return $json;
    }

    /**
     * @param array<string,string> $params
     * @return array{0:int,1:array<string,mixed>}
     */
    private static function postFormRaw(string $url, array $params): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException(__('Não foi possível iniciar o cURL.', 'kbwizard'));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Expect:',
            ],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException(sprintf(__('Falha de rede ao falar com o Google: %s', 'kbwizard'), $curlError));
        }
        $json = json_decode((string)$body, true);
        return [$status, is_array($json) ? $json : []];
    }

    /**
     * @return array<string,mixed>
     */
    private static function getJson(string $url, string $accessToken): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException(__('Não foi possível iniciar o cURL.', 'kbwizard'));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
                'Expect:',
            ],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return self::decodeResponse($body, $status, $curlError);
    }

    /**
     * @param string|false $body
     * @return array<string,mixed>
     */
    private static function decodeResponse($body, int $status, string $curlError): array
    {
        if ($body === false) {
            throw new RuntimeException(sprintf(__('Falha de rede ao falar com o Google: %s', 'kbwizard'), $curlError));
        }

        $json = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300) {
            $message = '';
            if (is_array($json)) {
                $message = (string)($json['error_description'] ?? $json['error'] ?? '');
            }
            if ($message === '') {
                $message = sprintf(__('Erro HTTP %d ao falar com o Google.', 'kbwizard'), $status);
            }
            throw new RuntimeException($message, $status);
        }
        return is_array($json) ? $json : [];
    }
}
