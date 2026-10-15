-- Dispatch coordination only; native Job records retain tenant/service ownership.
CREATE TABLE IF NOT EXISTS nexa_job_dispatch_rotation (
    scope_key CHAR(64) NOT NULL,
    last_tenant_id VARCHAR(36) NOT NULL DEFAULT '',
    PRIMARY KEY (scope_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
