// ---------- state ----------
let currentView = 'dashboard';
let currentAsset = null;          // asset shown on the details page
let selectedFolderId = 'All';
let foldersCache = [];
let hasPassword = false;

// ---------- helpers ----------
const $ = (id) => document.getElementById(id);

/** Escape text before putting it in innerHTML (also safe inside quoted attributes). */
function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** Call api.php. Resolves with parsed JSON; throws Error(message) on any failure. */
async function api(action, { method = 'GET', params = {}, body } = {}) {
    const query = new URLSearchParams({ action, ...params });
    let res;
    try {
        res = await fetch(`api.php?${query}`, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: method === 'POST' ? JSON.stringify(body ?? {}) : undefined,
        });
    } catch {
        throw new Error('Could not reach the server.');
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        if (res.status === 401 && action !== 'unlock' && action !== 'change_password' && action !== 'remove_password') {
            location.reload(); // vault got locked (e.g. in another tab)
        }
        throw new Error(data.error || `Request failed (${res.status})`);
    }
    return data;
}

/** Wrap an async handler so failures show an alert instead of failing silently. */
function safe(fn) {
    return async (...args) => {
        try {
            await fn(...args);
        } catch (err) {
            showAppAlert(err.message);
        }
    };
}

function debounce(fn, ms) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), ms);
    };
}

function fmtDate(value) {
    const d = new Date(String(value).replace(' ', 'T'));
    return isNaN(d) ? String(value ?? '') : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function fmtDateTime(value) {
    const d = new Date(String(value).replace(' ', 'T'));
    return isNaN(d) ? String(value ?? '') : d.toLocaleString();
}

const slug = (s) => String(s).toLowerCase().replace(/\s+/g, '-');
const typeBadge = (type) => `<span class="badge badge-${esc(slug(type))}">${esc(type)}</span>`;
const statusBadge = (status) => `<span class="badge badge-status badge-status-${esc(slug(status))}">${esc(status)}</span>`;

// Location hints, per the URS: digital = file path / URL, physical = a specific place.
const LOCATION_HINTS = {
    Physical: 'A specific place, e.g. Bedroom closet, top shelf',
    Digital: 'A file path or URL, e.g. Google Drive / Taxes / 2026.pdf',
};

// ---------- modals ----------
function openModal(id) {
    if (id === 'addModal') prepareAddModal();
    if (id === 'moveModal') $('moveLocation').placeholder = LOCATION_HINTS[currentAsset?.type] || '';
    if (id === 'settingsModal') openSettingsModal();
    $(id).style.display = 'flex';
}
function closeModal(id) { $(id).style.display = 'none'; }

// ---------- sorting ----------
// Every table keeps its rows and sort state here; clicking a header re-sorts without a server call.
const tables = {
    recent:  { body: 'recentTable',  cols: 8, rows: [], sort: { key: 'last_entry_at', dir: 'desc' }, row: assetRow },
    assets:  { body: 'assetsTable',  cols: 8, rows: [], sort: { key: 'last_entry_at', dir: 'desc' }, row: assetRow },
    history: { body: 'historyTable', cols: 4, rows: [], sort: { key: 'created_at', dir: 'desc' },   row: historyRow },
};
const folderSort = { key: 'name', dir: 'asc' };
const FOLDER_SORTS = [['name', 'Name'], ['type', 'Type'], ['asset_count', 'Assets']];

function sortRows(rows, { key, dir }) {
    const sign = dir === 'desc' ? -1 : 1;
    const empty = (v) => v === null || v === undefined || v === '';
    return [...rows].sort((a, b) => {
        if (empty(a[key]) || empty(b[key])) return empty(a[key]) - empty(b[key]); // blanks always last
        return sign * String(a[key]).localeCompare(String(b[key]), undefined, { numeric: true, sensitivity: 'base' });
    });
}

function renderTable(name) {
    const t = tables[name];
    $(t.body).innerHTML = sortRows(t.rows, t.sort).map(t.row).join('')
        || `<tr><td colspan="${t.cols}" class="muted">Nothing to show.</td></tr>`;
    document.querySelectorAll(`th[data-table="${name}"]`).forEach((th) => {
        const active = th.dataset.sort === t.sort.key;
        th.setAttribute('aria-sort', active ? (t.sort.dir === 'asc' ? 'ascending' : 'descending') : 'none');
    });
}

function setRows(name, rows) {
    tables[name].rows = rows;
    renderTable(name);
}

function toggleSort(state, key) {
    state.dir = state.key === key && state.dir === 'asc' ? 'desc' : 'asc';
    state.key = key;
}

function renderFolderSortBars() {
    document.querySelectorAll('[data-folder-sort]').forEach((bar) => {
        bar.innerHTML = '<span class="muted small">Sort:</span>' + FOLDER_SORTS.map(([key, label]) => {
            const active = folderSort.key === key;
            const arrow = active ? (folderSort.dir === 'asc' ? ' ▲' : ' ▼') : '';
            return `<button type="button" class="sort-btn${active ? ' active' : ''}" data-folder-sort-key="${key}">${label}${arrow}</button>`;
        }).join('');
    });
}

// ---------- startup ----------
document.addEventListener('DOMContentLoaded', () => {
    checkSession();

    $('unlockForm').addEventListener('submit', safe(handleUnlock));
    $('lockBtn').addEventListener('click', safe(handleLock));
    $('settingsBtn').addEventListener('click', () => openModal('settingsModal'));
    $('setPasswordForm').addEventListener('submit', safe(handleSetPassword));
    $('changePasswordForm').addEventListener('submit', safe(handleChangePassword));
    $('removePasswordBtn').addEventListener('click', safe(handleRemovePassword));

    $('navDashboard').addEventListener('click', (e) => { e.preventDefault(); selectedFolderId = 'All'; showView('dashboard'); });
    $('navFolders').addEventListener('click', (e) => { e.preventDefault(); showView('folders'); });
    $('navAssets').addEventListener('click', (e) => { e.preventDefault(); selectedFolderId = 'All'; showView('assets'); });
    $('backToAssets').addEventListener('click', (e) => { e.preventDefault(); showView('assets'); });

    $('searchInput').addEventListener('input', debounce(safe(loadAssets), 250));
    ['typeFilter', 'statusFilter', 'dateFrom', 'dateTo'].forEach((id) => $(id).addEventListener('change', safe(loadAssets)));
    $('folderFilter').addEventListener('change', (e) => { selectedFolderId = e.target.value; safe(loadAssets)(); });
    $('exportBtn').addEventListener('click', exportCsv);
    $('exportBackupBtn').addEventListener('click', safe(downloadBackup));
    $('importBackupBtn').addEventListener('click', () => $('importBackupFile').click());
    $('importBackupFile').addEventListener('change', safe(restoreBackup));

    $('addType').addEventListener('change', refreshAddFolderOptions);

    $('addFolderForm').addEventListener('submit', safe(handleAddFolder));
    $('addAssetForm').addEventListener('submit', safe(handleAddAsset));
    $('moveAssetForm').addEventListener('submit', safe(handleMoveAsset));
    $('editAssetForm').addEventListener('submit', safe(handleEditAsset));

    // One delegated click handler for everything rendered dynamically (and the static modal buttons).
    document.addEventListener('click', (e) => {
        const t = e.target;
        const open = t.closest('[data-open-modal]');
        if (open) return openModal(open.dataset.openModal);
        const close = t.closest('[data-close-modal]');
        if (close) return closeModal(close.dataset.closeModal);

        const th = t.closest('th.sortable');
        if (th) {
            const table = tables[th.dataset.table];
            toggleSort(table.sort, th.dataset.sort);
            return renderTable(th.dataset.table);
        }
        const sortBtn = t.closest('[data-folder-sort-key]');
        if (sortBtn) {
            toggleSort(folderSort, sortBtn.dataset.folderSortKey);
            return renderFolders();
        }
        const viewBtn = t.closest('[data-asset-id]');
        if (viewBtn) return safe(viewDetails)(viewBtn.dataset.assetId);
        const folderCard = t.closest('[data-folder-id]');
        if (folderCard) filterByFolder(folderCard.dataset.folderId);
    });

    // Make sortable headers keyboard-accessible.
    document.querySelectorAll('th.sortable').forEach((th) => {
        th.tabIndex = 0;
        th.addEventListener('keydown', (e) => { if (e.key === 'Enter') th.click(); });
    });

    // Close a modal by clicking the dim backdrop or pressing Escape.
    document.querySelectorAll('.modal').forEach((modal) => {
        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(modal.id); });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') document.querySelectorAll('.modal').forEach((m) => closeModal(m.id));
    });
});

// ---------- vault lock ----------
function showLockScreen() {
    $('lockView').style.display = 'flex';
    $('appShell').style.display = 'none';
}

async function checkSession() {
    try {
        const session = await api('session');
        hasPassword = session.has_password;
        if (session.locked) showLockScreen();
        else initApp();
    } catch (err) {
        showAppAlert(err.message);
    }
}

function showError(id, message) {
    const el = $(id);
    el.textContent = message;
    el.classList.remove('hidden');
}
function hideError(id) { $(id).classList.add('hidden'); }

async function handleUnlock(e) {
    e.preventDefault();
    hideError('unlockError');
    try {
        await api('unlock', { method: 'POST', body: { password: $('unlockPassword').value } });
    } catch (err) {
        showError('unlockError', err.message);
        return;
    }
    $('unlockPassword').value = '';
    initApp();
}

async function handleLock() {
    if (!(await showAppConfirm('Lock the vault? You will need your master password to get back in.'))) return;
    await api('lock', { method: 'POST' });
    location.reload();
}

function initApp() {
    $('lockView').style.display = 'none';
    $('appShell').style.display = 'flex';
    $('lockBtn').classList.toggle('hidden', !hasPassword);

    renderFolderSortBars();
    showView('dashboard');
}

// ---------- settings (master password) ----------
function openSettingsModal() {
    hideError('settingsError');
    $('noPasswordPanel').classList.toggle('hidden', hasPassword);
    $('hasPasswordPanel').classList.toggle('hidden', !hasPassword);
    $('setPasswordForm').reset();
    $('changePasswordForm').reset();
}

async function handleSetPassword(e) {
    e.preventDefault();
    hideError('settingsError');
    if ($('newPassword1').value !== $('newPassword2').value) {
        return showError('settingsError', 'Passwords do not match.');
    }
    try {
        await api('set_password', { method: 'POST', body: { password: $('newPassword1').value } });
    } catch (err) {
        return showError('settingsError', err.message);
    }
    hasPassword = true;
    $('lockBtn').classList.remove('hidden');
    closeModal('settingsModal');
}

async function handleChangePassword(e) {
    e.preventDefault();
    hideError('settingsError');
    if ($('changePassword1').value !== $('changePassword2').value) {
        return showError('settingsError', 'Passwords do not match.');
    }
    try {
        await api('change_password', {
            method: 'POST',
            body: { current_password: $('curPassword').value, new_password: $('changePassword1').value },
        });
    } catch (err) {
        return showError('settingsError', err.message);
    }
    closeModal('settingsModal');
    showAppAlert('Password changed.');
}

async function handleRemovePassword() {
    hideError('settingsError');
    const current = $('curPassword').value;
    if (!current) {
        return showError('settingsError', 'Enter your current password first.');
    }
    if (!(await showAppConfirm('Remove the master password? Anyone who opens this app will see your assets without one.'))) return;
    try {
        await api('remove_password', { method: 'POST', body: { current_password: current } });
    } catch (err) {
        return showError('settingsError', err.message);
    }
    hasPassword = false;
    $('lockBtn').classList.add('hidden');
    closeModal('settingsModal');
}

// ---------- navigation ----------
function showView(view) {
    currentView = view;
    for (const name of ['dashboard', 'folders', 'assets', 'details']) {
        $(`${name}View`).style.display = view === name ? 'block' : 'none';
    }
    $('navDashboard').className = view === 'dashboard' ? 'active' : '';
    $('navFolders').className = view === 'folders' ? 'active' : '';
    $('navAssets').className = view === 'assets' || view === 'details' ? 'active' : '';
    document.querySelector('.main').scrollTop = 0;

    const loaders = { dashboard: loadDashboard, folders: loadFolders, assets: loadAssets };
    if (loaders[view]) safe(loaders[view])();
}

function filterByFolder(folderId) {
    selectedFolderId = folderId;
    showView('assets');
}

// ---------- folders ----------
/** Folders of one type (or all when type is omitted) as <option> tags. */
function folderOptions(type) {
    return foldersCache
        .filter((f) => !type || f.type === type)
        .map((f) => `<option value="${esc(f.id)}">${esc(f.name)}</option>`).join('');
}

const noFolderOption = () => '<option value="">(No Folder)</option>';

async function loadFolders() {
    foldersCache = await api('folders');
    $('folderFilter').innerHTML = `<option value="All">All Folders</option>${folderOptions()}`;
    $('folderFilter').value = selectedFolderId;
    renderFolders();
}

function folderCard(f, detailed) {
    return `
        <div class="folder-card" data-folder-id="${esc(f.id)}">
            <div class="folder-icon">${f.type === 'Digital' ? '💾' : '📁'}</div>
            <h3>${esc(f.name)}</h3>
            ${typeBadge(f.type)}
            ${detailed ? `<p class="muted small mt-8">${esc(f.description || 'No description')}</p>` : ''}
            <p class="${detailed ? 'bold mt-8' : 'mt-8'}">${esc(f.asset_count)} ${detailed ? 'Assets' : 'assets'}</p>
        </div>`;
}

function renderFolders() {
    const sorted = sortRows(foldersCache.map((f) => ({ ...f, asset_count: Number(f.asset_count) })), folderSort);
    $('dashboardFolderGrid').innerHTML = sorted.map((f) => folderCard(f, false)).join('') || '<p class="muted">No folders yet.</p>';
    $('fullFolderGrid').innerHTML = sorted.map((f) => folderCard(f, true)).join('') || '<p class="muted">No folders yet.</p>';
    $('statFolders').textContent = foldersCache.length;
    renderFolderSortBars();
}

// ---------- tables ----------
function assetRow(a) {
    return `
        <tr>
            <td><strong>${esc(a.id)}</strong></td>
            <td>${esc(a.name)}${a.nickname ? `<div class="nick">“${esc(a.nickname)}”</div>` : ''}</td>
            <td><span class="badge badge-folder">${esc(a.folder_name || 'Unassigned')}</span></td>
            <td>${typeBadge(a.type)}</td>
            <td>${statusBadge(a.status)}</td>
            <td>${esc(a.location)}</td>
            <td>${esc(fmtDate(a.last_entry_at))}</td>
            <td><button class="btn btn-secondary" data-asset-id="${esc(a.id)}">View</button></td>
        </tr>`;
}

function historyRow(h) {
    return `
        <tr>
            <td>${esc(fmtDateTime(h.created_at))}</td>
            <td>${esc(h.location)}</td>
            <td>${esc(h.directions || '-')}</td>
            <td>${esc(h.notes || '-')}</td>
        </tr>`;
}

// ---------- data loading ----------
async function loadDashboard() {
    const [data] = await Promise.all([api('dashboard'), loadFolders()]);

    $('statTotal').textContent = data.metrics.total;
    $('statPhysical').textContent = data.metrics.physical;
    $('statDigital').textContent = data.metrics.digital;
    $('statPending').textContent = data.metrics.pending;
    $('statPendingNote').textContent = `No entry in ${data.pending_days}+ days`;
    setRows('recent', data.recent);
}

async function loadAssets() {
    if (!foldersCache.length) await loadFolders();
    const assets = await api('assets', {
        params: {
            search: $('searchInput').value,
            type: $('typeFilter').value,
            status: $('statusFilter').value,
            folder_id: selectedFolderId,
            date_from: $('dateFrom').value,
            date_to: $('dateTo').value,
        },
    });

    const folder = foldersCache.find((f) => String(f.id) === String(selectedFolderId));
    $('assetsTitle').textContent = folder ? `Folder: ${folder.name}` : 'All Assets';
    setRows('assets', assets);
}

async function viewDetails(id) {
    const { asset, history } = await api('details', { params: { id } });
    currentAsset = asset;
    const latest = history[0] || {};

    $('detailName').textContent = asset.name;
    $('detailNickname').textContent = asset.nickname ? `Nickname: “${asset.nickname}”` : '';
    $('detailID').textContent = asset.id;
    $('detailFolder').textContent = asset.folder_name || 'Unassigned';
    $('detailFolderBadge').textContent = asset.folder_name || 'No Folder';
    $('detailLocation').textContent = asset.location;
    $('detailDirections').textContent = latest.directions || '—';
    $('detailLastNote').textContent = latest.notes || '—';
    $('detailDesc').textContent = asset.description || 'No description provided.';

    $('detailTypeBadge').className = `badge badge-${slug(asset.type)}`;
    $('detailTypeBadge').textContent = asset.type;
    $('detailStatusBadge').className = `badge badge-status badge-status-${slug(asset.status)}`;
    $('detailStatusBadge').textContent = asset.status;

    // Edit form: the name is read-only, and only folders of the asset's own type are offered.
    $('editName').value = asset.name;
    $('editNickname').value = asset.nickname || '';
    $('editStatus').value = asset.status;
    $('editFolderSelect').innerHTML = noFolderOption() + folderOptions(asset.type);
    $('editFolderSelect').value = asset.folder_id || '';
    $('editFolderNote').textContent = `Only ${asset.type} folders are listed because this is a ${asset.type.toLowerCase()} asset.`;
    $('editDesc').value = asset.description || '';

    setRows('history', history);
    showView('details');
}

// ---------- add asset helpers ----------
function refreshAddFolderOptions() {
    const type = $('addType').value;
    const previous = $('addFolderSelect').value;
    $('addFolderSelect').innerHTML = noFolderOption() + folderOptions(type);
    // Keep the choice only if that folder still matches the type.
    $('addFolderSelect').value = foldersCache.some((f) => String(f.id) === previous && f.type === type) ? previous : '';
    $('addLocation').placeholder = LOCATION_HINTS[type];
}

function prepareAddModal() {
    const folder = foldersCache.find((f) => String(f.id) === String(selectedFolderId));
    if (folder) $('addType').value = folder.type; // adding from inside a folder: preselect it
    refreshAddFolderOptions();
    if (folder) $('addFolderSelect').value = folder.id;
}

// ---------- export ----------
function exportCsv() {
    const columns = [['id', 'Asset ID'], ['name', 'Name'], ['nickname', 'Nickname'], ['type', 'Type'], ['folder_name', 'Folder'],
        ['status', 'Status'], ['location', 'Location'], ['last_entry_at', 'Last Entry'], ['description', 'Description']];
    // Prefix risky cells so spreadsheets don't run them as formulas.
    const cell = (v) => {
        let s = String(v ?? '');
        if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`;
        return `"${s.replace(/"/g, '""')}"`;
    };
    const rows = sortRows(tables.assets.rows, tables.assets.sort);
    const csv = [columns.map(([, label]) => cell(label)).join(',')]
        .concat(rows.map((r) => columns.map(([key]) => cell(r[key])).join(','))).join('\r\n');

    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
    link.download = `assets-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

// ---------- form handlers ----------
async function handleAddFolder(e) {
    e.preventDefault();
    await api('add_folder', {
        method: 'POST',
        body: { name: $('folderName').value, type: $('folderType').value, description: $('folderDesc').value },
    });
    closeModal('folderModal');
    $('addFolderForm').reset();
    showView(currentView);
}

async function handleAddAsset(e) {
    e.preventDefault();
    if (!(await showAppConfirm(`Register "${$('addName').value.trim()}" as a ${$('addType').value.toLowerCase()} asset?`))) return;
    await api('add', {
        method: 'POST',
        body: {
            id: $('addId').value,
            name: $('addName').value,
            nickname: $('addNickname').value,
            folder_id: $('addFolderSelect').value,
            type: $('addType').value,
            location: $('addLocation').value,
            directions: $('addDirections').value,
            description: $('addDesc').value,
        },
    });
    closeModal('addModal');
    $('addAssetForm').reset();
    showView(currentView);
}

async function handleMoveAsset(e) {
    e.preventDefault();
    if (!(await showAppConfirm(`Move "${currentAsset.name}" to "${$('moveLocation').value.trim()}"? This adds a new entry to its history.`))) return;
    await api('move', {
        method: 'POST',
        params: { id: currentAsset.id },
        body: { location: $('moveLocation').value, directions: $('moveDirections').value, notes: $('moveNotes').value },
    });
    closeModal('moveModal');
    $('moveAssetForm').reset();
    await viewDetails(currentAsset.id);
}

async function handleEditAsset(e) {
    e.preventDefault();
    if (!(await showAppConfirm('Are you sure you want to change this asset\'s details? This will alter the primary asset record.'))) return;
    await api('edit', {
        method: 'POST',
        params: { id: currentAsset.id },
        body: {
            nickname: $('editNickname').value,
            folder_id: $('editFolderSelect').value,
            status: $('editStatus').value,
            description: $('editDesc').value,
        },
    });
    closeModal('editModal');
    await viewDetails(currentAsset.id);
}

// ---------- backup & restore (Settings) ----------

async function downloadBackup() {
    const data = await api('export');
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `assettracker-backup-${new Date().toISOString().slice(0, 10)}.json`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(a.href);
}

async function restoreBackup(e) {
    const input = e.target;
    const file = input.files[0];
    input.value = ''; // allow choosing the same file again later
    if (!file) return;
    if (!(await showAppConfirm('Restore from this backup? This REPLACES every folder, asset and history entry currently in the vault.'))) return;
    let data;
    try {
        data = JSON.parse(await file.text());
    } catch {
        throw new Error('That file is not valid JSON.');
    }
    const res = await api('import', { method: 'POST', body: data });
    const n = res.imported;
    closeModal('settingsModal');
    showView('dashboard');
    showAppAlert(`Restored ${n.folders} folders, ${n.assets} assets and ${n.history} history entries.`);
}


function showAppAlert(message) {
  let box = document.getElementById('app-toast-box');
  if (!box) {
    box = document.createElement('div');
    box.id = 'app-toast-box';
    Object.assign(box.style, {
      position: 'fixed', top: '16px', right: '16px', zIndex: '99999',
      display: 'flex', flexDirection: 'column', gap: '8px', maxWidth: '380px'
    });
    document.body.appendChild(box);
  }
  const toast = document.createElement('div');
  Object.assign(toast.style, {
    background: '#1e293b', color: '#f8fafc', padding: '12px 16px',
    borderRadius: '8px', boxShadow: '0 8px 20px rgba(0,0,0,0.25)',
    fontSize: '14px', lineHeight: '1.4', borderLeft: '4px solid #38bdf8'
  });
  toast.textContent = String(message);
  toast.onclick = () => toast.remove();
  box.appendChild(toast);
  setTimeout(() => toast.remove(), 4500);
}

function showAppConfirm(message) {
  return new Promise((resolve) => {
    const overlay = document.createElement('div');
    Object.assign(overlay.style, {
      position: 'fixed', inset: '0', background: 'rgba(15, 23, 42, 0.55)',
      display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: '99999'
    });
    const card = document.createElement('div');
    Object.assign(card.style, {
      background: '#ffffff', color: '#0f172a', padding: '20px 24px',
      borderRadius: '10px', maxWidth: '420px', width: '90%',
      boxShadow: '0 12px 30px rgba(0,0,0,0.25)', fontFamily: 'inherit'
    });
    const text = document.createElement('p');
    text.textContent = String(message);
    Object.assign(text.style, { margin: '0 0 18px 0', fontSize: '14px', lineHeight: '1.5' });
    const row = document.createElement('div');
    Object.assign(row.style, { display: 'flex', justifyContent: 'flex-end', gap: '10px' });
    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.textContent = 'Cancel';
    Object.assign(cancelBtn.style, {
      padding: '8px 14px', borderRadius: '6px', border: '1px solid #cbd5e1',
      background: '#f8fafc', color: '#334155', cursor: 'pointer'
    });
    const okBtn = document.createElement('button');
    okBtn.type = 'button';
    okBtn.textContent = 'Confirm';
    Object.assign(okBtn.style, {
      padding: '8px 14px', borderRadius: '6px', border: 'none',
      background: '#0f172a', color: '#ffffff', cursor: 'pointer'
    });
    const close = (result) => {
      document.removeEventListener('keydown', onKey);
      overlay.remove();
      resolve(result);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') close(false);
      if (e.key === 'Enter') { e.preventDefault(); close(true); }
    };
    cancelBtn.onclick = () => close(false);
    okBtn.onclick = () => close(true);
    document.addEventListener('keydown', onKey);
    row.append(cancelBtn, okBtn);
    card.append(text, row);
    overlay.appendChild(card);
    document.body.appendChild(overlay);
    okBtn.focus();
  });
}