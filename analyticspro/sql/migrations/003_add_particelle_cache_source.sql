-- Migration 003: add source column to particelle_cache (SQLite)
-- Tracks which provider (Zornade or WFS-AdE) resolved each cached parcel.
-- Existing rows default to 'WFS-AdE' for backward compatibility.

ALTER TABLE particelle_cache ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'WFS-AdE';
