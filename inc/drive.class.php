<?php
/**
 * KB Wizard - Cliente mínimo da Google Drive API v3
 *
 * Implementação sem dependências (sem google/apiclient): autentica com
 * service account via JWT RS256, assina com openssl e fala REST com cURL.
 * Pensado para Shared Drives (supportsAllDrives=true).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKbwizardDrive
{
    private const SCOPE = 'https://www.googleapis.com/auth/drive';
    private const API = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3';

    /** @var array<string,mixed> */
    private array $sa;
    /** @var array{client_id:string,client_secret:string,refresh_token:string}|null */
    private ?array $oauth = null;
    private string $sharedDriveId;
    private string $folderId;
    private string $lastError = '';

    /** @var string|null Token OAuth em memória para a requisição atual */
    private static ?string $tokenCache = null;
    private static int $tokenExpires = 0;
    /** @var string Identifica a credencial dona do token em cache */
    private static string $tokenKey = '';

    public function __construct(array $serviceAccount, string $sharedDriveId, string $folderId = '', ?array $oauth = null)
    {
        $this->sa = $serviceAccount;
        $this->oauth = $oauth;
        $this->sharedDriveId = trim($sharedDriveId);
        $this->folderId = trim($folderId);
    }

    /**
     * Monta o cliente a partir da configuração do plugin.
     *
     * Dois modos:
     *  - oauth: conta Google comum (Gmail), escopo drive.file, Meu Drive;
     *  - service_account: Google Workspace com Shared Drive.
     */
    public static function fromConfig(): ?self
    {
        $cfg = PluginKbwizardDriveConfig::get();

        if (($cfg['auth_mode'] ?? 'oauth') === 'oauth') {
            $clientId     = trim((string)($cfg['client_id'] ?? ''));
            $clientSecret = trim((string)($cfg['client_secret'] ?? ''));
            $refreshToken = trim((string)($cfg['refresh_token'] ?? ''));
            if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
                return null;
            }
            return new self([], '', (string)($cfg['folder_id'] ?? ''), [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
            ]);
        }

        $sa = PluginKbwizardDriveConfig::loadServiceAccount();
        if ($sa === null || trim((string)$cfg['shared_drive_id']) === '') {
            return null;
        }
        return new self($sa, (string)$cfg['shared_drive_id'], (string)$cfg['folder_id']);
    }

    public function isOAuth(): bool
    {
        return $this->oauth !== null;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function getFolderId(): string
    {
        return $this->folderId;
    }

    /**
     * Valida credencial, acesso ao Shared Drive e pasta de destino.
     * @return array{ok:bool,message:string,drive?:string,folder_id?:string,folder_url?:string}
     */
    public function testConnection(): array
    {
        try {
            $driveName = '';
            if ($this->sharedDriveId !== '') {
                $drive = $this->apiGet('/drives/' . rawurlencode($this->sharedDriveId), ['fields' => 'id,name']);
                $driveName = (string)($drive['name'] ?? '');
            }
            $folderId = $this->ensureFolder();
            $folder = $this->apiGet('/files/' . rawurlencode($folderId), [
                'fields' => 'id,name,webViewLink,trashed,mimeType',
                'supportsAllDrives' => 'true',
            ]);
            if (!empty($folder['trashed'])) {
                return ['ok' => false, 'message' => __('A pasta configurada está na lixeira do Drive.', 'kbwizard')];
            }
            return [
                'ok'         => true,
                'message'    => __('Conexão com o Google Drive validada.', 'kbwizard'),
                'drive'      => $driveName,
                'folder_id'  => (string)($folder['id'] ?? $folderId),
                'folder_url' => (string)($folder['webViewLink'] ?? ''),
            ];
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Garante que a pasta destino exista e retorna o ID.
     */
    public function ensureFolder(): string
    {
        if ($this->folderId !== '') {
            try {
                $file = $this->apiGet('/files/' . rawurlencode($this->folderId), [
                    'fields' => 'id,mimeType,trashed',
                    'supportsAllDrives' => 'true',
                ]);
                if (!empty($file['trashed'])) {
                    throw new RuntimeException(__('A pasta configurada está na lixeira do Drive.', 'kbwizard'), 410);
                }
                if (($file['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
                    throw new RuntimeException(__('O ID informado não é uma pasta do Drive.', 'kbwizard'), 422);
                }
                return (string)$file['id'];
            } catch (RuntimeException $e) {
                // Pasta removida/perdeu acesso: tenta recriar/encontrar pelo nome.
                if ($e->getCode() === 422) {
                    throw $e;
                }
                $this->folderId = '';
            }
        }

        $name = trim((string)PluginKbwizardDriveConfig::get()['folder_name']);
        if ($name === '') {
            $name = 'KB Wizard - Base de Conhecimento';
        }

        $found = $this->findFolderByName($name);
        if ($found !== null) {
            $this->folderId = $found;
            return $found;
        }

        $metadata = [
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ];
        if ($this->sharedDriveId !== '') {
            // Shared Drive: precisa nascer na raiz do drive.
            $metadata['parents'] = [$this->sharedDriveId];
        }
        $created = $this->apiPost('/files', $metadata, ['supportsAllDrives' => 'true', 'fields' => 'id']);

        if (empty($created['id'])) {
            throw new RuntimeException(__('Não foi possível criar a pasta no Shared Drive.', 'kbwizard'));
        }
        $this->folderId = (string)$created['id'];
        return $this->folderId;
    }

    private function findFolderByName(string $name): ?string
    {
        $q = sprintf("name = '%s' and mimeType = 'application/vnd.google-apps.folder' and trashed = false", $this->escapeQuery($name));
        $params = [
            'q'                         => $q,
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives'         => 'true',
            'pageSize'                  => '5',
            'fields'                    => 'files(id,name)',
        ];
        if ($this->sharedDriveId !== '') {
            $params['corpora'] = 'drive';
            $params['driveId'] = $this->sharedDriveId;
        }
        $res = $this->apiGet('/files', $params);
        foreach (($res['files'] ?? []) as $file) {
            if (!empty($file['id'])) {
                return (string)$file['id'];
            }
        }
        return null;
    }

    /**
     * Procura o arquivo espelho de um artigo (appProperties kbwizard_kb).
     * @return array{id:string,name:string,mimeType:string}|null
     */
    public function findFileForKb(int $kbId, string $kind = ''): ?array
    {
        $q = sprintf("appProperties has { key='kbwizard_kb' and value='%d' } and trashed = false", $kbId);
        if ($kind !== '') {
            $q .= sprintf(" and appProperties has { key='kbwizard_kind' and value='%s' }", $this->escapeQuery($kind));
        }
        $params = [
            'q'                         => $q,
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives'         => 'true',
            'pageSize'                  => '10',
            'fields'                    => 'files(id,name,mimeType)',
        ];
        if ($this->sharedDriveId !== '') {
            $params['corpora'] = 'drive';
            $params['driveId'] = $this->sharedDriveId;
        }
        $res = $this->apiGet('/files', $params);
        foreach (($res['files'] ?? []) as $file) {
            if (!empty($file['id'])) {
                return [
                    'id'       => (string)$file['id'],
                    'name'     => (string)($file['name'] ?? ''),
                    'mimeType' => (string)($file['mimeType'] ?? ''),
                ];
            }
        }
        return null;
    }

    /**
     * Cria (ou atualiza) o Google Doc espelho a partir de HTML.
     * O fileId é preservado nas atualizações — requisito para o NotebookLM
     * continuar sincronizando a mesma fonte.
     * @return array{id:string,created:bool}
     */
    public function upsertDoc(string $fileId, string $name, string $html, int $kbId): array
    {
        if ($fileId !== '') {
            try {
                $this->updateDoc($fileId, $html);
                return ['id' => $fileId, 'created' => false];
            } catch (RuntimeException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        }
        return ['id' => $this->createDoc($name, $html, $kbId), 'created' => true];
    }

    public function createDoc(string $name, string $html, int $kbId): string
    {
        $metadata = [
            'name'          => $name,
            'mimeType'      => 'application/vnd.google-apps.document',
            'parents'       => [$this->ensureFolder()],
            'appProperties' => ['kbwizard_kb' => (string)$kbId, 'kbwizard_kind' => 'doc'],
        ];
        $res = $this->multipartCreate($metadata, 'text/html; charset=UTF-8', $html);
        if (empty($res['id'])) {
            throw new RuntimeException(__('O Drive não retornou o ID do documento criado.', 'kbwizard'));
        }
        return (string)$res['id'];
    }

    /**
     * Substitui todo o conteúdo do Google Doc mantendo o mesmo arquivo
     * (Drive converte o HTML enviado, conforme documentação de uploads).
     */
    public function updateDoc(string $fileId, string $html): void
    {
        $this->apiUpload(
            'PATCH',
            '/files/' . rawurlencode($fileId),
            'text/html; charset=UTF-8',
            $html,
            ['uploadType' => 'media', 'supportsAllDrives' => 'true', 'fields' => 'id']
        );
    }

    /**
     * Exporta o Google Doc como PDF usando o próprio renderizador do Google.
     */
    public function exportPdf(string $docFileId): string
    {
        $url = self::API . '/files/' . rawurlencode($docFileId) . '/export?mimeType=' . rawurlencode('application/pdf');
        [$status, $body] = $this->rawRequest('GET', $url);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->extractError($body, $status), $status);
        }
        if ($body === '') {
            throw new RuntimeException(__('O Google retornou um PDF vazio.', 'kbwizard'));
        }
        return $body;
    }

    /**
     * Cria (ou atualiza) o PDF espelho.
     * @return array{id:string,created:bool}
     */
    public function upsertPdf(string $fileId, string $name, string $pdf, int $kbId): array
    {
        if ($fileId !== '') {
            try {
                $this->apiUpload(
                    'PATCH',
                    '/files/' . rawurlencode($fileId),
                    'application/pdf',
                    $pdf,
                    ['uploadType' => 'media', 'supportsAllDrives' => 'true', 'fields' => 'id']
                );
                return ['id' => $fileId, 'created' => false];
            } catch (RuntimeException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        }

        $metadata = [
            'name'          => $name,
            'mimeType'      => 'application/pdf',
            'parents'       => [$this->ensureFolder()],
            'appProperties' => ['kbwizard_kb' => (string)$kbId, 'kbwizard_kind' => 'pdf'],
        ];
        $res = $this->multipartCreate($metadata, 'application/pdf', $pdf);
        if (empty($res['id'])) {
            throw new RuntimeException(__('O Drive não retornou o ID do PDF criado.', 'kbwizard'));
        }
        return ['id' => (string)$res['id'], 'created' => true];
    }

    public function trash(string $fileId): void
    {
        if ($fileId === '') {
            return;
        }
        $this->apiPatch('/files/' . rawurlencode($fileId), ['trashed' => true], ['supportsAllDrives' => 'true']);
    }

    // ------------------------------------------------------------------
    // Autenticação
    // ------------------------------------------------------------------

    private function accessToken(): string
    {
        $cacheKey = $this->oauth !== null
            ? 'oauth|' . $this->oauth['client_id']
            : 'sa|' . (string)($this->sa['client_email'] ?? '');

        if (self::$tokenCache !== null
            && self::$tokenKey === $cacheKey
            && self::$tokenExpires > time() + 60) {
            return self::$tokenCache;
        }

        if ($this->oauth !== null) {
            return $this->oauthAccessToken($cacheKey);
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => (string)$this->sa['client_email'],
            'scope' => self::SCOPE,
            'aud'   => (string)$this->sa['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $signingInput = self::b64url((string)json_encode($header, JSON_UNESCAPED_SLASHES))
            . '.' . self::b64url((string)json_encode($claims, JSON_UNESCAPED_SLASHES));

        $privateKey = str_replace('\\n', "\n", (string)$this->sa['private_key']);
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new RuntimeException(__('A chave privada da service account é inválida.', 'kbwizard'));
        }
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException(__('Falha ao assinar o JWT da service account.', 'kbwizard'));
        }

        $assertion = $signingInput . '.' . self::b64url($signature);
        $post = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ]);

        [$status, $body] = $this->rawRequest(
            'POST',
            (string)$this->sa['token_uri'],
            $post,
            ['Content-Type: application/x-www-form-urlencoded'],
            false,
            false
        );
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->extractError($body, $status), $status);
        }
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['access_token'])) {
            throw new RuntimeException(__('O Google não retornou access_token.', 'kbwizard'));
        }

        self::$tokenCache = (string)$json['access_token'];
        self::$tokenKey = $cacheKey;
        self::$tokenExpires = $now + (int)($json['expires_in'] ?? 3600);
        return self::$tokenCache;
    }

    /**
     * Renova o token da conta Google conectada (refresh_token).
     */
    private function oauthAccessToken(string $cacheKey): string
    {
        if ($this->oauth === null) {
            throw new RuntimeException(__('Credencial OAuth ausente.', 'kbwizard'));
        }
        $json = PluginKbwizardDriveOAuth::refresh(
            $this->oauth['client_id'],
            $this->oauth['client_secret'],
            $this->oauth['refresh_token']
        );
        if (empty($json['access_token'])) {
            throw new RuntimeException(__('O Google não retornou access_token. Reconecte a conta Google.', 'kbwizard'));
        }
        self::$tokenCache = (string)$json['access_token'];
        self::$tokenKey = $cacheKey;
        self::$tokenExpires = time() + (int)($json['expires_in'] ?? 3600);
        return self::$tokenCache;
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    /**
     * @return array{0:int,1:string} [status, body]
     */
    private function rawRequest(string $method, string $url, ?string $body = null, array $extraHeaders = [], bool $withAuth = true, bool $retryOn401 = true): array
    {
        // POST não é idempotente: repetir um create em 5xx pode duplicar arquivos.
        $idempotent = in_array(strtoupper($method), ['GET', 'PATCH', 'PUT', 'DELETE'], true);
        $attempt = 0;
        $reauthed = false;

        do {
            $attempt++;
            // Header recalculado a cada tentativa para pegar um token renovado.
            $headers = ['Expect:'];
            if ($withAuth) {
                $headers[] = 'Authorization: Bearer ' . $this->accessToken();
            }
            $headers = array_merge($headers, $extraHeaders);

            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException(__('Não foi possível iniciar o cURL.', 'kbwizard'));
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new RuntimeException(sprintf(__('Falha de rede ao falar com o Google: %s', 'kbwizard'), $curlError));
            }
            $response = (string)$response;

            // Token expirado/revogado: descarta o cache e tenta uma única vez.
            if ($status === 401 && $withAuth && $retryOn401 && !$reauthed) {
                $reauthed = true;
                self::$tokenCache = null;
                self::$tokenExpires = 0;
                continue;
            }

            // Retry curto para limites de taxa e indisponibilidade temporária.
            if ($idempotent && in_array($status, [429, 500, 502, 503, 504], true) && $attempt < 3) {
                usleep(500000 * $attempt);
                continue;
            }
            return [$status, $response];
        } while (true);
    }

    /**
     * @return array<string,mixed>
     */
    private function apiGet(string $path, array $query = []): array
    {
        $url = self::API . $path;
        if (!empty($query)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }
        [$status, $body] = $this->rawRequest('GET', $url);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->extractError($body, $status), $status);
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function apiPost(string $path, array $payload, array $query = []): array
    {
        $url = self::API . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        [$status, $body] = $this->rawRequest(
            'POST',
            $url,
            (string)json_encode($payload, JSON_UNESCAPED_SLASHES),
            ['Content-Type: application/json; charset=UTF-8']
        );
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->extractError($body, $status), $status);
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function apiPatch(string $path, array $payload, array $query = []): array
    {
        $url = self::API . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        [$status, $body] = $this->rawRequest(
            'PATCH',
            $url,
            (string)json_encode($payload, JSON_UNESCAPED_SLASHES),
            ['Content-Type: application/json; charset=UTF-8']
        );
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->extractError($body, $status), $status);
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : [];
    }

    /**
     * Upload simples (media) usado para substituir conteúdo.
     */
    private function apiUpload(string $method, string $path, string $mime, string $bytes, array $query = []): array
    {
        $url = self::UPLOAD . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        [$status, $body] = $this->rawRequest($method, $url, $bytes, ['Content-Type: ' . $mime]);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->extractError($body, $status), $status);
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : [];
    }

    /**
     * Upload multipart (metadados + mídia) com conversão para Google Doc.
     * @return array<string,mixed>
     */
    private function multipartCreate(array $metadata, string $mime, string $bytes): array
    {
        $boundary = 'kbwizard' . bin2hex(random_bytes(12));
        $body = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode($metadata, JSON_UNESCAPED_SLASHES) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: {$mime}\r\n\r\n"
            . $bytes . "\r\n"
            . "--{$boundary}--\r\n";

        $url = self::UPLOAD . '/files?' . http_build_query([
            'uploadType'        => 'multipart',
            'supportsAllDrives' => 'true',
            'fields'            => 'id,name,mimeType,webViewLink',
        ]);

        [$status, $response] = $this->rawRequest(
            'POST',
            $url,
            $body,
            ['Content-Type: multipart/related; boundary=' . $boundary]
        );
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->extractError($response, $status), $status);
        }
        $json = json_decode($response, true);
        return is_array($json) ? $json : [];
    }

    private function extractError(string $body, int $status): string
    {
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['error']['message'])) {
            return sprintf('%s (HTTP %d)', (string)$json['error']['message'], $status);
        }
        $snippet = trim(strip_tags($body));
        if ($snippet !== '') {
            return sprintf('%s (HTTP %d)', mb_substr($snippet, 0, 400), $status);
        }
        return sprintf(__('Erro HTTP %d ao falar com o Google Drive.', 'kbwizard'), $status);
    }

    private function escapeQuery(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
