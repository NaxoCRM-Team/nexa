-- Contact portraits use Espo's native Attachment-backed image field.
ALTER TABLE contact
    ADD COLUMN IF NOT EXISTS profile_image_id VARCHAR(17) NULL AFTER last_website_visit_at,
    ADD INDEX IF NOT EXISTS idx_contact_profile_image (profile_image_id);
