-- Preserve the commercial terms of a quote even when its source product changes later.
CREATE TABLE IF NOT EXISTS `nexa_quote_line_item` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `quote_id` CHAR(36) NOT NULL,
    `product_id` CHAR(36) NULL,
    `name` VARCHAR(200) NOT NULL,
    `quantity` DECIMAL(12,3) NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `discount_percent` DECIMAL(6,3) NOT NULL DEFAULT 0,
    `currency` CHAR(3) NOT NULL,
    `position` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_nexa_quote_line_quote` (`tenant_id`, `service_id`, `quote_id`, `position`),
    CONSTRAINT `fk_nexa_quote_line_quote` FOREIGN KEY (`quote_id`) REFERENCES `nexa_quote` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nexa_quote_line_product` FOREIGN KEY (`product_id`) REFERENCES `nexa_product` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_nexa_quote_line_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
