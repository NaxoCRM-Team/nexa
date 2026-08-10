-- Persist customer profile details used by the Contact form and future activity tracking.
-- Tenant and service ownership continues to be injected and scoped by the central ORM processor.

ALTER TABLE contact
    ADD COLUMN IF NOT EXISTS facebook_url VARCHAR(255) NULL AFTER website,
    ADD COLUMN IF NOT EXISTS instagram_url VARCHAR(255) NULL AFTER facebook_url,
    ADD COLUMN IF NOT EXISTS skype_name VARCHAR(100) NULL AFTER instagram_url,
    ADD COLUMN IF NOT EXISTS x_url VARCHAR(255) NULL AFTER skype_name,
    ADD COLUMN IF NOT EXISTS linkedin_url VARCHAR(255) NULL AFTER x_url,
    ADD COLUMN IF NOT EXISTS tiktok_url VARCHAR(255) NULL AFTER linkedin_url,
    ADD COLUMN IF NOT EXISTS tags JSON NULL AFTER tiktok_url,
    ADD COLUMN IF NOT EXISTS preferred_time_zone VARCHAR(100) NULL AFTER tags,
    ADD COLUMN IF NOT EXISTS last_website_visit_at DATETIME NULL AFTER preferred_time_zone,
    ADD INDEX IF NOT EXISTS idx_contact_tenant_last_visit (tenant_id, service_id, last_website_visit_at);
