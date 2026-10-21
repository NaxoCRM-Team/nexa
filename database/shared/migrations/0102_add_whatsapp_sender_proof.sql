-- Preserve the shared connection model and exclusive verified sender ownership.
ALTER TABLE nexa_messaging_connection
 DROP CONSTRAINT IF EXISTS chk_messaging_verified_proof,
 ADD CONSTRAINT chk_messaging_verified_proof CHECK(
  status<>'verified' OR (
   verified_at IS NOT NULL AND provider_resource_sid IS NOT NULL AND (
    (channel='sms' AND provider_resource_sid REGEXP '^PN[0-9a-fA-F]{32}$') OR
    (channel='whatsapp' AND provider_resource_sid REGEXP '^XE[0-9a-fA-F]{32}$')
   )
  )
 );
