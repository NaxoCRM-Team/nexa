-- Add tenant-owned projects around native Tasks and Documents.
CREATE TABLE IF NOT EXISTS `nexa_project` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `status` VARCHAR(40) NOT NULL DEFAULT 'Planned',
    `priority` VARCHAR(20) NOT NULL DEFAULT 'Normal',
    `description` LONGTEXT NULL,
    `date_start` DATE NULL,
    `date_end` DATE NULL,
    `account_id` VARCHAR(17) NULL,
    `owner_id` VARCHAR(17) NULL,
    `created_by_id` VARCHAR(17) NULL,
    `modified_by_id` VARCHAR(17) NULL,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_nexa_project_status` (`tenant_id`, `service_id`, `is_archived`, `status`),
    KEY `idx_nexa_project_owner` (`tenant_id`, `service_id`, `owner_id`, `date_end`),
    CONSTRAINT `fk_nexa_project_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_project_status` CHECK (`status` IN ('Planned','Active','On Hold','Completed','Canceled')),
    CONSTRAINT `chk_nexa_project_priority` CHECK (`priority` IN ('Low','Normal','High','Urgent'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_project_task` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `project_id` CHAR(36) NOT NULL,
    `task_id` VARCHAR(17) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_project_task` (`tenant_id`, `service_id`, `project_id`, `task_id`),
    CONSTRAINT `fk_nexa_project_task_project` FOREIGN KEY (`project_id`) REFERENCES `nexa_project` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nexa_project_task_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_project_document` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `project_id` CHAR(36) NOT NULL,
    `document_id` VARCHAR(17) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_project_document` (`tenant_id`, `service_id`, `project_id`, `document_id`),
    CONSTRAINT `fk_nexa_project_document_project` FOREIGN KEY (`project_id`) REFERENCES `nexa_project` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nexa_project_document_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_project_update` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `project_id` CHAR(36) NOT NULL,
    `body_html` LONGTEXT NOT NULL,
    `mentioned_user_ids_json` LONGTEXT NULL,
    `created_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_nexa_project_update` (`tenant_id`, `service_id`, `project_id`, `created_at`),
    CONSTRAINT `fk_nexa_project_update_project` FOREIGN KEY (`project_id`) REFERENCES `nexa_project` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nexa_project_update_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_project_mentions` CHECK (`mentioned_user_ids_json` IS NULL OR JSON_VALID(`mentioned_user_ids_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
