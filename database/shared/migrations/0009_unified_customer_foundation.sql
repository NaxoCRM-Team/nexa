-- Customer 360 foundation shared by CRM, sales, service, marketing and automation.
-- These tables extend Contact and Account; they do not create a second customer master.

CREATE TABLE IF NOT EXISTS nexa_identity_link (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    contact_id VARCHAR(17) NOT NULL,
    identity_type VARCHAR(32) NOT NULL,
    provider_key VARCHAR(64) NULL,
    external_subject VARCHAR(191) NULL,
    normalized_value_hash CHAR(64) NULL,
    verification_status VARCHAR(24) NOT NULL DEFAULT 'unverified',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    verified_at DATETIME(6) NULL,
    last_seen_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_identity_link_id_tenant (id, tenant_id),
    UNIQUE KEY uq_nexa_identity_provider_subject (
        tenant_id,
        identity_type,
        provider_key,
        external_subject
    ),
    UNIQUE KEY uq_nexa_identity_normalized (
        tenant_id,
        identity_type,
        normalized_value_hash
    ),
    KEY idx_nexa_identity_contact (tenant_id, contact_id, is_primary),
    CONSTRAINT fk_nexa_customer_identity_tenant
        FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT chk_nexa_identity_type
        CHECK (identity_type IN ('email', 'phone', 'anonymous', 'login', 'external')),
    CONSTRAINT chk_nexa_identity_verification
        CHECK (verification_status IN ('unverified', 'pending', 'verified', 'revoked'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nexa_relationship_type (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    type_key VARCHAR(64) NOT NULL,
    label VARCHAR(120) NOT NULL,
    inverse_label VARCHAR(120) NULL,
    source_entity_type VARCHAR(64) NOT NULL,
    target_entity_type VARCHAR(64) NOT NULL,
    cardinality VARCHAR(24) NOT NULL DEFAULT 'many_to_many',
    is_directional TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    permission_policy_json JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_relationship_type_id_tenant (id, tenant_id),
    UNIQUE KEY uq_nexa_relationship_type_key (tenant_id, type_key),
    KEY idx_nexa_relationship_type_endpoint (
        tenant_id,
        source_entity_type,
        target_entity_type,
        is_active
    ),
    CONSTRAINT fk_nexa_relationship_type_tenant
        FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT chk_nexa_relationship_cardinality
        CHECK (cardinality IN ('one_to_one', 'one_to_many', 'many_to_one', 'many_to_many'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nexa_relationship_edge (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    relationship_type_id CHAR(36) NOT NULL,
    source_entity_type VARCHAR(64) NOT NULL,
    source_entity_id VARCHAR(36) NOT NULL,
    target_entity_type VARCHAR(64) NOT NULL,
    target_entity_id VARCHAR(36) NOT NULL,
    valid_from DATETIME(6) NULL,
    valid_until DATETIME(6) NULL,
    metadata_json JSON NULL,
    created_by_id VARCHAR(24) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_relationship_edge (
        tenant_id,
        relationship_type_id,
        source_entity_type,
        source_entity_id,
        target_entity_type,
        target_entity_id
    ),
    KEY idx_nexa_relationship_source (
        tenant_id,
        source_entity_type,
        source_entity_id,
        deleted_at
    ),
    KEY idx_nexa_relationship_target (
        tenant_id,
        target_entity_type,
        target_entity_id,
        deleted_at
    ),
    CONSTRAINT fk_nexa_relationship_edge_tenant
        FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT fk_nexa_relationship_edge_type
        FOREIGN KEY (relationship_type_id, tenant_id)
        REFERENCES nexa_relationship_type (id, tenant_id) ON DELETE CASCADE,
    CONSTRAINT chk_nexa_relationship_not_self
        CHECK (
            source_entity_type <> target_entity_type OR
            source_entity_id <> target_entity_id
        ),
    CONSTRAINT chk_nexa_relationship_validity
        CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nexa_lifecycle_definition (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    definition_key VARCHAR(64) NOT NULL,
    name VARCHAR(120) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_lifecycle_definition_id_tenant (id, tenant_id),
    UNIQUE KEY uq_nexa_lifecycle_definition_key (tenant_id, definition_key),
    KEY idx_nexa_lifecycle_definition_entity (tenant_id, entity_type, is_active),
    CONSTRAINT fk_nexa_lifecycle_definition_tenant
        FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nexa_lifecycle_stage (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    lifecycle_definition_id CHAR(36) NOT NULL,
    stage_key VARCHAR(64) NOT NULL,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(32) NOT NULL DEFAULT 'active',
    position SMALLINT UNSIGNED NOT NULL,
    is_terminal TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    metadata_json JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_lifecycle_stage_id_tenant (id, tenant_id),
    UNIQUE KEY uq_nexa_lifecycle_stage_key (
        tenant_id,
        lifecycle_definition_id,
        stage_key
    ),
    UNIQUE KEY uq_nexa_lifecycle_stage_position (
        tenant_id,
        lifecycle_definition_id,
        position
    ),
    CONSTRAINT fk_nexa_lifecycle_stage_tenant
        FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT fk_nexa_lifecycle_stage_definition
        FOREIGN KEY (lifecycle_definition_id, tenant_id)
        REFERENCES nexa_lifecycle_definition (id, tenant_id) ON DELETE CASCADE,
    CONSTRAINT chk_nexa_lifecycle_stage_category
        CHECK (category IN ('new', 'active', 'qualified', 'customer', 'inactive', 'lost'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nexa_lifecycle_assignment (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    lifecycle_definition_id CHAR(36) NOT NULL,
    lifecycle_stage_id CHAR(36) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id VARCHAR(36) NOT NULL,
    entered_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_lifecycle_assignment_id_tenant (id, tenant_id),
    UNIQUE KEY uq_nexa_lifecycle_assignment (
        tenant_id,
        lifecycle_definition_id,
        entity_type,
        entity_id
    ),
    KEY idx_nexa_lifecycle_assignment_stage (
        tenant_id,
        lifecycle_stage_id,
        entered_at
    ),
    CONSTRAINT fk_nexa_lifecycle_assignment_tenant
        FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT fk_nexa_lifecycle_assignment_definition
        FOREIGN KEY (lifecycle_definition_id, tenant_id)
        REFERENCES nexa_lifecycle_definition (id, tenant_id) ON DELETE CASCADE,
    CONSTRAINT fk_nexa_lifecycle_assignment_stage
        FOREIGN KEY (lifecycle_stage_id, tenant_id)
        REFERENCES nexa_lifecycle_stage (id, tenant_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nexa_lifecycle_transition (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    lifecycle_assignment_id CHAR(36) NOT NULL,
    from_stage_id CHAR(36) NULL,
    to_stage_id CHAR(36) NOT NULL,
    reason VARCHAR(255) NULL,
    source VARCHAR(64) NOT NULL,
    actor_type VARCHAR(32) NOT NULL,
    actor_id VARCHAR(36) NULL,
    correlation_id CHAR(36) NULL,
    occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    metadata_json JSON NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_lifecycle_transition_id_tenant (id, tenant_id),
    KEY idx_nexa_lifecycle_transition_history (
        tenant_id,
        lifecycle_assignment_id,
        occurred_at
    ),
    CONSTRAINT fk_nexa_lifecycle_transition_tenant
        FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT fk_nexa_lifecycle_transition_assignment
        FOREIGN KEY (lifecycle_assignment_id, tenant_id)
        REFERENCES nexa_lifecycle_assignment (id, tenant_id) ON DELETE CASCADE,
    CONSTRAINT fk_nexa_lifecycle_transition_from_stage
        FOREIGN KEY (from_stage_id, tenant_id)
        REFERENCES nexa_lifecycle_stage (id, tenant_id),
    CONSTRAINT fk_nexa_lifecycle_transition_to_stage
        FOREIGN KEY (to_stage_id, tenant_id)
        REFERENCES nexa_lifecycle_stage (id, tenant_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nexa_timeline_event (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    contact_id VARCHAR(17) NULL,
    account_id VARCHAR(17) NULL,
    event_type VARCHAR(128) NOT NULL,
    source_entity_type VARCHAR(64) NOT NULL,
    source_entity_id VARCHAR(36) NOT NULL,
    source_occurred_at DATETIME(6) NOT NULL,
    actor_type VARCHAR(32) NULL,
    actor_id VARCHAR(36) NULL,
    visibility VARCHAR(24) NOT NULL DEFAULT 'internal',
    correlation_id CHAR(36) NULL,
    summary VARCHAR(255) NULL,
    metadata_json JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_timeline_source (
        tenant_id,
        source_entity_type,
        source_entity_id,
        event_type
    ),
    KEY idx_nexa_timeline_contact (tenant_id, contact_id, source_occurred_at),
    KEY idx_nexa_timeline_account (tenant_id, account_id, source_occurred_at),
    KEY idx_nexa_timeline_correlation (tenant_id, correlation_id),
    CONSTRAINT fk_nexa_timeline_tenant
        FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT chk_nexa_timeline_subject
        CHECK (contact_id IS NOT NULL OR account_id IS NOT NULL),
    CONSTRAINT chk_nexa_timeline_visibility
        CHECK (visibility IN ('internal', 'team', 'customer', 'restricted'))
) ENGINE=InnoDB;
