-- Phase 6 orchestration state. CRM records remain in their native tables.
CREATE TABLE IF NOT EXISTS `nexa_workflow` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
    `draft_revision` INT UNSIGNED NOT NULL DEFAULT 1,
    `draft_json` JSON NOT NULL,
    `published_version` INT UNSIGNED NULL,
    `created_by_id` VARCHAR(24) NOT NULL,
    `modified_by_id` VARCHAR(24) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_workflow_scope` (`tenant_id`,`service_id`,`id`),
    KEY `idx_workflow_status` (`tenant_id`,`service_id`,`status`,`modified_at`),
    CONSTRAINT `fk_workflow_scope` FOREIGN KEY (`tenant_id`,`service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_workflow_status` CHECK (`status` IN ('draft','published','paused','archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_workflow_version` (
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `workflow_id` CHAR(36) NOT NULL,
    `version_number` INT UNSIGNED NOT NULL,
    `definition_json` JSON NOT NULL,
    `definition_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `execution_user_id` VARCHAR(24) NOT NULL,
    `published_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`tenant_id`,`service_id`,`workflow_id`,`version_number`),
    CONSTRAINT `fk_workflow_version` FOREIGN KEY (`tenant_id`,`service_id`,`workflow_id`)
        REFERENCES `nexa_workflow` (`tenant_id`,`service_id`,`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_workflow_run` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `workflow_id` CHAR(36) NOT NULL,
    `version_number` INT UNSIGNED NOT NULL,
    `contact_id` VARCHAR(24) NOT NULL,
    `request_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'ready',
    `node_id` VARCHAR(40) NOT NULL,
    `node_entered_at` DATETIME(6) NOT NULL,
    `due_at` DATETIME(6) NOT NULL,
    `enrolled_by_id` VARCHAR(24) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `finished_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_workflow_request` (`tenant_id`,`service_id`,`workflow_id`,`request_key_hash`),
    KEY `idx_workflow_due` (`tenant_id`,`service_id`,`status`,`due_at`,`id`),
    KEY `idx_workflow_contact` (`tenant_id`,`service_id`,`workflow_id`,`contact_id`),
    CONSTRAINT `fk_workflow_run_version` FOREIGN KEY (`tenant_id`,`service_id`,`workflow_id`,`version_number`)
        REFERENCES `nexa_workflow_version` (`tenant_id`,`service_id`,`workflow_id`,`version_number`) ON DELETE CASCADE,
    CONSTRAINT `chk_workflow_run_status` CHECK (`status` IN ('ready','waiting','completed','cancelled','failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_workflow_event` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `workflow_id` CHAR(36) NOT NULL,
    `run_id` CHAR(36) NULL,
    `event_type` VARCHAR(40) NOT NULL,
    `node_id` VARCHAR(40) NULL,
    `actor_user_id` VARCHAR(24) NOT NULL,
    `payload_json` JSON NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_workflow_history` (`tenant_id`,`service_id`,`workflow_id`,`created_at`,`id`),
    KEY `idx_workflow_run_history` (`tenant_id`,`service_id`,`run_id`,`created_at`,`id`),
    CONSTRAINT `fk_workflow_event` FOREIGN KEY (`tenant_id`,`service_id`,`workflow_id`)
        REFERENCES `nexa_workflow` (`tenant_id`,`service_id`,`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
