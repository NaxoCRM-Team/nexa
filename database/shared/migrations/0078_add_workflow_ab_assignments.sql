-- Persist A/B routing before advancing the existing immutable workflow graph.
CREATE TABLE IF NOT EXISTS nexa_workflow_ab_assignment (
 tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL,
 workflow_id CHAR(36) NOT NULL, version_number INT UNSIGNED NOT NULL,
 run_id CHAR(36) NOT NULL, node_id VARCHAR(40) NOT NULL, contact_id VARCHAR(24) NOT NULL,
 branch_key CHAR(1) NOT NULL, assignment_sha256 CHAR(64) NOT NULL, weight_a SMALLINT UNSIGNED NOT NULL,
 assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(tenant_id,service_id,run_id,node_id),
 KEY idx_workflow_ab_results(tenant_id,service_id,workflow_id,version_number,node_id,branch_key),
 CONSTRAINT fk_workflow_ab_run FOREIGN KEY(run_id) REFERENCES nexa_workflow_run(id) ON DELETE CASCADE,
 CONSTRAINT fk_workflow_ab_version FOREIGN KEY(tenant_id,service_id,workflow_id,version_number) REFERENCES nexa_workflow_version(tenant_id,service_id,workflow_id,version_number) ON DELETE CASCADE,
 CONSTRAINT chk_workflow_ab_weight CHECK(weight_a BETWEEN 1 AND 99),
 CONSTRAINT chk_workflow_ab_branch CHECK(branch_key IN ('A','B'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
