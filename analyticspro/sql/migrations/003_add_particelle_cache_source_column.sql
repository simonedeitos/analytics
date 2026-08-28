-- Migration 003: add `source` column to particelle_cache
-- Tracks which geocoding provider (Zornade or WFS-AdE) resolved each parcel.
-- Existing rows receive the default value 'WFS-AdE' to preserve backwards compatibility.
-- This migration is safe to run multiple times (uses IF NOT EXISTS / ignored by SQLite
-- if the column already exists — wrapped in a try/ignore pattern in PHP).

ALTER TABLE particelle_cache ADD COLUMN source VARCHAR(20) DEFAULT 'WFS-AdE';
