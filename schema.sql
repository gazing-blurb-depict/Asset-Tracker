-- AssetTracker schema + seed data.
-- Single source of truth: db.php runs this file automatically on first launch,
-- or you can import it manually in phpMyAdmin.
--
-- Single-owner app (no user accounts). One optional master password can lock
-- the whole vault, stored as a single row in app_settings.

CREATE DATABASE IF NOT EXISTS asset_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE asset_tracker;

-- Exactly one row (id is always 1). password_hash is NULL until the owner sets a password.
CREATE TABLE IF NOT EXISTS app_settings (
    id TINYINT PRIMARY KEY DEFAULT 1,
    password_hash VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT single_row CHECK (id = 1)
) ENGINE=InnoDB;

INSERT INTO app_settings (id, password_hash) VALUES (1, NULL)
ON DUPLICATE KEY UPDATE id = id;

-- A folder holds only assets of its own type (Physical or Digital).
CREATE TABLE IF NOT EXISTS folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    type ENUM('Physical', 'Digital') NOT NULL DEFAULT 'Physical',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assets (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(150) NOT NULL,          -- official name, fixed after registration
    nickname VARCHAR(100) NULL,          -- optional, editable
    type ENUM('Physical', 'Digital') NOT NULL,
    description TEXT,
    location VARCHAR(255) NOT NULL,
    status ENUM('Active', 'In Transit', 'Maintenance', 'Archived') NOT NULL DEFAULT 'Active',
    folder_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_assets_folder (folder_id),
    INDEX idx_assets_updated (updated_at),
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Movement / entry history: where it was, and how to get there.
CREATE TABLE IF NOT EXISTS asset_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id VARCHAR(50) NOT NULL,
    location VARCHAR(255) NOT NULL,
    notes TEXT,
    directions TEXT NULL,                -- optional: how to get to / open the location
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_history_asset (asset_id, created_at),
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---- Seed data -------------------------------------------------------------

INSERT INTO folders (id, name, type, description) VALUES
(1, 'IT Hardware', 'Physical', 'Laptops, tablets, and mobile computing hardware'),
(2, 'Office Furniture', 'Physical', 'Desks, chairs, and ergonomic furniture'),
(3, 'Software & Docs', 'Digital', 'API specs, documentation, cloud files, and master assets')
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO assets (id, name, type, description, location, status, folder_id) VALUES
('ASSET-001', 'Dell XPS 15 Laptop', 'Physical', 'High-performance laptop for design team', 'Main Office - Desk 4', 'Active', 1),
('ASSET-002', 'Project Aurora Specs', 'Digital', 'Architecture documentation and API specs', 'Internal SharePoint / Designs', 'Active', 3),
('ASSET-003', 'Herman Miller Aeron', 'Physical', 'Ergonomic office chair', 'Conference Room B', 'Maintenance', 2),
('ASSET-004', 'Logo Master File', 'Digital', 'Vector branding guidelines and logos', 'Cloud Storage / Branding', 'Active', 3),
('ASSET-005', 'iPad Pro 12.9', 'Physical', 'Testing device for mobile QA team', 'In Transit - Shipping', 'Active', 1)
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO asset_history (asset_id, location, notes, directions) VALUES
('ASSET-001', 'Main Office - Desk 17', 'Initial Assignment', NULL),
('ASSET-001', 'Main Office - Desk 4', 'Quarterly Maintenance Check', 'Second floor, Zone B, design cluster');
