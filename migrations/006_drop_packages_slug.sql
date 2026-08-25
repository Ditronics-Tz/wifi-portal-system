-- Drop packages.slug — packages are identified by id/name only.

BEGIN;

DROP INDEX IF EXISTS idx_packages_slug;
ALTER TABLE packages DROP COLUMN IF EXISTS slug;

COMMIT;
