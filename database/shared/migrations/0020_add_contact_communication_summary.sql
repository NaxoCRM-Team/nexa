-- Keep the latest active restriction context available to Contact workspaces.
ALTER TABLE contact
    ADD COLUMN do_not_contact_reason VARCHAR(64) NULL AFTER do_not_contact_channels,
    ADD COLUMN do_not_contact_note VARCHAR(1000) NULL AFTER do_not_contact_reason;
