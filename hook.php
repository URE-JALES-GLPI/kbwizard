<?php
/**
 * Hook file for KB Wizard plugin
 * Espelhado no modelo Ticket Answers (GLPI 11) - 100% funcional em 11.06
 */

function plugin_kbwizard_install() {
    /** @var DBmysql $DB */
    global $DB;

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
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `knowbaseitems_id` (`knowbaseitems_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

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

    // Futuro: atualização para 2.0.0 (GLPI 11) - exemplo espelhado do Ticket Answers
    if (version_compare($current_version, '2.0.0', '<')) {
        // Já estamos em 1.0.1, nada a fazer para 2.0.0 ainda, mas deixa estrutura pronta
        Toolbox::logInFile('kbwizard', "KB Wizard verificado para GLPI 11 - 2.0.0 checkpoint\n");
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

    // Limpar display preferences se houver
    try {
        $DB->delete('glpi_displaypreferences', ['itemtype' => 'PluginKbwizardStep']);
        $DB->delete('glpi_displaypreferences', ['itemtype' => 'PluginKbwizardConfig']);
    } catch (Throwable $e) {
        // silencioso
    }

    return true;
}
