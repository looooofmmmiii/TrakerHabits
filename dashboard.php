<?php
declare(strict_types=1);
session_name('habit_sid');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax'); // adjust if you need 'Strict'
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}

session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-XSS-Protection: 1; mode=block');

// Session timeout (30 minutes)
$session_timeout = 180000000; // seconds

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    // expire session gracefully
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

// Require helper functions (assumed to exist)
require_once 'functions/habit_functions.php';

// helper: ensure iterable to avoid foreach warnings
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

$user_id = intval($_SESSION['user_id']);

$today = date('Y-m-d');
ensureTrackingForToday($user_id);

// Simple CSRF token (rotate token on new session initiation)
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// POST: mark habit complete (double-submit cookie protected by token)
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'complete') {

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash_error'] = 'Invalid request';
        header('Location: dashboard.php'); exit;
    }

    // simple rate-limit: prevent same action repeated too fast
    $last_mark = $_SESSION['last_mark_time'] ?? 0;
    if (time() - $last_mark < 1) { // 1 second throttle
        $_SESSION['flash_error'] = 'Too fast';
        header('Location: dashboard.php'); exit;
    }
    $_SESSION['last_mark_time'] = time();

    $habit_id = (int)($_POST['habit_id'] ?? 0);
    $habit = getHabit($habit_id, $user_id);
    if (!$habit) {
        $_SESSION['flash_error'] = 'Habit not found';
        header('Location: dashboard.php'); exit;
    }

    $today = date('Y-m-d');
    $ok = trackHabit($habit_id, $today);
    $_SESSION['flash_' . ($ok ? 'success' : 'error')] = $ok ? 'Habit marked as completed' : 'Unable to track habit';
    header('Location: dashboard.php'); exit;
}

// Fetch user's habits once (assume getHabits uses prepared statements)
$habits = getHabits($user_id);
ensure_iterable($habits);
$totalHabits = count($habits);

// Get all tracking progress (flat rows) — used for today's map and for streaks summary
$progress = getHabitProgress($user_id); // rows: id, habit_id, title, track_date, completed
ensure_iterable($progress);

// Build today's map and distinct dates counts
$today = date('Y-m-d');
$completedDatesByHabit = []; // habit_id => [ '2025-08-25' => true, ... ]
$dateCompletedMap = [];      // date => count of completions (same as before)
$datesSet = [];

// Populate completedDatesByHabit from $progress (be tolerant to keys: habit_id or id)
foreach ($progress as $p) {
    // robust habit id resolution
    $hid = isset($p['habit_id']) ? intval($p['habit_id']) : (isset($p['id']) ? intval($p['id']) : 0);
    if ($hid <= 0) continue;

    if (!empty($p['track_date'])) {
        $d = $p['track_date'];
        $datesSet[$d] = true;
        if (intval($p['completed']) === 1) {
            $dateCompletedMap[$d] = ($dateCompletedMap[$d] ?? 0) + 1;
            $completedDatesByHabit[$hid][$d] = true;
        }
    }
}

// compute current week range (Monday..Sunday). Uses ISO-like week where Monday is start.
$dtToday = new DateTimeImmutable($today);
$weekStartObj = $dtToday->modify('monday this week');
$weekEndObj = $weekStartObj->add(new DateInterval('P6D'));
$weekStart = $weekStartObj->format('Y-m-d');
$weekEnd = $weekEndObj->format('Y-m-d');

$habitDoneMap = [];          // habit_id => 1|0
$habitNextAvailableMap = []; // habit_id => 'YYYY-MM-DD' (when user can next perform this habit)

// helper: parse textual frequency into days interval (returns null if unknown)
if (!function_exists('parseFrequencyToDays')) {
    function parseFrequencyToDays(string $freq): ?int {
        $f = strtolower(trim($freq));
        if ($f === '') return 1; // default = daily

        // explicit numeric like "7", "every 7 days", "14 days"
        if (preg_match('/(\d+)\s*(d|day|days)?/', $f, $m)) {
            return intval($m[1]);
        }
        if (strpos($f, 'weekly') !== false || strpos($f, 'week') !== false) return 7;
        if (strpos($f, 'daily') !== false || strpos($f, 'day') !== false) return 1;
        if (strpos($f, 'monthly') !== false || strpos($f, 'month') !== false) return 30;
        // unknown -> null (fallback to legacy logic)
        return null;
    }
}

// helper: compute most recent completed date (YYYY-MM-DD) for habit or null
if (!function_exists('getLastCompletedDate')) {
    function getLastCompletedDate(int $hid, array $completedDatesByHabit): ?string {
        if (empty($completedDatesByHabit[$hid]) || !is_array($completedDatesByHabit[$hid])) return null;
        $dates = array_keys($completedDatesByHabit[$hid]);
        if (empty($dates)) return null;
        rsort($dates); // newest first
        return $dates[0];
    }
}

// helper: format next-available label (returns array: ['date' => 'YYYY-MM-DD', 'label' => '...'])
if (!function_exists('computeNextAvailable')) {
    function computeNextAvailable(?string $lastCompleted, ?int $daysInterval, DateTimeImmutable $todayObj): array {
        // default next available is today
        $nextDate = $todayObj;
        $label = 'Available now';

        if (is_int($daysInterval) && $daysInterval > 0) {
            if ($lastCompleted !== null) {
                try {
                    $lastObj = new DateTimeImmutable($lastCompleted);
                    $nextDate = $lastObj->add(new DateInterval('P' . $daysInterval . 'D'));
                    if ($nextDate <= $todayObj) {
                        $label = 'Available now';
                    } else {
                        $diff = (int)$todayObj->diff($nextDate)->days;
                        $label = 'in ' . $diff . ' days'; // simple English phrase for UX
                    }
                } catch (Exception $ex) {
                    $nextDate = $todayObj;
                    $label = 'Available now';
                }
            } else {
                // never completed -> available immediately
                $nextDate = $todayObj;
                $label = 'Available now';
            }
        } else {
            // unknown interval -> fallback to today available (legacy handled elsewhere)
            $nextDate = $todayObj;
            $label = 'Available now';
        }

        return ['date' => $nextDate->format('Y-m-d'), 'label' => $label];
    }
}

$todayObj = new DateTimeImmutable($today);

foreach ($habits as $h) {
    $hid = intval($h['id']);
    $freqRaw = (string)($h['frequency'] ?? $h['recurrence'] ?? $h['period'] ?? 'daily');
    $freq = strtolower(trim($freqRaw));

    $done = 0;

    // last completed date if any
    $lastCompleted = getLastCompletedDate($hid, $completedDatesByHabit); // 'YYYY-MM-DD' or null

    // parse into days interval
    $daysInterval = parseFrequencyToDays($freq);

    if (is_int($daysInterval) && $daysInterval > 0) {
        if ($lastCompleted !== null) {
            try {
                $lastObj = new DateTimeImmutable($lastCompleted);
                $diffDays = (int)$todayObj->diff($lastObj)->days;
                // Completed while required interval NOT passed
                if ($diffDays < $daysInterval) {
                    $done = 1;
                } else {
                    $done = 0;
                }
            } catch (Exception $ex) {
                $done = 0;
            }
        } else {
            // never completed before => not done
            $done = 0;
        }
        // compute next-available date/label
        $na = computeNextAvailable($lastCompleted, $daysInterval, $todayObj);
        $habitNextAvailableMap[$hid] = $na;
    } else {
        // fallback to legacy semantics
        if ($freq === 'weekly' || $freq === 'week') {
            // legacy: any completion inside current ISO week marks done
            $found = false;
            if (!empty($completedDatesByHabit[$hid])) {
                foreach ($completedDatesByHabit[$hid] as $d => $_) {
                    if ($d >= $weekStart && $d <= $weekEnd) {
                        $found = true;
                        break;
                    }
                }
            }
            $done = $found ? 1 : 0;
            // next available -> end of week + 1 day
            $nextObj = DateTimeImmutable::createFromFormat('Y-m-d', $weekEnd)->add(new DateInterval('P1D'));
            $daysUntil = (int)$todayObj->diff($nextObj)->days;
            $habitNextAvailableMap[$hid] = ['date' => $nextObj->format('Y-m-d'), 'label' => ($nextObj <= $todayObj ? 'Available now' : 'in ' . $daysUntil . ' days')];
        } else {
            // default = daily
            $done = (!empty($completedDatesByHabit[$hid]) && !empty($completedDatesByHabit[$hid][$today])) ? 1 : 0;
            // next available: today if not done, otherwise tomorrow
            if ($done === 1) {
                $nextObj = $todayObj->add(new DateInterval('P1D'));
                $habitNextAvailableMap[$hid] = ['date' => $nextObj->format('Y-m-d'), 'label' => 'in 1 day'];
            } else {
                $habitNextAvailableMap[$hid] = ['date' => $todayObj->format('Y-m-d'), 'label' => 'Available now'];
            }
        }
    }

    $habitDoneMap[$hid] = $done;
}

// prepare list of incomplete habits for roulette (use $habitDoneMap)
/* --------------------
   Safe: build displayHabits (incomplete first, completed later)
   Doesn't mutate original $habits (avoids side-effects on stats/chart)
   -------------------- */

$incompleteDisplay = [];
$completedDisplay  = [];

foreach ($habits as $h) {
    $hid = intval($h['id'] ?? 0);
    $done = isset($habitDoneMap[$hid]) ? intval($habitDoneMap[$hid]) : 0; // 0 = not done, 1 = done

    // stable sort key
    $title = trim((string)($h['title'] ?? ''));
    $h['_title_sort'] = function_exists('mb_strtolower') ? mb_strtolower($title) : strtolower($title);

    if ($done === 0) {
        $incompleteDisplay[] = $h;
    } else {
        $completedDisplay[] = $h;
    }
}

// sort buckets by title (predictable order)
usort($incompleteDisplay, function($a, $b){
    return $a['_title_sort'] <=> $b['_title_sort'];
});
usort($completedDisplay, function($a, $b){
    return $a['_title_sort'] <=> $b['_title_sort'];
});

// merged display list used only for rendering
$displayHabits = array_merge($incompleteDisplay, $completedDisplay);

// cleanup helper key
foreach ($displayHabits as &$hh) { unset($hh['_title_sort']); }
unset($hh);

// fallback safety
if (!is_array($displayHabits) || empty($displayHabits)) {
    $displayHabits = $habits;
}


// Stats for today (interpretation: count habits considered done for "today view")
// safe compute of completedToday using habitDoneMap (normalize keys)
// Robust completedToday: normalize keys and use habitDoneMap
$completedToday = 0;
if (!is_array($habitDoneMap)) $habitDoneMap = [];

foreach ($habits as $h) {
    $hid = intval($h['id'] ?? 0);
    if ($hid <= 0) continue;
    // prefer int-key lookup (habitDoneMap keys are ints)
    $done = 0;
    if (array_key_exists($hid, $habitDoneMap)) $done = intval($habitDoneMap[$hid]);
    else {
        // fallback: maybe keys stored as strings
        $ks = array_keys($habitDoneMap);
        if (in_array((string)$hid, $ks, true)) $done = intval($habitDoneMap[(string)$hid]);
    }
    if ($done === 1) $completedToday++;
}
$missedToday = max(0, $totalHabits - $completedToday);



// Efficiency integer percent (same semantics)
$efficiency = ($totalHabits > 0) ? (int) round(($completedToday / $totalHabits) * 100) : 0;

// Longest streak across habits (if stored in habit row)
$habitStreakMap = []; // habit_id => ['current' => int, 'longest' => int]
$longestStreak = 0;

if (!function_exists('dateToSlot')) {
    // convert YYYY-MM-DD to integer slot based on interval days
    function dateToSlot(string $dateStr, int $intervalDays): int {
        // use UTC epoch days to avoid timezone issues
        $ts = strtotime($dateStr . ' 00:00:00 UTC');
        $days = (int) floor($ts / 86400);
        return intdiv($days, max(1, $intervalDays));
    }
}

foreach ($habits as $h) {
    $hid = intval($h['id']);
    // parse interval in days (reuse parseFrequencyToDays if available)
    $freqRaw = (string)($h['frequency'] ?? $h['recurrence'] ?? $h['period'] ?? 'daily');
    $intervalDays = null;
    if (function_exists('parseFrequencyToDays')) {
        $intervalDays = parseFrequencyToDays($freqRaw);
    } else {
        // fallback simple parser
        $intervalDays = (stripos($freqRaw, 'week') !== false) ? 7 : ((stripos($freqRaw,'month')!==false)?30:1);
    }
    if (!is_int($intervalDays) || $intervalDays <= 0) $intervalDays = 1;

    $dates = [];
    if (!empty($completedDatesByHabit[$hid]) && is_array($completedDatesByHabit[$hid])) {
        $dates = array_keys($completedDatesByHabit[$hid]);
        // ensure unique sorted ascending
        $dates = array_values(array_unique($dates));
        sort($dates); // oldest -> newest
    }

    if (empty($dates)) {
        $habitStreakMap[$hid] = ['current' => 0, 'longest' => 0];
        continue;
    }

    // Build set of slots from completion dates
    $slotSet = [];
    foreach ($dates as $d) {
        $slot = dateToSlot($d, $intervalDays);
        $slotSet[$slot] = true;
    }
    // unique slots sorted ascending
    $slots = array_keys($slotSet);
    sort($slots);

    // compute historical longest run (max consecutive integers in $slots)
    $maxRun = 0;
    $run = 0;
    $prev = null;
    foreach ($slots as $s) {
        if ($prev === null || $s !== $prev + 1) {
            // new run
            $run = 1;
        } else {
            $run++;
        }
        if ($run > $maxRun) $maxRun = $run;
        $prev = $s;
    }

    // compute current streak: count consecutive slots up to slot(today)
    $todaySlot = dateToSlot(date('Y-m-d'), $intervalDays);
    $currentRun = 0;
    $cursor = $todaySlot;
    while (isset($slotSet[$cursor])) {
        $currentRun++;
        $cursor--; // move to previous slot
    }

    $habitStreakMap[$hid] = ['current' => $currentRun, 'longest' => $maxRun];

    if ($maxRun > $longestStreak) $longestStreak = $maxRun;
}

/* --------------------
   Time-series for last 7 days (including today)
   -------------------- */
$endDate = new DateTimeImmutable('now');
$startDate = $endDate->sub(new DateInterval('P6D')); // last 7 days total

$datesRange = [];
$period = new DatePeriod($startDate, new DateInterval('P1D'), $endDate->add(new DateInterval('P1D')));
foreach ($period as $d) {
    $datesRange[] = $d->format('Y-m-d');
}

// prepare series containers
$series = [];
$chart_labels = [];
$chart_values = [];
$predicted = null;
$predictedDate = null;

try {
    global $pdo;
    if (!empty($pdo) && $pdo instanceof PDO) {
        $sql = <<<'SQL'
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
SQL;
        $startStr = $startDate->format('Y-m-d');
        $endStr = $endDate->format('Y-m-d');

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $startStr, $endStr]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ensure_iterable($rows);

        // map results by date
        $map = [];
        foreach ($rows as $r) {
            if (!isset($r['track_date'])) continue;
            $map[$r['track_date']] = [
                'efficiency' => isset($r['efficiency']) ? floatval($r['efficiency']) : 0.0,
                'total' => intval($r['total_tracked']),
                'completed' => intval($r['completed_count'])
            ];
        }

        // build ordered series for each date in range
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

        // predict next day using average delta (simple linear trend)
        $n = count($chart_values);
        if ($n >= 2) {
            $deltas = [];
            for ($i = 1; $i < $n; $i++) {
                $deltas[] = $chart_values[$i] - $chart_values[$i-1];
            }
            $avgDelta = array_sum($deltas) / max(1, count($deltas));
            $lastVal = floatval($chart_values[$n-1]);
            $predicted = $lastVal + $avgDelta;
        } elseif ($n === 1) {
            $predicted = floatval($chart_values[0]);
        } else {
            $predicted = null;
        }

        if (!is_null($predicted)) {
            $predicted = max(0.0, min(100.0, round($predicted, 2)));
            // predicted date: next day after last label
            if (count($chart_labels) > 0) {
                $lastLabel = end($chart_labels);
                $lastDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $lastLabel) ?: $endDate;
                $predictedDate = $lastDateObj->add(new DateInterval('P1D'))->format('Y-m-d');
            } else {
                $predictedDate = $endDate->add(new DateInterval('P1D'))->format('Y-m-d');
            }
        } else {
            $predicted = null;
            $predictedDate = null;
        }
    } else {
        // if no PDO configured, fallback to series built from $progress earlier
        foreach ($datesRange as $d) {
            $total = 0; $completed = 0;
            foreach ($progress as $p) {
                if (($p['track_date'] ?? '') === $d) {
                    $total++;
                    if (intval($p['completed']) === 1) $completed++;
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

?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard — Habits</title>
<style>
    :root{--bg:#f5f7fb;--card:#fff;--muted:#667085;--success-start:#34d399;--success-end:#10b981;--shadow:0 6px 18px rgba(16,24,40,0.06)}
    html,body{height:100%}
    body{font-family:Inter,system-ui,Arial,Helvetica, sans-serif;margin:0;padding:24px;background:var(--bg);color:#0f172a;line-height:1.35}
    .container{max-width:1100px;margin:0 auto}
    .btn{background:linear-gradient(90deg,#6366f1,#06b6d4);color:#fff;padding:8px 12px;border-radius:10px;text-decoration:none;display:inline-block}
    .top-stats{display:flex;gap:16px;margin:18px 0 22px;flex-wrap:wrap}
    .stat{background:var(--card);padding:14px;border-radius:12px;box-shadow:var(--shadow);flex:1;min-width:140px}
    .muted{color:var(--muted);font-size:13px}

    .progress-wrap{background:var(--card);padding:14px;border-radius:12px;box-shadow:var(--shadow);margin-bottom:14px}
    .progress-label{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
    .progress-bar-outer{background:#eef2ff;border-radius:999px;height:18px;overflow:hidden}
    .progress-bar{height:100%;width:0;border-radius:999px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:12px;transition:width .6s cubic-bezier(.2,.9,.2,1)}

    /* mini chart */
    .chart-card{background:var(--card);padding:12px;border-radius:12px;box-shadow:var(--shadow);margin-bottom:20px}
    .chart-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
    .chart-svg{width:100%;height:140px}
    .legend{font-size:12px;color:var(--muted)}

    /* grid */
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
    .habit-card{background:var(--card);padding:14px;border-radius:12px;box-shadow:var(--shadow);cursor:pointer;outline:none}
    .habit-card:focus{box-shadow:0 8px 30px rgba(2,6,23,0.08);transform:translateY(-2px)}
    .habit-card .muted{max-height:3.6em;overflow:hidden;text-overflow:ellipsis}
    .habit-details{margin-top:10px;padding-top:10px;border-top:1px dashed #eef2ff}
    .habit-actions a{margin-right:8px;text-decoration:none}

    /* roulette modal */
    .modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,0.6);display:none;align-items:center;justify-content:center;z-index:9999}
    .modal{background:var(--card);padding:18px;border-radius:12px;box-shadow:0 10px 40px rgba(2,6,23,0.3);max-width:520px;width:94%;text-align:center}
    .wheel{width:320px;height:320px;border-radius:50%;margin:0 auto;position:relative;overflow:visible;border:8px solid rgba(255,255,255,0.85)}
    .wheel-label{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);font-weight:700;padding:6px 10px;border-radius:8px;background:rgba(255,255,255,0.9)}
    .pointer{position:absolute;left:50%;top:8px;transform:translateX(-50%);width:0;height:0;border-left:12px solid transparent;border-right:12px solid transparent;border-bottom:18px solid #111}
    .spin-btn{display:inline-block;margin-top:12px;background:linear-gradient(90deg,#ef4444,#f97316);color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none;cursor:pointer}

    @media (max-width:640px){.top-stats{flex-direction:column}}
</style>
</head>
<body>
<div class="container">
    <header style="display:flex;justify-content:space-between;align-items:center">
        <div>
            <h1 style="margin:0">Dashboard — Habits & Progress</h1>
            <div class="muted" style="margin-top:6px">Today: <?php echo e(date('Y-m-d')); ?></div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="habits.php" class="btn" aria-label="Manage Habits">Manage Habits</a>
            <button id="rouletteOpen" class="spin-btn" aria-haspopup="dialog">Roulette</button>
        </div>
    </header>

    <section class="top-stats" role="region" aria-label="Top statistics">
        <div class="stat">
            <h3>Total Habits</h3>
            <p><?php echo $totalHabits; ?></p>
            <div class="muted" style="margin-top:6px">Active goals to manage</div>
        </div>

        <div class="stat">
            <h3>Completed Today</h3>
            <p><?php echo $completedToday; ?></p>
            <div class="muted" style="margin-top:6px"><?php echo $completedToday; ?> / <?php echo $totalHabits; ?> tasks</div>
        </div>

        <div class="stat">
            <h3>Missed Today</h3>
            <p><?php echo $missedToday; ?></p>
            <div class="muted" style="margin-top:6px">Opportunity to improve</div>
        </div>

        <div class="stat">
            <h3>Day Efficiency</h3>
            <p><?php echo $efficiency; ?>%</p>
            <div class="muted" style="margin-top:6px">
                <?php if ($efficiency === 100 && $totalHabits>0): ?>
                    <span style="display:inline-block;padding:6px 10px;border-radius:999px;background:#eef2ff;font-weight:700">All done — Great job!</span>
                <?php else: ?>
                    Keep it sustainable — <?php echo $efficiency; ?>% complete
                <?php endif; ?>
            </div>
        </div>
        
<?php
// --- ensure $longestStreak and $currentBestStreak are defined (safe fallback) ---
if (!isset($habitStreakMap) || !is_array($habitStreakMap)) {
    $habitStreakMap = []; // fallback empty map
}

// compute historical longest (if not present)
if (!isset($longestStreak)) {
    $longestStreak = 0;
    foreach ($habitStreakMap as $hs) {
        $l = intval($hs['longest'] ?? 0);
        if ($l > $longestStreak) $longestStreak = $l;
    }
}

// compute current best active streak (if not present)
if (!isset($currentBestStreak)) {
    $currentBestStreak = 0;
    foreach ($habitStreakMap as $hs) {
        $c = intval($hs['current'] ?? 0);
        if ($c > $currentBestStreak) $currentBestStreak = $c;
    }
}

// example: use in markup
// echoing for debug / template use
// Longest overall historical streak
// Current best active streak (how many consecutive periods a habit currently has)
echo '<!-- debug: $longestStreak = ' . $longestStreak . ' -->' . PHP_EOL;
echo '<!-- debug: $currentBestStreak = ' . $currentBestStreak . ' -->' . PHP_EOL;
?>


        <div class="stat">
        <h3>Best Streak</h3>
        <p><?php echo intval($longestStreak); ?> now/<?php echo intval($currentBestStreak); ?> the longest</p>
        <div class="muted">Longest streak across habits</div>
    </div>

    </section>

    <section class="progress-wrap" aria-label="Daily progress">
        <div class="progress-label">
            <div class="muted">Daily progress</div>
            <div style="font-weight:700"><?php echo $efficiency; ?>%</div>
        </div>
        <div class="progress-bar-outer" aria-hidden="true">
            <?php
                if ($efficiency >= 75) $grad = "linear-gradient(90deg,#34d399,#10b981)";
                elseif ($efficiency >= 40) $grad = "linear-gradient(90deg,#f59e0b,#f97316)";
                else $grad = "linear-gradient(90deg,#ef4444,#f43f5e)";
            ?>
            <div id="progressBar" class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $efficiency; ?>" style="background: <?php echo $grad; ?>;"><?php echo $efficiency; ?>%</div>
        </div>
    </section>

    <section class="chart-card" aria-label="Efficiency by day">
        <div class="chart-title">
            <div><strong>Efficiency by day</strong></div>
            <div class="legend">Shows last 7 days. Dashed extension is predicted next day.</div>
        </div>
        <div id="miniChart" class="chart-svg" aria-hidden="false"></div>
    </section>

    <main>
        <?php if (empty($habits)): ?>
            <div style="background:var(--card);padding:16px;border-radius:12px;box-shadow:var(--shadow)">No habits found. Go to <a href="habits.php">Manage Habits</a> to add one.</div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($displayHabits as $habit):

                    $hid = intval($habit['id']);
                    $isDoneToday = isset($habitDoneMap[$hid]) && $habitDoneMap[$hid] == 1;
                ?>
                <div class="habit-card" role="article" tabindex="0" aria-labelledby="habit-title-<?php echo $hid; ?>" data-hid="<?php echo $hid; ?>" aria-expanded="false">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start">
                        <div>
                            <h4 id="habit-title-<?php echo $hid; ?>" style="margin:0"><?php echo e($habit['title']); ?></h4>
                            <?php if (!empty($habit['description'])): ?>
                                <div class="muted" style="margin-top:6px"><?php echo e($habit['description']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div style="text-align:right">
                            <?php
                                $nextInfo = $habitNextAvailableMap[$hid] ?? ['date' => $today, 'label' => 'Available now'];
                                $nextDate = $nextInfo['date'];
                                $nextLabel = $nextInfo['label'];
                            ?>
                            <div class="muted">Available</div>
                            <div style="margin-top:6px;font-weight:700">
                                <?php
                                    // if nextDate is today or earlier -> available now
                                    $ndObj = DateTimeImmutable::createFromFormat('Y-m-d', $nextDate) ?: new DateTimeImmutable($nextDate);
                                    if ($ndObj <= new DateTimeImmutable($today)) {
                                        echo 'Available now';
                                    } else {
                                        // show friendly label plus date
                                        echo e($nextLabel) . ' • ' . e($nextDate);
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
                    <div id="details-<?php echo $hid; ?>" class="habit-details" style="display:none" aria-hidden="true">
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

                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- Roulette modal -->

<?php
// Build $incompleteHabits for JS roulette (safe, uses habitDoneMap)
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

<script>
// expose server-side incomplete items to JS
var INCOMPLETE = <?php echo json_encode(array_values($incompleteHabits), JSON_UNESCAPED_UNICODE); ?> || [];

// small utilities & progressive enhancements
function toggleCard(card){
    var details = card.querySelector('.habit-details');
    if (!details) return;
    var expanded = card.getAttribute('aria-expanded') === 'true';
    if (expanded) {
        details.style.display = 'none';
        details.setAttribute('aria-hidden','true');
        card.setAttribute('aria-expanded','false');
    } else {
        details.style.display = 'block';
        details.setAttribute('aria-hidden','false');
        card.setAttribute('aria-expanded','true');
    }
}

function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

function showFlash(message, type) {
    var root = document.getElementById('flash');
    if (!root) return;
    var el = document.createElement('div');
    el.textContent = message;
    el.setAttribute('role','status');
    el.style.padding = '10px 14px';
    el.style.borderRadius = '10px';
    el.style.marginTop = '8px';
    el.style.boxShadow = '0 6px 18px rgba(2,6,23,0.08)';
    el.style.background = (type === 'error') ? '#fee2e2' : '#ecfdf5';
    el.style.color = (type === 'error') ? '#991b1b' : '#065f46';
    root.appendChild(el);
    setTimeout(function(){ el.style.opacity = '0'; el.style.transition = 'opacity .6s ease'; }, 2200);
    setTimeout(function(){ try{ root.removeChild(el); }catch(e){} }, 3000);
}


document.addEventListener('DOMContentLoaded', function(){
    // progress bar animation
    var pb = document.getElementById('progressBar');
    if (pb) {
        var val = parseInt(pb.getAttribute('aria-valuenow') || '0',10);
        setTimeout(function(){ pb.style.width = Math.max(0, Math.min(100, val)) + '%'; }, 60);
    }

    // non-blocking flash (replace alert with better UX)
    <?php if (isset($_SESSION['flash_success'])): ?>
        showFlash(<?php echo json_encode($_SESSION['flash_success']); ?>, 'success');
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['flash_error'])): ?>
        showFlash(<?php echo json_encode($_SESSION['flash_error']); ?>, 'error');
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    var labels = <?php echo json_encode($chart_labels); ?> || [];
    var values = <?php echo json_encode($chart_values); ?> || [];
    var predicted = <?php echo json_encode($predicted); ?>;
    renderMiniChart('miniChart', labels, values, predicted);

    // Toggle details on card click
    document.querySelectorAll('.habit-card').forEach(function(card){
        card.addEventListener('click', function(e){
            toggleCard(card);
        });
        // keyboard support: enter / space
        card.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleCard(card);
            }
        });
    });

    // Roulette button
    var open = document.getElementById('rouletteOpen');
    var modal = document.getElementById('rouletteModal');
    var close = document.getElementById('closeModal');
    var spinBtn = document.getElementById('spinBtn');
    var wheel = document.getElementById('wheel');
    var wheelLabel = document.getElementById('wheelLabel');
    var goTo = document.getElementById('goToTask');

    open.addEventListener('click', function(){
        if (!INCOMPLETE || INCOMPLETE.length === 0) {
            showFlash('No incomplete tasks to spin', 'error');
            return;
        }
        buildWheel(INCOMPLETE, wheel);
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden','false');
        wheelLabel.textContent = 'Press Spin';
        goTo.style.display = 'none';
    });
    close.addEventListener('click', function(){
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden','true');
    });

    spinBtn.addEventListener('click', function(){
        if (!INCOMPLETE || INCOMPLETE.length === 0) return;
        spinWheel(INCOMPLETE, wheel, wheelLabel, goTo);
    });

});

/* ------------------------
   Roulette helpers
   ------------------------ */
function buildWheel(items, container) {
    // clear
    container.innerHTML = '';
    if (!items || items.length === 0) return;

    var n = items.length;
    var seg = 360 / n;
    var palette = ['#f97316','#fb923c','#f43f5e','#f87171','#f59e0b','#34d399','#60a5fa','#a78bfa'];
    var stops = [];

    for (var i = 0; i < n; i++) {
        var color = palette[i % palette.length];
        var start = i * seg;
        var end = (i+1) * seg;
        stops.push(color + ' ' + start + 'deg ' + end + 'deg');
    }

    // background using conic-gradient for crisp sectors
    container.style.background = 'conic-gradient(' + stops.join(',') + ')';
    container.style.transform = 'rotate(0deg)';
    container.style.transition = 'transform 4s cubic-bezier(.17,.67,.34,1)';

    // labels: place small labels around the wheel
    for (var i = 0; i < n; i++) {
        var lbl = document.createElement('div');
        lbl.className = 'wheel-label-item';
        lbl.setAttribute('role','presentation');
        lbl.style.position = 'absolute';
        lbl.style.left = '50%';
        lbl.style.top = '50%';
        lbl.style.transformOrigin = '0 0';
        var angle = (i + 0.5) * seg; // middle of segment
        lbl.style.transform = 'rotate(' + angle + 'deg) translate(0, -138px) rotate(-' + angle + 'deg)';
        lbl.style.fontSize = '13px';
        lbl.style.pointerEvents = 'none';
        lbl.style.width = '120px';
        lbl.style.textAlign = 'center';
        lbl.style.left = '50%';
        lbl.style.marginLeft = '-60px';
        lbl.style.color = '#062a2a';
        lbl.textContent = items[i].title || 'Untitled';
        container.appendChild(lbl);
    }
}

var _spinning = false;
function spinWheel(items, container, labelEl, goToEl) {
    if (_spinning) return; // prevent double spin
    if (!items || items.length === 0) return;
    _spinning = true;

    var n = items.length;
    var seg = 360 / n;
    // pick index weighted uniformly
    var index = Math.floor(Math.random() * n);
    // add bias to make spin feel natural
    var rounds = Math.floor(Math.random() * 3) + 4; // 4..6 rounds
    var randOffset = (Math.random() - 0.5) * (seg * 0.6); // small jitter
    var targetMid = index * seg + seg/2;
    var targetAngle = rounds * 360 + (360 - (targetMid + randOffset));

    container.style.transition = 'transform 4.2s cubic-bezier(.17,.67,.34,1)';
    // apply transform
    requestAnimationFrame(function(){ container.style.transform = 'rotate(' + targetAngle + 'deg)'; });

    // Accessibility: announce start
    labelEl.textContent = 'Spinning...';

    setTimeout(function(){
        _spinning = false;
        // normalize final angle
        var final = targetAngle % 360;
        container.style.transition = 'none';
        container.style.transform = 'rotate(' + (final) + 'deg)';

        // selected index computed by mapping final to sector
        var landed = Math.floor(((360 - final + seg/2) % 360) / seg);
        landed = (landed + n) % n; // safety

        var item = items[landed] || items[index] || {title: 'Unknown', id: null};
        labelEl.textContent = item.title || 'Selected';
        showFlash('Selected: ' + item.title, 'success');

        if (goToEl) {
            if (item.id) {
                goToEl.href = 'habit_history.php?id=' + encodeURIComponent(item.id);
                goToEl.style.display = 'inline-block';
            } else {
                goToEl.style.display = 'none';
            }
        }

    }, 4400);
}

/* ------------------------
   Mini SVG chart for Efficiency by day
   - draws last N points and predicted dashed extension
   ------------------------ */
function renderMiniChart(elId, labels, values, predicted) {
    var container = document.getElementById(elId);
    if (!container) return;
    container.innerHTML = '';

    if (!values || values.length === 0) {
        container.innerHTML = '<div class="muted" style="padding:18px;text-align:center">No data</div>';
        return;
    }

    var w = container.clientWidth || 600;
    var h = container.clientHeight || 140;
    var padding = {l:28, r:12, t:12, b:22};
    var plotW = w - padding.l - padding.r;
    var plotH = h - padding.t - padding.b;

    // clamp values to 0..100
    var pts = values.map(function(v){ return clamp(parseFloat(v) || 0, 0, 100); });
    var maxV = 100; // fixed scale

    // map to coordinates
    var stepX = plotW / Math.max(1, pts.length - 1);
    var poly = [];
    for (var i=0;i<pts.length;i++){
        var x = padding.l + i * stepX;
        var y = padding.t + (1 - (pts[i]/maxV)) * plotH;
        poly.push({x:x,y:y,v:pts[i],label:labels[i]});
    }

    // create SVG
    var svgNS = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(svgNS, 'svg');
    svg.setAttribute('width','100%');
    svg.setAttribute('height', h);
    svg.setAttribute('viewBox','0 0 '+w+' '+h);
    svg.setAttribute('role','img');
    svg.setAttribute('aria-label','Efficiency by day chart');

    // background grid lines (0,25,50,75,100)
    for (var g=0; g<=4; g++){
        var y = padding.t + (g/4) * plotH;
        var line = document.createElementNS(svgNS,'line');
        line.setAttribute('x1', padding.l);
        line.setAttribute('x2', w - padding.r);
        line.setAttribute('y1', y);
        line.setAttribute('y2', y);
        line.setAttribute('stroke', '#eef2ff');
        line.setAttribute('stroke-width', '1');
        svg.appendChild(line);
    }

    // polyline for actual values
    var pathD = poly.map(function(p,i){ return (i===0? 'M':'L') + p.x + ' ' + p.y; }).join(' ');
    var path = document.createElementNS(svgNS,'path');
    path.setAttribute('d', pathD);
    path.setAttribute('fill','none');
    path.setAttribute('stroke','#4f46e5');
    path.setAttribute('stroke-width','2');
    svg.appendChild(path);

    // circles and tooltips
    poly.forEach(function(p,i){
        var c = document.createElementNS(svgNS,'circle');
        c.setAttribute('cx', p.x);
        c.setAttribute('cy', p.y);
        c.setAttribute('r', 4);
        c.setAttribute('fill', '#4f46e5');
        c.setAttribute('tabindex', '0');
        c.setAttribute('aria-label', (p.label||'') + ': ' + p.v + '%');
        svg.appendChild(c);

        // simple hover tooltip
        c.addEventListener('mouseenter', function(){ showTooltip(container, p.x, p.y, p.label, p.v); });
        c.addEventListener('focus', function(){ showTooltip(container, p.x, p.y, p.label, p.v); });
        c.addEventListener('mouseleave', hideTooltip);
        c.addEventListener('blur', hideTooltip);
    });

    // predicted dashed line
    if (typeof predicted === 'number') {
        var last = poly[poly.length-1];
        var xPred = padding.l + (poly.length) * stepX; // next x position
        var yPred = padding.t + (1 - (clamp(predicted,0,100)/maxV)) * plotH;

        // line from last to predicted
        var d = 'M' + last.x + ' ' + last.y + ' L ' + xPred + ' ' + yPred;
        var pPred = document.createElementNS(svgNS,'path');
        pPred.setAttribute('d', d);
        pPred.setAttribute('fill','none');
        pPred.setAttribute('stroke','#10b981');
        pPred.setAttribute('stroke-width','1.5');
        pPred.setAttribute('stroke-dasharray','6 6');
        svg.appendChild(pPred);

        // predicted dot
        var cp = document.createElementNS(svgNS,'circle');
        cp.setAttribute('cx', xPred);
        cp.setAttribute('cy', yPred);
        cp.setAttribute('r', 3.5);
        cp.setAttribute('fill', '#10b981');
        svg.appendChild(cp);

        // annotate predicted value
        var t = document.createElementNS(svgNS,'text');
        t.setAttribute('x', xPred);
        t.setAttribute('y', yPred - 8);
        t.setAttribute('text-anchor','middle');
        t.setAttribute('font-size','11');
        t.setAttribute('fill','#065f46');
        t.textContent = predicted + '%';
        svg.appendChild(t);
    }

    container.appendChild(svg);
}

// tooltip helpers for chart
var _tt = null;
function showTooltip(root, x, y, label, v) {
    hideTooltip();
    _tt = document.createElement('div');
    _tt.style.position = 'absolute';
    _tt.style.zIndex = 9999;
    _tt.style.padding = '6px 8px';
    _tt.style.borderRadius = '6px';
    _tt.style.boxShadow = '0 6px 18px rgba(2,6,23,0.06)';
    _tt.style.background = '#fff';
    _tt.style.color = '#091020ff';
    _tt.style.fontSize = '12px';
    _tt.textContent = (label? label + ': ' : '') + v + '%';
    root.appendChild(_tt);
    // position (convert svg coords to client coords approx)
    var rect = root.getBoundingClientRect();
    _tt.style.left = (rect.left + x - (_tt.offsetWidth||60)/2) + 'px';
    _tt.style.top = (rect.top + y - 42) + 'px';
}
function hideTooltip(){ try{ if(_tt && _tt.parentNode) _tt.parentNode.removeChild(_tt); _tt = null; }catch(e){} }

</script>
</body>
</html>