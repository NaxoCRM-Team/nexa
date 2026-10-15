ALTER TABLE nexa_scoring_model
 ADD COLUMN IF NOT EXISTS automatic_enabled TINYINT(1) NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS interval_hours INT UNSIGNED NOT NULL DEFAULT 24,
 ADD COLUMN IF NOT EXISTS execution_user_id VARCHAR(24) NULL,
 ADD COLUMN IF NOT EXISTS next_evaluation_at DATETIME(6) NULL,
 ADD COLUMN IF NOT EXISTS scan_cursor VARCHAR(24) NULL,
 ADD COLUMN IF NOT EXISTS scan_until VARCHAR(24) NULL,
 ADD COLUMN IF NOT EXISTS scan_key CHAR(36) NULL,
 ADD COLUMN IF NOT EXISTS last_completed_at DATETIME(6) NULL,
 ADD COLUMN IF NOT EXISTS last_error_code VARCHAR(64) NULL,
 ADD INDEX IF NOT EXISTS idx_scoring_due (tenant_id,service_id,automatic_enabled,status,next_evaluation_at);
