<?php
// dashboard_logic.php — backend / logic part (updated)
// declare strict types
declare(strict_types=1);

// mark that view is included only from this logic file
define('DASHBOARD_LOADED', true);

session_name('habit_sid');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}

session_start();

// security headers (basic hardening)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-XSS-Protection: 1; mode=block');

// session timeout (30 minutes)
$session_timeout = 30 * 60; // 1800 seconds
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_unset();
    session_destroy();
    header("Location: auth/login.php?expired=1");
    exit;
}
$_SESSION['last_activity'] = time();

// protect against session fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// helpers
require_once __DIR__ . '/functions/habit_functions.php';

if (!function_exists('e')) {
    function e($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// require auth
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = intval($_SESSION['user_id']);
$today = date('Y-m-d');

// Ensure today's tracking rows exist (function from habit_functions.php)
if (function_exists('ensureTrackingForToday')) {
    ensureTrackingForToday($user_id);
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// POST: mark completed (legacy form support)
// Note: updated frontend prefers API endpoints; this keeps backward-compatibility.
if ($_SERVER['REQUEST_METHOD'] === 'POST' 
    && isset($_POST['action']) && $_POST['action'] === 'complete') {

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $_SESSION['flash_error'] = 'Invalid request';
        header('Location: ' . ($_SERVER['PHP_SELF'] ?? 'dashboard_logic.php'));
        exit;
    }

    // throttle (simple)
    $last_mark = $_SESSION['last_mark_time'] ?? 0;
    if (time() - $last_mark < 1) {
        $_SESSION['flash_error'] = 'Too fast';
        header('Location: ' . ($_SERVER['PHP_SELF'] ?? 'dashboard_logic.php'));
        exit;
    }
    $_SESSION['last_mark_time'] = time();

    $habit_id = (int)($_POST['habit_id'] ?? 0);
    $habit = getHabit($habit_id, $user_id);
    if (!$habit) {
        $_SESSION['flash_error'] = 'Habit not found';
        header('Location: ' . ($_SERVER['PHP_SELF'] ?? 'dashboard_logic.php'));
        exit;
    }

    $today = date('Y-m-d');
    $ok = trackHabit($habit_id, $today);
    $_SESSION['flash_' . ($ok ? 'success' : 'error')] = $ok ? 'Habit marked as completed' : 'Unable to track habit';
    header('Location: ' . ($_SERVER['PHP_SELF'] ?? 'dashboard_logic.php'));
    exit;
}

// fetch data (single retrieval)
$habits = [];
try {
    $habits = getHabits($user_id);
    if (!is_array($habits)) $habits = [];
} catch (Throwable $ex) {
    error_log('getHabits error: ' . $ex->getMessage());
    $habits = [];
}

$totalHabits = count($habits);
$progress = [];
try {
    $progress = getHabitProgress($user_id);
    if (!is_array($progress)) $progress = [];
} catch (Throwable $ex) {
    error_log('getHabitProgress error: ' . $ex->getMessage());
    $progress = [];
}

// build maps
$today = date('Y-m-d');
$todayMap = [];
$dateCompletedMap = [];
$datesSet = [];
foreach ($progress as $p) {
    if (!empty($p['track_date'])) {
        $d = $p['track_date'];
        $datesSet[$d] = true;
        if (isset($p['completed']) && intval($p['completed']) === 1) {
            $dateCompletedMap[$d] = ($dateCompletedMap[$d] ?? 0) + 1;
        }
    }
    if (!empty($p['track_date']) && ($p['track_date'] === $today) && isset($p['id'])) {
        $todayMap[intval($p['id'])] = intval($p['completed'] ?? 0);
    }
}

// Ensure each habit has done_today key and numeric streak
foreach ($habits as $k => $h) {
    $hid = intval($h['id'] ?? 0);
    $habits[$k]['done_today'] = isset($todayMap[$hid]) ? (bool) intval($todayMap[$hid]) : false;
    $habits[$k]['streak'] = isset($h['streak']) ? intval($h['streak']) : 0;
    // sanitize title/description presence for view (view will still escape)
    if (!isset($habits[$k]['title'])) $habits[$k]['title'] = 'Untitled';
    if (!isset($habits[$k]['description'])) $habits[$k]['description'] = '';
}

// sort so not-completed appear first
if (!empty($habits)) {
    usort($habits, function($a, $b) {
        $ad = isset($a['done_today']) ? intval($a['done_today']) : 0;
        $bd = isset($b['done_today']) ? intval($b['done_today']) : 0;
        if ($ad === $bd) {
            return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
        }
        // put not-done (0) before done (1)
        return ($ad < $bd) ? -1 : 1;
    });
}

// stats
$completedToday = 0;
foreach ($todayMap as $val) { if ($val) $completedToday++; }
$missedToday = max(0, $totalHabits - $completedToday);
$efficiency = ($totalHabits > 0) ? (int) round(($completedToday / $totalHabits) * 100) : 0;
$longestStreak = 0;
foreach ($habits as $h) {
    if (!empty($h['streak'])) {
        $s = intval($h['streak']);
        if ($s > $longestStreak) $longestStreak = $s;
    }
}

/* Time-series last 7 days */
$endDate = new DateTimeImmutable('now');
$startDate = $endDate->sub(new DateInterval('P6D'));
$datesRange = [];
$period = new DatePeriod($startDate, new DateInterval('P1D'), $endDate->add(new DateInterval('P1D')));
foreach ($period as $d) {
    $datesRange[] = $d->format('Y-m-d');
}

$series = [];
$chart_labels = [];
$chart_values = [];
$predicted = null;
$predictedDate = null;

try {
    // if global $pdo is set and is PDO, use the optimized query
    global $pdo;
    if (!empty($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare("
            SELECT 
                ht.track_date AS track_date,
                ROUND(AVG(ht.completed) * 100, 2) AS efficiency,
                COUNT(ht.id) AS total_tracked,
                SUM(ht.completed) AS completed_count
            FROM habit_tracking ht
            JOIN habits h ON h.id = ht.habit_id
            WHERE h.user_id = ?
              AND ht.track_date BETWEEN ? AND ?
            GROUP BY ht.track_date
            ORDER BY ht.track_date ASC
        ");
        $startStr = $startDate->format('Y-m-d');
        $endStr = $endDate->format('Y-m-d');
        $stmt->execute([$user_id, $startStr, $endStr]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $r) {
            $map[$r['track_date']] = [
                'efficiency' => isset($r['efficiency']) ? floatval($r['efficiency']) : 0.0,
                'total' => intval($r['total_tracked']),
                'completed' => intval($r['completed_count'])
            ];
        }

        foreach ($datesRange as $d) {
            if (isset($map[$d])) {
                $val = $map[$d]['efficiency'];
                $val = max(0.0, min(100.0, $val));
                $series[] = ['date' => $d, 'value' => round($val, 2), 'total' => $map[$d]['total'], 'completed' => $map[$d]['completed']];
            } else {
                $series[] = ['date' => $d, 'value' => 0.0, 'total' => 0, 'completed' => 0];
            }
        }

        $chart_labels = array_map(fn($i) => $i['date'], $series);
        $chart_values = array_map(fn($i) => $i['value'], $series);

        // simple linear predict (avg delta)
        $n = count($chart_values);
        if ($n >= 2) {
            $deltas = [];
            for ($i = 1; $i < $n; $i++) {
                $deltas[] = $chart_values[$i] - $chart_values[$i-1];
            }
            $avgDelta = array_sum($deltas) / max(1, count($deltas));
            $lastVal = floatval($chart_values[$n-1]);
            $pred = $lastVal + $avgDelta;
            $predicted = max(0.0, min(100.0, round($pred, 2)));
        } elseif ($n === 1) {
            $predicted = max(0.0, min(100.0, round(floatval($chart_values[0]), 2)));
        } else {
            $predicted = null;
        }

        if (!is_null($predicted)) {
            if (count($chart_labels) > 0) {
                $lastLabel = end($chart_labels);
                $lastDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $lastLabel) ?: $endDate;
                $predictedDate = $lastDateObj->add(new DateInterval('P1D'))->format('Y-m-d');
            } else {
                $predictedDate = $endDate->add(new DateInterval('P1D'))->format('Y-m-d');
            }
        } else {
            $predictedDate = null;
        }
    } else {
        // fallback to $progress if no PDO
        foreach ($datesRange as $d) {
            $total = 0; $completed = 0;
            foreach ($progress as $p) {
                if (($p['track_date'] ?? '') === $d) {
                    $total++;
                    if (intval($p['completed'] ?? 0) === 1) $completed++;
                }
            }
            $val = ($total > 0) ? round(($completed / $total) * 100, 2) : 0.0;
            $series[] = ['date'=>$d,'value'=>$val,'total'=>$total,'completed'=>$completed];
        }
        $chart_labels = array_map(fn($i)=>$i['date'],$series);
        $chart_values = array_map(fn($i)=>$i['value'],$series);
        $predicted = null;
        $predictedDate = null;
    }
} catch (Exception $ex) {
    error_log('Dashboard error: ' . $ex->getMessage());
    // graceful fallback
    $series = [];
    $chart_labels = [];
    $chart_values = [];
    $predicted = null;
    $predictedDate = null;
}

// Weekly tasks (try to load if helper exists)
$weeklyTasks = [];
if (function_exists('getWeeklyTasks')) {
    try {
        $weeklyTasks = getWeeklyTasks($user_id);
        if (!is_array($weeklyTasks)) $weeklyTasks = [];
    } catch (Throwable $ex) {
        error_log('getWeeklyTasks error: ' . $ex->getMessage());
        $weeklyTasks = [];
    }
}

// Provide $today string for view
$today = date('Y-m-d');

// Provide $csrf_token already above

// Now include the updated view
$viewPath = __DIR__ . '/dashboard_logic.php';
if (!file_exists($viewPath)) {
    // fallback to original view name if updated file not present
    $viewPath = __DIR__ . '/dashboard_view.php';
}

require $viewPath;
exit;
