-- AssetTracker schema for the standalone (desktop) build.
-- Used only when DB_DRIVER=sqlite and the vault file doesn't exist yet — i.e.
-- "New Vault" in the desktop app. Deliberately has NO sample data: a new
-- personal vault should start empty, not pre-populated with someone else's
-- demo assets. (Compare schema.sql, the MySQL version used for the hosted
-- demo, which does seed sample data.)

CREATE TABLE IF NOT EXISTS app_settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    password_hash TEXT,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
INSERT OR IGNORE INTO app_settings (id, password_hash) VALUES (1, NULL);

-- A folder holds only assets of its own type (Physical or Digital).
CREATE TABLE IF NOT EXISTS folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL DEFAULT 'Physical' CHECK (type IN ('Physical', 'Digital')),
    description TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS assets (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,                  -- official name, fixed after registration
    nickname TEXT,                       -- optional, editable
    type TEXT NOT NULL CHECK (type IN ('Physical', 'Digital')),
    description TEXT,
    location TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'Active' CHECK (status IN ('Active', 'In Transit', 'Maintenance', 'Archived')),
    folder_id INTEGER REFERENCES folders(id) ON DELETE SET NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_assets_folder ON assets(folder_id);
CREATE INDEX IF NOT EXISTS idx_assets_updated ON assets(updated_at);

-- SQLite has no "ON UPDATE CURRENT_TIMESTAMP" column option; this trigger does the same job.
CREATE TRIGGER IF NOT EXISTS assets_set_updated_at
AFTER UPDATE ON assets
BEGIN
    UPDATE assets SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- Movement / entry history: where it was, and how to get there.
CREATE TABLE IF NOT EXISTS asset_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_id TEXT NOT NULL REFERENCES assets(id) ON DELETE CASCADE,
    location TEXT NOT NULL,
    notes TEXT,
    directions TEXT,                     -- optional: how to get to / open the location
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_history_asset ON asset_history(asset_id, created_at);
