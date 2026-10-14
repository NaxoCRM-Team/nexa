CREATE TABLE IF NOT EXISTS nexa_scoring_model (
 id CHAR(36) NOT NULL,tenant_id CHAR(36) NOT NULL,service_id CHAR(36) NOT NULL,
 name VARCHAR(160) NOT NULL,entity_type VARCHAR(24) NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'draft',
 version_number INT UNSIGNED NOT NULL DEFAULT 1,definition_json JSON NOT NULL,definition_hash CHAR(64) NOT NULL,
 created_by_id VARCHAR(24) NOT NULL,modified_by_id VARCHAR(24) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),modified_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(id),UNIQUE KEY uq_scoring_model_scope(tenant_id,service_id,id),
 CONSTRAINT fk_scoring_model_scope FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id) ON DELETE CASCADE,
 CONSTRAINT chk_scoring_subject CHECK(entity_type IN ('Contact','Account','Opportunity')),
 CONSTRAINT chk_scoring_status CHECK(status IN ('draft','published','archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nexa_scoring_version (
 tenant_id CHAR(36) NOT NULL,service_id CHAR(36) NOT NULL,model_id CHAR(36) NOT NULL,version_number INT UNSIGNED NOT NULL,
 definition_json JSON NOT NULL,definition_hash CHAR(64) NOT NULL,published_by_id VARCHAR(24) NOT NULL,published_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(tenant_id,service_id,model_id,version_number),
 CONSTRAINT fk_scoring_version_model FOREIGN KEY(tenant_id,service_id,model_id) REFERENCES nexa_scoring_model(tenant_id,service_id,id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nexa_scoring_evaluation (
 id CHAR(36) NOT NULL,tenant_id CHAR(36) NOT NULL,service_id CHAR(36) NOT NULL,model_id CHAR(36) NOT NULL,version_number INT UNSIGNED NOT NULL,
 record_id VARCHAR(24) NOT NULL,request_hash CHAR(64) NOT NULL,score INT NOT NULL,inputs_json JSON NOT NULL,result_json JSON NOT NULL,input_hash CHAR(64) NOT NULL,
 evaluated_by_id VARCHAR(24) NOT NULL,evaluated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(id),UNIQUE KEY uq_scoring_request(tenant_id,service_id,model_id,request_hash),
 KEY idx_scoring_record(tenant_id,service_id,model_id,record_id,evaluated_at),
 CONSTRAINT fk_scoring_evaluation_version FOREIGN KEY(tenant_id,service_id,model_id,version_number) REFERENCES nexa_scoring_version(tenant_id,service_id,model_id,version_number) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
