<?php
/**
 * KB Wizard - Toolbox central
 * Centraliza lógicas duplicadas: webdir / incPath / logging / assets cache
 * Evita divergência entre plugins/ vs marketplace/ e melhora manutenção
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKbwizardToolbox
{
    /** @var string|null Cache webDir */
    private static ?string $webDirCache = null;
    /** @var string|null Cache incPath */
    private static ?string $incPathCache = null;
    /** @var string|null Cache css inline */
    private static ?string $cssInlineCache = null;
    /** @var string|null Cache js inline */
    private static ?string $jsInlineCache = null;

    /**
     * Retorna webDir do plugin (ex: /glpi/plugins/kbwizard ou /glpi/marketplace/kbwizard)
     * Centraliza lógica antes espalhada em 6 arquivos
     */
    public static function getWebDir(): string
    {
        if (self::$webDirCache !== null) {
            return self::$webDirCache;
        }
        // Tenta via Plugin::getPhpDir (mais confiável quando GLPI carregado)
        try {
            if (class_exists('Plugin')) {
                $phpDir = Plugin::getPhpDir('kbwizard');
                if (!empty($phpDir)) {
                    if (strpos($phpDir, 'marketplace') !== false) {
                        global $CFG_GLPI;
                        self::$webDirCache = ($CFG_GLPI['root_doc'] ?? '') . '/marketplace/kbwizard';
                        return self::$webDirCache;
                    }
                    if (strpos($phpDir, 'plugins') !== false) {
                        global $CFG_GLPI;
                        self::$webDirCache = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard';
                        return self::$webDirCache;
                    }
                }
            }
        } catch (Throwable $e) {
            // fallback abaixo
        }

        // Fallback filesystem: verifica onde css existe
        if (defined('GLPI_ROOT')) {
            if (is_file(GLPI_ROOT . '/marketplace/kbwizard/css/kbwizard.css')) {
                global $CFG_GLPI;
                self::$webDirCache = ($CFG_GLPI['root_doc'] ?? '') . '/marketplace/kbwizard';
                return self::$webDirCache;
            }
            if (is_file(GLPI_ROOT . '/plugins/kbwizard/css/kbwizard.css')) {
                global $CFG_GLPI;
                self::$webDirCache = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard';
                return self::$webDirCache;
            }
        }

        global $CFG_GLPI;
        // Se nada funcionou, verifica se pasta marketplace existe fisicamente para evitar 404
        if (defined('GLPI_ROOT') && is_dir(GLPI_ROOT . '/marketplace/kbwizard')) {
            self::$webDirCache = ($CFG_GLPI['root_doc'] ?? '') . '/marketplace/kbwizard';
        } else {
            self::$webDirCache = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/kbwizard';
        }
        return self::$webDirCache;
    }

    /**
     * Retorna o outro dir (para fallback 404 plugins<->marketplace)
     */
    public static function getOtherWebDir(): string
    {
        $webDir = self::getWebDir();
        if (strpos($webDir, 'marketplace') !== false) {
            return str_replace('marketplace', 'plugins', $webDir);
        }
        return str_replace('plugins', 'marketplace', $webDir);
    }

    /**
     * Retorna root_doc (ex: /glpi ou "")
     */
    public static function getRootDoc(): string
    {
        global $CFG_GLPI;
        if (isset($CFG_GLPI['root_doc'])) {
            return $CFG_GLPI['root_doc'];
        }
        // Tenta deduzir via Plugin
        try {
            if (class_exists('Plugin') && method_exists('Plugin', 'getWebDir')) {
                $wd = self::getWebDir();
                // webDir = root_doc + /plugins/kbwizard -> extrai root_doc
                if (preg_match('#^(.*)/(plugins|marketplace)/kbwizard$#', $wd, $m)) {
                    return $m[1];
                }
            }
        } catch (Throwable $e) {}
        return '';
    }

    /**
     * Retorna URL completa para um ajax do plugin (ex: progress.php)
     */
    public static function getAjaxUrl(string $file = 'progress.php'): string
    {
        return self::getWebDir() . '/ajax/' . ltrim($file, '/');
    }

    /**
     * Retorna caminho físico para inc/includes.php ou null se não encontrado
     * Substitui repetição de candidates em 4 arquivos front/ajax
     */
    public static function getIncPath(): ?string
    {
        if (self::$incPathCache !== null) {
            return self::$incPathCache;
        }
        $candidates = [
            __DIR__ . "/../../inc/includes.php",           // marketplace/kbwizard -> glpi/inc
            __DIR__ . "/../../../inc/includes.php",        // plugins/kbwizard -> glpi/inc (fallback)
            dirname(__DIR__, 3) . "/inc/includes.php",
        ];
        // Se estamos em ajax/ ou front/, ajusta: __DIR__ é inc/, então precisa subir mais um nível
        // Para chamada via ajax/front, o __DIR__ será inc/, mas o chamador está em ajax/ ou front/
        // Por isso também testa caminhos relativos ao chamador quando possível
        foreach ($candidates as $c) {
            if (file_exists($c)) {
                self::$incPathCache = $c;
                return self::$incPathCache;
            }
        }
        // Tenta também via GLPI_ROOT
        if (defined('GLPI_ROOT') && file_exists(GLPI_ROOT . '/inc/includes.php')) {
            self::$incPathCache = GLPI_ROOT . '/inc/includes.php';
            return self::$incPathCache;
        }
        return null;
    }

    /**
     * Resolve incPath a partir de um diretório base (ajax/ ou front/)
     * Útil para front/ajax que estão em subpastas diferentes
     * @param string $baseDir __DIR__ do chamador
     */
    public static function getIncPathFrom(string $baseDir): ?string
    {
        $candidates = [
            $baseDir . "/../../inc/includes.php",
            $baseDir . "/../../../inc/includes.php",
            dirname($baseDir, 3) . "/inc/includes.php",
            dirname(__DIR__, 2) . "/../inc/includes.php", // fallback relativo ao plugin
        ];
        foreach ($candidates as $c) {
            if (file_exists($c)) {
                return $c;
            }
        }
        return self::getIncPath();
    }

    /**
     * Inclui GLPI e envia erro JSON se falhar (para endpoints ajax)
     * Evita repetição de header + json_encode de erro
     */
    public static function requireGLPI(bool $jsonOnFail = true): bool
    {
        $inc = self::getIncPath();
        // Se incPath é de inc/toolbox, para ajax/front precisa resolver via caller
        if ($inc === null || !file_exists($inc)) {
            // tenta via debug_backtrace para pegar caller
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $callerDir = $trace[1]['file'] ?? __DIR__;
            $callerDir = dirname($callerDir);
            $inc2 = self::getIncPathFrom($callerDir);
            if ($inc2 && file_exists($inc2)) {
                $inc = $inc2;
            }
        }
        if ($inc && file_exists($inc)) {
            include_once($inc);
            return true;
        }
        if ($jsonOnFail) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'GLPI includes not found']);
            exit;
        }
        return false;
    }

    /**
     * Log helper (não quebra se Toolbox não existir)
     */
    public static function log(string $msg): void
    {
        try {
            if (class_exists('Toolbox')) {
                Toolbox::logInFile('kbwizard', $msg);
            }
        } catch (Throwable $e) {}
        // fallback error_log
        try {
            error_log('[kbwizard] ' . $msg);
        } catch (Throwable $e) {}
    }

    /**
     * Retorna sufixo versionado para cache busting (ex: ?v=1.0.21)
     */
    public static function getVersionedSuffix(): string
    {
        if (defined('PLUGIN_KBWIZARD_VERSION')) {
            return '?v=' . PLUGIN_KBWIZARD_VERSION;
        }
        return '';
    }

    /**
     * Verifica se request atual é de página KB (para carregamento condicional de assets)
     */
    public static function isKbPageRequest(): bool
    {
        // CLI / cron nunca precisa
        if (PHP_SAPI === 'cli') {
            return false;
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $combined = $uri . ' ' . $script;
        if (stripos($combined, 'knowbase') !== false) {
            return true;
        }
        // Também é KB se estamos dentro de ajax/front do próprio plugin
        if (stripos($combined, 'kbwizard') !== false) {
            return true;
        }
        // Para post_show_item hook, sempre retorna true quando chamado com KnowbaseItem
        return false;
    }

    /**
     * Retorna CSS inline com cache (evita file_get_contents a cada render)
     */
    public static function getCssInline(): string
    {
        if (self::$cssInlineCache !== null) {
            return self::$cssInlineCache;
        }
        $paths = [
            __DIR__ . '/../css/kbwizard.css',
        ];
        if (defined('GLPI_ROOT')) {
            $paths[] = GLPI_ROOT . '/plugins/kbwizard/css/kbwizard.css';
            $paths[] = GLPI_ROOT . '/marketplace/kbwizard/css/kbwizard.css';
        }
        foreach ($paths as $p) {
            if (is_file($p)) {
                $content = @file_get_contents($p);
                if ($content !== false) {
                    self::$cssInlineCache = $content;
                    return self::$cssInlineCache;
                }
            }
        }
        self::$cssInlineCache = '';
        return '';
    }

    /**
     * Retorna JS inline com cache
     */
    public static function getJsInline(): string
    {
        if (self::$jsInlineCache !== null) {
            return self::$jsInlineCache;
        }
        $paths = [
            __DIR__ . '/../js/kbwizard.js',
        ];
        if (defined('GLPI_ROOT')) {
            $paths[] = GLPI_ROOT . '/plugins/kbwizard/js/kbwizard.js';
            $paths[] = GLPI_ROOT . '/marketplace/kbwizard/js/kbwizard.js';
        }
        foreach ($paths as $p) {
            if (is_file($p)) {
                $content = @file_get_contents($p);
                if ($content !== false) {
                    self::$jsInlineCache = $content;
                    return self::$jsInlineCache;
                }
            }
        }
        self::$jsInlineCache = '';
        return '';
    }

    /**
     * Limpa caches (útil em testes)
     */
    public static function clearCache(): void
    {
        self::$webDirCache = null;
        self::$incPathCache = null;
        self::$cssInlineCache = null;
        self::$jsInlineCache = null;
    }
}
