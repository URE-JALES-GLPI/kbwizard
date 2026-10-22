<?php
/**
 * Hook file for KB Wizard plugin
 * Instalação/atualização/desinstalação - compatível GLPI 11/12
 */

/**
 * Verifica se uma coluna existe (compatível com versões do GLPI sem fieldExists).
 */
function plugin_kbwizard_drive_column_exists(string $table, string $field): bool {
    /** @var DBmysql $DB */
    global $DB;
    if (!$DB instanceof DBmysql) {
        return false;
    }
    try {
        if (method_exists($DB, 'fieldExists')) {
            return (bool)$DB->fieldExists($table, $field);
        }
    } catch (Throwable $e) {
        // cai no fallback abaixo
    }
    try {
        $result = $DB->doQuery("SHOW COLUMNS FROM `$table` LIKE '" . addslashes($field) . "'");
        if ($result) {
            return $DB->numRows($result) > 0;
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

/**
 * Cria as tabelas da sincronização com o Google Drive e registra o cron.
 * Idempotente: pode ser chamada em install, update e auto-reparo.
 */
function plugin_kbwizard_create_drive_tables(): void {
    /** @var DBmysql $DB */
    global $DB;
    if (!$DB instanceof DBmysql) {
        return;
    }

    try {
        $charset = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();

        if (!$DB->tableExists('glpi_plugin_kbwizard_drive_config')) {
            $table = 'glpi_plugin_kbwizard_drive_config';
            $DB->doQuery("CREATE TABLE IF NOT EXISTS `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `auth_mode` varchar(20) NOT NULL DEFAULT 'oauth',
                `is_active` tinyint(1) NOT NULL DEFAULT 0,
                `shared_drive_id` varchar(191) NOT NULL DEFAULT '',
                `folder_id` varchar(191) NOT NULL DEFAULT '',
                `folder_name` varchar(191) NOT NULL DEFAULT 'KB Wizard - Base de Conhecimento',
                `export_doc` tinyint(1) NOT NULL DEFAULT 1,
                `export_pdf` tinyint(1) NOT NULL DEFAULT 1,
                `pdf_engine` varchar(20) NOT NULL DEFAULT 'google',
                `include_images` tinyint(1) NOT NULL DEFAULT 1,
                `sa_filename` varchar(191) NOT NULL DEFAULT '',
                `client_id` varchar(191) NOT NULL DEFAULT '',
                `client_secret` varchar(191) NOT NULL DEFAULT '',
                `refresh_token` text,
                `google_email` varchar(191) NOT NULL DEFAULT '',
                `secret` varchar(64) NOT NULL DEFAULT '',
                `last_error` text,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
        }

        // Migração idempotente para instalações que já tinham a tabela (1.3.x).
        $table = 'glpi_plugin_kbwizard_drive_config';
        $newColumns = [
            'auth_mode'     => "varchar(20) NOT NULL DEFAULT 'oauth'",
            'client_id'     => "varchar(191) NOT NULL DEFAULT ''",
            'client_secret' => "varchar(191) NOT NULL DEFAULT ''",
            'refresh_token' => 'text',
            'google_email'  => "varchar(191) NOT NULL DEFAULT ''",
            'pdf_engine'    => "varchar(20) NOT NULL DEFAULT 'google'",
        ];
        foreach ($newColumns as $field => $ddl) {
            if (!plugin_kbwizard_drive_column_exists($table, $field)) {
                $DB->doQuery("ALTER TABLE `$table` ADD `$field` $ddl");
            }
        }

        // Opt-in por artigo: instalações antigas não têm a coluna drive_sync.
        $cfgTable = 'glpi_plugin_kbwizard_configs';
        if ($DB->tableExists($cfgTable) && !plugin_kbwizard_drive_column_exists($cfgTable, 'drive_sync')) {
            $DB->doQuery("ALTER TABLE `$cfgTable` ADD `drive_sync` tinyint(1) NOT NULL DEFAULT 0");
        }

        if (!$DB->tableExists('glpi_plugin_kbwizard_drive_files')) {
            $table = 'glpi_plugin_kbwizard_drive_files';
            $DB->doQuery("CREATE TABLE IF NOT EXISTS `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `knowbaseitems_id` int unsigned NOT NULL,
                `doc_file_id` varchar(191) NOT NULL DEFAULT '',
                `pdf_file_id` varchar(191) NOT NULL DEFAULT '',
                `content_hash` varchar(64) NOT NULL DEFAULT '',
                `status` varchar(20) NOT NULL DEFAULT 'pending',
                `attempts` int NOT NULL DEFAULT 0,
                `last_error` text,
                `date_sync` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `knowbaseitems_id` (`knowbaseitems_id`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
        }
    } catch (Throwable $e) {
        if (class_exists('Toolbox')) {
            try { Toolbox::logInFile('kbwizard', 'create_drive_tables erro: ' . $e->getMessage() . "\n"); } catch (Throwable $e2) {}
        }
    }

    if (class_exists('CronTask') && defined('MINUTE_TIMESTAMP')) {
        try {
            CronTask::register(
                'PluginKbwizardCron',
                'sync',
                MINUTE_TIMESTAMP,
                [
                    'comment'       => 'KB Wizard: sincronizar artigos com o Google Drive',
                    'logs_lifetime' => 30,
                ]
            );
        } catch (Throwable $e) {
            if (class_exists('Toolbox')) {
                try { Toolbox::logInFile('kbwizard', 'CronTask::register falhou: ' . $e->getMessage() . "\n"); } catch (Throwable $e2) {}
            }
        }
    }
}

function plugin_kbwizard_install() {
    /** @var DBmysql $DB */
    global $DB;

    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();

    // Criar tabela de configuração por artigo
    if (!$DB->tableExists('glpi_plugin_kbwizard_configs')) {
        $table = 'glpi_plugin_kbwizard_configs';

        $DB->doQuery("CREATE TABLE IF NOT EXISTS `$table` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `knowbaseitems_id` int unsigned NOT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 0,
            `split_mode` varchar(20) NOT NULL DEFAULT 'auto',
            `auto_delimiter` varchar(20) NOT NULL DEFAULT 'marker',
            `show_progress` tinyint(1) NOT NULL DEFAULT 1,
            `allow_jump` tinyint(1) NOT NULL DEFAULT 1,
            `require_sequential` tinyint(1) NOT NULL DEFAULT 0,
            `auto_titles` text,
            `drive_sync` tinyint(1) NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `knowbaseitems_id` (`knowbaseitems_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // Criar tabela de passos manuais
    if (!$DB->tableExists('glpi_plugin_kbwizard_steps')) {
        $table = 'glpi_plugin_kbwizard_steps';

        $DB->doQuery("CREATE TABLE IF NOT EXISTS `$table` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `knowbaseitems_id` int unsigned NOT NULL,
            `rank` int NOT NULL DEFAULT 0,
            `title` varchar(255) NOT NULL DEFAULT '',
            `content` mediumtext,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `knowbaseitems_id` (`knowbaseitems_id`),
            KEY `rank` (`rank`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // Criar tabela de progresso por usuário
    if (!$DB->tableExists('glpi_plugin_kbwizard_progress')) {
        $table = 'glpi_plugin_kbwizard_progress';

        $DB->doQuery("CREATE TABLE IF NOT EXISTS `$table` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `knowbaseitems_id` int unsigned NOT NULL,
            `users_id` int unsigned NOT NULL,
            `current_step` int NOT NULL DEFAULT 0,
            `is_completed` tinyint(1) NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`knowbaseitems_id`,`users_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // Sincronização com Google Drive (tabelas + cron)
    plugin_kbwizard_create_drive_tables();

    // Instalar direitos de perfil se a classe existir (opcional)
    if (class_exists('PluginKbwizardProfile')) {
        PluginKbwizardProfile::install();
    }

    return true;
}

function plugin_kbwizard_update($current_version) {
    /** @var DBmysql $DB */
    global $DB;

    $migration = new Migration(PLUGIN_KBWIZARD_VERSION);

    // Atualização de versões anteriores à 1.0.1 (fix carregamento infinito)
    if (version_compare($current_version, '1.0.1', '<')) {
        $table = 'glpi_plugin_kbwizard_configs';

        if ($DB->tableExists($table)) {
            // Garantir colunas que faltavam no 1.0.0
            if (!$DB->fieldExists($table, 'allow_jump')) {
                $migration->addField($table, 'allow_jump', 'bool', ['value' => 1]);
            }
            if (!$DB->fieldExists($table, 'show_progress')) {
                $migration->addField($table, 'show_progress', 'bool', ['value' => 1]);
            }
            if (!$DB->fieldExists($table, 'require_sequential')) {
                $migration->addField($table, 'require_sequential', 'bool', ['value' => 0]);
            }
            // Garantir índice único
            if (!$DB->indexExists($table, 'knowbaseitems_id')) {
                $migration->addKey($table, 'knowbaseitems_id', 'knowbaseitems_id', 'UNIQUE');
            }
            $migration->migrationOneTable($table);
            Toolbox::logInFile('kbwizard', "KB Wizard atualizado para 1.0.1 - colunas de config verificadas\n");
        }

        $tableSteps = 'glpi_plugin_kbwizard_steps';
        if ($DB->tableExists($tableSteps)) {
            if (!$DB->indexExists($tableSteps, 'knowbaseitems_id')) {
                $migration->addKey($tableSteps, 'knowbaseitems_id');
            }
            if (!$DB->indexExists($tableSteps, 'rank')) {
                $migration->addKey($tableSteps, 'rank');
            }
            $migration->migrationOneTable($tableSteps);
        }

        $tableProg = 'glpi_plugin_kbwizard_progress';
        if ($DB->tableExists($tableProg)) {
            if (!$DB->indexExists($tableProg, 'unicity')) {
                $migration->addKey($tableProg, ['knowbaseitems_id', 'users_id'], 'unicity', 'UNIQUE');
            }
            if (!$DB->indexExists($tableProg, 'users_id')) {
                $migration->addKey($tableProg, 'users_id');
            }
            $migration->migrationOneTable($tableProg);
        }
    }

    // Atualização para 1.0.15 - títulos editáveis por passo no modo auto
    if (version_compare($current_version, '1.0.15', '<')) {
        $table = 'glpi_plugin_kbwizard_configs';
        if ($DB->tableExists($table) && !$DB->fieldExists($table, 'auto_titles')) {
            $migration->addField($table, 'auto_titles', 'text');
            $migration->migrationOneTable($table);
            Toolbox::logInFile('kbwizard', "KB Wizard atualizado para 1.0.15 - coluna auto_titles adicionada\n");
        }
    }

    // Atualização para 1.0.19 - critério apenas marcador ---PASSO---
    if (version_compare($current_version, '1.0.19', '<')) {
        $table = 'glpi_plugin_kbwizard_configs';
        if ($DB->tableExists($table)) {
            if (!$DB->fieldExists($table, 'auto_titles')) {
                $migration->addField($table, 'auto_titles', 'text');
                $migration->migrationOneTable($table);
            }
            // Normaliza delimiter existente para marker (fallback universal já cobre antigos)
            try {
                $DB->doQuery("UPDATE `$table` SET `auto_delimiter`='marker' WHERE `auto_delimiter`!='marker'");
            } catch (Throwable $e) {}
            Toolbox::logInFile('kbwizard', "KB Wizard atualizado para 1.0.19 - auto_delimiter normalizado para marker\n");
        }
    }

    // Atualização para 1.3.0 - sincronização com Google Drive (Doc + PDF)
    if (version_compare($current_version, '1.3.0', '<')) {
        plugin_kbwizard_create_drive_tables();
        Toolbox::logInFile('kbwizard', "KB Wizard atualizado para 1.3.0 - tabelas de sincronização do Drive criadas\n");
    }

    // Atualização para 1.4.0 - OAuth com conta Google (colunas de autenticação)
    if (version_compare($current_version, '1.4.0', '<')) {
        plugin_kbwizard_create_drive_tables();
        Toolbox::logInFile('kbwizard', "KB Wizard atualizado para 1.4.0 - colunas de OAuth verificadas\n");
    }

    // Atualização para 1.5.0 - opt-in por artigo na sincronização com o Drive
    if (version_compare($current_version, '1.5.0', '<')) {
        $table = 'glpi_plugin_kbwizard_configs';
        if ($DB->tableExists($table) && !plugin_kbwizard_drive_column_exists($table, 'drive_sync')) {
            $DB->doQuery("ALTER TABLE `$table` ADD `drive_sync` tinyint(1) NOT NULL DEFAULT 0");
        }
        Toolbox::logInFile('kbwizard', "KB Wizard atualizado para 1.5.0 - coluna drive_sync adicionada\n");
    }

    // Atualizar direitos de perfil se existir
    if (class_exists('PluginKbwizardProfile')) {
        PluginKbwizardProfile::install();
    }

    return true;
}

function plugin_kbwizard_uninstall() {
    /** @var DBmysql $DB */
    global $DB;

    // Remover direitos de perfil se existir
    if (class_exists('PluginKbwizardProfile')) {
        PluginKbwizardProfile::uninstall();
    }

    // Remover tabelas - espelhado do Ticket Answers (usa doQuery)
    if ($DB->tableExists("glpi_plugin_kbwizard_configs")) {
        $DB->doQuery("DROP TABLE `glpi_plugin_kbwizard_configs`");
    }

    if ($DB->tableExists("glpi_plugin_kbwizard_steps")) {
        $DB->doQuery("DROP TABLE `glpi_plugin_kbwizard_steps`");
    }

    if ($DB->tableExists("glpi_plugin_kbwizard_progress")) {
        $DB->doQuery("DROP TABLE `glpi_plugin_kbwizard_progress`");
    }

    if ($DB->tableExists("glpi_plugin_kbwizard_drive_files")) {
        $DB->doQuery("DROP TABLE `glpi_plugin_kbwizard_drive_files`");
    }

    if ($DB->tableExists("glpi_plugin_kbwizard_drive_config")) {
        $DB->doQuery("DROP TABLE `glpi_plugin_kbwizard_drive_config`");
    }

    // Credencial da service account (fora do webroot)
    try {
        if (class_exists('PluginKbwizardDriveConfig')) {
            $saPath = PluginKbwizardDriveConfig::serviceAccountPath();
            if (is_file($saPath)) {
                @unlink($saPath);
            }
        }
    } catch (Throwable $e) {
        // silencioso
    }

    // Limpar display preferences se houver
    try {
        $DB->delete('glpi_displaypreferences', ['itemtype' => 'PluginKbwizardStep']);
        $DB->delete('glpi_displaypreferences', ['itemtype' => 'PluginKbwizardConfig']);
    } catch (Throwable $e) {
        // silencioso
    }

    return true;
}
