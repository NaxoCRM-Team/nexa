-- Expand governed segments with folders, access rules, exclusions and static snapshots.

ALTER TABLE `nexa_segment_definition`
    ADD COLUMN IF NOT EXISTS `folder_name` VARCHAR(120) NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `access_level` VARCHAR(16) NOT NULL DEFAULT 'everyone' AFTER `folder_name`,
    ADD COLUMN IF NOT EXISTS `exclusion_rules_json` JSON NULL AFTER `rules_json`,
    ADD COLUMN IF NOT EXISTS `snapshot_from_rules` TINYINT(1) NOT NULL DEFAULT 0 AFTER `exclusion_rules_json`,
    ADD CONSTRAINT `chk_nexa_segment_access` CHECK (`access_level` IN ('everyone', 'owner', 'admins'));

UPDATE `nexa_segment_definition` SET `exclusion_rules_json` = JSON_ARRAY() WHERE `exclusion_rules_json` IS NULL;
ALTER TABLE `nexa_segment_definition` MODIFY COLUMN `exclusion_rules_json` JSON NOT NULL;

ALTER TABLE `nexa_segment_version`
    ADD COLUMN IF NOT EXISTS `folder_name` VARCHAR(120) NULL AFTER `segment_type`,
    ADD COLUMN IF NOT EXISTS `access_level` VARCHAR(16) NOT NULL DEFAULT 'everyone' AFTER `folder_name`,
    ADD COLUMN IF NOT EXISTS `exclusion_rules_json` JSON NULL AFTER `rules_json`,
    ADD COLUMN IF NOT EXISTS `snapshot_from_rules` TINYINT(1) NOT NULL DEFAULT 0 AFTER `exclusion_rules_json`;

UPDATE `nexa_segment_version` SET `exclusion_rules_json` = JSON_ARRAY() WHERE `exclusion_rules_json` IS NULL;
ALTER TABLE `nexa_segment_version` MODIFY COLUMN `exclusion_rules_json` JSON NOT NULL;
