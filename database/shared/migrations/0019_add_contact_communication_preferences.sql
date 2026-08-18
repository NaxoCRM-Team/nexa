-- Persist contact-level communication restrictions and their immutable change history.
ALTER TABLE contact
    ADD COLUMN do_not_contact TINYINT(1) NOT NULL DEFAULT 0 AFTER legal_basis,
    ADD COLUMN do_not_contact_channels VARCHAR(160) NULL AFTER do_not_contact,
    ADD COLUMN do_not_contact_changed_at DATETIME NULL AFTER do_not_contact_channels,
    ADD COLUMN do_not_contact_changed_by_id VARCHAR(17) NULL AFTER do_not_contact_changed_at,
    ADD INDEX idx_contact_tenant_do_not_contact
        (tenant_id, service_id, do_not_contact, deleted);

CREATE TABLE IF NOT EXISTS nexa_communication_preference (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    contact_id VARCHAR(17) NOT NULL,
    channel VARCHAR(24) NOT NULL,
    status VARCHAR(16) NOT NULL,
    reason VARCHAR(64) NOT NULL,
    note VARCHAR(1000) NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'bulk_action',
    changed_by_id VARCHAR(17) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    INDEX idx_nexa_preference_contact
        (tenant_id, service_id, contact_id, channel, created_at),
    INDEX idx_nexa_preference_actor
        (tenant_id, service_id, changed_by_id, created_at),
    CONSTRAINT fk_nexa_preference_tenant_service
        FOREIGN KEY (tenant_id, service_id)
        REFERENCES nexa_tenant_service (tenant_id, service_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_nexa_preference_channel
        CHECK (channel IN ('email', 'phone', 'sms', 'whatsapp', 'postal')),
    CONSTRAINT chk_nexa_preference_status
        CHECK (status IN ('blocked', 'allowed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
