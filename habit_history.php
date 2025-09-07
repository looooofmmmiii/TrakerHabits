<?php
// habit_history-improved-v2.php
// Major improvements:
// - Works without an explicit ?id=...: shows habit selector and can load history for any habit (select or deep link)
// - 'Mark today completed' fixed: creates or updates today's habit_tracking row (AJAX-first, graceful fallback)
// - Modern SaaS-style UI (single-file, no external deps) with responsive layout and accessible controls
// - AJAX actions: toggle, delete, mark_today. All are CSRF-protected and return JSON for XHR clients
// - CSV export with BOM and UTF-8 encoding
// - Safe PDO usage, prepared statements, small helper functions
// - Optimistic UI updates and inline mini-chart as before

declare(strict_types=1);
session_name('habit_sid');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
ini_set('session.cookie_httponly', '1');
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');

require_once 'functions/habit_functions.php'; // Your project helpers

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}
$user_id = intval($_SESSION['user_id']);

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

function json_response(array $data, int $status = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Simple helpers that fallback to PDO if habit_functions don't exist
function getUserHabitsFallback(PDO $pdo, int $user_id): array {
    $sql = 'SELECT id, title, frequency FROM habits WHERE user_id = ? ORDER BY sort_order ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getHabitFallback(PDO $pdo, int $habit_id, int $user_id) {
    $stmt = $pdo->prepare('SELECT * FROM habits WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$habit_id, $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getHistoryFallback(PDO $pdo, int $habit_id, int $user_id, string $from, string $to, int $limit = 0, int $offset = 0) {
    $sql = 'SELECT ht.id, ht.track_date, ht.completed, ht.created_at, ht.updated_at
            FROM habit_tracking ht
            JOIN habits h ON ht.habit_id = h.id
            WHERE ht.habit_id = ? AND h.user_id = ? AND ht.track_date BETWEEN ? AND ?
            ORDER BY ht.track_date DESC'
            . ($limit>0 ? ' LIMIT ' . intval($limit) . ' OFFSET ' . intval($offset) : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$habit_id, $user_id, $from, $to]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ---------- get habit_id either from GET or POST; allow missing id to show selector ----------
$habit_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['habit_id'] ?? 0);

// Detect AJAX
$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (isset($_GET['ajax']) && $_GET['ajax']=='1');

// DB handle (expects $pdo available globally from bootstrap). If not, try to create via environment.
global $pdo;
if (empty($pdo) || !($pdo instanceof PDO)) {
    // try to create PDO from env (adjust to your config)
    $dbDsn = getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=your_db;charset=utf8mb4';
    $dbUser = getenv('DB_USER') ?: 'root';
    $dbPass = getenv('DB_PASS') ?: '';
    try { $pdo = new PDO($dbDsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); } catch (Exception $e) { /* ignore: functions may provide data */ }
}

// Load all user's habits for selector
$habits = [];
if (function_exists('getUserHabits')) {
    $habits = getUserHabits($user_id);
} elseif (!empty($pdo) && $pdo instanceof PDO) {
    $habits = getUserHabitsFallback($pdo, $user_id);
}

// If id missing and user has habits, default to first habit for immediate view
if ($habit_id <= 0 && !empty($habits)) {
    $habit_id = intval($habits[0]['id']);
}

$habit = null;
if ($habit_id > 0) {
    if (function_exists('getHabit')) $habit = getHabit($habit_id, $user_id);
    if (!$habit && !empty($pdo) && $pdo instanceof PDO) $habit = getHabitFallback($pdo, $habit_id, $user_id);
}

// ---------- POST actions (toggle / delete / mark_today) with AJAX support ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        if ($isAjax) json_response(['ok'=>false,'error'=>'Invalid CSRF'], 400);
        $_SESSION['flash_error'] = 'Invalid request';
        header('Location: habit_history-improved-v2.php' . ($habit_id ? '?id='.$habit_id : ''));
        exit;
    }

    // require habit_id for actions that touch habit-level data
    $post_habit_id = intval($_POST['habit_id'] ?? 0);
    if ($post_habit_id <= 0) {
        if ($isAjax) json_response(['ok'=>false,'error'=>'Missing habit_id'], 400);
        $_SESSION['flash_error'] = 'Missing habit id';
        header('Location: habit_history-improved-v2.php'); exit;
    }

    // simple authorization: confirm habit belongs to user
    $allowed = false;
    if (function_exists('getHabit')) {
        $tmp = getHabit($post_habit_id, $user_id);
        $allowed = (bool)$tmp;
    } elseif (!empty($pdo) && $pdo instanceof PDO) {
        $tmp = getHabitFallback($pdo, $post_habit_id, $user_id);
        $allowed = (bool)$tmp;
    }
    if (!$allowed) {
        if ($isAjax) json_response(['ok'=>false,'error'=>'Access denied'], 403);
        $_SESSION['flash_error'] = 'Access denied'; header('Location: habit_history-improved-v2.php'); exit;
    }

    // entry id used for toggle/delete
    $entry_id = intval($_POST['entry_id'] ?? 0);

    try {
        if ($action === 'toggle') {
            if ($entry_id <= 0) throw new RuntimeException('Invalid entry id');
            if (function_exists('toggleHabitEntry')) {
                $ok = (bool)toggleHabitEntry($entry_id, $user_id);
            } else {
                $check = $pdo->prepare('SELECT ht.id FROM habit_tracking ht JOIN habits h ON ht.habit_id = h.id WHERE ht.id = ? AND h.user_id = ? LIMIT 1');
                $check->execute([$entry_id, $user_id]);
                if (!$check->fetch()) throw new RuntimeException('Entry not found');
                $update = $pdo->prepare('UPDATE habit_tracking SET completed = 1 - completed WHERE id = ?');
                $ok = (bool)$update->execute([$entry_id]);
            }
            $msg = $ok ? 'Entry updated' : 'Unable to update entry';
            if ($isAjax) json_response(['ok'=>$ok,'message'=>$msg]);
            $_SESSION['flash_' . ($ok ? 'success' : 'error')] = $msg;
            header('Location: habit_history-improved-v2.php?id=' . $post_habit_id); exit;

        } elseif ($action === 'delete') {
            if ($entry_id <= 0) throw new RuntimeException('Invalid entry id');
            if (function_exists('deleteHabitEntry')) {
                $ok = (bool)deleteHabitEntry($entry_id, $user_id);
            } else {
                $del = $pdo->prepare('DELETE t FROM habit_tracking t JOIN habits h ON t.habit_id = h.id WHERE t.id = ? AND h.user_id = ?');
                $ok = (bool)$del->execute([$entry_id, $user_id]);
            }
            $msg = $ok ? 'Entry deleted' : 'Unable to delete entry';
            if ($isAjax) json_response(['ok'=>$ok,'message'=>$msg]);
            $_SESSION['flash_' . ($ok ? 'success' : 'error')] = $msg;
            header('Location: habit_history-improved-v2.php?id=' . $post_habit_id); exit;

        } elseif ($action === 'mark_today') {
            // Mark today's date as completed for the given habit (insert or update)
            $today = (new DateTimeImmutable('today'))->format('Y-m-d');
            if (function_exists('markTodayCompleted')) {
                // optional project-specific implementation
                $ok = (bool)markTodayCompleted($post_habit_id, $user_id);
            } else {
                // fallback implementation using transaction
                $pdo->beginTransaction();
                // ensure habit belongs to user
                $chk = $pdo->prepare('SELECT id FROM habits WHERE id = ? AND user_id = ? LIMIT 1');
                $chk->execute([$post_habit_id, $user_id]);
                if (!$chk->fetch()) throw new RuntimeException('Habit not found');
                // check existing entry
                $s = $pdo->prepare('SELECT id, completed FROM habit_tracking WHERE habit_id = ? AND track_date = ? LIMIT 1');
                $s->execute([$post_habit_id, $today]);
                $found = $s->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    // toggle to completed (set to 1)
                    $u = $pdo->prepare('UPDATE habit_tracking SET completed = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                    $ok = (bool)$u->execute([$found['id']]);
                    $entryId = intval($found['id']);
                } else {
                    $i = $pdo->prepare('INSERT INTO habit_tracking (habit_id, track_date, completed, created_at, updated_at) VALUES (?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
                    $ok = (bool)$i->execute([$post_habit_id, $today]);
                    $entryId = (int)$pdo->lastInsertId();
                }
                $pdo->commit();
            }
            if ($isAjax) json_response(['ok'=>true,'message'=>'Marked today completed','entry_id'=>$entryId,'track_date'=>$today]);
            $_SESSION['flash_success'] = 'Marked today completed';
            header('Location: habit_history-improved-v2.php?id=' . $post_habit_id); exit;

        } else {
            if ($isAjax) json_response(['ok'=>false,'error'=>'Unknown action'], 400);
            $_SESSION['flash_error'] = 'Unknown action'; header('Location: habit_history-improved-v2.php'); exit;
        }
    } catch (Exception $ex) {
        if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        if ($isAjax) json_response(['ok'=>false,'error'=>$ex->getMessage()], 500);
        $_SESSION['flash_error'] = $ex->getMessage(); header('Location: habit_history-improved-v2.php?id=' . $post_habit_id); exit;
    }
}

// ---------- Filters & Pagination (GET) ----------
$date_from = isset($_GET['from']) ? trim($_GET['from']) : null;
$date_to = isset($_GET['to']) ? trim($_GET['to']) : null;
if ($date_from === '') $date_from = null;
if ($date_to === '') $date_to = null;
$end = new DateTimeImmutable('today');
$start = $end->sub(new DateInterval('P89D'));
if ($date_from) { $tmp = DateTimeImmutable::createFromFormat('Y-m-d', $date_from); if ($tmp) $start = $tmp; }
if ($date_to) { $tmp = DateTimeImmutable::createFromFormat('Y-m-d', $date_to); if ($tmp) $end = $tmp; }
$startStr = $start->format('Y-m-d');
$endStr = $end->format('Y-m-d');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50; $offset = ($page-1)*$perPage;

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $habit_id > 0) {
    $rows = [];
    if (function_exists('getHabitHistory')) {
        $rows = getHabitHistory($habit_id, $user_id, $startStr, $endStr, 0, 0);
    } elseif (!empty($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare('SELECT ht.id, ht.track_date, ht.completed, ht.created_at, ht.updated_at FROM habit_tracking ht JOIN habits h ON ht.habit_id = h.id WHERE ht.habit_id = ? AND h.user_id = ? AND ht.track_date BETWEEN ? AND ? ORDER BY ht.track_date DESC');
        $stmt->execute([$habit_id, $user_id, $startStr, $endStr]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="habit-' . $habit_id . '-history.csv"');
    echo "\xEF\xBB\xBF"; // BOM
    $out = fopen('php://output','w');
    fputcsv($out, ['id','track_date','completed','created_at','updated_at']);
    foreach ($rows as $r) fputcsv($out, [$r['id'],$r['track_date'],intval($r['completed']),$r['created_at'] ?? '', $r['updated_at'] ?? '']);
    fclose($out);
    exit;
}

// ---------- Fetch history + aggregate ----------
$history = []; $totalRows = 0; $dailyStats = [];
if ($habit_id > 0) {
    if (function_exists('getHabitHistory')) {
        $history = getHabitHistory($habit_id, $user_id, $startStr, $endStr, $perPage, $offset);
        // count (best-effort)
        $totalRows = function_exists('countHabitHistory') ? countHabitHistory($habit_id,$user_id,$startStr,$endStr) : (count($history));
    } elseif (!empty($pdo) && $pdo instanceof PDO) {
        $countSql = 'SELECT COUNT(*) FROM habit_tracking ht JOIN habits h ON ht.habit_id = h.id WHERE ht.habit_id = ? AND h.user_id = ? AND ht.track_date BETWEEN ? AND ?';
        $stmt = $pdo->prepare($countSql); $stmt->execute([$habit_id,$user_id,$startStr,$endStr]); $totalRows = (int)$stmt->fetchColumn();
        $sql = 'SELECT ht.id, ht.track_date, ht.completed, ht.created_at, ht.updated_at FROM habit_tracking ht JOIN habits h ON ht.habit_id = h.id WHERE ht.habit_id = ? AND h.user_id = ? AND ht.track_date BETWEEN ? AND ? ORDER BY ht.track_date DESC LIMIT ? OFFSET ?';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1,$habit_id,PDO::PARAM_INT); $stmt->bindValue(2,$user_id,PDO::PARAM_INT); $stmt->bindValue(3,$startStr,PDO::PARAM_STR); $stmt->bindValue(4,$endStr,PDO::PARAM_STR); $stmt->bindValue(5,$perPage,PDO::PARAM_INT); $stmt->bindValue(6,$offset,PDO::PARAM_INT);
        $stmt->execute(); $history = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $aggSql = 'SELECT ht.track_date, COUNT(*) as total, SUM(ht.completed) as completed FROM habit_tracking ht JOIN habits h ON ht.habit_id = h.id WHERE ht.habit_id = ? AND h.user_id = ? AND ht.track_date BETWEEN ? AND ? GROUP BY ht.track_date ORDER BY ht.track_date ASC';
        $stmt = $pdo->prepare($aggSql); $stmt->execute([$habit_id,$user_id,$startStr,$endStr]); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) $dailyStats[$r['track_date']] = ['total'=>intval($r['total']),'completed'=>intval($r['completed'])];
    }
}

// compute stats
$totalTracked = $totalRows;
$completedCount = 0; foreach ($history as $r) if (intval($r['completed'])===1) $completedCount++;
$eff = ($totalTracked>0) ? round(($completedCount/$totalTracked)*100) : 0;

// prepare chart series
$period = new DatePeriod(new DateTimeImmutable($startStr), new DateInterval('P1D'), (new DateTimeImmutable($endStr))->modify('+1 day'));
$labels = []; $series = [];
foreach ($period as $dt) {
    $d = $dt->format('Y-m-d'); $labels[] = $d;
    if (isset($dailyStats[$d])) { $t = $dailyStats[$d]['total']; $c = $dailyStats[$d]['completed']; $series[] = ($t>0)? round(($c/$t)*100,2): 0; } else $series[] = 0;
}
$predicted = null; $vals = array_values($series); if (count($vals)>=2) { $last=$vals[count($vals)-1]; $prev=$vals[count($vals)-2]; $predicted = min(100,max(0,round($last+($last-$prev),2))); }

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Habit history — <?php echo e($habit['title'] ?? 'Habits'); ?></title>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<style>
:root{
  --bg:#0f172a;
  --card:#ffffff;
  --accent:#6366f1;
  --accent-2:#7c3aed;
  --success:#10b981;
  --danger:#ef4444;
  --glass: rgba(255,255,255,0.06);

  /* text tokens */
  --text-default: #0b1220;           /* main body text on light surfaces */
  --text-muted: #94a3b8;             /* muted gray for light surfaces */

  /* tokens for use on dark surfaces */
  --text-on-dark: #ffffff;           /* primary text on dark surfaces */
  --muted-on-dark: rgba(255,255,255,0.72);

  /* component-level fallbacks (can be overridden by contexts like .card.dark) */
  --text-on-card: var(--text-default);
  --muted-on-card: var(--text-muted);
}

/* Utility text helpers (use when you need explicit contrast) */
.text-strong { color: var(--text-on-card) !important; }
.text-weak   { color: var(--muted-on-card, var(--text-muted)) !important; }
.text-inverse{ color: var(--text-on-dark) !important; }
*{box-sizing:border-box}
html,body{height:100%}
body{font-family:Inter,ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial; margin:0; background:linear-gradient(180deg,#f8fafc,#eef2ff); color:#0b1220}
.container{max-width:1200px;margin:28px auto;padding:18px}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}
.brand{display:flex;align-items:center;gap:12px}
.logo{width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,var(--accent),var(--accent-2));display:flex;align-items:center;justify-content:center;color:white;font-weight:700}
.h1{font-size:18px;font-weight:700}
.controls{display:flex;gap:10px;align-items:center}
.btn{background:var(--accent);color:white;padding:10px 14px;border-radius:10px;border:0;cursor:pointer;font-weight:600;display:inline-flex;gap:8px;align-items:center}
.btn.ghost{background:transparent;color:var(--accent);border:1px solid rgba(99,102,241,0.14)}
.btn.small{font-size:13px;color:var(--muted-on-card, var(--text-muted))}
.card{background:var(--card);padding:16px;border-radius:14px;box-shadow:0 8px 30px rgba(2,6,23,0.06);}
.grid{display:grid;grid-template-columns:1fr 360px;gap:18px}
.card.dark, .card.--dark {
  background: linear-gradient(180deg,#0b1220,#061024);
  color: var(--text-on-dark);
  --text-on-card: var(--text-on-dark);
  --muted-on-card: var(--muted-on-dark);
}
@media (max-width:960px){.grid{grid-template-columns:1fr}}
.controls .select{padding:10px;border-radius:10px;border:1px solid #e6eefc;background:white}
.toolbar{display:flex;gap:8px;align-items:center}
.stats{display:flex;gap:12px;align-items:center}
.kpi{padding:12px;border-radius:12px;background:linear-gradient(180deg,#fff,#fbfdff);min-width:120px;text-align:center}
.table{width:100%;border-collapse:collapse;margin-top:12px}
.table th,.table td{padding:12px;border-bottom:1px solid #f1f5f9;text-align:left}
.table th{font-size:13px;color:var(--muted-on-card, var(--text-muted))}
.pager{display:flex;gap:6px;flex-wrap:wrap}
.small{font-size:13px;color:var(--muted)}
.flash{padding:10px;border-radius:8px;margin-bottom:12px}
.flash.success{background:#ecfdf5;color:#065f46}
.flash.error{background:#ffebe9;color:#7f1d1d}
.mini-chart{height:120px}
.empty{padding:28px;text-align:center;color:var(--muted-on-card, var(--text-muted))}
</style>
</head>
<body>
<?php include "elements.php"; ?>  <!-- sidebar only once -->
<div class="container">
    <header class="header">
        <div class="brand">
            <div class="logo">HN</div>
            <div>
                <div class="h1">Habit history</div>
                <div class="small">Track, analyze &amp; improve your routines</div>
            </div>
        </div>
        <div class="controls">
            <form method="GET" action="" style="display:flex;gap:8px;align-items:center">
                <input type="hidden" name="id" id="selected-habit-input" value="<?php echo e($habit_id); ?>">
                <select id="habit-select" name="id" class="select">
                    <?php if (empty($habits)): ?>
                        <option value="">No habits found</option>
                    <?php else: foreach ($habits as $h): ?>
                        <option value="<?php echo intval($h['id']); ?>" <?php echo ($habit_id==intval($h['id'])) ? 'selected' : ''; ?>><?php echo e($h['title'] ?? ('Habit '.intval($h['id']))); ?> — <?php echo e($h['frequency'] ?? 'daily'); ?></option>
                    <?php endforeach; endif; ?>
                </select>
                <button class="btn small" type="submit">Open</button>
                <a class="btn ghost small" href="dashboard.php">Back</a>
            </form>
        </div>
    </header>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="flash success" id="flash-success"><?php echo e($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="flash error" id="flash-error"><?php echo e($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>

    <div class="grid">
        <main>
            <section class="card">
                <?php if (!$habit): ?>
                    <div class="empty">No habit selected. Use the selector on the top-right to open a habit history.</div>
                <?php else: ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                        <div>
                            <div class="small">Habit</div>
                            <h2 style="margin:0;font-size:16px"><?php echo e($habit['title']); ?></h2>
                            <div class="small">ID: <?php echo intval($habit['id']); ?> · <?php echo e($habit['frequency'] ?? 'daily'); ?></div>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center">
                            <div class="kpi">
                                <div class="small">Efficiency</div>
                                <div style="font-weight:800;font-size:18px"><?php echo $eff; ?>%</div>
                                <div class="small">Completed <?php echo $completedCount; ?> / <?php echo $totalTracked; ?></div>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:8px">
                                <button class="btn" id="mark-today" data-habit-id="<?php echo intval($habit['id']); ?>">Mark today completed</button>
                                <a class="btn ghost small" href="?id=<?php echo intval($habit['id']); ?>&export=csv&from=<?php echo e($startStr); ?>&to=<?php echo e($endStr); ?>">Export CSV</a>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:14px;display:flex;gap:12px;align-items:center;justify-content:space-between">
                        <form method="GET" action="" style="display:flex;gap:8px;align-items:center">
                            <input type="hidden" name="id" value="<?php echo intval($habit['id']); ?>">
                            <label class="small">From <input type="date" name="from" value="<?php echo e($startStr); ?>"></label>
                            <label class="small">To <input type="date" name="to" value="<?php echo e($endStr); ?>"></label>
                            <button class="btn small" type="submit">Filter</button>
                        </form>
                        <div class="small">Showing <?php echo e($startStr); ?> — <?php echo e($endStr); ?> · Rows: <?php echo $totalRows; ?></div>
                    </div>

                    <table class="table" role="table" aria-label="History table">
                        <thead><tr><th>Date</th><th>Completed</th><th>Created / Updated</th><th>Actions</th></tr></thead>
                        <tbody id="history-body">
                        <?php if (empty($history)): ?>
                            <tr><td colspan="4" class="small">No entries in this period.</td></tr>
                        <?php else: foreach ($history as $row): ?>
                            <tr data-entry-id="<?php echo intval($row['id']); ?>">
                                <td><?php echo e($row['track_date']); ?></td>
                                <td class="small status"><?php echo (intval($row['completed'])===1) ? '✅' : '—'; ?></td>
                                <td class="small"><?php echo e(($row['created_at'] ?? '') . (isset($row['updated_at']) ? ' / ' . $row['updated_at'] : '')); ?></td>
                                <td>
                                    <button class="btn small" data-action="toggle" data-id="<?php echo intval($row['id']); ?>">Toggle</button>
                                    <button class="btn small" data-action="delete" data-id="<?php echo intval($row['id']); ?>" style="background:var(--danger)">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>

                    <?php if ($totalRows > $perPage): $pages = (int)ceil($totalRows/$perPage); ?>
                        <div style="margin-top:12px;display:flex;justify-content:flex-end" class="pager">
                        <?php for ($p=1;$p<=$pages;$p++): ?>
                            <a class="btn small" href="?id=<?php echo intval($habit['id']); ?>&from=<?php echo e($startStr); ?>&to=<?php echo e($endStr); ?>&page=<?php echo $p; ?>" style="background:<?php echo ($p===$page) ? 'var(--bg)' : 'var(--accent)'; ?>; color:<?php echo ($p===$page) ? 'white' : 'white'; ?>"><?php echo $p; ?></a>
                        <?php endfor; ?>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>
            </section>
        </main>

        <aside>
            <section class="card">
                <div class="small">Efficiency chart</div>
                <div class="mini-chart" id="mini-chart"></div>
                <div class="small" style="margin-top:8px">Predicted next day: <?php echo ($predicted===null) ? '—' : $predicted . '%'; ?></div>
            </section>

            <section class="card" style="margin-top:12px">
                <div class="small">Quick actions</div>
                <div style="margin-top:8px;display:flex;gap:8px">
                    <button class="btn small" id="mark-today-compact" data-habit-id="<?php echo intval($habit['id'] ?? 0); ?>">Mark today</button>
                    <a class="btn small ghost" href="settings.php">Settings</a>
                </div>
            </section>
        </aside>
    </div>
</div>

<script>
const csrf = '<?php echo $csrf; ?>';

// helper: POST action
async function postAction(payloadObj) {
    const form = new URLSearchParams();
    for (const k in payloadObj) form.append(k, payloadObj[k]);
    form.append('csrf_token', csrf);
    try {
        const res = await fetch(location.pathname + location.search + (location.search ? '&ajax=1' : '?ajax=1'), {
            method: 'POST', headers: {'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'}, body: form
        });
        return await res.json();
    } catch (e) { return {ok:false, error:'network'}; }
}

// Attach handlers for toggle/delete
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-action]'); if (!btn) return;
    const action = btn.dataset.action; const entryId = btn.dataset.id; const habitId = document.getElementById('selected-habit-input').value || btn.closest('[data-habit-id]')?.dataset.habitId || '<?php echo intval($habit_id); ?>';
    if (action === 'delete' && !confirm('Delete this entry?')) return;
    if (action === 'toggle' && !confirm('Toggle completed?')) return;
    const res = await postAction({action, entry_id: entryId, habit_id: habitId});
    if (res.ok) {
        if (action === 'toggle') {
            const tr = document.querySelector('tr[data-entry-id="'+entryId+'"]');
            if (tr) {
                const td = tr.querySelector('.status'); td.textContent = td.textContent.trim() === '✅' ? '—' : '✅';
            } else location.reload();
        }
        if (action === 'delete') {
            const tr = document.querySelector('tr[data-entry-id="'+entryId+'"]'); if (tr) tr.remove();
        }
    } else alert(res.error || res.message || 'Error');
});

// Mark today action (main button + compact)
document.getElementById('mark-today')?.addEventListener('click', async (e)=>{
    const habitId = e.currentTarget.dataset.habitId || '<?php echo intval($habit_id); ?>';
    e.currentTarget.disabled = true; e.currentTarget.textContent = 'Saving...';
    const res = await postAction({action:'mark_today', habit_id: habitId});
    if (res.ok) {
        // if new entry created, insert into table head
        if (res.entry_id) {
            const tbody = document.getElementById('history-body');
            if (tbody) {
                const tr = document.createElement('tr'); tr.setAttribute('data-entry-id', res.entry_id);
                tr.innerHTML = `<td>${res.track_date}</td><td class="small status">✅</td><td class="small"><?= date('Y-m-d H:i:s') ?></td><td><button class="btn small" data-action="toggle" data-id="${res.entry_id}">Toggle</button> <button class="btn small" data-action="delete" data-id="${res.entry_id}" style="background:var(--danger)">Delete</button></td>`;
                if (tbody.firstChild) tbody.insertBefore(tr, tbody.firstChild);
            }
        }
        location.reload();
    } else {
        alert(res.error || res.message || 'Unable to mark today');
        e.currentTarget.disabled = false; e.currentTarget.textContent = 'Mark today completed';
    }
});

// compact button
document.getElementById('mark-today-compact')?.addEventListener('click', async (e)=>{ document.getElementById('mark-today')?.click(); });

// habit selector sync hidden input
const habitSelect = document.getElementById('habit-select');
if (habitSelect) habitSelect.addEventListener('change', ()=>{ document.getElementById('selected-habit-input').value = habitSelect.value; });

// mini SVG chart render (same approach as previous)
(function renderChart(){
    const labels = <?php echo json_encode($labels); ?>;
    const series = <?php echo json_encode($series); ?>;
    const predicted = <?php echo json_encode($predicted); ?>;
    const container = document.getElementById('mini-chart'); if (!container) return;
    container.innerHTML = '';
    const w = 560, h = 120, pad = 12; const svgNS='http://www.w3.org/2000/svg';
    const svg = document.createElementNS(svgNS,'svg'); svg.setAttribute('viewBox',`0 0 ${w} ${h}`); svg.setAttribute('width','100%'); svg.setAttribute('height','120');
    const max = 100; const stepX = (w-pad*2)/Math.max(1,series.length-1);
    const points = series.map((v,i)=>{ const x = pad + i*stepX; const y = h - pad - (v/max)*(h-pad*2); return [x,y]; });
    if (points.length===0) return;
    const path = document.createElementNS(svgNS,'path'); path.setAttribute('d', points.map((p,i)=>(i===0?'M':'L')+p[0]+' '+p[1]).join(' ')); path.setAttribute('fill','none'); path.setAttribute('stroke','#6366f1'); path.setAttribute('stroke-width','2'); svg.appendChild(path);
    points.forEach(p=>{ const c=document.createElementNS(svgNS,'circle'); c.setAttribute('cx',p[0]); c.setAttribute('cy',p[1]); c.setAttribute('r',2.4); c.setAttribute('fill','#6366f1'); svg.appendChild(c); });
    if (predicted !== null) {
        const last = points[points.length-1]; const x2 = last[0] + stepX; const y2 = h - pad - (predicted/max)*(h-pad*2);
        const pd = document.createElementNS(svgNS,'path'); pd.setAttribute('d', `M ${last[0]} ${last[1]} L ${x2} ${y2}`); pd.setAttribute('stroke','#a78bfa'); pd.setAttribute('stroke-width','1.6'); pd.setAttribute('stroke-dasharray','4 4'); pd.setAttribute('fill','none'); svg.appendChild(pd);
        const c=document.createElementNS(svgNS,'circle'); c.setAttribute('cx',x2); c.setAttribute('cy',y2); c.setAttribute('r',2.4); c.setAttribute('fill','#a78bfa'); svg.appendChild(c);
    }
    container.appendChild(svg);
})();
</script>
</body>
</html>
