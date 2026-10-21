<?php
/**
 * KB Wizard - Montagem do HTML enviado ao Google Drive
 *
 * O conteúdo é o artigo cru, com a mesma regra do botão "Exportar PDF":
 * apenas os separadores ---PASSO--- são removidos. As imagens internas do
 * GLPI (document.send.php) viram URLs absolutas assinadas, porque o Google
 * precisa baixá-las sem sessão para embutir no Google Doc.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKbwizardDriveExport
{
    /**
     * Gera o HTML completo (documento) de um artigo.
     */
    public static function buildHtml(KnowbaseItem $kb, bool $signImages = true): string
    {
        $kbId = (int)$kb->getID();
        $title = self::buildName($kb);
        $answer = (string)($kb->fields['answer'] ?? '');

        // Remove apenas os separadores de passo (mesma regra do export.php).
        $answer = self::stripMarkers($answer);

        // Scripts nunca devem ir para o documento (e não são executados lá).
        $answer = (string)preg_replace('#<script\b[^>]*>.*?</script>#is', '', $answer);

        $cfg = PluginKbwizardDriveConfig::get();
        if (!empty($cfg['include_images'])) {
            try {
                $answer = self::rewriteDocumentUrls($answer, $kbId, $signImages);
            } catch (Throwable $e) {
                // Sem url_base (comum em CLI) o Google não consegue baixar imagens.
                // Não aborta a sincronização: o texto continua sendo espelhado.
                if (class_exists('PluginKbwizardToolbox')) {
                    PluginKbwizardToolbox::log('driveexport: imagens ignoradas no artigo ' . $kbId . ': ' . $e->getMessage());
                }
            }
        }

        $updated = (string)($kb->fields['date_mod'] ?? '');
        $updatedLabel = '';
        if ($updated !== '' && $updated !== '0000-00-00 00:00:00') {
            $ts = strtotime($updated);
            if ($ts !== false) {
                $updatedLabel = date('d/m/Y H:i', $ts);
            }
        }

        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $html = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<title>' . $titleEsc . '</title></head><body>';
        $html .= '<h1>' . $titleEsc . '</h1>';
        if ($updatedLabel !== '') {
            $html .= '<p><em>' . sprintf(__('Atualizado em %s', 'kbwizard'), $updatedLabel) . '</em></p>';
        }
        $html .= '<hr>';
        $html .= $answer;
        $html .= '</body></html>';

        return $html;
    }

    /**
     * Nome do arquivo no Drive (título do artigo, saneado).
     */
    public static function buildName(KnowbaseItem $kb): string
    {
        $title = trim(strip_tags((string)($kb->fields['name'] ?? '')));
        $title = (string)preg_replace('/\s+/u', ' ', $title);
        if ($title === '') {
            $title = sprintf(__('Artigo %d', 'kbwizard'), (int)$kb->getID());
        }
        return mb_substr($title, 0, 200);
    }

    /**
     * Hash estável do conteúdo: título + data de modificação + HTML sem URLs
     * assinadas (que expiram e mudariam a cada execução).
     */
    public static function contentHash(KnowbaseItem $kb): string
    {
        return sha1(self::buildHtml($kb, false));
    }

    /**
     * Remove os marcadores ---PASSO--- preservando o restante do HTML.
     */
    public static function stripMarkers(string $html): string
    {
        if (!class_exists('PluginKbwizardStep') && is_file(__DIR__ . '/step.class.php')) {
            require_once __DIR__ . '/step.class.php';
        }
        if (class_exists('PluginKbwizardStep') && method_exists('PluginKbwizardStep', 'stripStepMarkers')) {
            $clean = PluginKbwizardStep::stripStepMarkers($html);
            if (is_string($clean)) {
                return $clean;
            }
        }
        // Fallback defensivo (não deve ser usado quando a classe está carregada).
        $clean = preg_replace(
            '#<(p|div|h[1-6]|li)\b[^>]*>\s*(?:&nbsp;|<br\s*/?>|<span\b[^>]*>|</span>|\s)*-{2,3}\s*PASSO\s*-{2,3}(?:&nbsp;|<br\s*/?>|<span\b[^>]*>|</span>|\s)*</\1>#i',
            '',
            $html
        );
        if (!is_string($clean)) {
            return $html;
        }
        $clean = (string)preg_replace('/-{2,3}\s*PASSO\s*-{2,3}/i', '', $clean);
        return $clean;
    }

    /**
     * Converte links/imagens internas do GLPI em URLs absolutas.
     * Quando $sign = true, imagens de documentos ganham token HMAC temporário
     * para que o Google consiga baixá-las durante a conversão.
     */
    private static function rewriteDocumentUrls(string $html, int $kbId, bool $sign): string
    {
        $base = self::baseUrl();

        // <img src="...document.send.php?docid=N..."> -> URL assinada
        $html = (string)preg_replace_callback(
            '#(<img\b[^>]*\bsrc\s*=\s*["\'])([^"\']*document\.send\.php\?[^"\']*)(["\'])#i',
            static function (array $m) use ($kbId, $sign, $base): string {
                if (preg_match('/[?&](?:amp;)?docid=(\d+)/i', html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), $mm)) {
                    $docId = (int)$mm[1];
                    $url = $sign
                        ? self::signDocUrl($docId, $kbId)
                        : $base . '/front/document.send.php?docid=' . $docId;
                    return $m[1] . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . $m[3];
                }
                return $m[0];
            },
            $html
        );

        // Demais links internos para document.send.php (âncoras): absolutizar.
        $html = (string)preg_replace_callback(
            '#(\b(?:href|src)\s*=\s*["\'])(/(?:front/)?document\.send\.php\?[^"\']*)(["\'])#i',
            static function (array $m) use ($base): string {
                return $m[1] . htmlspecialchars($base . $m[2], ENT_QUOTES, 'UTF-8') . $m[3];
            },
            $html
        );

        return $html;
    }

    /**
     * Gera URL pública e temporária para uma imagem de documento do GLPI.
     */
    public static function signDocUrl(int $docId, int $kbId, int $ttl = 1800): string
    {
        $exp = time() + max(60, $ttl);
        $secret = PluginKbwizardDriveConfig::secret();
        $sig = hash_hmac('sha256', self::imagePayload($docId, $kbId, $exp), $secret);
        $url = self::webDir() . '/front/driveimage.php?' . http_build_query([
            'docid' => $docId,
            'kb'    => $kbId,
            'exp'   => $exp,
            'sig'   => $sig,
        ]);
        return self::absoluteUrl($url);
    }

    /**
     * Converte um caminho web que já inclui o root_doc (ex: /glpi/plugins/kbwizard)
     * em URL absoluta sem duplicar o root_doc.
     *
     * baseUrl() devolve "esquema://host/glpi" e webDir() devolve
     * "/glpi/plugins/kbwizard" — concatenar os dois gera
     * "http://host/glpi/glpi/plugins/kbwizard/..." (404, imagem quebrada).
     */
    public static function absoluteUrl(string $webPath): string
    {
        $base = self::baseUrl();

        $root = '';
        if (class_exists('PluginKbwizardToolbox')) {
            try {
                $root = rtrim(PluginKbwizardToolbox::getRootDoc(), '/');
            } catch (Throwable $e) {
                $root = '';
            }
        }
        if ($root === '') {
            global $CFG_GLPI;
            $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        }

        if ($root !== '' && substr($base, -strlen($root)) === $root) {
            return substr($base, 0, -strlen($root)) . $webPath;
        }

        return rtrim($base, '/') . '/' . ltrim($webPath, '/');
    }

    public static function verifyDocToken(int $docId, int $kbId, int $exp, string $sig): bool
    {
        if ($docId <= 0 || $kbId <= 0 || $exp < time()) {
            return false;
        }
        // Janela máxima de 24h, para limitar reuso de um token vazado.
        if ($exp > time() + 86400) {
            return false;
        }
        $secret = PluginKbwizardDriveConfig::secret();
        if ($secret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', self::imagePayload($docId, $kbId, $exp), $secret);
        return hash_equals($expected, $sig);
    }

    private static function imagePayload(int $docId, int $kbId, int $exp): string
    {
        return 'kbwizard-img|' . $docId . '|' . $kbId . '|' . $exp;
    }

    /**
     * URL base absoluta (esquema + host + root_doc).
     */
    public static function baseUrl(): string
    {
        global $CFG_GLPI;

        $rootDoc = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');

        $urlBase = trim((string)($CFG_GLPI['url_base'] ?? ''));
        if ($urlBase !== '') {
            $urlBase = rtrim($urlBase, '/');
            // Evita duplicar o root_doc quando ele já está na url_base.
            if ($rootDoc !== '' && substr($urlBase, -strlen($rootDoc)) === $rootDoc) {
                return $urlBase;
            }
            return $urlBase . $rootDoc;
        }

        if (PHP_SAPI === 'cli') {
            throw new RuntimeException(__(
                'O parâmetro url_base do GLPI não está configurado. Defina-o em Configurar > Geral para que o Drive possa baixar as imagens dos artigos.',
                'kbwizard'
            ));
        }

        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            throw new RuntimeException(__('Não foi possível determinar a URL pública do GLPI.', 'kbwizard'));
        }
        return $scheme . '://' . $host . $rootDoc;
    }

    /**
     * Caminho web do plugin (ex: /glpi/plugins/kbwizard), com fallback local
     * para uso em CLI/cron onde o Toolbox pode não ter cache.
     */
    public static function webDir(): string
    {
        if (class_exists('PluginKbwizardToolbox')) {
            $dir = PluginKbwizardToolbox::getWebDir();
            if ($dir !== '') {
                return $dir;
            }
        }
        global $CFG_GLPI;
        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        if (defined('GLPI_ROOT') && is_dir(GLPI_ROOT . '/marketplace/kbwizard')) {
            return $root . '/marketplace/kbwizard';
        }
        return $root . '/plugins/kbwizard';
    }
}
