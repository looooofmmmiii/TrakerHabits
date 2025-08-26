<?php
// dashboard.php — improved version
// Main goals: security, robustness, UX improvements (mini chart + predicted point fix)

declare(strict_types=1);

session_name('habit_sid');
// session cookie params: secure, httponly, samesite
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax'); // adjust if you need 'Strict'
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}

// Start session early
session_start();

// SECURITY: basic response headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-XSS-Protection: 1; mode=block');

// Session timeout (30 minutes)
$session_timeout = 3000 * 60; // 1800 seconds

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
$totalHabits = count($habits);

// Get all tracking progress (flat rows) — used for today's map and for streaks summary
$progress = getHabitProgress($user_id); // rows: id, title, track_date, completed

// Build today's map and distinct dates counts
$today = date('Y-m-d');
$todayMap = [];
$dateCompletedMap = [];
$datesSet = [];
foreach ($progress as $p) {
    if (!empty($p['track_date'])) {
        $d = $p['track_date'];
        $datesSet[$d] = true;
        if (intval($p['completed']) === 1) {
            $dateCompletedMap[$d] = ($dateCompletedMap[$d] ?? 0) + 1;
        }
    }
    if (!empty($p['track_date']) && $p['track_date'] === $today) {
        $todayMap[intval($p['id'])] = intval($p['completed']);
    }
}

// --- Sort habits so that not-completed (невиконані) appear first ---
if (!empty($habits)) {
    usort($habits, function($a, $b) use ($todayMap) {
        $aid = intval($a['id']); $bid = intval($b['id']);
        $ad = isset($todayMap[$aid]) ? intval($todayMap[$aid]) : 0; // 1 if done, 0 otherwise
        $bd = isset($todayMap[$bid]) ? intval($todayMap[$bid]) : 0;
        if ($ad === $bd) {
            // stable fallback: alphabetical by title
            return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
        }
        // put 0 (not completed) before 1 (completed)
        return ($ad < $bd) ? -1 : 1;
    });
}

// Stats for today
$completedToday = 0;
foreach ($todayMap as $val) { if ($val) $completedToday++; }
$missedToday = max(0, $totalHabits - $completedToday);

// Efficiency integer percent
$efficiency = ($totalHabits > 0) ? (int) round(($completedToday / $totalHabits) * 100) : 0;

// Longest streak across habits (if stored in habit row)
$longestStreak = 0;
foreach ($habits as $h) {
    if (!empty($h['streak'])) {
        $s = intval($h['streak']);
        if ($s > $longestStreak) $longestStreak = $s;
    }
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

        // map results by date
        $map = [];
        foreach ($rows as $r) {
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
        <div><a href="habits.php" class="btn" aria-label="Manage Habits">Manage Habits</a></div>
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

        <div class="stat">
            <h3>Best Streak</h3>
            <p><?php echo $longestStreak; ?></p>
            <div class="muted" style="margin-top:6px">Longest streak across habits</div>
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
                <?php foreach ($habits as $habit):
                    $hid = intval($habit['id']);
                    $isDoneToday = isset($todayMap[$hid]) && $todayMap[$hid] == 1;
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
                            <div class="muted">Today</div>
                            <div style="margin-top:6px;font-weight:700"><?php echo $today; ?></div>
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

<script>
// small utilities & progressive enhancements
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

    var labels = <?php echo json_encode($chart_labels); ?>;
    var values = <?php echo json_encode($chart_values); ?>;
    var predicted = <?php echo json_encode($predicted); ?>;
    renderMiniChart('miniChart', labels, values, predicted);

    // Toggle details on card click
    document.querySelectorAll('.habit-card').forEach(function(card){
        card.addEventListener('click', function(e){
            // ignore clicks from interactive children (buttons, links, forms) — they stopPropagation themselves
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
});

function toggleCard(card){
    var hid = card.getAttribute('data-hid');
    var details = document.getElementById('details-' + hid);
    if (!details) return;
    var expanded = card.getAttribute('aria-expanded') === 'true';
    if (expanded) {
        details.style.display = 'none';
        details.setAttribute('aria-hidden', 'true');
        card.setAttribute('aria-expanded', 'false');
    } else {
        details.style.display = 'block';
        details.setAttribute('aria-hidden', 'false');
        card.setAttribute('aria-expanded', 'true');
    }
}

// lightweight flash UI
function showFlash(text, kind) {
    var d = document.createElement('div');
    d.textContent = text;
    d.style.position = 'fixed';
    d.style.right = '18px';
    d.style.bottom = '18px';
    d.style.padding = '10px 14px';
    d.style.borderRadius = '10px';
    d.style.boxShadow = '0 6px 18px rgba(16,24,40,0.08)';
    d.style.zIndex = 9999;
    d.style.background = (kind === 'success') ? '#ecfdf5' : '#fff1f2';
    d.style.color = (kind === 'success') ? '#065f46' : '#7f1d1d';
    document.body.appendChild(d);
    setTimeout(function(){ d.style.opacity = '0'; d.style.transition = 'opacity .5s'; setTimeout(()=>d.remove(),500); }, 2500);
}

// mini chart renderer (same as before but robust)
function renderMiniChart(containerId, labels, values, predicted) {
    var container = document.getElementById(containerId);
    container.innerHTML = '';

    if (!labels || labels.length === 0) {
        container.innerHTML = '<div class="muted">No tracking data yet.</div>';
        return;
    }

    values = values.map(function(v){ return Number(v) || 0; });

    var W = Math.max(container.clientWidth || 600, 360);
    var H = 140;
    var padding = {l:36, r:24, t:12, b:28};
    var innerW = W - padding.l - padding.r;
    var innerH = H - padding.t - padding.b;
    var maxY = 100;

    function xPos(i){
        var denom = Math.max(labels.length - 1, 1);
        return padding.l + (innerW) * (i / denom);
    }
    function yPos(v){
        var vv = Number(v);
        vv = isNaN(vv) ? 0 : vv;
        vv = Math.max(0, Math.min(maxY, vv));
        return padding.t + innerH - ((vv / maxY) * innerH);
    }

    var svgns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(svgns, 'svg');
    svg.setAttribute('width', '100%');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    svg.setAttribute('preserveAspectRatio', 'xMinYMin meet');

    var defs = document.createElementNS(svgns, 'defs');
    var gradId = 'gradLine_' + Math.floor(Math.random()*1000000);
    var linGrad = document.createElementNS(svgns, 'linearGradient');
    linGrad.setAttribute('id', gradId);
    linGrad.setAttribute('x1','0%'); linGrad.setAttribute('x2','100%');
    var stop1 = document.createElementNS(svgns, 'stop'); stop1.setAttribute('offset','0%'); stop1.setAttribute('stop-color','#34d399');
    var stop2 = document.createElementNS(svgns, 'stop'); stop2.setAttribute('offset','100%'); stop2.setAttribute('stop-color','#10b981');
    linGrad.appendChild(stop1); linGrad.appendChild(stop2);
    defs.appendChild(linGrad);
    svg.appendChild(defs);

    // horizontal grid
    for (var g=0; g<=4; g++){
        var yy = padding.t + (innerH/4)*g;
        var line = document.createElementNS(svgns, 'line');
        line.setAttribute('x1', padding.l);
        line.setAttribute('x2', padding.l + innerW);
        line.setAttribute('y1', yy);
        line.setAttribute('y2', yy);
        line.setAttribute('stroke', '#eef2ff');
        line.setAttribute('stroke-width', '1');
        svg.appendChild(line);
    }

    // path for real values
    var pathD = '';
    if (values.length === 1) {
        var x = xPos(0), y = yPos(values[0]);
        var tiny = Math.max(6, innerW * 0.03);
        pathD = 'M ' + (x - tiny/2) + ' ' + y + ' L ' + (x + tiny/2) + ' ' + y;
    } else {
        for (var i=0;i<values.length;i++){
            var xi = xPos(i), yi = yPos(values[i]);
            if (i===0) pathD += 'M ' + xi + ' ' + yi;
            else pathD += ' L ' + xi + ' ' + yi;
        }
    }

    var path = document.createElementNS(svgns, 'path');
    path.setAttribute('d', pathD);
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', 'url(#' + gradId + ')');
    path.setAttribute('stroke-width', '3');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('stroke-linejoin', 'round');
    svg.appendChild(path);

    // markers for real points
    for (var j=0;j<values.length;j++){
        var cx = xPos(j);
        var cy = yPos(values[j]);
        var circle = document.createElementNS(svgns, 'circle');
        circle.setAttribute('cx', cx);
        circle.setAttribute('cy', cy);
        circle.setAttribute('r', 4);
        circle.setAttribute('fill', '#ffffff');
        circle.setAttribute('stroke', '#10b981');
        circle.setAttribute('stroke-width', 1.6);
        svg.appendChild(circle);
    }

    // predicted dashed extension
    if (predicted !== null && !isNaN(predicted)) {
        var lastIdx = values.length - 1;
        var lastX = xPos(lastIdx);
        var lastY = yPos(values[lastIdx]);

        var step = (labels.length > 1) ? (innerW / (labels.length - 1)) : Math.min(40, innerW*0.15);

        var nextX = lastX + step;
        var nextY = yPos(predicted);

        var dashLine = document.createElementNS(svgns, 'line');
        dashLine.setAttribute('x1', lastX);
        dashLine.setAttribute('y1', lastY);
        dashLine.setAttribute('x2', nextX);
        dashLine.setAttribute('y2', nextY);
        dashLine.setAttribute('stroke', '#f97316');
        dashLine.setAttribute('stroke-width', '2');
        dashLine.setAttribute('stroke-dasharray', '6 6');
        svg.appendChild(dashLine);

        var pCirc = document.createElementNS(svgns, 'circle');
        pCirc.setAttribute('cx', nextX);
        pCirc.setAttribute('cy', nextY);
        pCirc.setAttribute('r', 5);
        pCirc.setAttribute('fill', '#f97316');
        pCirc.setAttribute('stroke', '#ffffff');
        pCirc.setAttribute('stroke-width', 1.5);
        svg.appendChild(pCirc);

        var txt = document.createElementNS(svgns, 'text');
        txt.setAttribute('x', nextX);
        txt.setAttribute('y', Math.max(12, nextY - 8));
        txt.setAttribute('font-size', '11');
        txt.setAttribute('text-anchor', 'middle');
        txt.setAttribute('fill', '#f97316');
        txt.textContent = (predicted || 0) + '%';
        svg.appendChild(txt);
    }

    // x-axis labels
    for (var i=0;i<labels.length;i++){
        var x = xPos(i);
        var text = document.createElementNS(svgns, 'text');
        text.setAttribute('x', x);
        text.setAttribute('y', H - 6);
        text.setAttribute('font-size', '10');
        text.setAttribute('text-anchor', 'middle');
        text.setAttribute('fill', '#6b7280');
        var lbl = labels[i];
        var parts = String(lbl).split('-');
        if (parts.length===3) lbl = parts[1] + '-' + parts[2]; // MM-DD
        text.textContent = lbl;
        svg.appendChild(text);
    }

    container.appendChild(svg);
}
</script>
</body>
</html>
