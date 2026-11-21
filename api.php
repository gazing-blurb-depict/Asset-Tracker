<?php
/**
 * JSON API for AssetTracker.  Usage: api.php?action=<name>
 *
 * Single-owner app: no accounts. An optional master password can lock the
 * whole vault (see the lock/unlock/set_password/... routes below).
 */

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db.php';

const ASSET_TYPES = ['Physical', 'Digital'];
const STATUSES = ['Active', 'In Transit', 'Maintenance', 'Archived'];

// An asset is "pending update" when its last location entry is older than this (Archived assets excluded).
const PENDING_UPDATE_DAYS = 30;

// Date of the newest history entry, falling back to the registration date.
const LAST_ENTRY = '(SELECT MAX(h.created_at) FROM asset_history h WHERE h.asset_id = a.id)';
const ASSET_COLUMNS = 'a.*, f.name AS folder_name, f.type AS folder_type, COALESCE(' . LAST_ENTRY . ', a.created_at) AS last_entry_at';

// ---------- response helpers ----------

function respond($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail(string $message, int $code = 400): never
{
    respond(['error' => $message], $code);
}

// ---------- vault lock helpers ----------

function password_hash_stored(PDO $pdo): ?string
{
    $hash = $pdo->query('SELECT password_hash FROM app_settings WHERE id = 1')->fetchColumn();
    return $hash ?: null;
}

function is_locked(PDO $pdo): bool
{
    return password_hash_stored($pdo) !== null && empty($_SESSION['unlocked']);
}

/** Every asset/folder route calls this first. */
function require_unlocked(PDO $pdo): void
{
    if (is_locked($pdo)) {
        fail('Vault is locked', 401);
    }
}

// ---------- input helpers ----------

/** Read a trimmed string field from the JSON body; fail if required and empty. */
function field(array $input, string $key, bool $required = true, int $max = 255): string
{
    $value = $input[$key] ?? '';
    $value = is_string($value) ? trim($value) : '';
    if ($required && $value === '') {
        fail("'$key' is required", 422);
    }
    if (mb_strlen($value) > $max) {
        fail("'$key' must be at most $max characters", 422);
    }
    return $value;
}

function one_of(string $value, array $allowed, string $label): string
{
    return in_array($value, $allowed, true) ? $value : fail("Invalid $label", 422);
}

function folder_id_or_null(array $input): ?int
{
    $v = $input['folder_id'] ?? '';
    return ($v === '' || $v === null) ? null : (int) $v;
}

function valid_date(string $value): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

function require_password(array $input, string $key, int $min = 6): string
{
    $pw = (string) ($input[$key] ?? '');
    if (strlen($pw) < $min) {
        fail("'$key' must be at least $min characters", 422);
    }
    return $pw;
}

// ---------- domain helpers ----------

function load_asset(PDO $pdo, string $id): array
{
    $stmt = $pdo->prepare('SELECT id, type, folder_id FROM assets WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: fail('Asset not found', 404);
}

/** A folder may only hold assets of its own type. */
function check_folder(PDO $pdo, ?int $folderId, string $assetType): void
{
    if ($folderId === null) {
        return;
    }
    $stmt = $pdo->prepare('SELECT type FROM folders WHERE id = ?');
    $stmt->execute([$folderId]);
    $folderType = $stmt->fetchColumn();
    if ($folderType === false) {
        fail('Referenced folder does not exist', 422);
    }
    if ($folderType !== $assetType) {
        fail("That folder only holds $folderType assets", 422);
    }
}

function next_asset_id(PDO $pdo): string
{
    $max = 0;
    foreach ($pdo->query("SELECT id FROM assets WHERE id LIKE 'ASSET-%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (preg_match('/^ASSET-(\d+)$/', $id, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return sprintf('ASSET-%03d', $max + 1);
}

// ---------- request setup ----------

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input = [];

if ($method === 'POST') {
    // Requiring JSON blocks cross-site form posts (browsers can't send it without a CORS preflight).
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        fail('Content-Type must be application/json', 415);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        fail('Invalid JSON body', 400);
    }
}

// ---------- routes ----------

try {
    $routes = [
        // ----- Vault lock (no unlock required) -----
        'GET session' => function () use ($pdo) {
            respond([
                'has_password' => password_hash_stored($pdo) !== null,
                'locked' => is_locked($pdo),
                'pending_days' => PENDING_UPDATE_DAYS,
            ]);
        },
        'POST unlock' => function () use ($pdo, $input) {
            $hash = password_hash_stored($pdo);
            if ($hash === null) {
                fail('No password is set', 409);
            }
            if (!password_verify((string) ($input['password'] ?? ''), $hash)) {
                fail('Incorrect password', 401);
            }
            session_regenerate_id(true);
            $_SESSION['unlocked'] = true;
            respond(['success' => true]);
        },
        'POST lock' => function () {
            session_destroy();
            respond(['success' => true]);
        },
        // First time only: no password exists yet, so this needs no current password.
        'POST set_password' => function () use ($pdo, $input) {
            if (password_hash_stored($pdo) !== null) {
                fail('A password is already set. Use change_password instead.', 409);
            }
            $hash = password_hash(require_password($input, 'password'), PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE app_settings SET password_hash = ? WHERE id = 1')->execute([$hash]);
            session_regenerate_id(true);
            $_SESSION['unlocked'] = true;
            respond(['success' => true]);
        },
        'POST change_password' => function () use ($pdo, $input) {
            require_unlocked($pdo);
            $hash = password_hash_stored($pdo);
            if ($hash === null || !password_verify((string) ($input['current_password'] ?? ''), $hash)) {
                fail('Current password is incorrect', 401);
            }
            $newHash = password_hash(require_password($input, 'new_password'), PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE app_settings SET password_hash = ? WHERE id = 1')->execute([$newHash]);
            respond(['success' => true]);
        },
        'POST remove_password' => function () use ($pdo, $input) {
            require_unlocked($pdo);
            $hash = password_hash_stored($pdo);
            if ($hash !== null && !password_verify((string) ($input['current_password'] ?? ''), $hash)) {
                fail('Current password is incorrect', 401);
            }
            $pdo->prepare('UPDATE app_settings SET password_hash = NULL WHERE id = 1')->execute();
            respond(['success' => true]);
        },

        // ----- Read -----
        'GET dashboard' => function () use ($pdo) {
            require_unlocked($pdo);
            $cutoff = date('Y-m-d H:i:s', strtotime('-' . PENDING_UPDATE_DAYS . ' days'));

            $metrics = $pdo->prepare("SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN type = 'Physical' THEN 1 ELSE 0 END), 0) AS physical,
                    COALESCE(SUM(CASE WHEN type = 'Digital' THEN 1 ELSE 0 END), 0) AS digital,
                    COALESCE(SUM(CASE WHEN status <> 'Archived' AND COALESCE(" . LAST_ENTRY . ", created_at) < ? THEN 1 ELSE 0 END), 0) AS pending
                FROM assets a");
            $metrics->execute([$cutoff]);

            $recent = $pdo->query('SELECT ' . ASSET_COLUMNS . ' FROM assets a LEFT JOIN folders f ON a.folder_id = f.id ORDER BY last_entry_at DESC LIMIT 5');

            respond(['metrics' => array_map('intval', $metrics->fetch()), 'recent' => $recent->fetchAll(), 'pending_days' => PENDING_UPDATE_DAYS]);
        },
        'GET folders' => function () use ($pdo) {
            require_unlocked($pdo);
            respond($pdo->query('SELECT f.*, COUNT(a.id) AS asset_count FROM folders f LEFT JOIN assets a ON f.id = a.folder_id GROUP BY f.id ORDER BY f.name')->fetchAll());
        },
        'GET assets' => function () use ($pdo) {
            require_unlocked($pdo);
            $sql = 'SELECT ' . ASSET_COLUMNS . ' FROM assets a LEFT JOIN folders f ON a.folder_id = f.id WHERE 1 = 1';
            $params = [];

            $search = trim($_GET['search'] ?? '');
            if ($search !== '') {
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $sql .= ' AND (a.name LIKE ? OR a.nickname LIKE ? OR a.id LIKE ? OR a.location LIKE ?)';
                array_push($params, $like, $like, $like, $like);
            }
            if (in_array($_GET['type'] ?? '', ASSET_TYPES, true)) {
                $sql .= ' AND a.type = ?';
                $params[] = $_GET['type'];
            }
            if (in_array($_GET['status'] ?? '', STATUSES, true)) {
                $sql .= ' AND a.status = ?';
                $params[] = $_GET['status'];
            }
            $folderId = $_GET['folder_id'] ?? 'All';
            if ($folderId !== 'All' && $folderId !== '') {
                $sql .= ' AND a.folder_id = ?';
                $params[] = (int) $folderId;
            }
            // Date filter applies to the date of the latest entry.
            $lastEntry = 'COALESCE(' . LAST_ENTRY . ', a.created_at)';
            if (valid_date($_GET['date_from'] ?? '')) {
                $sql .= " AND $lastEntry >= ?";
                $params[] = $_GET['date_from'] . ' 00:00:00';
            }
            if (valid_date($_GET['date_to'] ?? '')) {
                $sql .= " AND $lastEntry <= ?";
                $params[] = $_GET['date_to'] . ' 23:59:59';
            }
            $stmt = $pdo->prepare($sql . ' ORDER BY last_entry_at DESC');
            $stmt->execute($params);
            respond($stmt->fetchAll());
        },
        'GET details' => function () use ($pdo) {
            require_unlocked($pdo);
            $asset = load_asset($pdo, $_GET['id'] ?? '');

            $stmt = $pdo->prepare('SELECT ' . ASSET_COLUMNS . ' FROM assets a LEFT JOIN folders f ON a.folder_id = f.id WHERE a.id = ?');
            $stmt->execute([$asset['id']]);

            $hist = $pdo->prepare('SELECT * FROM asset_history WHERE asset_id = ? ORDER BY created_at DESC, id DESC');
            $hist->execute([$asset['id']]);

            respond(['asset' => $stmt->fetch(), 'history' => $hist->fetchAll()]);
        },

        // ----- Write -----
        'POST add_folder' => function () use ($pdo, $input) {
            require_unlocked($pdo);
            $type = one_of(field($input, 'type'), ASSET_TYPES, 'folder type');
            $pdo->prepare('INSERT INTO folders (name, type, description) VALUES (?, ?, ?)')
                ->execute([field($input, 'name', true, 100), $type, field($input, 'description', false, 5000)]);
            respond(['success' => true], 201);
        },
        'POST add' => function () use ($pdo, $input) {
            require_unlocked($pdo);
            $type = one_of(field($input, 'type'), ASSET_TYPES, 'asset type');
            $location = field($input, 'location');
            $folderId = folder_id_or_null($input);
            check_folder($pdo, $folderId, $type);

            $pdo->beginTransaction();
            $id = field($input, 'id', false, 50) ?: next_asset_id($pdo);
            $pdo->prepare('INSERT INTO assets (id, name, nickname, type, description, location, folder_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $id, field($input, 'name', true, 150), field($input, 'nickname', false, 100) ?: null, $type,
                    field($input, 'description', false, 5000), $location, $folderId,
                ]);
            $pdo->prepare('INSERT INTO asset_history (asset_id, location, notes, directions) VALUES (?, ?, ?, ?)')
                ->execute([$id, $location, 'Initial Asset Registration', field($input, 'directions', false, 5000) ?: null]);
            $pdo->commit();

            respond(['success' => true, 'id' => $id], 201);
        },
        'POST move' => function () use ($pdo, $input) {
            require_unlocked($pdo);
            $asset = load_asset($pdo, $_GET['id'] ?? '');
            $location = field($input, 'location');

            $pdo->beginTransaction();
            $pdo->prepare('UPDATE assets SET location = ? WHERE id = ?')->execute([$location, $asset['id']]);
            $pdo->prepare('INSERT INTO asset_history (asset_id, location, notes, directions) VALUES (?, ?, ?, ?)')
                ->execute([$asset['id'], $location, field($input, 'notes', false, 5000) ?: 'Location updated', field($input, 'directions', false, 5000) ?: null]);
            $pdo->commit();

            respond(['success' => true]);
        },
        'POST edit' => function () use ($pdo, $input) {
            require_unlocked($pdo);
            $asset = load_asset($pdo, $_GET['id'] ?? '');

            // Name and type are fixed after registration; only these fields can change.
            $folderId = folder_id_or_null($input);
            check_folder($pdo, $folderId, $asset['type']);
            $status = one_of(field($input, 'status'), STATUSES, 'status');

            $pdo->prepare('UPDATE assets SET nickname = ?, description = ?, folder_id = ?, status = ? WHERE id = ?')
                ->execute([field($input, 'nickname', false, 100) ?: null, field($input, 'description', false, 5000), $folderId, $status, $asset['id']]);
            respond(['success' => true]);
        },

        // ----- Backup & restore -----
        // A full, engine-agnostic snapshot of the vault's data (not its password). Works the same
        // whether the vault is MySQL (hosted) or SQLite (desktop) — it's a data-level dump, not a
        // copy of any particular file.
        'GET export' => function () use ($pdo) {
            require_unlocked($pdo);
            respond([
                'app' => 'AssetTracker', 'format' => 1, 'generated_at' => date('c'),
                'folders' => $pdo->query('SELECT id, name, type, description FROM folders ORDER BY id')->fetchAll(),
                'assets' => $pdo->query('SELECT id, name, nickname, type, description, location, status, folder_id, created_at FROM assets ORDER BY id')->fetchAll(),
                'history' => $pdo->query('SELECT asset_id, location, notes, directions, created_at FROM asset_history ORDER BY id')->fetchAll(),
            ]);
        },
        // Restoring REPLACES everything currently in the vault with the contents of the file. The
        // master password (if any) is untouched — only folders/assets/history are swapped.
        'POST import' => function () use ($pdo, $input) {
            require_unlocked($pdo);
            $folders = $input['folders'] ?? null;
            $assets = $input['assets'] ?? null;
            $history = $input['history'] ?? null;
            if (!is_array($folders) || !is_array($assets) || !is_array($history)) {
                fail('That file does not look like an AssetTracker backup', 422);
            }

            // Validate everything up front so a bad file fails before anything is touched.
            $folderIds = [];
            foreach ($folders as $f) {
                if (!isset($f['id'], $f['name'], $f['type']) || !in_array($f['type'], ASSET_TYPES, true)) {
                    fail('Invalid folder entry in backup file', 422);
                }
                $folderIds[(int) $f['id']] = true;
            }
            $assetIds = [];
            foreach ($assets as $a) {
                if (!isset($a['id'], $a['name'], $a['type'], $a['location'])
                    || !in_array($a['type'], ASSET_TYPES, true)
                    || !in_array($a['status'] ?? 'Active', STATUSES, true)) {
                    fail('Invalid asset entry in backup file', 422);
                }
                if (($a['folder_id'] ?? null) !== null && !isset($folderIds[(int) $a['folder_id']])) {
                    fail("Asset '{$a['id']}' references a folder not included in the backup file", 422);
                }
                $assetIds[$a['id']] = true;
            }
            foreach ($history as $h) {
                if (!isset($h['asset_id'], $h['location']) || !isset($assetIds[$h['asset_id']])) {
                    fail('Invalid history entry in backup file', 422);
                }
            }

            $pdo->beginTransaction();
            try {
                $pdo->exec('DELETE FROM asset_history');
                $pdo->exec('DELETE FROM assets');
                $pdo->exec('DELETE FROM folders');

                $insFolder = $pdo->prepare('INSERT INTO folders (id, name, type, description) VALUES (?, ?, ?, ?)');
                foreach ($folders as $f) {
                    $insFolder->execute([(int) $f['id'], (string) $f['name'], $f['type'], $f['description'] ?? null]);
                }
                $insAsset = $pdo->prepare('INSERT INTO assets (id, name, nickname, type, description, location, status, folder_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($assets as $a) {
                    $insAsset->execute([
                        $a['id'], $a['name'], $a['nickname'] ?? null, $a['type'], $a['description'] ?? null,
                        $a['location'], $a['status'] ?? 'Active', $a['folder_id'] ?? null, $a['created_at'] ?? date('Y-m-d H:i:s'),
                    ]);
                }
                $insHist = $pdo->prepare('INSERT INTO asset_history (asset_id, location, notes, directions, created_at) VALUES (?, ?, ?, ?, ?)');
                foreach ($history as $h) {
                    $insHist->execute([$h['asset_id'], $h['location'], $h['notes'] ?? null, $h['directions'] ?? null, $h['created_at'] ?? date('Y-m-d H:i:s')]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                fail('Restore failed, nothing was changed: ' . $e->getMessage(), 500);
            }

            respond(['success' => true, 'imported' => ['folders' => count($folders), 'assets' => count($assets), 'history' => count($history)]]);
        },
        // Desktop-only: a true file-level copy of the live vault (SQLite's VACUUM INTO is atomic,
        // safe to run even while the app is open). This is what the Electron "Save Vault Copy As…"
        // menu item calls; there's nothing to call here for the hosted MySQL build.
        'POST backup_file' => function () use ($pdo, $input) {
            require_unlocked($pdo);
            if (driver() !== 'sqlite') {
                fail('Saving a vault file copy is only available in the desktop app. Use Export here instead.', 400);
            }
            $path = (string) ($input['path'] ?? '');
            if ($path === '') {
                fail("'path' is required", 422);
            }
            if (!is_dir(dirname($path))) {
                fail('Destination folder does not exist', 422);
            }
            try {
                $pdo->exec('VACUUM INTO ' . $pdo->quote($path));
            } catch (PDOException $e) {
                fail('Backup failed: ' . $e->getMessage(), 500);
            }
            respond(['success' => true]);
        },
    ];

    $route = $routes["$method $action"] ?? fail('Unknown action', 404);
    $route();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // 1062 = duplicate key, 1452 = foreign key points at a row that doesn't exist
    $mysqlCode = $e->errorInfo[1] ?? 0;
    if ($mysqlCode === 1062) {
        fail('That value already exists (duplicate name or ID)', 409);
    }
    if ($mysqlCode === 1452) {
        fail('Referenced folder does not exist', 422);
    }
    error_log('AssetTracker API error: ' . $e->getMessage());
    fail('Server error', 500);
}
