<?php
/**
 * Schéma de base de données — ISPAG CRM
 *
 * Généré à partir de la structure de production (export phpMyAdmin du 2026-09-29).
 * Chaque entrée est un CREATE TABLE IF NOT EXISTS : exécuté sans risque sur un site existant
 * (rien n'est modifié si la table existe déjà). {prefix} = $wpdb->prefix, {charset} = $wpdb->get_charset_collate().
 * Les tables sont classées pour que les clés étrangères pointent vers des tables déjà créées.
 */
defined('ABSPATH') || exit;

return [
    'achats_fournisseurs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_fournisseurs` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `viag_id` bigint NOT NULL,
  `isSupplier` int NOT NULL,
  `isIngenieur` int NOT NULL,
  `Fournisseur` text NOT NULL,
  `IdContactCommande` int NOT NULL,
  `IdContactPlan` int NOT NULL,
  `IdContactFacturation` int NOT NULL DEFAULT '0',
  `IdContactLivraison` int NOT NULL DEFAULT '0',
  `TVA` text NOT NULL,
  `Mail` text NOT NULL,
  `compagnyDomain` text NOT NULL,
  `SupplierAdresse` text NOT NULL,
  `Ville` text NOT NULL,
  `CodePostal` text NOT NULL,
  `region` text NOT NULL,
  `Pays` text NOT NULL,
  `industry` enum('Installateur CVC','Ingenieur CVC') DEFAULT NULL,
  `NumTel` text NOT NULL,
  `Langue` text NOT NULL,
  `Monnaie` text NOT NULL,
  `deliveryDays` int NOT NULL,
  `TransportTime` int NOT NULL DEFAULT '0',
  `Image` text NOT NULL,
  PRIMARY KEY (`Id`),
  KEY `compagnydomain_idx` (`compagnyDomain`(100))
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_companies' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_companies` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `viag_id` bigint NOT NULL,
  `isSupplier` int NOT NULL,
  `isIngenieur` int NOT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `uid_number` varchar(20) DEFAULT NULL,
  `uid_status` varchar(20) DEFAULT NULL,
  `uid_entity_type` varchar(100) DEFAULT NULL,
  `last_uid_check` datetime DEFAULT NULL,
  `uid_validation_data` longtext,
  `compagny_domain` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `favicon` varchar(512) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`Id`),
  KEY `viag_id` (`viag_id`),
  KEY `idx_supplier_viag` (`isSupplier`,`viag_id`),
  KEY `idx_viag_id` (`viag_id`),
  KEY `idx_company_name` (`company_name`),
  KEY `idx_uid_number` (`uid_number`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_companies_discounts' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_companies_discounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_id` int NOT NULL,
  `discount_type` enum('rabais','coef_vente') NOT NULL DEFAULT 'coef_vente',
  `discount_value` decimal(10,4) NOT NULL,
  `department_id` varchar(255) DEFAULT NULL,
  `valid_from` datetime NOT NULL,
  `valid_to` datetime DEFAULT NULL,
  `created_by` int NOT NULL,
  `modified_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`,`valid_from`,`valid_to`),
  CONSTRAINT `{prefix}ispag_companies_discounts_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `{prefix}ispag_companies` (`Id`) ON DELETE CASCADE
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_companies_lifecycle' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_companies_lifecycle` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_id` int NOT NULL,
  `lifecycle_type` varchar(255) DEFAULT NULL,
  `department_id` varchar(255) DEFAULT NULL,
  `valid_from` datetime NOT NULL,
  `valid_to` datetime DEFAULT NULL,
  `created_by` int NOT NULL,
  `modified_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`,`valid_from`,`valid_to`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_companies_meta' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_companies_meta` (
  `meta_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` bigint UNSIGNED NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`),
  KEY `post_id` (`company_id`),
  KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_companies_owners' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_companies_owners` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `company_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `status` varchar(20) DEFAULT 'active',
  `department_key` varchar(50) NOT NULL,
  `assigned_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `unassigned_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company_dept_status` (`company_id`,`department_key`,`status`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_contact_audit' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_contact_audit` (
  `audit_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `contact_id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `old_value` text,
  `new_value` text,
  `change_date` datetime NOT NULL,
  PRIMARY KEY (`audit_id`),
  KEY `contact_id` (`contact_id`),
  KEY `user_id` (`user_id`),
  KEY `contact_id_2` (`contact_id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_contact_notes' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_contact_notes` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `contact_id` varchar(255) DEFAULT NULL COMMENT 'Liste des IDs de contacts liés (Séparés par des virgules)',
  `user_id` bigint UNSIGNED NOT NULL COMMENT 'ID de l''utilisateur WordPress qui a créé la note/tâche',
  `company_id` varchar(255) DEFAULT NULL COMMENT 'Liste des IDs d''entreprises liées (Séparés par des virgules)',
  `deal_id` varchar(255) DEFAULT NULL COMMENT 'Liste des IDs de deals liés (Séparés par des virgules)',
  `type` enum('EMAIL','CALL','MEETING','NOTE','TASK','HEALTH_REMINDER','EMAIL_CAMPAIGN','EMAIL_TRANSACTIONAL','CHRISTMAS_PRESENT','WHATSAPP','SMS','STAGE','SYSTEM','LOG_EMAIL','LINKEDIN') NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `content` text NOT NULL COMMENT 'Contenu détaillé de la note ou de la tâche',
  `is_task` tinyint(1) DEFAULT '0' COMMENT '1 si c''est une tâche nécessitant un suivi',
  `outcome` varchar(20) DEFAULT NULL,
  `attendees_ids` longtext,
  `due_date` datetime DEFAULT NULL COMMENT 'Date d''échéance si is_task est à 1',
  `reminder_date` datetime DEFAULT NULL COMMENT 'Date et heure pour l''envoi d''une notification de rappel',
  `reminder_offset` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date et heure de création de l''entrée',
  `notified_at` datetime DEFAULT NULL COMMENT 'Date et heure de l''envoi de la notification de rappel',
  `is_completed` tinyint(1) DEFAULT '0' COMMENT '1 si la tâche est complétée',
  `completed_at` datetime DEFAULT NULL COMMENT 'Date et heure de complétion de la tâche',
  `updated_at` datetime DEFAULT NULL COMMENT 'Date et heure de la dernière modification',
  `priority` varchar(10) DEFAULT 'Normal' COMMENT 'Niveau de priorité (Normal, Haute, Basse)',
  `media_ids` varchar(255) DEFAULT NULL COMMENT 'IDs des médias WordPress liés (séparés par des virgules)',
  PRIMARY KEY (`id`),
  KEY `contact_id` (`contact_id`),
  KEY `user_id` (`user_id`),
  KEY `contact_id_2` (`contact_id`,`company_id`,`deal_id`),
  KEY `idx_comp_type_date` (`company_id`(20),`type`,`created_at`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_contacts_owners' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_contacts_owners` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `contact_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `status` varchar(20) DEFAULT 'active',
  `department_key` varchar(50) NOT NULL,
  `assigned_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `unassigned_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company_dept_status` (`contact_id`,`department_key`,`status`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_deal_stages' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_deal_stages` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `stage_key` varchar(50) NOT NULL,
  `stage_label` varchar(100) NOT NULL,
  `stage_order` int UNSIGNED NOT NULL DEFAULT '100',
  `probability` decimal(5,2) NOT NULL DEFAULT '0.00',
  `is_closed` tinyint(1) NOT NULL DEFAULT '0',
  `stage_type` varchar(10) NOT NULL DEFAULT 'open',
  `stage_color` varchar(7) NOT NULL DEFAULT '#cccccc',
  `date_added` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stage_key` (`stage_key`),
  KEY `idx_stage_order` (`stage_order`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_deals_audit' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_deals_audit` (
  `Id` int UNSIGNED NOT NULL,
  `status_label` varchar(100) NOT NULL,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_deals_list' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_deals_list` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `deal_group_ref` varchar(50) DEFAULT NULL,
  `identifiant_viag` text NOT NULL,
  `date_creation` date DEFAULT NULL,
  `closing_date` date DEFAULT NULL,
  `project_name` text NOT NULL,
  `project_num` text,
  `offer_num` varchar(15) DEFAULT NULL,
  `customer_order_id` text,
  `associated_company_id` int NOT NULL DEFAULT '0',
  `associated_contact_ids` text NOT NULL,
  `project_status` int NOT NULL,
  `database_status` int NOT NULL,
  `project_db_status` int NOT NULL COMMENT '0 = ouvert, 1 = gagné, 2 = perdue',
  `engineer_id` int DEFAULT NULL,
  `process_type` varchar(50) DEFAULT NULL,
  `reseller_offer` tinyint(1) NOT NULL,
  `sales_coef` text NOT NULL,
  `total_excl_vat` text NOT NULL,
  `reason_for_rejection` varchar(50) DEFAULT NULL,
  `deal_owner` int NOT NULL,
  `created_by` int NOT NULL,
  `abonne` text NOT NULL,
  `is_copie` tinyint(1) NOT NULL,
  `record_source` text NOT NULL,
  `current_stage_key` varchar(50) NOT NULL DEFAULT 'submission_received',
  PRIMARY KEY (`id`),
  KEY `deal_group_ref` (`deal_group_ref`),
  KEY `deal_group_ref_2` (`deal_group_ref`),
  KEY `idx_deal_status_type` (`project_db_status`,`process_type`),
  KEY `idx_deal_owner` (`deal_owner`),
  KEY `idx_closing_date` (`closing_date`),
  KEY `idx_assoc_company` (`associated_company_id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_deals_stages' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_deals_stages` (
  `deal_group_ref` varchar(50) NOT NULL,
  `current_stage_key` varchar(50) NOT NULL DEFAULT 'submission_received',
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  PRIMARY KEY (`deal_group_ref`),
  KEY `idx_deal_group` (`deal_group_ref`),
  KEY `idx_stage_key` (`current_stage_key`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_delayed_notifications' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_delayed_notifications` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` text NOT NULL,
  `content` text NOT NULL,
  `url` text,
  `entity_id` bigint UNSIGNED DEFAULT NULL,
  `extra_data` text,
  `scheduled_for` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `scheduled_for` (`scheduled_for`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_lead_statuses' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_lead_statuses` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `status_key` varchar(50) NOT NULL,
  `status_label` varchar(100) NOT NULL,
  `status_description` text,
  `bg_color` varchar(7) NOT NULL DEFAULT '#cccccc',
  `text_color` varchar(7) NOT NULL DEFAULT '#333333',
  `status_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`Id`),
  UNIQUE KEY `status_key` (`status_key`),
  UNIQUE KEY `status_key_2` (`status_key`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_lifecycle_phases' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_lifecycle_phases` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `phase_key` varchar(50) NOT NULL,
  `phase_label` varchar(100) NOT NULL,
  `phase_description` text,
  `bg_color` varchar(7) NOT NULL DEFAULT '#cccccc',
  `text_color` varchar(7) NOT NULL DEFAULT '#333333',
  `phase_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`Id`),
  UNIQUE KEY `key_unique` (`phase_key`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_notifications' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_notifications` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `title` text NOT NULL,
  `content` text NOT NULL,
  `url` text,
  `type` varchar(50) NOT NULL,
  `entity_id` bigint UNSIGNED DEFAULT NULL,
  `onesignal_id` varchar(255) DEFAULT NULL,
  `is_read` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `is_deleted` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `is_read` (`is_read`),
  KEY `type` (`type`),
  KEY `is_deleted` (`is_deleted`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_rejection_reasons' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_rejection_reasons` (
  `id` int NOT NULL AUTO_INCREMENT,
  `reason_key` varchar(50) NOT NULL,
  `label_en` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `reason_key` (`reason_key`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_sequence_enrollments' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_sequence_enrollments` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `sequence_id` bigint UNSIGNED NOT NULL,
  `contact_id` bigint UNSIGNED NOT NULL,
  `deal_id` bigint UNSIGNED DEFAULT NULL,
  `status` enum('ACTIVE','PAUSED','COMPLETED','UNENROLLED_REPLY','UNENROLLED_MANUAL','PROCESSING') DEFAULT 'ACTIVE',
  `lock_token` varchar(255) DEFAULT NULL,
  `current_step_id` int DEFAULT '1',
  `next_step_date` datetime DEFAULT NULL,
  `last_error` text,
  `enrolled_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_enrollment_deal` (`contact_id`,`sequence_id`,`deal_id`),
  KEY `next_step_date` (`next_step_date`),
  KEY `status` (`status`),
  KEY `deal_id` (`deal_id`),
  KEY `idx_enroll_lock` (`lock_token`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_sequences' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_sequences` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,
  `created_by` bigint UNSIGNED NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `exit_on_reply` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_sequence_steps' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_sequence_steps` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `sequence_id` bigint UNSIGNED NOT NULL,
  `step_number` int NOT NULL,
  `objective` varchar(255) DEFAULT NULL,
  `value_added` varchar(255) DEFAULT NULL,
  `action_type` enum('EMAIL','CALL','MEETING','EMAIL_CAMPAIGN','EMAIL_TRANSACTIONAL','CHRISTMAS_PRESENT','WHATSAPP','SMS','LINKEDIN','TASK') NOT NULL,
  `condition_type` varchar(50) DEFAULT NULL,
  `condition_operator` varchar(10) DEFAULT NULL,
  `condition_value` varchar(255) DEFAULT NULL,
  `delay_days` int DEFAULT '0',
  `if_false_step_number` int DEFAULT NULL,
  `template_id` bigint UNSIGNED DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `content` longtext,
  PRIMARY KEY (`id`),
  KEY `sequence_id` (`sequence_id`),
  CONSTRAINT `fk_sequence_steps` FOREIGN KEY (`sequence_id`) REFERENCES `{prefix}ispag_sequences` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_simap_notices' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_simap_notices` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `simap_id` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `type` varchar(50) DEFAULT 'award',
  `publication_date` datetime DEFAULT NULL,
  `content_json` longtext,
  `matched_deal_id` bigint DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `simap_id` (`simap_id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_template_folders' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_template_folders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `owner_id` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `owner_id` (`owner_id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_templates' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `folder_id` int DEFAULT NULL,
  `owner_id` bigint UNSIGNED DEFAULT NULL,
  `language` varchar(10) DEFAULT 'fr',
  `name` varchar(150) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `content` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `folder_id` (`folder_id`),
  KEY `owner_id` (`owner_id`),
  KEY `language` (`language`),
  CONSTRAINT `{prefix}ispag_templates_ibfk_1` FOREIGN KEY (`folder_id`) REFERENCES `{prefix}ispag_template_folders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_user_priorities' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_user_priorities` (
  `user_id` bigint NOT NULL,
  `entity_id` bigint NOT NULL,
  `entity_type` varchar(20) NOT NULL DEFAULT 'company',
  `priority_level` varchar(10) DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`entity_id`,`entity_type`),
  KEY `user_id` (`user_id`),
  KEY `user_id_2` (`user_id`),
  KEY `idx_user_entity` (`user_id`,`entity_id`,`entity_type`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'ispag_workflow_executions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}ispag_workflow_executions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `workflow_id` bigint UNSIGNED NOT NULL COMMENT 'ID du workflow (post ID dans wp_posts)',
  `deal_id` bigint UNSIGNED NOT NULL,
  `current_step_index` int NOT NULL DEFAULT '0' COMMENT 'Index de l''étape actuelle',
  `status` enum('pending','running','completed','interrupted') NOT NULL DEFAULT 'pending' COMMENT 'État du workflow pour ce deal',
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date de démarrage du workflow',
  `completed_at` datetime DEFAULT NULL COMMENT 'Date de fin du workflow',
  `interrupted_reason` varchar(255) DEFAULT NULL COMMENT 'Raison de l''interruption',
  `next_step_due` datetime DEFAULT NULL COMMENT 'Date à laquelle la prochaine étape doit être exécutée',
  PRIMARY KEY (`id`),
  KEY `workflow_deal_idx` (`workflow_id`,`deal_id`),
  KEY `deal_idx` (`deal_id`),
  KEY `status_idx` (`status`)
) ENGINE=InnoDB {charset}
SQL
    ,
];
