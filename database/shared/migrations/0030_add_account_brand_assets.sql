-- Account brand assets are tenant-owned attachments. Website favicons are
-- cached so list rendering never depends on a third-party site being online.
ALTER TABLE `account`
    ADD COLUMN IF NOT EXISTS `company_logo_id` VARCHAR(17) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `favicon_id` VARCHAR(17) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `favicon_source_host` VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `favicon_fetched_at` DATETIME DEFAULT NULL;

SET @company_logo_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'account'
      AND index_name = 'IDX_ACCOUNT_COMPANY_LOGO_ID'
);
SET @company_logo_index_sql := IF(@company_logo_index_exists = 0,
    'ALTER TABLE `account` ADD INDEX `IDX_ACCOUNT_COMPANY_LOGO_ID` (`company_logo_id`)', 'SELECT 1');
PREPARE company_logo_index_stmt FROM @company_logo_index_sql;
EXECUTE company_logo_index_stmt;
DEALLOCATE PREPARE company_logo_index_stmt;
