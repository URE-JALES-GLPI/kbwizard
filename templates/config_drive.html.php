<?php
/**
 * KB Wizard - Tela de configuração da sincronização com o Google Drive
 *
 * Variáveis esperadas (definidas em front/config.php):
 * $driveCfg, $driveStats, $driveHasSa, $driveSaEmail, $driveTest,
 * $driveActionUrl, $driveWebDir, $driveDataDir
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

$cfg = is_array($driveCfg ?? null) ? $driveCfg : [];
$stats = is_array($driveStats ?? null) ? $driveStats : ['pending' => 0, 'synced' => 0, 'error' => 0, 'skipped' => 0, 'total' => 0];
$isActive = !empty($cfg['is_active']);
$exportDoc = !empty($cfg['export_doc']);
$exportPdf = !empty($cfg['export_pdf']);
$includeImages = !empty($cfg['include_images']);
$hasSa = !empty($driveHasSa);
$lastError = trim((string)($cfg['last_error'] ?? ''));

$esc = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
?>
<div class="card mb-3" id="kbwizard-drive-config">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="mb-0">
            <i class="ti ti-brand-google-drive me-2"></i>
            <?= $esc(__('Sincronização com o Google Drive', 'kbwizard')) ?>
        </h3>
        <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?>">
            <?= $isActive ? $esc(__('ativa', 'kbwizard')) : $esc(__('inativa', 'kbwizard')) ?>
        </span>
    </div>

    <div class="card-body">
        <p class="text-muted mb-3">
            <?= $esc(__('Espelha os artigos da Base de Conhecimento em um Shared Drive como Google Doc + PDF. O NotebookLM sincroniza automaticamente apenas Google Docs nativos, por isso o Doc é o arquivo de referência.', 'kbwizard')) ?>
        </p>

        <?php if (is_array($driveTest ?? null)): ?>
            <div class="alert <?= !empty($driveTest['ok']) ? 'alert-success' : 'alert-danger' ?>">
                <strong><?= $esc((string)($driveTest['message'] ?? '')) ?></strong>
                <?php if (!empty($driveTest['ok'])): ?>
                    <div class="small mt-1">
                        <?= $esc(__('Shared Drive:', 'kbwizard')) ?> <?= $esc((string)($driveTest['drive'] ?? '-')) ?>
                        <?php if (!empty($driveTest['folder_url'])): ?>
                            &middot;
                            <a href="<?= $esc($driveTest['folder_url']) ?>" target="_blank" rel="noopener">
                                <?= $esc(__('abrir pasta no Drive', 'kbwizard')) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$hasSa || trim((string)($cfg['shared_drive_id'] ?? '')) === ''): ?>
            <div class="alert alert-warning">
                <?= $esc(__('Falta configurar a service account e/ou o Shared Drive. Sem isso a sincronização não roda.', 'kbwizard')) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= $esc($driveActionUrl) ?>" enctype="multipart/form-data" data-submit-once>
            <input type="hidden" name="_glpi_csrf_token" value="<?= $esc(Session::getNewCSRFToken()) ?>" />

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-check form-switch">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
                        <span class="form-check-label"><?= $esc(__('Ativar sincronização', 'kbwizard')) ?></span>
                    </label>
                    <div class="form-hint"><?= $esc(__('Quando ativa, o cron processa a fila a cada minuto.', 'kbwizard')) ?></div>
                </div>

                <div class="col-md-4">
                    <label class="form-check form-switch">
                        <input type="hidden" name="export_doc" value="0">
                        <input class="form-check-input" type="checkbox" name="export_doc" value="1" <?= $exportDoc ? 'checked' : '' ?>>
                        <span class="form-check-label"><?= $esc(__('Manter Google Doc', 'kbwizard')) ?></span>
                    </label>
                    <div class="form-hint"><?= $esc(__('Arquivo usado pelo NotebookLM (sync automático).', 'kbwizard')) ?></div>
                </div>

                <div class="col-md-4">
                    <label class="form-check form-switch">
                        <input type="hidden" name="export_pdf" value="0">
                        <input class="form-check-input" type="checkbox" name="export_pdf" value="1" <?= $exportPdf ? 'checked' : '' ?>>
                        <span class="form-check-label"><?= $esc(__('Gerar PDF', 'kbwizard')) ?></span>
                    </label>
                    <div class="form-hint"><?= $esc(__('O PDF é exportado pelo próprio Google a partir do Doc.', 'kbwizard')) ?></div>
                </div>

                <div class="col-md-4">
                    <label class="form-check form-switch">
                        <input type="hidden" name="include_images" value="0">
                        <input class="form-check-input" type="checkbox" name="include_images" value="1" <?= $includeImages ? 'checked' : '' ?>>
                        <span class="form-check-label"><?= $esc(__('Incluir imagens', 'kbwizard')) ?></span>
                    </label>
                    <div class="form-hint"><?= $esc(__('Imagens anexadas ao artigo são servidas por URL assinada temporária.', 'kbwizard')) ?></div>
                </div>

                <div class="col-md-8">
                    <label class="form-label" for="kbwizard-shared-drive">
                        <?= $esc(__('ID do Shared Drive', 'kbwizard')) ?>
                    </label>
                    <input type="text" class="form-control" id="kbwizard-shared-drive" name="shared_drive_id"
                           value="<?= $esc($cfg['shared_drive_id'] ?? '') ?>"
                           placeholder="0AJ...">
                    <div class="form-hint">
                        <?= $esc(__('Está na URL do Drive compartilhado: drive.google.com/drive/folders/&lt;ID&gt;. A service account precisa ser membro com "Gerente de conteúdo".', 'kbwizard')) ?>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="kbwizard-folder-name"><?= $esc(__('Nome da pasta', 'kbwizard')) ?></label>
                    <input type="text" class="form-control" id="kbwizard-folder-name" name="folder_name"
                           value="<?= $esc($cfg['folder_name'] ?? '') ?>"
                           placeholder="KB Wizard - Base de Conhecimento">
                    <div class="form-hint"><?= $esc(__('Criada automaticamente dentro do Shared Drive se não existir.', 'kbwizard')) ?></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="kbwizard-folder-id"><?= $esc(__('ID da pasta (opcional)', 'kbwizard')) ?></label>
                    <input type="text" class="form-control" id="kbwizard-folder-id" name="folder_id"
                           value="<?= $esc($cfg['folder_id'] ?? '') ?>">
                    <div class="form-hint"><?= $esc(__('Preenchido automaticamente após "Testar conexão". Use só se quiser apontar para uma pasta existente.', 'kbwizard')) ?></div>
                </div>

                <div class="col-12">
                    <hr>
                    <h4 class="mb-2"><?= $esc(__('Credencial (service account)', 'kbwizard')) ?></h4>
                    <?php if ($hasSa): ?>
                        <div class="alert alert-info py-2 mb-3">
                            <i class="ti ti-key me-1"></i>
                            <?= $esc(__('Credencial instalada:', 'kbwizard')) ?>
                            <strong><?= $esc($driveSaEmail !== '' ? $driveSaEmail : __('service account', 'kbwizard')) ?></strong>
                            <div class="small text-muted mt-1">
                                <?= $esc(__('Arquivo:', 'kbwizard')) ?> <code><?= $esc($driveDataDir . '/service-account.json') ?></code>
                            </div>
                            <label class="form-check mt-2 mb-0">
                                <input class="form-check-input" type="checkbox" name="remove_sa" value="1">
                                <span class="form-check-label"><?= $esc(__('Remover credencial ao salvar', 'kbwizard')) ?></span>
                            </label>
                        </div>
                    <?php endif; ?>
                    <label class="form-label" for="kbwizard-sa-json">
                        <?= $esc($hasSa ? __('Substituir JSON da service account', 'kbwizard') : __('Enviar JSON da service account', 'kbwizard')) ?>
                    </label>
                    <input type="file" class="form-control" id="kbwizard-sa-json" name="sa_json" accept=".json,application/json">
                    <div class="form-hint">
                        <?= $esc(__('O arquivo fica em files/_plugins/kbwizard/, fora do webroot. Compartilhe o Shared Drive com o e-mail da service account.', 'kbwizard')) ?>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <button type="submit" name="save_drive_settings" value="1" class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1"></i><?= $esc(__('Salvar', 'kbwizard')) ?>
                </button>
                <button type="submit" name="test_drive" value="1" class="btn btn-outline-primary">
                    <i class="ti ti-plug-connected me-1"></i><?= $esc(__('Testar conexão', 'kbwizard')) ?>
                </button>
                <button type="submit" name="sync_all" value="1" class="btn btn-outline-secondary">
                    <i class="ti ti-refresh me-1"></i><?= $esc(__('Sincronizar todos os artigos', 'kbwizard')) ?>
                </button>
                <button type="submit" name="retry_errors" value="1" class="btn btn-outline-warning">
                    <i class="ti ti-alert-triangle me-1"></i><?= $esc(__('Reprocessar erros', 'kbwizard')) ?>
                </button>
            </div>
        </form>

        <hr>

        <div class="row g-2">
            <div class="col-auto">
                <span class="badge bg-warning text-dark">
                    <?= $esc(sprintf(__('Pendentes: %d', 'kbwizard'), (int)$stats['pending'])) ?>
                </span>
            </div>
            <div class="col-auto">
                <span class="badge bg-success">
                    <?= $esc(sprintf(__('Sincronizados: %d', 'kbwizard'), (int)$stats['synced'])) ?>
                </span>
            </div>
            <div class="col-auto">
                <span class="badge bg-danger">
                    <?= $esc(sprintf(__('Erros: %d', 'kbwizard'), (int)$stats['error'])) ?>
                </span>
            </div>
            <div class="col-auto">
                <span class="badge bg-secondary">
                    <?= $esc(sprintf(__('Ignorados: %d', 'kbwizard'), (int)$stats['skipped'])) ?>
                </span>
            </div>
        </div>

        <?php if ($lastError !== ''): ?>
            <div class="alert alert-danger mt-3 mb-0">
                <strong><?= $esc(__('Último erro:', 'kbwizard')) ?></strong>
                <pre class="mb-0 mt-2" style="white-space:pre-wrap;max-height:220px;overflow:auto"><?= $esc($lastError) ?></pre>
            </div>
        <?php endif; ?>

        <div class="alert alert-secondary mt-3 mb-0 small">
            <?= $esc(__('Como funciona: ao salvar um artigo (ou um passo manual), ele entra na fila. O cron gera/atualiza o Google Doc no mesmo fileId (o NotebookLM mantém a fonte) e exporta o PDF. A primeira sincronização completa pode levar alguns minutos.', 'kbwizard')) ?>
        </div>
    </div>
</div>
