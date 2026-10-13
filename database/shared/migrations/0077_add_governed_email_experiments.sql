-- Experiments retain native approved templates, queues and provider reconciliation.
CREATE TABLE IF NOT EXISTS nexa_email_experiment (
 id CHAR(36) NOT NULL, tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL,
 name VARCHAR(160) NOT NULL, campaign_id VARCHAR(24) NOT NULL, purpose_id CHAR(36) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'draft', test_percent SMALLINT UNSIGNED NOT NULL,
 holdout_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0, window_minutes INT UNSIGNED NOT NULL,
 assignment_seed CHAR(64) NOT NULL, execution_user_id VARCHAR(24) NOT NULL,
 winner_variant CHAR(1) NULL, winner_reason VARCHAR(1000) NULL,
 started_at DATETIME(6) NULL, test_completed_at DATETIME(6) NULL, decision_due_at DATETIME(6) NULL,
 completed_at DATETIME(6) NULL, last_error VARCHAR(1000) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 modified_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(id), UNIQUE KEY uq_email_experiment_scope(tenant_id,service_id,id),
 KEY idx_email_experiment_worker(tenant_id,service_id,status,created_at),
 CONSTRAINT fk_email_experiment_scope FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id) ON DELETE CASCADE,
 CONSTRAINT chk_email_experiment_status CHECK(status IN ('draft','testing','awaiting_winner','rollout','paused','completed','cancelled')),
 CONSTRAINT chk_email_experiment_size CHECK(test_percent BETWEEN 2 AND 100 AND holdout_percent<=98 AND test_percent+holdout_percent<=100),
 CONSTRAINT chk_email_experiment_window CHECK(window_minutes BETWEEN 1 AND 10080)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_email_experiment_variant (
 tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL, experiment_id CHAR(36) NOT NULL,
 variant_key CHAR(1) NOT NULL, source_profile_id CHAR(36) NOT NULL, source_version INT UNSIGNED NOT NULL,
 snapshot_json LONGTEXT NOT NULL, snapshot_sha256 CHAR(64) NOT NULL,
 PRIMARY KEY(tenant_id,service_id,experiment_id,variant_key),
 KEY idx_email_experiment_source(tenant_id,service_id,source_profile_id),
 CONSTRAINT fk_email_experiment_variant FOREIGN KEY(tenant_id,service_id,experiment_id) REFERENCES nexa_email_experiment(tenant_id,service_id,id) ON DELETE CASCADE,
 CONSTRAINT chk_email_experiment_variant CHECK(variant_key IN ('A','B'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_email_experiment_assignment (
 tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL, experiment_id CHAR(36) NOT NULL,
 contact_id VARCHAR(24) NOT NULL, recipient_sha256 CHAR(64) NOT NULL,
 cohort VARCHAR(12) NOT NULL, variant_key CHAR(1) NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending', queue_item_id VARCHAR(24) NULL, send_profile_id CHAR(36) NULL,
 error_reason VARCHAR(1000) NULL, queued_at DATETIME(6) NULL,
 PRIMARY KEY(tenant_id,service_id,experiment_id,contact_id),
 UNIQUE KEY uq_email_experiment_recipient(tenant_id,service_id,experiment_id,recipient_sha256),
 UNIQUE KEY uq_email_experiment_queue(tenant_id,service_id,queue_item_id),
 KEY idx_email_experiment_pending(tenant_id,service_id,experiment_id,status,cohort),
 CONSTRAINT fk_email_experiment_assignment FOREIGN KEY(tenant_id,service_id,experiment_id) REFERENCES nexa_email_experiment(tenant_id,service_id,id) ON DELETE CASCADE,
 CONSTRAINT chk_email_experiment_cohort CHECK(cohort IN ('test','holdout','rollout')),
 CONSTRAINT chk_email_experiment_assignment_status CHECK(status IN ('pending','queued','suppressed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_email_experiment_event (
 id CHAR(36) NOT NULL, tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL, experiment_id CHAR(36) NOT NULL,
 event_type VARCHAR(40) NOT NULL, actor_user_id VARCHAR(24) NULL, payload_json JSON NOT NULL,
 occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(id), KEY idx_email_experiment_history(tenant_id,service_id,experiment_id,occurred_at),
 CONSTRAINT fk_email_experiment_event FOREIGN KEY(tenant_id,service_id,experiment_id) REFERENCES nexa_email_experiment(tenant_id,service_id,id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
