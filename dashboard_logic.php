<?php
// dashboard.php — centralized router + logic + view
declare(strict_types=1);

// mark view/logic protection
define('DASHBOARD_LOADED', true);

// session hardening
session_name('habit_sid');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();

// basic security headers (simple hardening)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-XSS-Protection: 1; mode=block');

// short helpers
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('json_ok')) {
    function json_ok($payload = []) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => true], (array)$payload));
        exit;
    }
}
if (!function_exists('json_err')) {
    function json_err(string $msg = 'error', int $code = 400, array $extra = []) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra));
        exit;
    }
}

// require app helpers (DB functions, habit functions, etc.)
require_once __DIR__ . '/functions/habit_functions.php';

// require your bootstrap/db if exists (optional)
if (file_exists(__DIR__ . '/bootstrap.php')) {
    require_once __DIR__ . '/bootstrap.php';
}

// auth check (redirect to login if not authenticated)
if (!isset($_SESSION['user_id'])) {
    // allow API to return 401 for XHR
    $isApiCall = isset($_GET['__api']) || (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === 0);
    if ($isApiCall) {
        json_err('Not authenticated', 401);
    }
    header('Location: auth/login.php');
    exit;
}

$user_id = intval($_SESSION['user_id']);
$today = date('Y-m-d');

// CSRF token init (used by both forms and JSON fetch)
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

/*
 * Simple session timeout (30 minutes)
 * keep it non-blocking; if expired redirect to login
 */
$session_timeout = 30 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_unset();
    session_destroy();
    if (isset($_GET['__api'])) {
        json_err('Session expired', 440);
    }
    header('Location: auth/login.php?expired=1');
    exit;
}
$_SESSION['last_activity'] = time();

// session fixation protection
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

/*
 * API routing
 * .htaccess rewrites /api/... -> /dashboard.php?__api=...
 * Use whitelist to avoid path traversal and remote include attacks.
 */
if (!empty($_GET['__api'])) {
    $raw = (string)$_GET['__api']; // e.g. "habit/complete.php" or "habit/complete"
    // normalize: remove leading/trailing slashes, backslashes
    $clean = str_replace('\\', '/', trim($raw, "/ \t\n\r\0\x0B"));

    // allow both with and without .php; normalize to .php
    if (substr($clean, -4) !== '.php') {
        $clean .= '.php';
    }

    // whitelist of allowed api endpoints (adjust as needed)
    $allowed = [
        'habit/complete.php',
        'habit/delete.php',
        'habit/bulk_complete.php',
        'weekly/toggle.php',
        'weekly/create.php',
        // add more endpoints here if you implement them in /api/
    ];

    if (!in_array($clean, $allowed, true)) {
        json_err('Not found', 404);
    }

    $apiPath = __DIR__ . '/api/' . $clean;
    if (!is_file($apiPath) || !is_readable($apiPath)) {
        json_err('Endpoint missing on server', 500);
    }

    // parse JSON body if present (modern fetch uses application/json)
    $rawBody = file_get_contents('php://input');
    $jsonBody = null;
    if (!empty($rawBody)) {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($ct, 'application/json')) {
            $jsonBody = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // merge into $_REQUEST for compatibility
                $_REQUEST = array_merge($_REQUEST, (array)$jsonBody);
                // also populate $_POST for endpoints expecting POST
                $_POST = array_merge($_POST, (array)$jsonBody);
            }
        }
    }

    // Basic CSRF validation for state-changing requests (POST/PUT/DELETE)
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['POST','PUT','DELETE','PATCH'], true)) {
        $incoming_csrf = $_REQUEST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!is_string($incoming_csrf) || !hash_equals($csrf_token, (string)$incoming_csrf)) {
            json_err('Invalid CSRF token', 403);
        }
    }

    // include API file inside isolated scope to avoid leaking variables
    try {
        // provide $user_id and helpers to the API scope
        $user_id_for_api = $user_id;
        // buffer output so we can ensure JSON response shape if needed
        ob_start();
        include $apiPath;
        $out = ob_get_clean();
        // if the included script already emitted JSON and exited, it won't return here.
        // Otherwise, try to forward output (assume the script echoed JSON or plain text)
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo $out;
    } catch (Throwable $ex) {
        error_log('API include error: ' . $ex->getMessage());
        json_err('Server error', 500);
    }
    exit;
}

/*
 * Legacy export route support (from .htaccess rewrite)
 * ?__export=1
 */
if (!empty($_GET['__export'])) {
    $exportPath = __DIR__ . '/export.php';
    if (!is_file($exportPath)) {
        header('Content-Type: text/plain; charset=utf-8', true, 500);
        echo 'Export handler not found';
        exit;
    }
    include $exportPath;
    exit;
}

/*
 * Handle legacy form POST (non-AJAX) for backward compatibility
 * e.g. forms with action=complete (submit form POST)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete') {
    // CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, (string)$_POST['csrf_token'])) {
        $_SESSION['flash_error'] = 'Invalid request';
        header('Location: ' . ($_SERVER['PHP_SELF'] ?? '/dashboard.php'));
        exit;
    }
    // throttle (simple)
    $last_mark = $_SESSION['last_mark_time'] ?? 0;
    if (time() - $last_mark < 1) {
        $_SESSION['flash_error'] = 'Too fast';
        header('Location: ' . ($_SERVER['PHP_SELF'] ?? '/dashboard.php'));
        exit;
    }
    $_SESSION['last_mark_time'] = time();

    $habit_id = (int)($_POST['habit_id'] ?? 0);
    $habit = getHabit($habit_id, $user_id);
    if (!$habit) {
        $_SESSION['flash_error'] = 'Habit not found';
        header('Location: ' . ($_SERVER['PHP_SELF'] ?? '/dashboard.php'));
        exit;
    }
    $ok = trackHabit($habit_id, date('Y-m-d'));
    $_SESSION['flash_' . ($ok ? 'success' : 'error')] = $ok ? 'Habit marked as completed' : 'Unable to track habit';
    header('Location: ' . ($_SERVER['PHP_SELF'] ?? '/dashboard.php'));
    exit;
}

/*
 * Main dashboard logic (data aggregation for view)
 * Use functions from functions/habit_functions.php:
 *   getHabits($user_id), getHabitProgress($user_id), getWeeklyTasks($user_id), ensureTrackingForToday($user_id)
 */

// ensure today's tracking rows exist (helper may create rows)
if (function_exists('ensureTrackingForToday')) {
    ensureTrackingForToday($user_id);
}

// fetch habits
try {
    $habits = getHabits($user_id);
    if (!is_array($habits)) $habits = [];
} catch (Throwable $ex) {
    error_log('getHabits error: ' . $ex->getMessage());
    $habits = [];
}

// fetch progress/time-series
try {
    $progress = getHabitProgress($user_id);
    if (!is_array($progress)) $progress = [];
} catch (Throwable $ex) {
    error_log('getHabitProgress error: ' . $ex->getMessage());
    $progress = [];
}

// build maps for today & date aggregates
$today = date('Y-m-d');
$todayMap = [];
$dateCompletedMap = [];
$datesSet = [];
foreach ($progress as $p) {
    $d = $p['track_date'] ?? null;
    if ($d) {
        $datesSet[$d] = true;
        if (isset($p['completed']) && intval($p['completed']) === 1) {
            $dateCompletedMap[$d] = ($dateCompletedMap[$d] ?? 0) + 1;
        }
    }
    if (!empty($p['track_date']) && ($p['track_date'] === $today) && isset($p['id'])) {
        $todayMap[intval($p['id'])] = intval($p['completed'] ?? 0);
    }
}

// normalize habits
foreach ($habits as $k => $h) {
    $hid = intval($h['id'] ?? 0);
    $habits[$k]['done_today'] = isset($todayMap[$hid]) ? (bool) intval($todayMap[$hid]) : false;
    $habits[$k]['streak'] = isset($h['streak']) ? intval($h['streak']) : 0;
    if (!isset($habits[$k]['title'])) $habits[$k]['title'] = 'Untitled';
    if (!isset($habits[$k]['description'])) $habits[$k]['description'] = '';
}

// sort not-done first for better UX
if (!empty($habits)) {
    usort($habits, function($a, $b) {
        $ad = isset($a['done_today']) ? intval($a['done_today']) : 0;
        $bd = isset($b['done_today']) ? intval($b['done_today']) : 0;
        if ($ad === $bd) {
            return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
        }
        return ($ad < $bd) ? -1 : 1;
    });
}

// stats
$totalHabits = count($habits);
$completedToday = 0;
foreach ($todayMap as $v) { if ($v) $completedToday++; }
$missedToday = max(0, $totalHabits - $completedToday);
$efficiency = ($totalHabits > 0) ? (int) round(($completedToday / $totalHabits) * 100) : 0;
$longestStreak = 0;
foreach ($habits as $h) {
    if (!empty($h['streak'])) {
        $s = intval($h['streak']);
        if ($s > $longestStreak) $longestStreak = $s;
    }
}

// time-series last 7 days + simple predict
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
        // fallback based on $progress array
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
} catch (Throwable $ex) {
    error_log('Dashboard error: ' . $ex->getMessage());
    $series = [];
    $chart_labels = [];
    $chart_values = [];
    $predicted = null;
    $predictedDate = null;
}

// weekly tasks
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

// pass variables to view
// pick improved view file if present
$viewCandidates = [
    __DIR__ . '/dashboard.php',
];

$viewPath = null;
foreach ($viewCandidates as $v) {
    if (is_file($v)) { $viewPath = $v; break; }
}
if (!$viewPath) {
    // fallback: simple inline minimal view to avoid white screen
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Dashboard</title></head><body><h1>Dashboard</h1><p>View template missing. Create <code>dashboard_view.improved.fixed.php</code> in project root.</p></body></html>';
    exit;
}

// expose variables to view (view will use them directly)
require $viewPath;
exit;
