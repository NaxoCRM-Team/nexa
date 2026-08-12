-- Store Contact Title independently so contacts without a primary Account can
-- still carry a role. Existing primary relationship roles are retained.
ALTER TABLE contact
    ADD COLUMN IF NOT EXISTS title VARCHAR(100) NULL AFTER last_name,
    ADD INDEX IF NOT EXISTS idx_contact_tenant_title (
        tenant_id, service_id, title, deleted
    );

UPDATE contact AS c
INNER JOIN account_contact AS ac
    ON ac.contact_id = c.id
    AND ac.account_id = c.account_id
    AND ac.deleted = 0
SET c.title = ac.role
WHERE c.title IS NULL
    AND ac.role IS NOT NULL;
