# AssetTracker Desktop (v1.0.0)

AssetTracker is an offline Windows desktop application for tracking IT and laboratory hardware assets. It runs a PHP and SQLite interface inside an Electron desktop window so equipment records stay stored locally on the workstation.

## Download

- [Download AssetTracker Setup 1.0.0.exe (Windows)](https://drive.google.com/file/d/1gEZkpTcij2Pm718mYsvyZy100NlCxOZS/view?usp=drive_link)

## Features

- **Asset inventory management:** Add, edit, and view hardware records including asset details, serial numbers, categories, and status.
- **Search and filtering:** Filter records by keyword, status, category, or entry date range.
- **Local SQLite storage:** Stores all data offline in `%APPDATA%\assettracker-desktop\vault.sqlite` with no internet connection or database server setup required.

## System Requirements

- Windows 10 or Windows 11 (64-bit)
- XAMPP for Windows installed in its default folder (`C:\xampp`) for PHP runtime extensions (Apache and MySQL services do not need to be running)

## Installation

1. Confirm that XAMPP is installed at `C:\xampp`.
2. Download **`AssetTracker Setup 1.0.0.exe`** from the release link above and run the installer.
3. If Windows Defender SmartScreen appears, click **More info** and select **Run anyway**.
4. Open **AssetTracker** from your Desktop shortcut or Start Menu.

## Repository Contents

- `asset-tracker/` — Core PHP, HTML, CSS, and SQLite application files.
- `README.md` — Documentation and installer download page.
