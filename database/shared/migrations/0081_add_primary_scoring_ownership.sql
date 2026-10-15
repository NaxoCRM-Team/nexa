ALTER TABLE nexa_scoring_model
 ADD COLUMN IF NOT EXISTS is_primary TINYINT(1) NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS primary_entity_type VARCHAR(24) GENERATED ALWAYS AS (CASE WHEN is_primary=1 THEN entity_type ELSE NULL END) STORED,
 ADD UNIQUE INDEX IF NOT EXISTS uq_scoring_primary_subject (tenant_id,service_id,primary_entity_type);
