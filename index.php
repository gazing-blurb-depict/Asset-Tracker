<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AssetTracker</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <!-- LOCK SCREEN: only shown while a master password is set and not yet unlocked -->
    <div id="lockView" class="auth-wrapper hidden">
        <div class="auth-card">
            <h2>AssetTracker</h2>
            <p class="auth-intro">This vault is locked.</p>
            <form id="unlockForm">
                <div class="form-group"><label>Master Password</label><input type="password" id="unlockPassword" autocomplete="current-password" required autofocus></div>
                <button type="submit" class="btn btn-primary btn-block mt-8">Unlock</button>
                <p id="unlockError" class="error-text mt-8 hidden"></p>
            </form>
        </div>
    </div>

    <!-- MAIN APP SHELL -->
    <div id="appShell" class="hidden">
        <div class="sidebar">
            <h2>AssetTracker</h2>
            <nav class="sidebar-nav">
                <a href="#" id="navDashboard" class="active">Dashboard</a>
                <a href="#" id="navFolders">Folders &amp; Categories</a>
                <a href="#" id="navAssets">All Assets</a>
            </nav>
            <div class="sidebar-footer">
                <button type="button" id="settingsBtn" class="btn btn-secondary btn-block">Settings</button>
                <button type="button" id="lockBtn" class="btn btn-secondary btn-block hidden">Lock Vault</button>
            </div>
        </div>

        <div class="main">
            <!-- Dashboard View -->
            <div id="dashboardView" class="hidden">
                <div class="header">
                    <h1>Dashboard Overview</h1>
                    <div class="btn-row">
                        <button class="btn btn-secondary" data-open-modal="folderModal">+ New Folder</button>
                        <button class="btn btn-primary" data-open-modal="addModal">+ Add New Asset</button>
                    </div>
                </div>
                <div class="content">
                    <div class="metrics-grid">
                        <div class="card"><p>Total Assets</p><h3 id="statTotal">0</h3></div>
                        <div class="card"><p>Physical</p><h3 id="statPhysical">0</h3></div>
                        <div class="card"><p>Digital</p><h3 id="statDigital">0</h3></div>
                        <div class="card"><p>Pending Update</p><h3 id="statPending">0</h3><span class="muted small" id="statPendingNote"></span></div>
                        <div class="card"><p>Folders</p><h3 id="statFolders">0</h3></div>
                    </div>

                    <div class="section-head">
                        <h2>Asset Folders</h2>
                        <div class="sort-bar" data-folder-sort></div>
                    </div>
                    <div class="folder-grid" id="dashboardFolderGrid"></div>

                    <h2 class="mt-24">Recent Assets</h2>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th class="sortable" data-table="recent" data-sort="id">Asset ID</th>
                                    <th class="sortable" data-table="recent" data-sort="name">Name</th>
                                    <th class="sortable" data-table="recent" data-sort="folder_name">Folder</th>
                                    <th class="sortable" data-table="recent" data-sort="type">Type</th>
                                    <th class="sortable" data-table="recent" data-sort="status">Status</th>
                                    <th class="sortable" data-table="recent" data-sort="location">Location</th>
                                    <th class="sortable" data-table="recent" data-sort="last_entry_at">Last Entry</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="recentTable"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Folders View -->
            <div id="foldersView" class="hidden">
                <div class="header">
                    <h1>Folders &amp; Categories</h1>
                    <button class="btn btn-primary" data-open-modal="folderModal">+ Create New Folder</button>
                </div>
                <div class="content">
                    <div class="section-head">
                        <p class="muted small">A folder holds only assets of its own type (Physical or Digital).</p>
                        <div class="sort-bar" data-folder-sort></div>
                    </div>
                    <div class="folder-grid" id="fullFolderGrid"></div>
                </div>
            </div>

            <!-- Assets List View -->
            <div id="assetsView" class="hidden">
                <div class="header">
                    <h1 id="assetsTitle">All Assets</h1>
                    <div class="btn-row">
                        <button class="btn btn-secondary" id="exportBtn">Export CSV</button>
                        <button class="btn btn-primary" data-open-modal="addModal">+ Add New Asset</button>
                    </div>
                </div>
                <div class="content">
                    <div class="filters">
                        <input type="text" id="searchInput" placeholder="Search ID, name, nickname, location..." class="search">
                        <select id="folderFilter" aria-label="Folder"><option value="All">All Folders</option></select>
                        <select id="typeFilter" aria-label="Type">
                            <option value="All">All Types</option>
                            <option value="Physical">Physical</option>
                            <option value="Digital">Digital</option>
                        </select>
                        <select id="statusFilter" aria-label="Status">
                            <option value="All">All Statuses</option>
                            <option>Active</option><option>In Transit</option><option>Maintenance</option><option>Archived</option>
                        </select>
                        <label class="date-filter">Last entry from <input type="date" id="dateFrom"></label>
                        <label class="date-filter">to <input type="date" id="dateTo"></label>
                    </div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th class="sortable" data-table="assets" data-sort="id">Asset ID</th>
                                    <th class="sortable" data-table="assets" data-sort="name">Name</th>
                                    <th class="sortable" data-table="assets" data-sort="folder_name">Folder</th>
                                    <th class="sortable" data-table="assets" data-sort="type">Type</th>
                                    <th class="sortable" data-table="assets" data-sort="status">Status</th>
                                    <th class="sortable" data-table="assets" data-sort="location">Location</th>
                                    <th class="sortable" data-table="assets" data-sort="last_entry_at">Last Entry</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="assetsTable"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Asset Details View -->
            <div id="detailsView" class="hidden">
                <div class="header">
                    <div>
                        <a href="#" id="backToAssets" class="back-link">&larr; Back to Assets List</a>
                        <h1 id="detailName" class="mt-4">Asset Name</h1>
                        <p id="detailNickname" class="muted"></p>
                    </div>
                    <div class="btn-row">
                        <button class="btn btn-primary" data-open-modal="moveModal">Move Location</button>
                        <button class="btn btn-warning" data-open-modal="editModal">Edit Details</button>
                    </div>
                </div>
                <div class="content">
                    <div class="badge-row">
                        <span id="detailTypeBadge" class="badge">Type</span>
                        <span id="detailFolderBadge" class="badge badge-folder">Folder</span>
                        <span id="detailStatusBadge" class="badge badge-status">Status</span>
                    </div>

                    <div class="card mb-24">
                        <p><strong>Asset ID:</strong> <span id="detailID"></span></p>
                        <p class="mt-8"><strong>Folder / Category:</strong> <span id="detailFolder">Unassigned</span></p>
                        <p class="mt-8"><strong>Current Location:</strong> <span id="detailLocation"></span></p>
                        <p class="mt-8"><strong>How to get there:</strong> <span id="detailDirections"></span></p>
                        <p class="mt-8"><strong>Latest Entry Note:</strong> <span id="detailLastNote"></span></p>
                        <p class="mt-8"><strong>Description / Purpose:</strong></p>
                        <p id="detailDesc" class="detail-desc"></p>
                    </div>

                    <h2>Location Movement History</h2>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th class="sortable" data-table="history" data-sort="created_at">Date &amp; Time</th>
                                    <th class="sortable" data-table="history" data-sort="location">Location</th>
                                    <th>Directions</th>
                                    <th class="sortable" data-table="history" data-sort="notes">Notes</th>
                                </tr>
                            </thead>
                            <tbody id="historyTable"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: SETTINGS (master password) -->
    <div id="settingsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Vault Security</div>

            <div id="noPasswordPanel" class="hidden">
                <p class="muted small">No master password is set. Anyone who opens this app can see and edit your assets. Set one to lock the vault when you're not using it.</p>
                <form id="setPasswordForm">
                    <div class="form-group"><label>New Master Password (min. 6 characters)</label><input type="password" id="newPassword1" minlength="6" autocomplete="new-password" required></div>
                    <div class="form-group"><label>Confirm Password</label><input type="password" id="newPassword2" minlength="6" autocomplete="new-password" required></div>
                    <div class="modal-actions mt-16">
                        <button type="button" class="btn btn-secondary" data-close-modal="settingsModal">Not Now</button>
                        <button type="submit" class="btn btn-primary">Set Password</button>
                    </div>
                </form>
            </div>

            <div id="hasPasswordPanel" class="hidden">
                <p class="muted small">Change or remove your master password.</p>
                <form id="changePasswordForm">
                    <div class="form-group"><label>Current Password</label><input type="password" id="curPassword" autocomplete="current-password" required></div>
                    <div class="form-group"><label>New Password (min. 6 characters)</label><input type="password" id="changePassword1" minlength="6" autocomplete="new-password" required></div>
                    <div class="form-group"><label>Confirm New Password</label><input type="password" id="changePassword2" minlength="6" autocomplete="new-password" required></div>
                    <div class="modal-actions mt-16">
                        <button type="button" class="btn btn-secondary" id="removePasswordBtn">Remove Password</button>
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </div>
                </form>
            </div>
            <p id="settingsError" class="error-text mt-8 hidden"></p>

            <div class="settings-section">
                <div class="modal-header">Backup &amp; Restore</div>
                <p class="muted small">Download a full copy of your folders, assets and location history, or restore from one. Restoring <strong>replaces</strong> everything currently in the vault — it does not touch your master password.</p>
                <div class="btn-row mt-8">
                    <button type="button" class="btn btn-secondary" id="exportBackupBtn">Download Backup</button>
                    <button type="button" class="btn btn-secondary" id="importBackupBtn">Restore From Backup</button>
                    <input type="file" id="importBackupFile" accept="application/json" class="hidden">
                </div>
                <p id="backupStatus" class="small mt-8 hidden"></p>
            </div>

            <div class="modal-actions mt-16">
                <button type="button" class="btn btn-secondary" data-close-modal="settingsModal">Close</button>
            </div>
        </div>
    </div>

    <!-- MODAL: ADD FOLDER -->
    <div id="folderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Create New Folder</div>
            <form id="addFolderForm">
                <div class="form-group"><label>Folder Name</label><input type="text" id="folderName" required></div>
                <div class="form-group">
                    <label>Folder Type</label>
                    <select id="folderType"><option value="Physical">Physical</option><option value="Digital">Digital</option></select>
                    <p class="muted small mt-4">Only assets of this type can be placed in the folder. It can't be changed later.</p>
                </div>
                <div class="form-group"><label>Description</label><textarea id="folderDesc" rows="3"></textarea></div>
                <div class="modal-actions mt-16">
                    <button type="button" class="btn btn-secondary" data-close-modal="folderModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Folder</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: ADD ASSET -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Register New Asset</div>
            <form id="addAssetForm">
                <div class="form-row">
                    <div class="form-group"><label>Asset ID (Optional)</label><input type="text" id="addId" placeholder="Auto-generated if blank"></div>
                    <div class="form-group">
                        <label>Asset Type</label>
                        <select id="addType"><option value="Physical">Physical</option><option value="Digital">Digital</option></select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Asset Name</label><input type="text" id="addName" required></div>
                    <div class="form-group"><label>Nickname (Optional)</label><input type="text" id="addNickname"></div>
                </div>
                <div class="form-group"><label>Folder / Category</label><select id="addFolderSelect"></select></div>
                <div class="form-group"><label>Initial Location</label><input type="text" id="addLocation" required></div>
                <div class="form-group"><label>Directions to Location (Optional)</label><input type="text" id="addDirections"></div>
                <div class="form-group"><label>Description / Purpose</label><textarea id="addDesc" rows="3"></textarea></div>
                <div class="modal-actions mt-16">
                    <button type="button" class="btn btn-secondary" data-close-modal="addModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Asset</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: MOVE LOCATION -->
    <div id="moveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Update Asset Location</div>
            <form id="moveAssetForm">
                <div class="form-group"><label>New Location</label><input type="text" id="moveLocation" required></div>
                <div class="form-group"><label>Directions to Location (Optional)</label><input type="text" id="moveDirections"></div>
                <div class="form-group"><label>Notes / Details (Optional)</label><textarea id="moveNotes" rows="3"></textarea></div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="moveModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Location Entry</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: EDIT CORE DETAILS -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Edit Asset Details</div>
            <form id="editAssetForm">
                <div class="form-group">
                    <label>Asset Name (fixed)</label>
                    <input type="text" id="editName" disabled>
                </div>
                <div class="form-group"><label>Nickname (Optional)</label><input type="text" id="editNickname" placeholder="Leave empty to show only the name"></div>
                <div class="form-row">
                    <div class="form-group"><label>Folder / Category</label><select id="editFolderSelect"></select></div>
                    <div class="form-group">
                        <label>Status</label>
                        <select id="editStatus"><option>Active</option><option>In Transit</option><option>Maintenance</option><option>Archived</option></select>
                    </div>
                </div>
                <p id="editFolderNote" class="muted small"></p>
                <div class="form-group mt-8"><label>Description / Purpose</label><textarea id="editDesc" rows="3"></textarea></div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="editModal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Save Details</button>
                </div>
            </form>
        </div>
    </div>

    <script src="app.js"></script>
</body>
</html>
