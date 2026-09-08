ALTER TABLE property_owners
    ADD COLUMN luogo_nascita_enc VARBINARY(512) NULL AFTER data_nascita;
