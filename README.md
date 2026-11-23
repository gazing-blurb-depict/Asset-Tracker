# AssetTracker

Single-owner Physical & Digital Asset Vault for tracking asset locations, movement history, and folder categories.

## Live Demo

- **Web Demo (GitHub Pages):** `https://gazing-blurb-depict.github.io/Asset-Tracker/`
- **Desktop Installer (Windows):** Available under [Releases](../../releases/latest)
- [Download AssetTracker Setup 1.0.0.exe (Windows)](https://drive.google.com/file/d/1gEZkpTcij2Pm718mYsvyZy100NlCxOZS/view?usp=drive_link)

## Features

- **Physical & Digital Asset Vault:** Register physical items and digital files/URLs with custom or auto-generated IDs (`ASSET-001`), optional nicknames, and typed folders.
- **Location Movement History:** Log location updates with directions and notes while keeping a full chronological audit trail.
- **Search, Filtering & Sorting:** Search by ID, name, nickname, or location; filter by folder, type (`Physical` / `Digital`), status (`Active`, `In Transit`, `Maintenance`, `Archived`), and last-entry date range.
- **Dashboard & Pending Alerts:** Track total, physical, digital, and pending-update counts (assets with no entry in 30+ days).
- **Vault Security & Backups:** Optional master password lock, CSV export, and full JSON vault backup/restore.

## Architecture

- **GitHub Pages Build (`index.html`, `style.css`, `app.js`):** Static client-side build backed by `localStorage` for instant browser deployment.
- **Web / Desktop Build (`index.php`, `api.php`, `db.php`, `main.js`):** PHP + MySQL (XAMPP) or Electron + SQLite (`vault.sqlite`).