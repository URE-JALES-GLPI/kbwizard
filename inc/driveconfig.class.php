<?php
/**
 * KB Wizard - Configuração da sincronização com o Google Drive
 *
 * Uma única linha (id=1) guarda as preferências globais. A credencial da
 * service account NÃO fica no banco: ela é gravada em
 * GLPI_PLUGIN_DOC_DIR/kbwizard/service-account.json, fora do webroot.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKbwizardDriveConfig
{
    public const TABLE = 'glpi_plugin_kbwizard_drive_config';

    /** @var array|null Cache da linha de configuração */
    private static ?array $cache = null;

    /**
     * Valores padrão. Mantidos em sincronia com o CREATE TABLE do hook.php.
     */
    public static function defaults(): array
    {
        return [
            'id'             => 1,
            'is_active'      => 0,
            'shared_drive_id' => '',
            'folder_id'      => '',
            'folder_name'    => 'KB Wizard - Base de Conhecimento',
            'export_doc'     => 1,
            'export_pdf'     => 1,
            'include_images' => 1,
            'sa_filename'    => '',
            'secret'         => '',
            'last_error'     => '',
            'date_mod'       => null,
        ];
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }

    /**
     * Pasta física (fora do webroot) onde ficam credenciais e artefatos.
     */
    public static function dataDir(): string
    {
        if (defined('GLPI_PLUGIN_DOC_DIR')) {
            $base = GLPI_PLUGIN_DOC_DIR;
        } elseif (defined('GLPI_DOC_DIR')) {
            $base = GLPI_DOC_DIR . '/_plugins';
        } else {
            $base = sys_get_temp_dir();
        }
        return rtrim($base, '/\\') . '/kbwizard';
    }

    public static function serviceAccountPath(): string
    {
        return self::dataDir() . '/service-account.json';
    }

    public static function hasServiceAccount(): bool
    {
        return is_file(self::serviceAccountPath());
    }

    /**
     * Lê e valida o JSON da service account.
     * @return array<string,mixed>|null
     */
    public static function loadServiceAccount(): ?array
    {
        $path = self::serviceAccountPath();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }
        foreach (['client_email', 'private_key', 'token_uri'] as $key) {
            if (empty($json[$key]) || !is_string($json[$key])) {
                return null;
            }
        }
        return $json;
    }

    /**
     * Configuração persistida + defaults.
     */
    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        global $DB;
        $defaults = self::defaults();

        if (!$DB || !$DB->tableExists(self::TABLE)) {
            self::$cache = $defaults;
            return self::$cache;
        }

        try {
            $iterator = $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['id' => 1],
                'LIMIT' => 1,
            ]);
            foreach ($iterator as $row) {
                self::$cache = array_merge($defaults, $row);
                return self::$cache;
            }
        } catch (Throwable $e) {
            self::$cache = $defaults;
            return self::$cache;
        }

        self::$cache = $defaults;
        return self::$cache;
    }

    /**
     * Grava somente as chaves conhecidas, coagindo tipos.
     */
    public static function save(array $input): bool
    {
        global $DB;
        if (!$DB || !$DB->tableExists(self::TABLE)) {
            return false;
        }

        $allowed = [
            'is_active', 'shared_drive_id', 'folder_id', 'folder_name',
            'export_doc', 'export_pdf', 'include_images', 'sa_filename', 'last_error', 'secret',
        ];
        $values = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            if (in_array($key, ['is_active', 'export_doc', 'export_pdf', 'include_images'], true)) {
                $values[$key] = (int)(!empty($input[$key]) ? 1 : 0);
            } else {
                $values[$key] = trim((string)$input[$key]);
            }
        }
        if (empty($values)) {
            return false;
        }
        $values['date_mod'] = date('Y-m-d H:i:s');

        $exists = false;
        try {
            foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => 1], 'LIMIT' => 1]) as $row) {
                $exists = true;
                break;
            }
        } catch (Throwable $e) {
            $exists = false;
        }

        try {
            if ($exists) {
                $DB->update(self::TABLE, $values, ['id' => 1]);
            } else {
                $values['id'] = 1;
                $DB->insert(self::TABLE, $values);
            }
        } catch (Throwable $e) {
            if (class_exists('PluginKbwizardToolbox')) {
                PluginKbwizardToolbox::log('drive config save falhou: ' . $e->getMessage());
            }
            return false;
        }

        self::resetCache();
        return true;
    }

    public static function setFolderId(string $folderId): void
    {
        if ($folderId === '') {
            return;
        }
        $current = self::get();
        if (($current['folder_id'] ?? '') === $folderId) {
            return;
        }
        self::save(['folder_id' => $folderId]);
    }

    public static function setLastError(string $message): void
    {
        self::save(['last_error' => mb_substr($message, 0, 5000)]);
    }

    /**
     * Segredo HMAC usado para assinar URLs temporárias de imagem.
     * Gerado sob demanda; nunca exposto na interface.
     */
    public static function secret(): string
    {
        $cfg = self::get();
        $secret = (string)($cfg['secret'] ?? '');
        if ($secret !== '') {
            return $secret;
        }

        global $DB;
        if (!$DB || !$DB->tableExists(self::TABLE)) {
            return '';
        }

        try {
            $secret = bin2hex(random_bytes(32));
            if (self::save(['secret' => $secret])) {
                self::resetCache();
                return $secret;
            }
        } catch (Throwable $e) {
            if (class_exists('PluginKbwizardToolbox')) {
                PluginKbwizardToolbox::log('drive secret falhou: ' . $e->getMessage());
            }
        }
        return '';
    }

    public static function isConfigured(): bool
    {
        $cfg = self::get();
        return !empty($cfg['is_active'])
            && trim((string)$cfg['shared_drive_id']) !== ''
            && self::hasServiceAccount();
    }
}
