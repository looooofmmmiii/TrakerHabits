<?php declare(strict_types=1);
/**
 * dashboard.php — full fixed version
 * - supports JSON reorder (SortableJS) and normal form POST for marking complete
 * - cleans up HTML structure, fixes duplicated blocks
 */

session_name('habit_sid');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-XSS-Protection: 1; mode=block');

require_once 'functions/habit_functions.php';
require_once 'config/db.php'; // ensure $pdo

// Session timeout (seconds)
$session_timeout = 180000000;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_unset();
    session_destroy();
    header("Location: auth/login.php?expired=1");
    exit;
}
$_SESSION['last_activity'] = time();

// Protect against session fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// helper: ensure iterable to avoid foreach warnings (safe fallback)
if (!function_exists('ensure_iterable')) {
    function ensure_iterable(&$v) {
        if (!is_iterable($v)) $v = [];
    }
}

// Escape helper — safe wrapper around htmlspecialchars
if (!function_exists('e')) {
    function e($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];

$today = date('Y-m-d');

// Simple CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

/*
 * Handle JSON reorder POST to this file.
 * Expecting application/json body: { order: [id1, id2, ...], csrf_token: '...' }
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false
) {
    header('Content-Type: application/json; charset=utf-8');

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    $token = (string)($payload['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $order = $payload['order'] ?? null;
    if (!is_array($order)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid order array']);
        exit;
    }

    // sanitize -> ints, remove zeros/duplicates while preserving order
    $clean = [];
    foreach ($order as $v) {
        $id = intval($v);
        if ($id > 0 && !in_array($id, $clean, true)) $clean[] = $id;
    }

    if (count($clean) === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Empty order']);
        exit;
    }

    try {
        // fetch all user's habit ids (canonical set)
        $stmt = $pdo->prepare('SELECT id FROM habits WHERE user_id = :uid ORDER BY COALESCE(sort_order, 0) ASC, id ASC');
        $stmt->execute([':uid' => $user_id]);
        $existing = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        // keep only ids that actually belong to this user (ignore malicious ids)
        $clean = array_values(array_filter($clean, function($id) use ($existing) {
            return in_array($id, $existing, true);
        }));

        // final order = client-sent IDs first (in that order), then any remaining habits preserved
        $final = $clean;
        foreach ($existing as $eid) {
            if (!in_array($eid, $final, true)) $final[] = $eid;
        }

        // if nothing changed, return current canonical order
        $same = true;
        if (count($final) === count($existing)) {
            for ($i = 0; $i < count($final); $i++) {
                if ($final[$i] !== $existing[$i]) { $same = false; break; }
            }
        } else {
            $same = false;
        }
        if ($same) {
            echo json_encode(['ok' => true, 'order' => $existing]);
            exit;
        }

        // perform a single UPDATE with CASE WHEN ... THEN ... END for atomicity & efficiency
        $cases = [];
        $params = [];
        foreach ($final as $i => $hid) {
            $pos = $i + 1; // positions start at 1
            $cases[] = "WHEN id = ? THEN ?";
            $params[] = $hid;
            $params[] = $pos;
        }
        $sql = "UPDATE habits SET sort_order = CASE " . implode(" ", $cases) . " ELSE sort_order END WHERE user_id = ?";
        $params[] = $user_id;

        $pdo->beginTransaction();
        $upd = $pdo->prepare($sql);
        $upd->execute($params);

        // After update, fetch canonical order from DB to return to client
        $stmt2 = $pdo->prepare('SELECT id FROM habits WHERE user_id = :uid ORDER BY COALESCE(sort_order, 0) ASC, id ASC');
        $stmt2->execute([':uid' => $user_id]);
        $canonical = updateSortOrderForIncomplete($clean, $user_id, date('Y-m-d'));

        $pdo->commit();

        echo json_encode(['ok' => true, 'order' => $canonical]);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('reorder error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Server error']);
        exit;
    }
}

/*
 * Handle normal form POST for marking a habit complete
 */
// тимчасово дозвольте debug (видаліть в продакшн)
if (!defined('APP_DEBUG')) define('APP_DEBUG', true);

/*
 * JSON reorder handler (SortableJS)
 * Працює так:
 *  - перевіряє CSRF
 *  - приймає {"order":[1,2,3], "csrf_token":"..."}
 *  - обновляє sort_order ТІЛЬКИ для незавершених сьогодні habit'ів
 *  - повертає canonical order (усі id) або детальну помилку в APP_DEBUG
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // detect JSON request (we assume reorder uses application/json)
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        // read payload
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
            exit;
        }

        // CSRF
        $token = (string)($payload['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        // order must be an array
        $order = $payload['order'] ?? null;
        if (!is_array($order)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid order array']);
            exit;
        }

        // sanitize -> ints, remove zeros/duplicates while preserving order
        $clean = [];
        foreach ($order as $v) {
            $id = intval($v);
            if ($id > 0 && !in_array($id, $clean, true)) $clean[] = $id;
        }
        if (count($clean) === 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Empty order']);
            exit;
        }

        // Ensure helper exists (you may have this helper from earlier suggestion)
        if (!function_exists('updateSortOrderForIncomplete') || !function_exists('getCompletedHabitIdsOnDate')) {
            // Optional: define fallback simple implementation or fail with descriptive error
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Server helper missing: updateSortOrderForIncomplete']);
            error_log('reorder error: missing helper functions updateSortOrderForIncomplete/getCompletedHabitIdsOnDate');
            exit;
        }

        try {
            // perform update — only incomplete items will be updated
            $canonical = updateSortOrderForIncomplete($clean, $user_id, date('Y-m-d'));

            // success
            echo json_encode(['ok' => true, 'order' => $canonical]);
            exit;
        } catch (Throwable $e) {
            // log details
            error_log('reorder exception: ' . $e->getMessage());
            error_log($e->getTraceAsString());

            http_response_code(500);
            if (defined('APP_DEBUG') && APP_DEBUG) {
                echo json_encode(['ok' => false, 'error' => 'Server error', 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Server error']);
            }
            exit;
        }
    }
    // else: not JSON request — allow other POST handlers below (e.g. form POST "complete")
}



/*
 * Obtain dashboard data
 */

// --- Handle regular form POSTs (not JSON) e.g. marking habit complete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    // simple router for form actions
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'complete') {
        // CSRF check
        $token = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            // do not expose details in prod
            $_SESSION['flash_error'] = 'Invalid CSRF token';
            header('Location: dashboard.php');
            exit;
        }

        $habit_id = intval($_POST['habit_id'] ?? 0);
        if ($habit_id <= 0) {
            $_SESSION['flash_error'] = 'Invalid habit id';
            header('Location: dashboard.php');
            exit;
        }

        // verify ownership
        $stmt = $pdo->prepare('SELECT id FROM habits WHERE id = ? AND user_id = ?');
        $stmt->execute([$habit_id, $user_id]);
        $exists = $stmt->fetchColumn();
        if (!$exists) {
            $_SESSION['flash_error'] = 'Habit not found or not yours';
            header('Location: dashboard.php');
            exit;
        }

        // perform track (uses your trackHabit helper)
        try {
            $ok = trackHabit($habit_id, $today); // $today already defined above as date('Y-m-d')
            if ($ok) {
                $_SESSION['flash_success'] = 'Marked completed';
            } else {
                $_SESSION['flash_error'] = 'Could not mark habit as completed';
            }
        } catch (Throwable $e) {
            error_log('mark complete error: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Server error';
        }

        // Redirect to avoid double-submit and to show updated state
        header('Location: dashboard.php');
        exit;
    }

    // other non-JSON form actions can be handled here (add more if needed)
}

$data = [];
try {
    if (function_exists('getDashboardData')) {
        $data = getDashboardData($user_id, $pdo);
        if (!is_array($data)) $data = [];
    } else {
        ensureTrackingForToday($user_id);
        $habits = getHabits($user_id); // getHabits should ideally order by sort_order
        ensure_iterable($habits);

        // Normalize sort_order and ensure stable ordering: missing sort_order -> large value
        foreach ($habits as &$__h) {
            if (!isset($__h['sort_order']) || $__h['sort_order'] === null || $__h['sort_order'] === '') {
                $__h['sort_order'] = PHP_INT_MAX;
            } else {
                $__h['sort_order'] = intval($__h['sort_order']);
            }
            $__h['id'] = intval($__h['id'] ?? 0);
        }
        unset($__h);

        usort($habits, function($a, $b){
            if ($a['sort_order'] === $b['sort_order']) return $a['id'] <=> $b['id'];
            return $a['sort_order'] <=> $b['sort_order'];
        });

        $progress = getHabitProgress($user_id);
        ensure_iterable($progress);

        $data = [
            'habits' => $habits,
            'progress' => $progress,
            'displayHabits' => $habits,
            'completedDatesByHabit' => [],
            'habitDoneMap' => [],
            'habitNextAvailableMap' => [],
            'completedToday' => 0,
            'totalHabits' => count($habits),
            'missedToday' => count($habits),
            'efficiency' => 0,
            'habitStreakMap' => [],
            'longestStreak' => 0,
            'chart' => ['labels' => [], 'values' => [], 'series' => [], 'predicted' => null, 'predictedDate' => null]
        ];
    }
} catch (Throwable $ex) {
    error_log('getDashboardData error: ' . $ex->getMessage());
    $data = [];
}

// map dashboard data -> local variables (safe fallbacks)
$habits = $data['habits'] ?? [];
ensure_iterable($habits);
$displayHabits = $data['displayHabits'] ?? $habits;
ensure_iterable($displayHabits);
$progress = $data['progress'] ?? [];
ensure_iterable($progress);
$completedDatesByHabit = $data['completedDatesByHabit'] ?? [];
$habitDoneMap = $data['habitDoneMap'] ?? [];
$habitNextAvailableMap = $data['habitNextAvailableMap'] ?? [];
$completedToday = isset($data['completedToday']) ? intval($data['completedToday']) : 0;
$totalHabits = isset($data['totalHabits']) ? intval($data['totalHabits']) : count($habits);
$missedToday = isset($data['missedToday']) ? intval($data['missedToday']) : max(0, $totalHabits - $completedToday);
$efficiency = isset($data['efficiency']) ? intval($data['efficiency']) : 0;
$habitStreakMap = $data['habitStreakMap'] ?? [];
$longestStreak = isset($data['longestStreak']) ? intval($data['longestStreak']) : 0;

$chart_labels = $data['chart']['labels'] ?? [];
$chart_values = $data['chart']['values'] ?? [];
$series = $data['chart']['series'] ?? [];
$predicted = $data['chart']['predicted'] ?? null;
$predictedDate = $data['chart']['predictedDate'] ?? null;

if (!is_array($habitStreakMap)) $habitStreakMap = [];
$currentBestStreak = 0;
foreach ($habitStreakMap as $hs) {
    $c = intval($hs['current'] ?? 0);
    if ($c > $currentBestStreak) $currentBestStreak = $c;
}

// Build incompleteHabits for roulette
$incompleteHabits = [];
foreach ($habits as $h) {
    $hid = intval($h['id'] ?? 0);
    if ($hid <= 0) continue;
    $done = isset($habitDoneMap[$hid]) ? intval($habitDoneMap[$hid]) : 0;
    if ($done === 0) {
        $incompleteHabits[] = [
            'id' => $hid,
            'title' => $h['title'] ?? '',
            'description' => $h['description'] ?? ''
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/styles/dashboard.css">
<title>Dashboard — Habits</title>

<style>
:root{--bg:#f5f7fb;--card:#fff;--muted:#667085;--shadow:0 6px 18px rgba(16,24,40,0.06)}
html,body{height:100%}
body{font-family:Inter,system-ui,Arial,Helvetica,sans-serif;margin:0;padding:24px;background:var(--bg);color:#0f172a;line-height:1.35}
.container{max-width:1100px;margin:0 auto}
.top-stats{display:flex;gap:16px;margin:18px 0 22px;flex-wrap:wrap}
.stat{background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(250,250,255,0.92));padding: 14px;border-radius: 12px;box-shadow: 0 10px 30px rgba(16,24,40,0.06);flex: 1;min-width: 140px;border:1px solid rgba(15,23,42,0.04);position:relative;overflow:hidden;transition:transform .18s ease,box-shadow .18s ease}
.stat[data-ribbon]::after{content: attr(data-ribbon);position:absolute;top:12px;right:12px;font-size:11px;padding:6px 10px;border-radius:999px;color:#fff;font-weight:700;background:linear-gradient(90deg,#6366f1,#06b6d4);box-shadow:0 8px 20px rgba(99,102,241,0.12)}
.stat:hover{transform:translateY(-6px);box-shadow:0 22px 60px rgba(16,24,40,0.12)}
.progress-wrap{background:var(--card);padding:14px;border-radius:12px;box-shadow:var(--shadow);margin-bottom:14px}
.progress-label{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.progress-bar-outer{background:#eef2ff;border-radius:999px;height:18px;overflow:hidden}
.progress-bar{height:100%;width:0;border-radius:999px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:12px;transition:width .6s cubic-bezier(.2,.9,.2,1)}
.chart-card{background:var(--card);padding:12px;border-radius:12px;box-shadow:var(--shadow);margin-bottom:20px}
.chart-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
.habit-card{background:var(--card);padding:14px;border-radius:12px;box-shadow:var(--shadow);cursor:pointer;outline:none;position:relative;padding-right:64px;transition:transform .12s ease,box-shadow .12s ease}
.habit-card:focus{box-shadow:0 8px 30px rgba(2,6,23,0.08);transform:translateY(-2px)}
.title-row{display:flex;align-items:flex-start;gap:12px}
.habit-card h4{margin:0;font-size:1rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.habit-card .meta-right{margin-left:auto;text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:6px}
.drag-handle{position:absolute;top:12px;right:12px;display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:8px;background:#f8f9fa;border:1px solid #ddd;font-size:16px;cursor:grab;z-index:5}
.drag-handle:hover{background:#e9ecef;color:#222;border-color:#bbb}
.drag-handle:active{cursor:grabbing;transform:scale(.98)}
.habit-desc .desc-text{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;text-overflow:ellipsis;max-height:3.6em;line-height:1.2em}
.habit-desc.expanded .desc-text{-webkit-line-clamp:none;max-height:none;overflow:visible}
.desc-toggle{display:inline-block;margin-top:6px;background:none;border:none;color:#0369a1;cursor:pointer;padding:0;font-weight:700;font-size:13px}
.habit-details{margin-top:10px;padding-top:10px;border-top:1px dashed #eef2ff;display:none}
.habit-details[aria-hidden="false"]{display:block}
.habit-actions a{margin-right:8px;text-decoration:none}
.muted{color:var(--muted);font-size:13px}
.modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,0.6);display:none;align-items:center;justify-content:center;z-index:9999}
.modal{background:var(--card);padding:18px;border-radius:12px;box-shadow:0 10px 40px rgba(2,6,23,0.3);max-width:520px;width:94%;text-align:center}
.wheel{width:320px;height:320px;border-radius:50%;margin:0 auto;position:relative;overflow:visible;border:8px solid rgba(255,255,255,0.85)}
.pointer{position:absolute;left:50%;top:8px;transform:translateX(-50%);width:0;height:0;border-left:12px solid transparent;border-right:12px solid transparent;border-bottom:18px solid #111}
.spin-btn{display:inline-block;margin-top:12px;background:linear-gradient(90deg,#ef4444,#f97316);color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none;cursor:pointer}
@media (max-width:640px){.top-stats{flex-direction:column}.habit-card{padding-right:52px}.drag-handle{top:10px;right:8px;padding:6px}}
</style>
</head>
<body>
    <?php include 'notification.php'; ?>

    <?php include "elements.php"; ?>  <!-- sidebar (assumed) -->
    

    <div class="main-content">
        <div class="container">
            <header style="display:flex;justify-content:space-between;align-items:center">
                <div>
                    <h1 style="margin:0">Dashboard — Habits & Progress</h1>
                    <div class="muted" style="margin-top:6px">Today: <?php echo e($today); ?></div>
                </div>
            </header>

            <section class="top-stats" role="region" aria-label="Top statistics">
                <div class="stat">
                    <h3>Total Habits</h3>
                    <p><?php echo intval($totalHabits); ?></p>
                    <div class="muted" style="margin-top:6px">Active goals to manage</div>
                </div>

                <div class="stat">
                    <h3>Completed Today</h3>
                    <p><?php echo intval($completedToday); ?></p>
                    <div class="muted" style="margin-top:6px"><?php echo intval($completedToday); ?> / <?php echo intval($totalHabits); ?> tasks</div>
                </div>

                <div class="stat">
                    <h3>Missed Today</h3>
                    <p><?php echo intval($missedToday); ?></p>
                    <div class="muted" style="margin-top:6px">Opportunity to improve</div>
                </div>

                <div class="stat">
                    <h3>Day Efficiency</h3>
                    <p><?php echo intval($efficiency); ?>%</p>
                    <div class="muted" style="margin-top:6px">
                        <?php if ($efficiency === 100 && $totalHabits>0): ?>
                            <span style="display:inline-block;padding:6px 10px;border-radius:999px;background:#eef2ff;font-weight:700">All done — Great job!</span>
                        <?php else: ?>
                            Keep it sustainable — <?php echo intval($efficiency); ?>% complete
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stat">
                    <h3>Best Streak</h3>
                    <p><?php echo intval($longestStreak); ?> days</p>
                    <div class="muted">Longest streak across habits</div>
                </div>
            </section>

            <section class="progress-wrap" aria-label="Daily progress">
                <div class="progress-label">
                    <div class="muted">Daily progress</div>
                    <div style="font-weight:700"><?php echo intval($efficiency); ?>%</div>
                </div>
                <div class="progress-bar-outer" aria-hidden="true">
                    <?php
                        if ($efficiency >= 75) $grad = "linear-gradient(90deg,#34d399,#10b981)";
                        elseif ($efficiency >= 40) $grad = "linear-gradient(90deg,#f59e0b,#f97316)";
                        else $grad = "linear-gradient(90deg,#ef4444,#f43f5e)";
                    ?>
                    <div id="progressBar"
                        class="progress-bar"
                        role="progressbar"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="<?php echo intval($efficiency); ?>"
                        style="background: <?php echo $grad; ?>; width: <?php echo intval($efficiency); ?>%;">
                    </div>
                </div>
            </section>

            <section class="chart-card" aria-label="Efficiency by day">
                <div class="chart-title">
                    <div><strong>Efficiency by day</strong></div>
                    <div class="legend">Shows last 7 days. Dashed extension is predicted next day.</div>
                </div>
                <div id="miniChart" class="chart-svg" aria-hidden="false"></div>
            </section>

            <!-- Habits grid -->
            <main aria-label="Habits list">
                <?php if (empty($displayHabits)): ?>
                    <div style="background:var(--card);padding:16px;border-radius:12px;box-shadow:var(--shadow)">No habits found. Go to <a href="habits.php">Manage Habits</a> to add one.</div>
                <?php else: ?>
                    <div id="habitsGrid" class="grid" role="list">
                        <?php foreach ($displayHabits as $habit):
                            $hid = intval($habit['id']);
                            $isDoneToday = isset($habitDoneMap[$hid]) && $habitDoneMap[$hid] == 1;
                            $nextInfo = $habitNextAvailableMap[$hid] ?? ['date' => $today, 'label' => 'Now available'];
                            $nextDate = $nextInfo['date'];
                            $nextLabel = $nextInfo['label'];
                        ?>
                       <article class="habit-card"
                            role="article"
                            tabindex="0"
                            aria-labelledby="habit-title-<?php echo $hid; ?>"
                            data-hid="<?php echo $hid; ?>"
                            data-id="<?php echo $hid; ?>"
                            data-done="<?php echo $isDoneToday ? '1' : '0'; ?>"
                            aria-expanded="false"
                            role="listitem">

                            <!-- drag handle (visual/top-right) -->
                            <div style="display:flex;flex-direction:column;align-items:center;gap:2px">
                                <button type="button" class="drag-handle"
                                        aria-label="Drag to reorder"
                                        title="Hold & drag to reorder"
                                        onmousedown="event.stopPropagation();"
                                        onclick="event.stopPropagation();">☰</button>

                                <?php
                                    $ndObj = DateTimeImmutable::createFromFormat('Y-m-d', $nextDate);
                                    if ($ndObj > new DateTimeImmutable($today)) {
                                        echo '<div class="muted" style="font-size:12px;font-weight:600">';
                                            
                                        echo '</div>';
                                    }
                                ?>
                            </div>

                            <div class="title-row">
                                <div style="flex:0 1 auto;min-width:0">
                                    <h4 id="habit-title-<?php echo $hid; ?>"><?php echo e($habit['title']); ?></h4>

                                    <?php if (!empty($habit['description'])): ?>
                                        <div class="muted habit-desc" id="habit-desc-<?php echo $hid; ?>">
                                            <div class="desc-text" id="desc-full-<?php echo $hid; ?>"><?php echo function_exists('linkify') ? linkify($habit['description']) : e($habit['description']); ?></div>
                                            <button type="button" class="desc-toggle" aria-expanded="false" aria-controls="desc-full-<?php echo $hid; ?>" onclick="event.stopPropagation(); toggleDesc(<?php echo $hid; ?>);">Show more</button>
                                        </div>
                                    <?php else: ?>
                                        <div class="muted">No description provided.</div>
                                    <?php endif; ?>
                                </div>

                                <div class="meta-right" aria-hidden="false">
                                    <div style="margin-top:6px;font-weight:700">
                                        <?php
                                            $ndObj = DateTimeImmutable::createFromFormat('Y-m-d', $nextDate);
                                            if ($ndObj > new DateTimeImmutable($today)) {
                                                // Виводимо тільки місяць і день
                                                echo e($ndObj->format('m-d')); // приклад: 09-02
                                                // або echo e($ndObj->format('d.m')); // приклад: 02.09
                                                // або echo e($ndObj->format('d F')); // приклад: 02 September
                                            }
                                            ?>
                                    </div>
                                </div>
                            </div>

                            <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
                                <?php if ($isDoneToday): ?>
                                    <div style="background:#ecfdf5;color:#065f46;padding:6px 10px;border-radius:999px;font-weight:600" aria-live="polite">✅ Completed</div>
                                <?php else: ?>
                                    <form method="POST" style="margin:0" onclick="event.stopPropagation();">
                                        <input type="hidden" name="action" value="complete">
                                        <input type="hidden" name="habit_id" value="<?php echo $hid; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <button type="submit"
                                                style="background:linear-gradient(90deg,#10b981,#059669);border:none;color:#fff;padding:8px 12px;border-radius:8px;cursor:pointer;font-weight:700"
                                                aria-label="Mark habit completed" onclick="event.stopPropagation();">
                                            Mark Completed
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!empty($habit['streak'])): ?>
                                    <div class="muted">Streak: <strong><?php echo intval($habit['streak']); ?></strong></div>
                                <?php endif; ?>
                            </div>

                            <!-- collapsible details / menu (hidden by default) -->
                            <div id="details-<?php echo $hid; ?>" class="habit-details" aria-hidden="true">
                                <?php if (!empty($habit['description'])): ?>
                                    <div class="muted"><?php echo e($habit['description']); ?></div>
                                <?php else: ?>
                                    <div class="muted">No description provided.</div>
                                <?php endif; ?>

                                <div class="habit-actions" style="margin-top:8px">
                                    <a href="habit_history.php?id=<?php echo $hid; ?>" onclick="event.stopPropagation();">View history</a>
                                    <a href="habits.php?edit=<?php echo $hid; ?>" onclick="event.stopPropagation();">Edit</a>
                                    <a href="habits.php?delete=<?php echo $hid; ?>" onclick="event.stopPropagation(); return confirm('Delete this habit?');">Delete</a>
                                </div>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>

        </div> <!-- .container -->
    </div> <!-- .main-content -->

    <div id="rouletteModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="modal" role="document">
            <h2 style="margin-top:0">Roulette — pick a task</h2>
            <div class="pointer" aria-hidden="true"></div>
            <div id="wheel" class="wheel" aria-hidden="false"></div>
            <div class="wheel-label" id="wheelLabel">Press Spin</div>
            <div style="margin-top:10px">
                <button id="spinBtn" class="spin-btn">Spin</button>
                <a id="goToTask" class="btn" style="margin-left:8px;display:none" href="#">Go to task</a>
                <button id="closeModal" class="btn" style="margin-left:8px;">Close</button>
            </div>
        </div>
    </div>

    <!-- lightweight flash container -->
    <div id="flash" aria-live="polite" style="position:fixed;right:18px;bottom:18px;z-index:99999"></div>

    <!-- Sortable (CDN) -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js" defer></script>

    <script>
      window.__DASHBOARD_CONFIG__ = {
        csrf: <?php echo json_encode($csrf_token); ?>,
        chart_labels: <?php echo json_encode($chart_labels); ?>,
        chart_values: <?php echo json_encode($chart_values); ?>,
        predicted: <?php echo json_encode($predicted); ?>,
        incomplete: <?php echo json_encode(array_values($incompleteHabits), JSON_UNESCAPED_UNICODE); ?>,
        flash_success: <?php echo json_encode($_SESSION['flash_success'] ?? null); ?>,
        flash_error: <?php echo json_encode($_SESSION['flash_error'] ?? null); ?>
      };
    </script>
    <?php unset($_SESSION['flash_success'], $_SESSION['flash_error']); ?>

    <script src="assets/scripts/dashboard.js" defer></script>
</body>
</html>
