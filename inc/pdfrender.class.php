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

            $mpdf->WriteHTML(self::css() . $html);

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
