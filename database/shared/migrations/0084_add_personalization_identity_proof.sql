-- M13: expiring backend verification over canonical M11 visitor identities.
-- No raw visitor/session key or identity reference is stored. Recovery: revoke/expire
-- proofs; preserve canonical CRM, consent and append-only audit records.
CREATE TABLE IF NOT EXISTS nexa_personalization_identity_proof (
 tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL,
 visitor_identity_id CHAR(36) NOT NULL, issuer_user_id VARCHAR(24) NOT NULL,
 contact_id VARCHAR(24) NOT NULL, proof_method VARCHAR(32) NOT NULL,
 evidence_hash CHAR(64) NOT NULL, verification_event_id CHAR(36) NOT NULL, verified_at DATETIME(6) NOT NULL,
 expires_at DATETIME(6) NOT NULL, revoked_at DATETIME(6) NULL,
 PRIMARY KEY(tenant_id,service_id,issuer_user_id,visitor_identity_id),
 KEY idx_personalization_proof_expiry(tenant_id,service_id,expires_at),
 CONSTRAINT fk_personalization_proof_workspace FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id) ON DELETE CASCADE,
 CONSTRAINT fk_personalization_proof_visitor FOREIGN KEY(visitor_identity_id) REFERENCES nexa_visitor_identity(id) ON DELETE CASCADE,
 CONSTRAINT chk_personalization_proof_method CHECK(proof_method IN ('authenticated_session','verified_email','oauth_subject'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
