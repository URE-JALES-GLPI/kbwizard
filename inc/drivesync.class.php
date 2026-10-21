<?php
/**
 * KB Wizard - Fila e execução da sincronização com o Google Drive
 *
 * Fluxo: qualquer criação/alteração de artigo (ou de passo manual) marca o
 * artigo como "pending". O cron processa a fila em lotes pequenos, gera o
 * Google Doc, exporta o PDF pelo próprio Google e mantém o mesmo fileId.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKbwizardDriveSync
{
    public const TABLE = 'glpi_plugin_kbwizard_drive_files';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED  = 'synced';
    public const STATUS_ERROR   = 'error';
    public const STATUS_SKIPPED = 'skipped';

    private const MAX_ATTEMPTS = 3;
    private const BATCH_SIZE   = 5;

    private static function tableExists(): bool
    {
        global $DB;
        return $DB && $DB->tableExists(self::TABLE);
    }

    /**
     * Marca um artigo como pendente de sincronização.
     */
    public static function markDirty(int $kbId, string $reason = ''): void
    {
        if ($kbId <= 0 || !self::tableExists()) {
            return;
        }
        global $DB;

        try {
            $state = self::getState($kbId);
            $now = date('Y-m-d H:i:s');
            if ($state !== null) {
                $DB->update(self::TABLE, [
                    'status'     => self::STATUS_PENDING,
                    'attempts'   => 0,
                    'last_error' => '',
                    'date_mod'   => $now,
                ], ['id' => (int)$state['id']]);
            } else {
                $DB->insert(self::TABLE, [
                    'knowbaseitems_id' => $kbId,
                    'doc_file_id'      => '',
                    'pdf_file_id'      => '',
                    'content_hash'     => '',
                    'status'           => self::STATUS_PENDING,
                    'attempts'         => 0,
                    'last_error'       => '',
                    'date_sync'        => null,
                    'date_mod'         => $now,
                ]);
            }
            if ($reason !== '' && class_exists('PluginKbwizardToolbox')) {
                PluginKbwizardToolbox::log("drive: kb=$kbId marcado pendente ($reason)");
            }
        } catch (Throwable $e) {
            if (class_exists('PluginKbwizardToolbox')) {
                PluginKbwizardToolbox::log('markDirty falhou kb=' . $kbId . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * @param mixed $step PluginKbwizardStep
     */
    public static function markDirtyByStep($step): void
    {
        try {
            if (is_object($step) && isset($step->fields['knowbaseitems_id'])) {
                self::markDirty((int)$step->fields['knowbaseitems_id'], 'passo alterado');
                return;
            }
            if (is_array($step) && isset($step['knowbaseitems_id'])) {
                self::markDirty((int)$step['knowbaseitems_id'], 'passo alterado');
            }
        } catch (Throwable $e) {
            // nunca deixar um hook derrubar a requisição
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getState(int $kbId): ?array
    {
        if ($kbId <= 0 || !self::tableExists()) {
            return null;
        }
        global $DB;
        try {
            foreach ($DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['knowbaseitems_id' => $kbId],
                'LIMIT' => 1,
            ]) as $row) {
                return $row;
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    /**
     * Processa a fila pendente.
     * @return array{processed:int,synced:int,failed:int,skipped:int,errors:array<int,string>}
     */
    public static function processQueue(int $limit = self::BATCH_SIZE): array
    {
        $result = ['processed' => 0, 'synced' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];

        if (!self::tableExists()) {
            return $result;
        }
        if (!PluginKbwizardDriveConfig::isConfigured()) {
            return $result;
        }

        global $DB;
        $ids = [];
        try {
            $iterator = $DB->request([
                'SELECT' => ['knowbaseitems_id'],
                'FROM'   => self::TABLE,
                'WHERE'  => ['status' => self::STATUS_PENDING],
                'ORDER'  => ['date_mod ASC', 'id ASC'],
                'LIMIT'  => max(1, $limit),
            ]);
            foreach ($iterator as $row) {
                $ids[] = (int)$row['knowbaseitems_id'];
            }
        } catch (Throwable $e) {
            if (class_exists('PluginKbwizardToolbox')) {
                PluginKbwizardToolbox::log('processQueue select falhou: ' . $e->getMessage());
            }
            return $result;
        }

        foreach ($ids as $kbId) {
            $result['processed']++;
            try {
                $outcome = self::syncItem($kbId);
                switch ($outcome['status']) {
                    case 'synced':
                        $result['synced']++;
                        break;
                    case 'error':
                        $result['failed']++;
                        $result['errors'][$kbId] = (string)($outcome['message'] ?? '');
                        break;
                    default:
                        $result['skipped']++;
                        break;
                }
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][$kbId] = $e->getMessage();
                self::registerFailure($kbId, $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Sincroniza um artigo.
     * @return array{status:string,message?:string,doc_id?:string,pdf_id?:string,changed?:bool}
     */
    public static function syncItem(int $kbId, bool $force = false): array
    {
        $cfg = PluginKbwizardDriveConfig::get();
        if (empty($cfg['is_active'])) {
            return ['status' => 'disabled', 'message' => __('Sincronização desativada.', 'kbwizard')];
        }
        if (PluginKbwizardDrive::fromConfig() === null) {
            $msg = (($cfg['auth_mode'] ?? 'oauth') !== 'service_account')
                ? __('Conta Google não conectada.', 'kbwizard')
                : __('Service account não configurada.', 'kbwizard');
            self::registerFailure($kbId, $msg);
            return ['status' => 'error', 'message' => $msg];
        }

        $kb = new KnowbaseItem();
        if (!$kb->getFromDB($kbId)) {
            self::registerFailure($kbId, __('Artigo não encontrado.', 'kbwizard'));
            return ['status' => 'error', 'message' => __('Artigo não encontrado.', 'kbwizard')];
        }
        if (!empty($kb->fields['is_deleted'])) {
            self::setStateValues($kbId, ['status' => self::STATUS_SKIPPED, 'last_error' => __('Artigo na lixeira.', 'kbwizard')]);
            return ['status' => 'skipped', 'message' => __('Artigo na lixeira.', 'kbwizard')];
        }

        $state = self::getState($kbId);
        $hash = PluginKbwizardDriveExport::contentHash($kb);

        if (!$force
            && $state !== null
            && ($state['status'] ?? '') === self::STATUS_SYNCED
            && hash_equals((string)($state['content_hash'] ?? ''), $hash)
        ) {
            return ['status' => 'unchanged'];
        }

        $client = PluginKbwizardDrive::fromConfig();
        if ($client === null) {
            $msg = __('Credenciais do Google Drive incompletas.', 'kbwizard');
            self::registerFailure($kbId, $msg);
            return ['status' => 'error', 'message' => $msg];
        }

        try {
            // A pasta pode ter sido criada agora; persiste para as próximas execuções.
            $folderId = $client->ensureFolder();
            PluginKbwizardDriveConfig::setFolderId($folderId);

            $docId = (string)($state['doc_file_id'] ?? '');
            $pdfId = (string)($state['pdf_file_id'] ?? '');

            if ($docId === '') {
                $existing = $client->findFileForKb($kbId, 'doc');
                if ($existing !== null) {
                    $docId = $existing['id'];
                }
            }
            if ($pdfId === '') {
                $existing = $client->findFileForKb($kbId, 'pdf');
                if ($existing !== null) {
                    $pdfId = $existing['id'];
                }
            }

            $name = PluginKbwizardDriveExport::buildName($kb);

            // PDF: por padrão o Google exporta a partir do Doc. Com o motor mPDF,
            // o próprio servidor renderiza — assim as imagens entram mesmo com o
            // GLPI em http/IP interno (o Google não alcançaria esse IP).
            $useLocalPdf = (($cfg['pdf_engine'] ?? 'google') === 'mpdf')
                && !empty($cfg['export_pdf'])
                && class_exists('PluginKbwizardPdfRender')
                && PluginKbwizardPdfRender::isAvailable();

            $needDoc = !empty($cfg['export_doc']) || (!empty($cfg['export_pdf']) && !$useLocalPdf);

            $html = '';
            if ($needDoc || $useLocalPdf) {
                $html = PluginKbwizardDriveExport::buildHtml($kb, true);
            }

            if ($needDoc) {
                $doc = $client->upsertDoc($docId, $name, $html, $kbId);
                $docId = $doc['id'];
            }

            if (!empty($cfg['export_pdf'])) {
                $pdfBytes = '';

                if ($useLocalPdf) {
                    try {
                        $pdfBytes = PluginKbwizardPdfRender::render($html, $name);
                    } catch (Throwable $e) {
                        if (class_exists('PluginKbwizardToolbox')) {
                            PluginKbwizardToolbox::log(
                                "drive: mPDF falhou no artigo $kbId, usando Google: " . $e->getMessage()
                            );
                        }
                        $pdfBytes = '';
                    }
                }

                // Fallback automático (mPDF ausente/com erro) e modo padrão.
                if ($pdfBytes === '' && $docId !== '') {
                    $pdfBytes = $client->exportPdf($docId);
                }

                if ($pdfBytes !== '') {
                    $pdf = $client->upsertPdf($pdfId, $name . '.pdf', $pdfBytes, $kbId);
                    $pdfId = $pdf['id'];
                }
            }

            self::setStateValues($kbId, [
                'doc_file_id'  => $docId,
                'pdf_file_id'  => $pdfId,
                'content_hash' => $hash,
                'status'       => self::STATUS_SYNCED,
                'attempts'     => 0,
                'last_error'   => '',
                'date_sync'    => date('Y-m-d H:i:s'),
            ]);

            if (class_exists('PluginKbwizardToolbox')) {
                PluginKbwizardToolbox::log("drive: kb=$kbId sincronizado (doc=$docId pdf=$pdfId)");
            }
            return ['status' => 'synced', 'doc_id' => $docId, 'pdf_id' => $pdfId, 'changed' => true];
        } catch (Throwable $e) {
            self::registerFailure($kbId, $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Incrementa tentativas e guarda o erro (vira 'error' após 3 tentativas).
     */
    public static function registerFailure(int $kbId, string $message): void
    {
        if (!self::tableExists()) {
            return;
        }
        $message = mb_substr(trim($message), 0, 5000);
        $state = self::getState($kbId);
        $attempts = (int)($state['attempts'] ?? 0) + 1;
        $status = $attempts >= self::MAX_ATTEMPTS ? self::STATUS_ERROR : self::STATUS_PENDING;

        self::setStateValues($kbId, [
            'status'     => $status,
            'attempts'   => $attempts,
            'last_error' => $message,
        ]);
        PluginKbwizardDriveConfig::setLastError(sprintf('Artigo #%d: %s', $kbId, $message));

        if (class_exists('PluginKbwizardToolbox')) {
            PluginKbwizardToolbox::log("drive: falha kb=$kbId tentativa=$attempts: $message");
        }
    }

    private static function setStateValues(int $kbId, array $values): void
    {
        if (!self::tableExists()) {
            return;
        }
        global $DB;
        $values['date_mod'] = date('Y-m-d H:i:s');

        try {
            $state = self::getState($kbId);
            if ($state !== null) {
                $DB->update(self::TABLE, $values, ['id' => (int)$state['id']]);
                return;
            }
            $DB->insert(self::TABLE, array_merge([
                'knowbaseitems_id' => $kbId,
                'doc_file_id'      => '',
                'pdf_file_id'      => '',
                'content_hash'     => '',
                'attempts'         => 0,
                'date_sync'        => null,
            ], $values));
        } catch (Throwable $e) {
            if (class_exists('PluginKbwizardToolbox')) {
                PluginKbwizardToolbox::log('setStateValues falhou kb=' . $kbId . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Enfileira todos os artigos visíveis da base (ação manual da configuração).
     */
    public static function markAllDirty(): int
    {
        if (!self::tableExists()) {
            return 0;
        }
        global $DB;
        $kbTable = KnowbaseItem::getTable();
        $now = date('Y-m-d H:i:s');

        try {
            $DB->doQuery(
                "INSERT INTO `" . self::TABLE . "`
                    (`knowbaseitems_id`, `doc_file_id`, `pdf_file_id`, `content_hash`, `status`, `attempts`, `last_error`, `date_sync`, `date_mod`)
                 SELECT `id`, '', '', '', '" . self::STATUS_PENDING . "', 0, '', NULL, '$now'
                 FROM `$kbTable` WHERE `is_deleted` = 0
                 ON DUPLICATE KEY UPDATE `status` = '" . self::STATUS_PENDING . "', `attempts` = 0, `last_error` = '', `date_mod` = '$now'"
            );
        } catch (Throwable $e) {
            // Fallback para versões do GLPI sem doQuery(): enfileira linha a linha.
            if (class_exists('PluginKbwizardToolbox')) {
                PluginKbwizardToolbox::log('markAllDirty via SQL falhou, usando fallback: ' . $e->getMessage());
            }
            $enqueued = 0;
            try {
                foreach ($DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => $kbTable,
                    'WHERE'  => ['is_deleted' => 0],
                ]) as $row) {
                    self::markDirty((int)$row['id']);
                    $enqueued++;
                }
            } catch (Throwable $e2) {
                return 0;
            }
            return $enqueued;
        }

        try {
            $count = 0;
            foreach ($DB->request(['SELECT' => ['COUNT' => 'id'], 'FROM' => self::TABLE, 'WHERE' => ['status' => self::STATUS_PENDING]]) as $row) {
                $count = (int)($row['COUNT'] ?? $row['count'] ?? 0);
            }
            return $count;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Recoloca erros na fila (botão "Reprocessar erros").
     */
    public static function retryErrors(): int
    {
        if (!self::tableExists()) {
            return 0;
        }
        global $DB;
        try {
            $count = 0;
            foreach ($DB->request([
                'SELECT' => ['COUNT' => 'id'],
                'FROM'   => self::TABLE,
                'WHERE'  => ['status' => self::STATUS_ERROR],
            ]) as $row) {
                $count = (int)($row['COUNT'] ?? $row['count'] ?? 0);
            }
            if ($count === 0) {
                return 0;
            }
            $DB->update(self::TABLE, [
                'status'     => self::STATUS_PENDING,
                'attempts'   => 0,
                'last_error' => '',
                'date_mod'   => date('Y-m-d H:i:s'),
            ], ['status' => self::STATUS_ERROR]);
            return $count;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array{pending:int,synced:int,error:int,skipped:int,total:int}
     */
    public static function stats(): array
    {
        $stats = ['pending' => 0, 'synced' => 0, 'error' => 0, 'skipped' => 0, 'total' => 0];
        if (!self::tableExists()) {
            return $stats;
        }
        global $DB;
        try {
            foreach (['pending', 'synced', 'error', 'skipped'] as $status) {
                $count = 0;
                foreach ($DB->request([
                    'SELECT' => ['COUNT' => 'id'],
                    'FROM'   => self::TABLE,
                    'WHERE'  => ['status' => $status],
                ]) as $row) {
                    $count = (int)($row['COUNT'] ?? $row['count'] ?? 0);
                }
                $stats[$status] = $count;
                $stats['total'] += $count;
            }
        } catch (Throwable $e) {
            return $stats;
        }
        return $stats;
    }

    /**
     * Remove os arquivos espelho quando o artigo é excluído em definitivo.
     */
    public static function purgeItem(int $kbId): void
    {
        if ($kbId <= 0) {
            return;
        }
        $state = self::getState($kbId);

        try {
            $client = PluginKbwizardDrive::fromConfig();
            if ($client !== null && $state !== null) {
                foreach (['doc_file_id', 'pdf_file_id'] as $key) {
                    $fileId = (string)($state[$key] ?? '');
                    if ($fileId !== '') {
                        $client->trash($fileId);
                    }
                }
            }
        } catch (Throwable $e) {
            if (class_exists('PluginKbwizardToolbox')) {
                PluginKbwizardToolbox::log('purgeItem drive kb=' . $kbId . ': ' . $e->getMessage());
            }
        }

        if (!self::tableExists()) {
            return;
        }
        global $DB;
        try {
            $DB->delete(self::TABLE, ['knowbaseitems_id' => $kbId]);
        } catch (Throwable $e) {
            // silencioso
        }
    }
}
