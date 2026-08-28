-- Migration 004: add `coord_source` column to properties
-- Tracks which geocoding provider resolved each property's coordinates.
-- Values: 'gml_locale' | 'db_cadastral' | 'cache' | 'wfs' | 'zornade' | null
-- Existing rows with coordinates receive 'wfs' as a conservative default.

ALTER TABLE properties ADD COLUMN coord_source VARCHAR(20) NULL DEFAULT NULL
    AFTER posizione_verificata;

UPDATE properties SET coord_source = 'wfs' WHERE lat IS NOT NULL AND coord_source IS NULL;
