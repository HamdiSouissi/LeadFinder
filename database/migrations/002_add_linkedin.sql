-- Apply once to an existing LeadFinder database after migration 001.
ALTER TABLE prospects ADD COLUMN linkedin TEXT NULL AFTER instagram;
