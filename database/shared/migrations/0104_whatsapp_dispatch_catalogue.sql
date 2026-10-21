ALTER TABLE nexa_messaging_connection
 ADD COLUMN IF NOT EXISTS whatsapp_templates_json LONGTEXT DEFAULT NULL,
 ADD CONSTRAINT chk_whatsapp_template_catalogue CHECK (whatsapp_templates_json IS NULL OR JSON_VALID(whatsapp_templates_json));
