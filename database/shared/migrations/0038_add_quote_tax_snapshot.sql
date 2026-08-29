-- Preserve tax as part of each immutable quote line snapshot.
ALTER TABLE `nexa_quote_line_item`
    ADD COLUMN IF NOT EXISTS `tax_percent` DECIMAL(6,3) NOT NULL DEFAULT 0 AFTER `discount_percent`;
