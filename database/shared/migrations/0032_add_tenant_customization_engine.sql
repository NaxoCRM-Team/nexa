-- Tenant-owned customization definitions and values.
-- Native Contact and Account fields remain physical columns; tenant-created
-- properties and objects use these tables so one tenant never alters the
-- shared application schema for every other tenant.

CREATE TABLE IF NOT EXISTS nexa_custom_entity_definition (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    entity_key VARCHAR(64) NOT NULL,
    label VARCHAR(120) NOT NULL,
    plural_label VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    icon_class VARCHAR(80) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_by_id VARCHAR(24) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_custom_entity_scope (tenant_id, service_id, entity_key),
    UNIQUE KEY uq_nexa_custom_entity_id_scope (id, tenant_id, service_id),
    KEY idx_nexa_custom_entity_status (tenant_id, service_id, status, label),
    CONSTRAINT fk_nexa_custom_entity_tenant FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT chk_nexa_custom_entity_status CHECK (status IN ('active', 'archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_custom_field_definition (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    field_key VARCHAR(64) NOT NULL,
    label VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    data_type VARCHAR(24) NOT NULL,
    options_json JSON NULL,
    default_value_json JSON NULL,
    validation_json JSON NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_unique TINYINT(1) NOT NULL DEFAULT 0,
    is_filterable TINYINT(1) NOT NULL DEFAULT 1,
    is_searchable TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_by_id VARCHAR(24) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_custom_field_scope (tenant_id, service_id, entity_type, field_key),
    UNIQUE KEY uq_nexa_custom_field_id_scope (id, tenant_id, service_id),
    KEY idx_nexa_custom_field_layout (tenant_id, service_id, entity_type, is_active, position),
    CONSTRAINT fk_nexa_custom_field_tenant FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT chk_nexa_custom_field_type CHECK (data_type IN ('text','long_text','number','currency','date','datetime','boolean','single_select','multi_select','url','email','phone','user','relationship'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_custom_layout_definition (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    layout_context VARCHAR(24) NOT NULL,
    layout_json JSON NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by_id VARCHAR(24) NULL,
    updated_by_id VARCHAR(24) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_custom_layout_scope (tenant_id, service_id, entity_type, layout_context),
    KEY idx_nexa_custom_layout_entity (tenant_id, service_id, entity_type),
    CONSTRAINT fk_nexa_custom_layout_tenant FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT chk_nexa_custom_layout_context CHECK (layout_context IN ('create','edit','detail','list','search'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_custom_record (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    custom_entity_id CHAR(36) NOT NULL,
    display_name VARCHAR(191) NOT NULL,
    assigned_user_id VARCHAR(24) NULL,
    created_by_id VARCHAR(24) NULL,
    modified_by_id VARCHAR(24) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_custom_record_id_scope (id, tenant_id, service_id),
    KEY idx_nexa_custom_record_entity (tenant_id, service_id, custom_entity_id, deleted_at, updated_at),
    KEY idx_nexa_custom_record_owner (tenant_id, service_id, assigned_user_id, deleted_at),
    CONSTRAINT fk_nexa_custom_record_tenant FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT fk_nexa_custom_record_entity FOREIGN KEY (custom_entity_id, tenant_id, service_id) REFERENCES nexa_custom_entity_definition (id, tenant_id, service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_custom_field_value (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    field_definition_id CHAR(36) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id VARCHAR(36) NOT NULL,
    value_text LONGTEXT NULL,
    value_number DECIMAL(30,8) NULL,
    value_date DATE NULL,
    value_datetime DATETIME(6) NULL,
    value_boolean TINYINT(1) NULL,
    value_json JSON NULL,
    created_by_id VARCHAR(24) NULL,
    updated_by_id VARCHAR(24) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_custom_value_record (tenant_id, service_id, field_definition_id, entity_type, entity_id),
    KEY idx_nexa_custom_value_record_lookup (tenant_id, service_id, entity_type, entity_id),
    KEY idx_nexa_custom_value_text (tenant_id, service_id, field_definition_id, value_text(128)),
    KEY idx_nexa_custom_value_number (tenant_id, service_id, field_definition_id, value_number),
    KEY idx_nexa_custom_value_date (tenant_id, service_id, field_definition_id, value_date),
    CONSTRAINT fk_nexa_custom_value_tenant FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT fk_nexa_custom_value_field FOREIGN KEY (field_definition_id, tenant_id, service_id) REFERENCES nexa_custom_field_definition (id, tenant_id, service_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_custom_relationship_definition (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    relationship_key VARCHAR(64) NOT NULL,
    label VARCHAR(120) NOT NULL,
    inverse_label VARCHAR(120) NOT NULL,
    source_entity_type VARCHAR(64) NOT NULL,
    target_entity_type VARCHAR(64) NOT NULL,
    cardinality VARCHAR(24) NOT NULL DEFAULT 'many_to_many',
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_id VARCHAR(24) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_custom_relationship_scope (tenant_id, service_id, relationship_key),
    UNIQUE KEY uq_nexa_custom_relationship_id_scope (id, tenant_id, service_id),
    KEY idx_nexa_custom_relationship_endpoint (tenant_id, service_id, source_entity_type, target_entity_type, is_active),
    CONSTRAINT fk_nexa_custom_relationship_tenant FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT chk_nexa_custom_relationship_cardinality CHECK (cardinality IN ('one_to_one','one_to_many','many_to_one','many_to_many'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_custom_relationship_link (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    relationship_definition_id CHAR(36) NOT NULL,
    source_entity_type VARCHAR(64) NOT NULL,
    source_entity_id VARCHAR(36) NOT NULL,
    target_entity_type VARCHAR(64) NOT NULL,
    target_entity_id VARCHAR(36) NOT NULL,
    created_by_id VARCHAR(24) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_custom_relationship_link (tenant_id, service_id, relationship_definition_id, source_entity_type, source_entity_id, target_entity_type, target_entity_id),
    KEY idx_nexa_custom_relationship_source (tenant_id, service_id, source_entity_type, source_entity_id, deleted_at),
    KEY idx_nexa_custom_relationship_target (tenant_id, service_id, target_entity_type, target_entity_id, deleted_at),
    CONSTRAINT fk_nexa_custom_link_tenant FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT fk_nexa_custom_link_definition FOREIGN KEY (relationship_definition_id, tenant_id, service_id) REFERENCES nexa_custom_relationship_definition (id, tenant_id, service_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

