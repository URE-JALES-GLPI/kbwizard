<?php
/**
 * KB Wizard - Renderização local de PDF com mPDF (opcional)
 *
 * O modo padrão continua sendo o Google: o Doc é convertido com files.export.
 * Este renderer existe para quem quer o PDF gerado no próprio servidor — assim
 * as imagens do artigo entram mesmo quando o GLPI está em http/IP interno
 * (o servidor baixa de si mesmo; o Google não alcançaria o IP).
 *
 * Ativação: composer require mpdf/mpdf dentro da pasta do plugin.
 * Sem vendor/, isAvailable() retorna false e a sincronização usa o Google.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKbwizardPdfRender
{
    /** @var bool|null Cache da checagem do autoload */
    private static ?bool $available = null;

    public static function vendorAutoload(): string
    {
        return dirname(__DIR__) . '/vendor/autoload.php';
    }

    /**
     * Carrega o autoload do plugin (se existir) e verifica se o mPDF está presente.
     */
    public static function isAvailable(bool $refresh = false): bool
    {
        if (self::$available !== null && !$refresh) {
            return self::$available;
        }

        self::$available = false;

        if (!class_exists('Mpdf\\Mpdf', false)) {
            $autoload = self::vendorAutoload();
            if (is_file($autoload)) {
                try {
                    require_once $autoload;
                } catch (Throwable $e) {
                    if (class_exists('PluginKbwizardToolbox')) {
                        PluginKbwizardToolbox::log('pdfrender: autoload falhou: ' . $e->getMessage());
                    }
                }
            }
        }

        self::$available = class_exists('Mpdf\\Mpdf');
        return self::$available;
    }

    /**
     * Pasta temporária do mPDF (fora do webroot).
     */
    public static function tempDir(): string
    {
        $dir = PluginKbwizardDriveConfig::dataDir() . '/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    /**
     * Diagnóstico para a tela de configuração.
     * @return array{available:bool,version:string,autoload:string,temp_dir:string,writable:bool}
     */
    public static function status(): array
    {
        $available = self::isAvailable(true);
        $version = '';
        if ($available) {
            try {
                $version = (string)\Mpdf\Mpdf::VERSION;
            } catch (Throwable $e) {
                $version = '';
            }
        }
        $tempDir = self::tempDir();

        return [
            'available' => $available,
            'version'   => $version,
            'autoload'  => self::vendorAutoload(),
            'temp_dir'  => $tempDir,
            'writable'  => is_dir($tempDir) && is_writable($tempDir),
            'gd'        => extension_loaded('gd'),
            'mbstring'  => extension_loaded('mbstring'),
            'curl'      => function_exists('curl_init'),
            'fopen'     => (bool)ini_get('allow_url_fopen'),
        ];
    }

    /**
     * Renderiza o HTML do artigo em PDF.
     *
     * @param string $html  HTML completo montado por PluginKbwizardDriveExport::buildHtml()
     * @param string $title Título usado nos metadados do PDF
     * @return string Bytes do PDF
     * @throws RuntimeException
     */
    public static function render(string $html, string $title = ''): string
    {
        if (!self::isAvailable()) {
            throw new RuntimeException(sprintf(
                __('mPDF não instalado. Rode "composer require mpdf/mpdf" em %s.', 'kbwizard'),
                dirname(__DIR__)
            ));
        }

        $tempDir = self::tempDir();
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            throw new RuntimeException(sprintf(
                __('Pasta temporária do PDF sem permissão de escrita: %s', 'kbwizard'),
                $tempDir
            ));
        }

        try {
            $mpdf = new \Mpdf\Mpdf([
                'mode'              => 'utf-8',
                'format'            => 'A4',
                'margin_left'       => 15,
                'margin_right'      => 15,
                'margin_top'        => 16,
                'margin_bottom'     => 18,
                'margin_header'     => 8,
                'margin_footer'     => 8,
                'tempDir'           => $tempDir,
                'default_font'      => 'dejavusans',
                'default_font_size' => 11,
                'img_dpi'           => 96,
                'showImageErrors'   => false,
            ]);

            $title = trim($title);
            if ($title !== '') {
                $mpdf->SetTitle($title);
            }
            $mpdf->SetAuthor('GLPI');
            $mpdf->SetCreator('KB Wizard');

            $footer = '<div style="font-size:8pt;color:#64748b;border-top:0.3mm solid #e2e8f0;'
                . 'padding-top:2mm;text-align:right;">{PAGENO}/{nbpg}</div>';
            $mpdf->SetHTMLFooter($footer);

            $mpdf->WriteHTML(self::css() . self::embedImages($html));

            $bytes = (string)$mpdf->Output('', 'S');
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf(__('Falha ao gerar o PDF com mPDF: %s', 'kbwizard'), $e->getMessage())
            );
        }

        if ($bytes === '' || strncmp($bytes, '%PDF', 4) !== 0) {
            throw new RuntimeException(__('O mPDF não retornou um PDF válido.', 'kbwizard'));
        }

        return $bytes;
    }

    /**
     * Baixa as imagens no servidor e as embute como data URI.
     *
     * O mPDF tenta buscar cada <img> por conta própria e falha sem avisar quando
     * allow_url_fopen está desligado, quando o certificado é interno ou quando a
     * URL exige sessão. Com data URI o binário já vai no HTML e a imagem entra
     * no PDF mesmo com o GLPI em rede interna.
     */
    private static function embedImages(string $html): string
    {
        if (stripos($html, '<img') === false) {
            return $html;
        }

        $cache    = [];
        $embedded = 0;
        $failed   = 0;

        $html = (string)preg_replace_callback(
            '#<img\b[^>]*>#i',
            static function (array $m) use (&$cache, &$embedded, &$failed): string {
                $tag = $m[0];
                if (!preg_match('#\bsrc\s*=\s*(["\'])(.*?)\1#i', $tag, $sm)) {
                    return $tag;
                }
                $src = html_entity_decode(trim($sm[2]), ENT_QUOTES, 'UTF-8');
                if ($src === '' || stripos($src, 'data:') === 0) {
                    return $tag;
                }

                if (!array_key_exists($src, $cache)) {
                    $cache[$src] = self::fetchImage($src);
                }
                if ($cache[$src] === null) {
                    $failed++;
                    return $tag;
                }
                $embedded++;

                return (string)preg_replace(
                    '#\bsrc\s*=\s*(["\'])(.*?)\1#i',
                    'src="' . $cache[$src] . '"',
                    $tag,
                    1
                );
            },
            $html
        );

        if ($embedded > 0 || $failed > 0) {
            self::log("imagens: embutidas=$embedded falharam=$failed");
        }

        return $html;
    }

    /**
     * Baixa uma imagem e devolve a data URI, ou null se não for possível.
     */
    private static function fetchImage(string $url): ?string
    {
        if (stripos($url, 'http://') !== 0 && stripos($url, 'https://') !== 0) {
            $base = '';
            if (class_exists('PluginKbwizardDriveExport')) {
                try {
                    $base = rtrim(PluginKbwizardDriveExport::baseUrl(), '/');
                } catch (Throwable $e) {
                    $base = '';
                }
            }
            if ($base === '' || strpos($url, '/') !== 0) {
                self::log('imagem relativa sem url_base configurado: ' . $url);
                return null;
            }
            $url = $base . $url;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 20,
            // Intranet pode usar certificado interno/autoassinado.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'KBWizard-PDF/1.0',
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false || $body === '' || $status < 200 || $status >= 300) {
            self::log("imagem não baixada (HTTP $status" . ($err !== '' ? ", $err" : '') . "): $url");
            return null;
        }
        if (strlen($body) > 10 * 1024 * 1024) {
            self::log('imagem ignorada por tamanho: ' . $url);
            return null;
        }

        $type = strtolower(trim(explode(';', $type)[0]));
        if (strpos($type, 'image/') !== 0) {
            $info = @getimagesizefromstring($body);
            if (is_array($info) && !empty($info['mime'])) {
                $type = strtolower((string)$info['mime']);
            } else {
                self::log("resposta não é imagem (HTTP $status, $type): $url");
                return null;
            }
        }

        return 'data:' . $type . ';base64,' . base64_encode($body);
    }

    private static function log(string $message): void
    {
        if (class_exists('PluginKbwizardToolbox')) {
            PluginKbwizardToolbox::log('pdfrender: ' . $message);
        }
    }

    /**
     * CSS aplicado ao conteúdo do artigo (mPDF tem suporte limitado a CSS moderno,
     * então usamos apenas o essencial de tipografia, tabelas e imagens).
     */
    private static function css(): string
    {
        return '<style>'
            . 'body{font-family:dejavusans,sans-serif;font-size:11pt;line-height:1.5;color:#1f2937;}'
            . 'h1{font-size:20pt;line-height:1.25;margin:0 0 6pt 0;color:#111827;}'
            . 'h2{font-size:15pt;margin:14pt 0 6pt 0;color:#111827;}'
            . 'h3{font-size:13pt;margin:12pt 0 5pt 0;color:#111827;}'
            . 'h4{font-size:11.5pt;margin:10pt 0 4pt 0;color:#111827;}'
            . 'p{margin:0 0 8pt 0;}'
            . 'ul,ol{margin:0 0 8pt 0;padding-left:18pt;}'
            . 'li{margin-bottom:3pt;}'
            . 'hr{border:none;border-top:0.3mm solid #cbd5e1;margin:10pt 0;}'
            . 'table{border-collapse:collapse;width:100%;margin:8pt 0;font-size:9.5pt;}'
            . 'th,td{border:0.3mm solid #cbd5e1;padding:4pt 6pt;text-align:left;vertical-align:top;}'
            . 'th{background-color:#f1f5f9;font-weight:bold;}'
            . 'code,pre{font-family:dejavusansmono,monospace;font-size:9pt;}'
            . 'pre{background-color:#f8fafc;border:0.3mm solid #e2e8f0;padding:6pt;white-space:pre-wrap;}'
            . 'blockquote{margin:8pt 0;padding:4pt 10pt;border-left:1mm solid #cbd5e1;color:#475569;}'
            . 'img{max-width:180mm;}'
            . 'a{color:#1d4ed8;text-decoration:none;}'
            . '</style>';
    }
}
