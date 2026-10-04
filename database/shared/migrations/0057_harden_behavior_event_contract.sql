-- Phase 5: governed event classification and verifiable anonymous-to-known identity resolution.

ALTER TABLE nexa_visitor_identity
    ADD COLUMN IF NOT EXISTS resolution_method VARCHAR(40) NULL AFTER identified_at,
    ADD COLUMN IF NOT EXISTS resolution_evidence_hash CHAR(64) NULL AFTER resolution_method,
    ADD COLUMN IF NOT EXISTS resolved_by_event_id CHAR(36) NULL AFTER resolution_evidence_hash;

ALTER TABLE nexa_behavior_event
    ADD COLUMN IF NOT EXISTS event_category VARCHAR(32) NOT NULL DEFAULT 'custom' AFTER event_version,
    ADD COLUMN IF NOT EXISTS consent_category VARCHAR(24) NOT NULL DEFAULT 'necessary' AFTER event_category;

