CREATE TABLE IF NOT EXISTS nexa_scoring_adjustment (
 id CHAR(36) NOT NULL,tenant_id CHAR(36) NOT NULL,service_id CHAR(36) NOT NULL,model_id CHAR(36) NOT NULL,
 record_id VARCHAR(24) NOT NULL,request_hash CHAR(64) NOT NULL,request_json JSON NOT NULL,
 delta INT NOT NULL,reason VARCHAR(500) NOT NULL,expires_at DATETIME(6) NULL,
 reverses_id CHAR(36) NULL,evaluation_id CHAR(36) NULL,result_json JSON NULL,
 created_by_id VARCHAR(24) NOT NULL,created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(id),UNIQUE KEY uq_scoring_adjustment_scope(tenant_id,service_id,model_id,id),
 UNIQUE KEY uq_scoring_adjustment_request(tenant_id,service_id,model_id,request_hash),
 UNIQUE KEY uq_scoring_adjustment_reversal(tenant_id,service_id,model_id,reverses_id),
 KEY idx_scoring_adjustment_record(tenant_id,service_id,model_id,record_id,expires_at),
 CONSTRAINT fk_scoring_adjustment_model FOREIGN KEY(tenant_id,service_id,model_id) REFERENCES nexa_scoring_model(tenant_id,service_id,id) ON DELETE CASCADE,
 CONSTRAINT fk_scoring_adjustment_reverse FOREIGN KEY(tenant_id,service_id,model_id,reverses_id) REFERENCES nexa_scoring_adjustment(tenant_id,service_id,model_id,id),
 CONSTRAINT chk_scoring_adjustment_delta CHECK(delta<>0 AND delta BETWEEN -10000 AND 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
