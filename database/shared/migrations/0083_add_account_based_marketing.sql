ALTER TABLE account ADD COLUMN IF NOT EXISTS is_target_account TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE account ADD COLUMN IF NOT EXISTS target_account_tier VARCHAR(16) NOT NULL DEFAULT 'Tier3';
CREATE TABLE IF NOT EXISTS nexa_abm_list (
 target_list_id VARCHAR(24) NOT NULL,tenant_id CHAR(36) NOT NULL,service_id CHAR(36) NOT NULL,
 list_type VARCHAR(16) NOT NULL,conditions_json JSON NULL,version_number INT UNSIGNED NOT NULL DEFAULT 1,
 execution_user_id VARCHAR(24) NOT NULL,automatic_enabled TINYINT(1) NOT NULL DEFAULT 1,
 next_refresh_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),last_refreshed_at DATETIME(6) NULL,
 last_error_code VARCHAR(64) NULL,account_count INT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),modified_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(tenant_id,service_id,target_list_id),
 KEY idx_abm_due(tenant_id,service_id,automatic_enabled,next_refresh_at),
 CONSTRAINT fk_abm_list_workspace FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id) ON DELETE CASCADE,
 CONSTRAINT chk_abm_list_type CHECK(list_type IN ('static','dynamic'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
