-- Enforce CRM service ownership for every converted EspoCRM table.
-- TenantContext is the trusted source; the database rejects incomplete ownership.

INSERT INTO nexa_service_definition (id, service_key, display_name, description, measurement_unit, status) VALUES
    ('20000000-0000-4000-8000-000000000001', 'crm', 'CRM', 'Customer relationship and sales records', 'users', 'active')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), description = VALUES(description), status = VALUES(status);

INSERT INTO nexa_tenant_service (tenant_id, service_id, status, starts_at)
SELECT id, '20000000-0000-4000-8000-000000000001', 'active', CURRENT_TIMESTAMP(6) FROM nexa_tenant
ON DUPLICATE KEY UPDATE status = VALUES(status);

UPDATE `account`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `account` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `account_contact`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `account_contact` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `account_document`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `account_document` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `account_portal_user`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `account_portal_user` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `account_target_list`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `account_target_list` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `action_history_record`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `action_history_record` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `app_log_record`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `app_log_record` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `app_secret`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `app_secret` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `array_value`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `array_value` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `attachment`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `attachment` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `authentication_provider`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `authentication_provider` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `auth_log_record`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `auth_log_record` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `auth_token`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `auth_token` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `autofollow`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `autofollow` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `call`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `call` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `call_contact`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `call_contact` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `call_lead`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `call_lead` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `call_user`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `call_user` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `campaign`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `campaign` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `campaign_log_record`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `campaign_log_record` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `campaign_target_list`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `campaign_target_list` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `campaign_target_list_excluding`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `campaign_target_list_excluding` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `campaign_tracking_url`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `campaign_tracking_url` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `case`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `case` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `case_contact`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `case_contact` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `case_knowledge_base_article`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `case_knowledge_base_article` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `contact`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `contact` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `contact_document`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `contact_document` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `contact_meeting`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `contact_meeting` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `contact_opportunity`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `contact_opportunity` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `contact_target_list`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `contact_target_list` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `currency`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `currency` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `dashboard_template`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `dashboard_template` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `document`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `document` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `document_folder`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `document_folder` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `document_folder_path`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `document_folder_path` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `document_lead`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `document_lead` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `document_opportunity`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `document_opportunity` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `email`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `email` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `email_account`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `email_account` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `email_address`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `email_address` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `email_email_account`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `email_email_account` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `email_email_address`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `email_email_address` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `email_filter`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `email_filter` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `email_folder`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `email_folder` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `email_inbound_email`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `email_inbound_email` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `email_queue_item`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `email_queue_item` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `email_template`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `email_template` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `email_template_category`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `email_template_category` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `email_template_category_path`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `email_template_category_path` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `email_user`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `email_user` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `entity_email_address`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `entity_email_address` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `entity_phone_number`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `entity_phone_number` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `entity_team`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `entity_team` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `entity_user`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `entity_user` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `export`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `export` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `external_account`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `external_account` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `group_email_folder`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `group_email_folder` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `group_email_folder_team`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `group_email_folder_team` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `import`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `import` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `import_entity`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `import_entity` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `import_error`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `import_error` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `inbound_email`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `inbound_email` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `inbound_email_team`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `inbound_email_team` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `integration`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `integration` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `job`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `job` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `kanban_order`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `kanban_order` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `knowledge_base_article`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `knowledge_base_article` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `knowledge_base_article_knowledge_base_category`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `knowledge_base_article_knowledge_base_category` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `knowledge_base_article_portal`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `knowledge_base_article_portal` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `knowledge_base_category`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `knowledge_base_category` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `knowledge_base_category_path`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `knowledge_base_category_path` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `layout_record`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `layout_record` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `layout_set`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `layout_set` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `lead`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `lead` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `lead_capture`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `lead_capture` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `lead_capture_log_record`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `lead_capture_log_record` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `lead_meeting`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `lead_meeting` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `lead_target_list`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `lead_target_list` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `mass_action`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `mass_action` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `mass_email`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `mass_email` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `mass_email_target_list`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `mass_email_target_list` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `mass_email_target_list_excluding`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `mass_email_target_list_excluding` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `meeting`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `meeting` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `meeting_user`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `meeting_user` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `next_number`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `next_number` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `note`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `note` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `note_portal`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `note_portal` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `note_team`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `note_team` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `note_user`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `note_user` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `notification`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `notification` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `opportunity`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `opportunity` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `o_auth_account`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `o_auth_account` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `o_auth_provider`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `o_auth_provider` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `password_change_request`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `password_change_request` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `phone_number`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `phone_number` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `portal`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `portal` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `portal_portal_role`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `portal_portal_role` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `portal_role`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `portal_role` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `portal_role_user`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `portal_role_user` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `portal_user`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `portal_user` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `preferences`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `preferences` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `reminder`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `reminder` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `role`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `role` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `role_team`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `role_team` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `role_user`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `role_user` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `scheduled_job`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `scheduled_job` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `scheduled_job_log_record`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `scheduled_job_log_record` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `sms`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `sms` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `sms_phone_number`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `sms_phone_number` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `star_subscription`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `star_subscription` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `stream_subscription`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `stream_subscription` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `target`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `target` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `target_list`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `target_list` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `target_list_category`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `target_list_category` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `target_list_category_path`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `target_list_category_path` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `target_list_user`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `target_list_user` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `task`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `task` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `team`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `team` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `team_user`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `team_user` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `template`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `template` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `two_factor_code`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `two_factor_code` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `unique_id`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `unique_id` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `user`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `user` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `user_data`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `user_data` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `user_reaction`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `user_reaction` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `user_working_time_range`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `user_working_time_range` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `webhook`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `webhook` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `webhook_event_queue_item`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `webhook_event_queue_item` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `webhook_queue_item`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `webhook_queue_item` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `working_time_calendar`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `working_time_calendar` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `working_time_calendar_working_time_range`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `working_time_calendar_working_time_range` MODIFY COLUMN service_id CHAR(36) NOT NULL;

UPDATE `working_time_range`
SET service_id = '20000000-0000-4000-8000-000000000001'
WHERE service_id IS NULL OR service_id = '';
ALTER TABLE `working_time_range` MODIFY COLUMN service_id CHAR(36) NOT NULL;
