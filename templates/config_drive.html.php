<?php
/**
 * KB Wizard - Tela de configuração da sincronização com o Google Drive
 *
 * Variáveis esperadas (definidas em front/config.php):
 * $driveCfg, $driveStats, $driveHasSa, $driveSaEmail, $driveTest,
 * $driveActionUrl, $driveWebDir, $driveDataDir, $driveAuthMode,
 * $driveGoogleEmail, $driveHasSecret, $driveRedirectUri
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

$cfg = is_array($driveCfg ?? null) ? $driveCfg : [];
$stats = is_array($driveStats ?? null) ? $driveStats : ['pending' => 0, 'synced' => 0, 'error' => 0, 'skipped' => 0, 'disabled' => 0, 'total' => 0];
$isActive = !empty($cfg['is_active']);
$exportDoc = !empty($cfg['export_doc']);
$exportPdf = !empty($cfg['export_pdf']);
$includeImages = !empty($cfg['include_images']);
$hasSa = !empty($driveHasSa);
$lastError = trim((string)($cfg['last_error'] ?? ''));

$authMode = (string)($driveAuthMode ?? ($cfg['auth_mode'] ?? 'oauth'));
if (!in_array($authMode, ['oauth', 'device', 'service_account'], true)) {
    $authMode = 'oauth';
}
$isOAuth = $authMode === 'oauth';
$isDevice = $authMode === 'device';
$isGoogle = $isOAuth || $isDevice;
$device = is_array($driveDevice ?? null) ? $driveDevice : null;
$googleEmail = trim((string)($driveGoogleEmail ?? ($cfg['google_email'] ?? '')));
$hasSecret = !empty($driveHasSecret);
$hasRefresh = trim((string)($cfg['refresh_token'] ?? '')) !== '';
$redirectUri = (string)($driveRedirectUri ?? '');
$sharedDriveId = trim((string)($cfg['shared_drive_id'] ?? ''));
$needsSetup = $isGoogle
    ? (trim((string)($cfg['client_id'] ?? '')) === '' || !$hasRefresh)
    : (!$hasSa || $sharedDriveId === '');

$pdfEngine = (string)($drivePdfEngine ?? ($cfg['pdf_engine'] ?? 'google'));
if (!in_array($pdfEngine, ['google', 'mpdf'], true)) {
    $pdfEngine = 'google';
}
$pdfStatus = is_array($drivePdfStatus ?? null) ? $drivePdfStatus : [];
$pdfAvailable = !empty($pdfStatus['available']);
$pdfVersion = (string)($pdfStatus['version'] ?? '');
$pdfTempDir = (string)($pdfStatus['temp_dir'] ?? '');

$overview = is_array($driveOverview ?? null) ? $driveOverview : [];
$kbUrl = ($CFG_GLPI['root_doc'] ?? '') . '/front/knowbaseitem.form.php?id=';

$esc = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$csrfToken = (defined('GLPI_VERSION') && version_compare(GLPI_VERSION, '12.0', '>='))
    ? ''
    : Session::getNewCSRFToken();
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
            <?= $esc(__('Espelha os artigos da Base de Conhecimento no Google Drive como Google Doc + PDF. O NotebookLM sincroniza automaticamente apenas Google Docs nativos, por isso o Doc é o arquivo de referência. Somente os artigos marcados com "Sincronizar com Google Drive" entram na fila.', 'kbwizard')) ?>
        </p>

        <?php if (is_array($driveTest ?? null)): ?>
            <div class="alert <?= !empty($driveTest['ok']) ? 'alert-success' : 'alert-danger' ?>">
                <strong><?= $esc((string)($driveTest['message'] ?? '')) ?></strong>
                <?php if (!empty($driveTest['ok']) && (!empty($driveTest['drive']) || !empty($driveTest['folder_url']))): ?>
                    <div class="small mt-1">
                        <?php if (!empty($driveTest['drive'])): ?>
                            <?= $esc(__('Drive:', 'kbwizard')) ?> <?= $esc((string)$driveTest['drive']) ?>
                        <?php endif; ?>
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

        <?php if ($needsSetup): ?>
            <div class="alert alert-warning">
                <?php if ($isGoogle): ?>
                    <?= $esc(__('Conecte a conta Google (Client ID/secret + botão de conexão). Sem isso a sincronização não roda.', 'kbwizard')) ?>
                <?php else: ?>
                    <?= $esc(__('Falta configurar a service account e/ou o Shared Drive. Sem isso a sincronização não roda.', 'kbwizard')) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= $esc($driveActionUrl) ?>" enctype="multipart/form-data" data-submit-once>
            <input type="hidden" name="_glpi_csrf_token" value="<?= $esc($csrfToken) ?>" />

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
                    <label class="form-label" for="kbwizard-pdf-engine"><?= $esc(__('Motor do PDF', 'kbwizard')) ?></label>
                    <select class="form-select" id="kbwizard-pdf-engine" name="pdf_engine">
                        <option value="google" <?= $pdfEngine === 'google' ? 'selected' : '' ?>>
                            <?= $esc(__('Google (exporta do Doc)', 'kbwizard')) ?>
                        </option>
                        <option value="mpdf" <?= $pdfEngine === 'mpdf' ? 'selected' : '' ?>>
                            <?= $esc(__('mPDF (gera no servidor)', 'kbwizard')) ?>
                        </option>
                    </select>
                    <div class="form-hint">
                        <?php if ($pdfEngine === 'mpdf' && !$pdfAvailable): ?>
                            <span class="text-danger">
                                <?= $esc(__('mPDF não instalado — o plugin vai usar o Google até você rodar "composer require mpdf/mpdf".', 'kbwizard')) ?>
                            </span>
                        <?php elseif ($pdfAvailable && empty($pdfStatus['gd'])): ?>
                            <span class="text-danger">
                                <?= $esc(__('mPDF instalado, mas a extensão GD está desativada: imagens não entram no PDF. Habilite extension=gd no php.ini.', 'kbwizard')) ?>
                            </span>
                        <?php elseif ($pdfAvailable): ?>
                            <?= $esc(sprintf(__('mPDF %s disponível (GD ok). Imagens são baixadas pelo servidor e embutidas no PDF.', 'kbwizard'), $pdfVersion !== '' ? $pdfVersion : '')) ?>
                        <?php else: ?>
                            <?= $esc(__('Google: sem dependências. mPDF: gera no servidor e embute as imagens do artigo (requer composer).', 'kbwizard')) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-check form-switch">
                        <input type="hidden" name="include_images" value="0">
                        <input class="form-check-input" type="checkbox" name="include_images" value="1" <?= $includeImages ? 'checked' : '' ?>>
                        <span class="form-check-label"><?= $esc(__('Incluir imagens', 'kbwizard')) ?></span>
                    </label>
                    <div class="form-hint"><?= $esc(__('Imagens anexadas ao artigo são servidas por URL assinada temporária.', 'kbwizard')) ?></div>
                </div>

                <div class="col-12">
                    <hr>
                    <label class="form-label d-block"><?= $esc(__('Autenticação', 'kbwizard')) ?></label>
                    <div class="d-flex flex-wrap gap-4">
                        <label class="form-check">
                            <input class="form-check-input" type="radio" name="auth_mode" value="oauth" <?= $isOAuth ? 'checked' : '' ?>>
                            <span class="form-check-label">
                                <?= $esc(__('Conta Google — GLPI com HTTPS', 'kbwizard')) ?>
                            </span>
                        </label>
                        <label class="form-check">
                            <input class="form-check-input" type="radio" name="auth_mode" value="device" <?= $isDevice ? 'checked' : '' ?>>
                            <span class="form-check-label">
                                <?= $esc(__('Conta Google — código (GLPI interno, sem HTTPS)', 'kbwizard')) ?>
                            </span>
                        </label>
                        <label class="form-check">
                            <input class="form-check-input" type="radio" name="auth_mode" value="service_account" <?= !$isGoogle ? 'checked' : '' ?>>
                            <span class="form-check-label">
                                <?= $esc(__('Service account (Google Workspace + Shared Drive)', 'kbwizard')) ?>
                            </span>
                        </label>
                    </div>
                    <div class="form-hint">
                        <?= $esc(__('A conta Google usa escopo drive.file: o plugin só enxerga os arquivos que ele mesmo cria. Use "código" quando o GLPI não tiver URL pública HTTPS — o Google recusa redirect em http ou IP.', 'kbwizard')) ?>
                    </div>
                </div>

                <?php if ($isGoogle): ?>
                    <div class="col-12">
                        <hr>
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                            <h4 class="mb-0"><?= $esc(__('Conta Google conectada', 'kbwizard')) ?></h4>
                            <?php if ($hasRefresh): ?>
                                <span class="badge bg-success">
                                    <i class="ti ti-circle-check me-1"></i>
                                    <?= $esc($googleEmail !== '' ? $googleEmail : __('conectada', 'kbwizard')) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><?= $esc(__('não conectada', 'kbwizard')) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="kbwizard-client-id"><?= $esc(__('Client ID', 'kbwizard')) ?></label>
                                <input type="text" class="form-control" id="kbwizard-client-id" name="client_id"
                                       value="<?= $esc($cfg['client_id'] ?? '') ?>"
                                       placeholder="000000000000-xxxx.apps.googleusercontent.com"
                                       autocomplete="off">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="kbwizard-client-secret"><?= $esc(__('Client secret', 'kbwizard')) ?></label>
                                <input type="password" class="form-control" id="kbwizard-client-secret" name="client_secret"
                                       value=""
                                       placeholder="<?= $hasSecret ? $esc(__('•••••••• (já salvo)', 'kbwizard')) : 'GOCSPX-...' ?>"
                                       autocomplete="new-password">
                                <div class="form-hint"><?= $esc(__('Deixe em branco para manter o segredo atual.', 'kbwizard')) ?></div>
                            </div>

                            <?php if ($isOAuth): ?>
                            <div class="col-12">
                                <label class="form-label" for="kbwizard-redirect-uri"><?= $esc(__('Redirect URI (cadastre no Google Cloud)', 'kbwizard')) ?></label>
                                <input type="text" class="form-control" id="kbwizard-redirect-uri"
                                       value="<?= $esc($redirectUri) ?>" readonly onclick="this.select();">
                                <div class="form-hint">
                                    <?= $esc(__('Em Google Cloud > Credentials > OAuth client, adicione exatamente esta URL em "Authorized redirect URIs". O Google exige https (só aceita http em localhost).', 'kbwizard')) ?>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="submit" name="oauth_connect" value="1" class="btn btn-outline-danger">
                                        <i class="ti ti-brand-google me-1"></i>
                                        <?= $hasRefresh ? $esc(__('Reconectar conta Google', 'kbwizard')) : $esc(__('Conectar conta Google', 'kbwizard')) ?>
                                    </button>
                                    <?php if ($hasRefresh): ?>
                                        <button type="submit" name="oauth_disconnect" value="1" class="btn btn-outline-secondary">
                                            <i class="ti ti-plug-off me-1"></i><?= $esc(__('Desconectar', 'kbwizard')) ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="form-hint">
                                    <?= $esc(__('Ao conectar, o Google mostra um aviso de app não verificado. É esperado: clique em "Avançado" e depois em "Acessar".', 'kbwizard')) ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="col-12">
                                <?php if (is_array($device)): ?>
                                    <div class="alert alert-info">
                                        <p class="mb-2">
                                            <?= $esc(__('Abra a página abaixo em qualquer navegador, escolha a conta Google do plugin e digite o código:', 'kbwizard')) ?>
                                        </p>
                                        <p class="mb-2">
                                            <a href="<?= $esc($device['url'] ?? 'https://www.google.com/device') ?>" target="_blank" rel="noopener">
                                                <?= $esc($device['url'] ?? 'https://www.google.com/device') ?>
                                            </a>
                                        </p>
                                        <div class="fs-3 fw-bold mb-3" style="letter-spacing:.25em;font-family:monospace">
                                            <?= $esc($device['user_code'] ?? '') ?>
                                        </div>
                                        <p class="small mb-2">
                                            <?= $esc(sprintf(
                                                __('O código expira às %s. Depois de autorizar, clique em "Já autorizei".', 'kbwizard'),
                                                date('H:i', (int)($device['expires_at'] ?? time()))
                                            )) ?>
                                        </p>
                                        <div class="d-flex flex-wrap gap-2">
                                            <button type="submit" name="device_poll" value="1" class="btn btn-success">
                                                <i class="ti ti-circle-check me-1"></i><?= $esc(__('Já autorizei', 'kbwizard')) ?>
                                            </button>
                                            <button type="submit" name="device_cancel" value="1" class="btn btn-outline-secondary">
                                                <?= $esc(__('Descartar código', 'kbwizard')) ?>
                                            </button>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <button type="submit" name="device_start" value="1" class="btn btn-outline-danger">
                                            <i class="ti ti-brand-google me-1"></i><?= $esc(__('Gerar código de conexão', 'kbwizard')) ?>
                                        </button>
                                        <?php if ($hasRefresh): ?>
                                            <button type="submit" name="oauth_disconnect" value="1" class="btn btn-outline-secondary">
                                                <i class="ti ti-plug-off me-1"></i><?= $esc(__('Desconectar', 'kbwizard')) ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="form-hint">
                                    <?= $esc(__('Use esta opção quando o GLPI roda em http/IP interno: o Google recusa redirect URI em http ou IP, mas o fluxo por código não usa redirect.', 'kbwizard')) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="col-12">
                        <hr>
                        <h4 class="mb-2"><?= $esc(__('Shared Drive (service account)', 'kbwizard')) ?></h4>
                        <label class="form-label" for="kbwizard-shared-drive">
                            <?= $esc(__('ID do Shared Drive', 'kbwizard')) ?>
                        </label>
                        <input type="text" class="form-control" id="kbwizard-shared-drive" name="shared_drive_id"
                               value="<?= $esc($sharedDriveId) ?>"
                               placeholder="0AJ...">
                        <div class="form-hint">
                            <?= $esc(__('Está na URL do Drive compartilhado: drive.google.com/drive/folders/&lt;ID&gt;. A service account precisa ser membro com "Gerente de conteúdo".', 'kbwizard')) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="col-md-6">
                    <label class="form-label" for="kbwizard-folder-name"><?= $esc(__('Nome da pasta', 'kbwizard')) ?></label>
                    <input type="text" class="form-control" id="kbwizard-folder-name" name="folder_name"
                           value="<?= $esc($cfg['folder_name'] ?? '') ?>"
                           placeholder="KB Wizard - Base de Conhecimento">
                    <div class="form-hint">
                        <?= $esc($isGoogle
                            ? __('Criada automaticamente no Meu Drive. Deixe o plugin criar — o escopo drive.file não enxerga pastas criadas manualmente.', 'kbwizard')
                            : __('Criada automaticamente dentro do Shared Drive se não existir.', 'kbwizard')) ?>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="kbwizard-folder-id"><?= $esc(__('ID da pasta (opcional)', 'kbwizard')) ?></label>
                    <input type="text" class="form-control" id="kbwizard-folder-id" name="folder_id"
                           value="<?= $esc($cfg['folder_id'] ?? '') ?>">
                    <div class="form-hint"><?= $esc(__('Preenchido automaticamente após "Testar conexão". Use só se quiser apontar para uma pasta existente.', 'kbwizard')) ?></div>
                </div>

                <?php if (!$isGoogle): ?>
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
                <?php endif; ?>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <button type="submit" name="save_drive_settings" value="1" class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1"></i><?= $esc(__('Salvar', 'kbwizard')) ?>
                </button>
                <button type="submit" name="test_drive" value="1" class="btn btn-outline-primary">
                    <i class="ti ti-plug-connected me-1"></i><?= $esc(__('Testar conexão', 'kbwizard')) ?>
                </button>
                <button type="submit" name="sync_all" value="1" class="btn btn-outline-secondary">
                    <i class="ti ti-refresh me-1"></i><?= $esc(__('Sincronizar artigos marcados', 'kbwizard')) ?>
                </button>
                <button type="submit" name="process_now" value="1" class="btn btn-outline-success" id="kbw-process-now" data-kbw-process="1">
                    <i class="ti ti-player-play me-1"></i><?= $esc(__('Processar fila agora', 'kbwizard')) ?>
                </button>
                <button type="submit" name="retry_errors" value="1" class="btn btn-outline-warning">
                    <i class="ti ti-alert-triangle me-1"></i><?= $esc(__('Reprocessar erros', 'kbwizard')) ?>
                </button>
            </div>
        </form>

        <div id="kbw-drive-progress" class="mt-3 d-none" data-kbw-progress
             data-endpoint="<?= $esc($driveActionUrl) ?>"
             data-pending="<?= (int)$stats['pending'] ?>"
             data-configured="<?= PluginKbwizardDriveConfig::isConfigured() ? '1' : '0' ?>">
            <div class="alert alert-warning d-flex align-items-start gap-2 mb-2">
                <i class="ti ti-alert-triangle mt-1"></i>
                <div>
                    <strong><?= $esc(__('Não feche esta aba durante o processamento.', 'kbwizard')) ?></strong>
                    <div class="small">
                        <?= $esc(__('A fila é processada em lotes de até 10 artigos. Se você fechar ou atualizar a página, o processamento para e os itens restantes continuam pendentes.', 'kbwizard')) ?>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span id="kbw-drive-progress-title"><?= $esc(__('Processando fila...', 'kbwizard')) ?></span>
                <span id="kbw-drive-progress-count" class="small text-muted"></span>
            </div>
            <div class="progress" style="height: 1.25rem;" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div id="kbw-drive-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: 0%;">0%</div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2 small">
                <span class="badge bg-success"><?= $esc(__('Sincronizados:', 'kbwizard')) ?> <span id="kbw-drive-synced">0</span></span>
                <span class="badge bg-danger"><?= $esc(__('Erros:', 'kbwizard')) ?> <span id="kbw-drive-failed">0</span></span>
                <span class="badge bg-secondary"><?= $esc(__('Ignorados:', 'kbwizard')) ?> <span id="kbw-drive-skipped">0</span></span>
                <span class="badge bg-warning text-dark"><?= $esc(__('Restantes:', 'kbwizard')) ?> <span id="kbw-drive-remaining"><?= (int)$stats['pending'] ?></span></span>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-outline-danger" id="kbw-drive-cancel">
                    <?= $esc(__('Cancelar após o lote atual', 'kbwizard')) ?>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="kbw-drive-reload">
                    <?= $esc(__('Atualizar página', 'kbwizard')) ?>
                </button>
            </div>
            <div id="kbw-drive-summary" class="mt-2 small" aria-live="polite"></div>
        </div>

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
            <div class="col-auto">
                <span class="badge bg-dark">
                    <?= $esc(sprintf(__('Desativados: %d', 'kbwizard'), (int)($stats['disabled'] ?? 0))) ?>
                </span>
            </div>
        </div>

        <?php if ($lastError !== ''): ?>
            <div class="alert alert-danger mt-3 mb-0">
                <strong><?= $esc(__('Último erro:', 'kbwizard')) ?></strong>
                <pre class="mb-0 mt-2" style="white-space:pre-wrap;max-height:220px;overflow:auto"><?= $esc($lastError) ?></pre>
            </div>
        <?php endif; ?>

        <?php if ($overview !== []): ?>
            <h4 class="mt-4 mb-2"><?= $esc(__('Status por artigo', 'kbwizard')) ?></h4>
            <form method="post" action="<?= $esc($driveActionUrl) ?>" data-submit-once>
                <input type="hidden" name="_glpi_csrf_token" value="<?= $esc($csrfToken) ?>" />
                <div class="table-responsive" style="max-height:420px;overflow:auto">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light" style="position:sticky;top:0;z-index:1">
                            <tr>
                                <th style="width:90px"><?= $esc(__('Sincronizar', 'kbwizard')) ?></th>
                                <th><?= $esc(__('Artigo', 'kbwizard')) ?></th>
                                <th><?= $esc(__('Status', 'kbwizard')) ?></th>
                                <th>Doc</th>
                                <th>PDF</th>
                                <th><?= $esc(__('Tentativas', 'kbwizard')) ?></th>
                                <th><?= $esc(__('Última sincronização', 'kbwizard')) ?></th>
                                <th><?= $esc(__('Erro', 'kbwizard')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($overview as $row):
                            $status     = (string)($row['status'] ?? '');
                            $kbId       = (int)($row['knowbaseitems_id'] ?? 0);
                            $docFileId  = (string)($row['doc_file_id'] ?? '');
                            $pdfFileId  = (string)($row['pdf_file_id'] ?? '');
                            $rowError   = trim((string)($row['last_error'] ?? ''));
                            $syncedAt   = (string)($row['date_sync'] ?? '');
                            $attempts   = (int)($row['attempts'] ?? 0);
                            if ($status === 'synced') {
                                $label = __('sincronizado', 'kbwizard');
                                $badge = 'bg-success';
                            } elseif ($status === 'error') {
                                $label = __('erro', 'kbwizard');
                                $badge = 'bg-danger';
                            } elseif ($status === 'disabled') {
                                $label = __('sem sincronização', 'kbwizard');
                                $badge = 'bg-secondary';
                            } elseif ($status === 'skipped') {
                                $label = __('ignorado', 'kbwizard');
                                $badge = 'bg-secondary';
                            } else {
                                $label = __('pendente', 'kbwizard');
                                $badge = 'bg-warning text-dark';
                            }
                        ?>
                            <tr>
                                <td class="text-center">
                                    <input type="hidden" name="drive_sync_rows[]" value="<?= $kbId ?>">
                                    <input class="form-check-input" type="checkbox" name="drive_sync_kb[]" value="<?= $kbId ?>" <?= !empty($row['drive_sync']) ? 'checked' : '' ?> aria-label="<?= $esc(__('Sincronizar este artigo', 'kbwizard')) ?>">
                                </td>
                                <td>
                                    <a href="<?= $esc($kbUrl . $kbId) ?>">
                                        <?= $esc((string)($row['kb_name'] ?? ('#' . $kbId))) ?>
                                    </a>
                                </td>
                                <td><span class="badge <?= $badge ?>"><?= $esc($label) ?></span></td>
                                <td>
                                    <?php if ($docFileId !== ''): ?>
                                        <a href="https://docs.google.com/document/d/<?= $esc(rawurlencode($docFileId)) ?>/edit" target="_blank" rel="noopener">Doc</a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($pdfFileId !== ''): ?>
                                        <a href="https://drive.google.com/file/d/<?= $esc(rawurlencode($pdfFileId)) ?>/view" target="_blank" rel="noopener">PDF</a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td><?= $attempts ?></td>
                                <td class="small"><?= $esc($syncedAt !== '' ? $syncedAt : '—') ?></td>
                                <td class="small text-danger" style="max-width:280px">
                                    <?= $esc($rowError !== '' ? mb_substr($rowError, 0, 160) : '') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                    <button type="submit" name="save_drive_sync" value="1" class="btn btn-primary btn-sm">
                        <i class="ti ti-device-floppy me-1"></i><?= $esc(__('Salvar seleção', 'kbwizard')) ?>
                    </button>
                    <span class="form-hint mb-0">
                        <?= $esc(__('Marque os artigos que devem ser espelhados no Drive. Desmarcar interrompe as atualizações, mas mantém o Doc/PDF já existentes.', 'kbwizard')) ?>
                    </span>
                </div>
            </form>
            <div class="form-hint mt-2">
                <?= $esc(__('Mostrando os 200 registros mais recentes. Links abrem o arquivo no Google Drive.', 'kbwizard')) ?>
            </div>
        <?php endif; ?>

        <div class="alert alert-secondary mt-3 mb-0 small">
            <?= $esc(__('Como funciona: só os artigos marcados com "Sincronizar com Google Drive" entram na fila. Ao marcar (ou salvar um artigo marcado), o cron gera/atualiza o Google Doc no mesmo fileId (o NotebookLM mantém a fonte) e exporta o PDF. Desmarcar interrompe as atualizações e mantém os arquivos no Drive. A primeira sincronização completa pode levar alguns minutos.', 'kbwizard')) ?>
        </div>
    </div>
</div>
<script src="<?= $esc($driveWebDir . '/public/js/drivequeue.js?v=' . PLUGIN_KBWIZARD_VERSION) ?>" defer></script>
