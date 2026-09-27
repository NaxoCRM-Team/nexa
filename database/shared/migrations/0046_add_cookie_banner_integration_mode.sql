-- A tenant may retain an existing consent-management banner and use Nexa only
-- as the normalized consent ledger. This prevents competing banners.

ALTER TABLE `nexa_cookie_banner`
    ADD COLUMN IF NOT EXISTS `integration_mode` VARCHAR(24) NOT NULL DEFAULT 'managed' AFTER `locale`,
    ADD CONSTRAINT `chk_nexa_cookie_banner_integration_mode`
        CHECK (`integration_mode` IN ('managed', 'existing_banner'));
