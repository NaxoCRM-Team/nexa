-- Track how each tenant obtains exchange rates and when those rates were effective.
ALTER TABLE `nexa_tenant_currency_profile`
    ADD COLUMN IF NOT EXISTS `rate_mode` VARCHAR(20) NOT NULL DEFAULT 'automatic' AFTER `default_currency`,
    ADD COLUMN IF NOT EXISTS `rate_provider` VARCHAR(40) NULL AFTER `rate_mode`,
    ADD COLUMN IF NOT EXISTS `rates_effective_date` DATE NULL AFTER `rate_provider`,
    ADD COLUMN IF NOT EXISTS `rates_updated_at` DATETIME(6) NULL AFTER `rates_effective_date`;

ALTER TABLE `nexa_tenant_currency_history`
    ADD COLUMN IF NOT EXISTS `rate_source` VARCHAR(40) NOT NULL DEFAULT 'manual' AFTER `currencies_json`,
    ADD COLUMN IF NOT EXISTS `rate_effective_date` DATE NULL AFTER `rate_source`;
