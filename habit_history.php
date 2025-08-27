<?php
// habit_history.php — lightweight history viewer for a single habit
// Features: secure session checks, CSRF, filter by date range, CSV export, toggle/delete entries
// Adjusted to match DB schema: habit_tracking has no `notes` column — uses created_at/updated_at instead.

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

require_once 'functions/habit_functions.php';

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

// get habit id
$habit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($habit_id <= 0) {
    $_SESSION['flash_error'] = 'Invalid habit id';
    header('Location: dashboard.php'); exit;
}

// try to load habit (use helper if available)
$habit = getHabit($habit_id, $user_id);
if (!$habit) {
    // fallback: try PDO ownership check
    global $pdo;
    if (!empty($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare('SELECT * FROM habits WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$habit_id, $user_id]);
        $habit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!$habit) {
    $_SESSION['flash_error'] = 'Habit not found or access denied';
    header('Location: dashboard.php'); exit;
}

// handle POST actions: toggle complete / delete entry
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash_error'] = 'Invalid request';
        header("Location: habit_history.php?id={$habit_id}"); exit;
    }

    if ($action === 'toggle') {
        $entry_id = intval($_POST['entry_id'] ?? 0);
        // toggle completed flag via helper or direct query
        if ($entry_id > 0) {
            $ok = false;
            if (function_exists('toggleHabitEntry')) {
                $ok = (bool)toggleHabitEntry($entry_id, $user_id);
            } else {
                global $pdo;
                if (!empty($pdo) && $pdo instanceof PDO) {
                    // verify ownership
                    $check = $pdo->prepare('SELECT ht.id, h.user_id FROM habit_tracking ht JOIN habits h ON ht.habit_id = h.id WHERE ht.id = ? AND h.user_id = ? LIMIT 1');
                    $check->execute([$entry_id, $user_id]);
                    $row = $check->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $update = $pdo->prepare('UPDATE habit_tracking SET completed = 1 - completed WHERE id = ?');
                        $ok = (bool)$update->execute([$entry_id]);
                    }
                }
            }
            $_SESSION['flash_' . ($ok ? 'success' : 'error')] = $ok ? 'Entry updated' : 'Unable to update entry';
        }
    } elseif ($action === 'delete') {
        $entry_id = intval($_POST['entry_id'] ?? 0);
        if ($entry_id > 0) {
            $ok = false;
            if (function_exists('deleteHabitEntry')) {
                $ok = (bool)deleteHabitEntry($entry_id, $user_id);
            } else {
                global $pdo;
                if (!empty($pdo) && $pdo instanceof PDO) {
                    $del = $pdo->prepare('DELETE t FROM habit_tracking t JOIN habits h ON t.habit_id = h.id WHERE t.id = ? AND h.user_id = ?');
                    $ok = (bool)$del->execute([$entry_id, $user_id]);
                }
            }
            $_SESSION['flash_' . ($ok ? 'success' : 'error')] = $ok ? 'Entry deleted' : 'Unable to delete entry';
        }
    }

    header("Location: habit_history.php?id={$habit_id}"); exit;
}

// Filters: date_from, date_to
$date_from = isset($_GET['from']) ? trim($_GET['from']) : null;
$date_to = isset($_GET['to']) ? trim($_GET['to']) : null;

// basic validation
if ($date_from === '') $date_from = null;
if ($date_to === '') $date_to = null;

// defaults: last 90 days
$end = new DateTimeImmutable('today');
$start = $end->sub(new DateInterval('P89D'));
if ($date_from) {
    $tmp = DateTimeImmutable::createFromFormat('Y-m-d', $date_from);
    if ($tmp) $start = $tmp;
}
if ($date_to) {
    $tmp = DateTimeImmutable::createFromFormat('Y-m-d', $date_to);
    if ($tmp) $end = $tmp;
}

$startStr = $start->format('Y-m-d');
$endStr = $end->format('Y-m-d');

// pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// export CSV if requested
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // fetch all matching rows (no pagination)
    global $pdo;
    $rows = [];
    if (!empty($pdo) && $pdo instanceof PDO) {
        $sql = 'SELECT ht.id, ht.track_date, ht.completed, ht.created_at, ht.updated_at FROM habit_tracking ht JOIN habits h ON ht.habit_id = h.id WHERE ht.habit_id = ? AND h.user_id = ? AND ht.track_date BETWEEN ? AND ? ORDER BY ht.track_date DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$habit_id, $user_id, $startStr, $endStr]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        // fallback: try helper (may not include created/updated timestamps)
        if (function_exists('getHabitHistory')) {
            $rows = getHabitHistory($habit_id, $user_id, $startStr, $endStr, 0, 0); // assume helper supports range
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="habit-' . $habit_id . '-history.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'track_date', 'completed', 'created_at', 'updated_at']);
    foreach ($rows as $r) {
        fputcsv($out, [ $r['id'], $r['track_date'], intval($r['completed']), $r['created_at'] ?? '', $r['updated_at'] ?? '' ]);
    }
    fclose($out);
    exit;
}

// main data fetch (with pagination)
$history = [];
$totalRows = 0;

global $pdo;
if (!empty($pdo) && $pdo instanceof PDO) {
    $countSql = "SELECT COUNT(*) FROM habit_tracking ht
                 JOIN habits h ON ht.habit_id = h.id
                 WHERE ht.habit_id = ? AND h.user_id = ? AND ht.track_date BETWEEN ? AND ?";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute([$habit_id, $user_id, $startStr, $endStr]);
    $totalRows = (int)$stmt->fetchColumn();

    $sql = "SELECT ht.id, ht.track_date, ht.completed, ht.created_at, ht.updated_at
            FROM habit_tracking ht
            JOIN habits h ON ht.habit_id = h.id
            WHERE ht.habit_id = ? AND h.user_id = ? AND ht.track_date BETWEEN ? AND ?
            ORDER BY ht.track_date DESC LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $habit_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $user_id, PDO::PARAM_INT);
    $stmt->bindValue(3, $startStr, PDO::PARAM_STR);
    $stmt->bindValue(4, $endStr, PDO::PARAM_STR);
    $stmt->bindValue(5, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(6, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    // try helper
    if (function_exists('getHabitHistory')) {
        $history = getHabitHistory($habit_id, $user_id, $startStr, $endStr, $perPage, $offset);
        $totalRows = count($history);
    }
}

// compute simple stats for display
$totalTracked = $totalRows;
$completedCount = 0;
foreach ($history as $r) { if (intval($r['completed']) === 1) $completedCount++; }
$eff = ($totalTracked>0) ? round(($completedCount/$totalTracked)*100) : 0;

// helper for escaping
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Habit history — <?php echo e($habit['title'] ?? 'Habit'); ?></title>
<style>
    body{font-family:Inter,system-ui,Arial,Helvetica,sans-serif;padding:18px;background:#f8fafc;color:#0f172a}
    .card{background:#fff;padding:14px;border-radius:10px;box-shadow:0 6px 18px rgba(2,6,23,0.06);margin-bottom:12px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:8px 10px;text-align:left;border-bottom:1px solid #eef2ff}
    .muted{color:#667085;font-size:13px}
    .btn{display:inline-block;padding:8px 12px;border-radius:8px;text-decoration:none;background:#6366f1;color:#fff}
    form{display:inline}
</style>
</head>
<body>
<div style="max-width:1000px;margin:0 auto">
    <header style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div>
            <h1 style="margin:0">Habit history — <?php echo e($habit['title']); ?></h1>
            <div class="muted">ID: <?php echo intval($habit['id']); ?> · Frequency: <?php echo e($habit['frequency'] ?? ($habit['recurrence'] ?? 'daily')); ?></div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a class="btn" href="dashboard.php">Back to dashboard</a>
            <a class="btn" href="habit_history.php?id=<?php echo $habit_id; ?>&export=csv&from=<?php echo e($startStr); ?>&to=<?php echo e($endStr); ?>">CSV export</a>
        </div>
    </header>

    <section class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <div>
                <div class="muted">Showing:</div>
                <div style="font-weight:700"><?php echo e($startStr); ?> — <?php echo e($endStr); ?></div>
                <div class="muted" style="margin-top:6px">Total rows: <?php echo $totalRows; ?> · Efficiency: <?php echo $eff; ?>%</div>
            </div>
            <form method="GET" action="habit_history.php" style="display:flex;gap:8px;align-items:center">
                <input type="hidden" name="id" value="<?php echo $habit_id; ?>">
                <label class="muted">From <input type="date" name="from" value="<?php echo e($startStr); ?>"></label>
                <label class="muted">To <input type="date" name="to" value="<?php echo e($endStr); ?>"></label>
                <button class="btn" type="submit">Filter</button>
            </form>
        </div>
    </section>

    <section class="card">
        <table role="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Completed</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                    <tr><td colspan="4" class="muted">No entries found for this period.</td></tr>
                <?php else: foreach ($history as $row): ?>
                    <tr>
                        <td><?php echo e($row['track_date']); ?></td>
                        <td><?php echo (intval($row['completed'])===1) ? '✅' : '—'; ?></td>
                        <td><?php echo e(($row['created_at'] ?? '') . (isset($row['updated_at']) ? ' / ' . $row['updated_at'] : '')); ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Toggle completed?');" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="entry_id" value="<?php echo intval($row['id']); ?>">
                                <button type="submit" class="btn" style="background:#10b981">Toggle</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Delete this entry?');" style="display:inline;margin-left:6px">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="entry_id" value="<?php echo intval($row['id']); ?>">
                                <button type="submit" class="btn" style="background:#ef4444">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($totalRows > $perPage):
            $pages = (int)ceil($totalRows / $perPage);
        ?>
            <div style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap">
                <?php for ($p=1;$p<=$pages;$p++): ?>
                    <a href="habit_history.php?id=<?php echo $habit_id; ?>&from=<?php echo e($startStr); ?>&to=<?php echo e($endStr); ?>&page=<?php echo $p; ?>" class="btn" style="background:<?php echo ($p===$page) ? '#0f172a' : '#6366f1'; ?>;"><?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

    </section>
</div>
</body>
</html>
