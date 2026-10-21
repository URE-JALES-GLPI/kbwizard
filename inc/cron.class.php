<?php
/**
 * KB Wizard - Ação automática (CronTask) de sincronização com o Google Drive
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKbwizardCron
{
    /**
     * Descrição exibida em Configurar > Ações automáticas.
     */
    public static function cronInfo(string $name): array
    {
        if ($name === 'sync') {
            return [
                'description' => __('Sincronizar artigos da base de conhecimento com o Google Drive', 'kbwizard'),
            ];
        }
        return [];
    }

    /**
     * Processa um lote pequeno da fila a cada execução.
     *
     * @param CronTask|null $task
     * @return int 1 se algo foi processado, 0 caso contrário
     */
    public static function cronSync($task = null): int
    {
        try {
            if (!PluginKbwizardDriveConfig::isConfigured()) {
                return 0;
            }

            @set_time_limit(300);

            $result = PluginKbwizardDriveSync::processQueue(5);

            if ($task instanceof CronTask) {
                $task->addVolume((int)$result['synced']);
            }

            if (class_exists('PluginKbwizardToolbox')) {
                PluginKbwizardToolbox::log(sprintf(
                    'cron sync: processados=%d sincronizados=%d falhas=%d ignorados=%d',
                    $result['processed'],
                    $result['synced'],
                    $result['failed'],
                    $result['skipped']
                ));
            }

            return $result['processed'] > 0 ? 1 : 0;
        } catch (Throwable $e) {
            if (class_exists('PluginKbwizardToolbox')) {
                PluginKbwizardToolbox::log('cron sync erro: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
            return 0;
        }
    }
}
