<?php
session_start();

// Session timeout (30 minutes)
$session_timeout = 18000000; // keep your value

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

require_once 'functions/habit_functions.php';

// Escape helper — safe wrapper around htmlspecialchars
if (!function_exists('e')) {
    function e($s) {
        // force string, avoid warnings on null
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}


if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = intval($_SESSION['user_id']);
$habits = getHabits($user_id);

// Simple CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// Handle marking habit as completed today
if ($_SERVER['REQUEST_METHOD'] === 'POST' 
    && isset($_POST['action']) && $_POST['action'] === 'complete') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['flash_error'] = 'Invalid request';
        header('Location: dashboard.php'); exit;
    }

    $habit_id = (int)$_POST['habit_id'];
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


// Get all tracking progress
$progress = getHabitProgress($user_id); // array of rows: id, title, track_date, completed

// Build today's map and gather distinct dates from tracking
$today = date('Y-m-d');
$todayMap = [];
$dateCompletedMap = []; // date => count of completed entries
$datesSet = [];
foreach ($progress as $p) {
    if (!empty($p['track_date'])) {
        $d = $p['track_date'];
        $datesSet[$d] = true;
        if (intval($p['completed']) === 1) {
            if (!isset($dateCompletedMap[$d])) $dateCompletedMap[$d] = 0;
            $dateCompletedMap[$d]++;
        }
    }
    if (!empty($p['track_date']) && $p['track_date'] === $today) {
        $todayMap[intval($p['id'])] = intval($p['completed']);
    }
}

// Stats
$totalHabits = count($habits);
$completedToday = 0;
foreach ($todayMap as $val) if ($val) $completedToday++;
$missedToday = max(0, $totalHabits - $completedToday);

// Efficiency
if ($totalHabits > 0) {
    $efficiency = ($completedToday === $totalHabits) ? 100 : round(($completedToday / $totalHabits) * 100);
} else {
    $efficiency = 0;
}

// compute longest streak among habits
$longestStreak = 0;
foreach ($habits as $h) {
    if (isset($h['streak'])) {
        $s = intval($h['streak']);
        if ($s > $longestStreak) $longestStreak = $s;
    }
}

// Prepare time-series arrays (calculate efficiency per day properly)
// ---------- REPLACE previous aggregation with this block ----------

// prepare last 7 days range (including today)
$endDate = new DateTime(); // today
$startDate = (clone $endDate)->modify('-6 days'); // last 7 days = today and previous 6

$datesRange = [];
$period = new DatePeriod($startDate, new DateInterval('P1D'), (clone $endDate)->modify('+1 day'));
foreach ($period as $d) {
    $datesRange[] = $d->format('Y-m-d');
}

// fetch efficiencies for last 7 days from DB
$series = [];
$chart_labels = [];
$chart_values = [];
$predicted = null;

try {
    global $pdo;
    if (!empty($pdo)) {
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
                'efficiency' => is_null($r['efficiency']) ? 0.0 : floatval($r['efficiency']),
                'total' => intval($r['total_tracked']),
                'completed' => intval($r['completed_count'])
            ];
        }

        // build series for each date in range (preserves order)
        foreach ($datesRange as $d) {
            if (isset($map[$d])) {
                $val = $map[$d]['efficiency'];
                // clamp 0..100
                if ($val < 0) $val = 0;
                if ($val > 100) $val = 100;
                $series[] = ['date' => $d, 'value' => round($val, 2), 'total' => $map[$d]['total'], 'completed' => $map[$d]['completed']];
            } else {
                $series[] = ['date' => $d, 'value' => 0.0, 'total' => 0, 'completed' => 0];
            }
        }

        // prepare labels/values for JS
        $chart_labels = array_map(function($i){ return $i['date']; }, $series);
        $chart_values = array_map(function($i){ return $i['value']; }, $series);

        // Predict next day using simple linear trend (slope between first and last or avg delta)
        $n = count($chart_values);
        if ($n >= 2) {
            // compute average daily delta across days where there is at least some data to avoid noise
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
            $predicted = 0.0;
        }

        // clamp predicted and round
        if ($predicted < 0) $predicted = 0;
        if ($predicted > 100) $predicted = 100;
        $predicted = round($predicted, 2);

        // also expose predictedDate (next day) for JS if needed
        $lastDateObj = new DateTime(end($chart_labels));
        $predictedDateObj = $lastDateObj->modify('+1 day');
        $predictedDate = $predictedDateObj->format('Y-m-d');
        // optionally push predicted label to chart_labels/values if you want to show it as extra tick:
        // but in our JS we'll draw predicted point past right edge, not as full label.
    }
} catch (Exception $ex) {
    // safe fallback
    $series = [];
    $chart_labels = [];
    $chart_values = [];
    $predicted = null;
}
// ---------- end replace ----------


?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard — Habits</title>
<style>
    :root{--bg:#f5f7fb;--card:#fff;--muted:#667085;--success-start:#34d399;--success-end:#10b981;--shadow:0 6px 18px rgba(16,24,40,0.06)}
    body{font-family:Inter,system-ui,Arial;margin:0;padding:24px;background:var(--bg);color:#0f172a}
    .container{max-width:1100px;margin:0 auto}
    .btn{background:linear-gradient(90deg,#6366f1,#06b6d4);color:#fff;padding:8px 12px;border-radius:10px;text-decoration:none}
    .top-stats{display:flex;gap:16px;margin:18px 0 22px}
    .stat{background:var(--card);padding:14px;border-radius:12px;box-shadow:var(--shadow);flex:1;min-width:140px}
    .muted{color:var(--muted);font-size:13px}

    .progress-wrap{background:var(--card);padding:14px;border-radius:12px;box-shadow:var(--shadow);margin-bottom:14px}
    .progress-label{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
    .progress-bar-outer{background:#eef2ff;border-radius:999px;height:18px;overflow:hidden}
    .progress-bar{height:100%;width:0;border-radius:999px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:12px;transition:width .6s ease}

    /* mini chart */
    .chart-card{background:var(--card);padding:12px;border-radius:12px;box-shadow:var(--shadow);margin-bottom:20px}
    .chart-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
    .chart-svg{width:100%;height:140px}
    .legend{font-size:12px;color:var(--muted)}

    /* grid */
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
    .habit-card{background:var(--card);padding:14px;border-radius:12px;box-shadow:var(--shadow)}

    @media (max-width:640px){.top-stats{flex-direction:column}}
</style>
</head>
<body>
<div class="container">
    <header style="display:flex;justify-content:space-between;align-items:center">
        <div>
            <h1 style="margin:0">Dashboard — Habits & Progress</h1>
            <div class="muted" style="margin-top:6px">Today: <?php echo date('Y-m-d'); ?></div>
        </div>
        <div><a href="habits.php" class="btn">Manage Habits</a></div>
    </header>

    <section class="top-stats">
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

    <!-- Daily progress (kept as requested) -->
    <section class="progress-wrap">
        <div class="progress-label">
            <div class="muted">Daily progress</div>
            <div style="font-weight:700"><?php echo $efficiency; ?>%</div>
        </div>
        <div class="progress-bar-outer" aria-hidden="true">
            <?php
                $grad = "linear-gradient(90deg,var(--success-start),var(--success-end))";
                if ($efficiency >= 75) $grad = "linear-gradient(90deg,#34d399,#10b981)";
                elseif ($efficiency >= 40) $grad = "linear-gradient(90deg,#f59e0b,#f97316)";
                else $grad = "linear-gradient(90deg,#ef4444,#f43f5e)";
            ?>
            <div id="progressBar" class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $efficiency; ?>" style="background: <?php echo $grad; ?>;"><?php echo $efficiency; ?>%</div>
        </div>
    </section>

    <!-- small line chart of efficiencies (only dates present in habit_tracking) -->
    <section class="chart-card">
        <div class="chart-title">
            <div><strong>Efficiency by day</strong></div>
            <div class="legend">Shows days from habit_tracking only. Dashed extension is predicted next day.</div>
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
                <div class="habit-card">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start">
                        <div>
                            <h4 style="margin:0"><?php echo e($habit['title']); ?></h4>
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
                            <div style="background:#ecfdf5;color:#065f46;padding:6px 10px;border-radius:999px;font-weight:600">✅ Completed</div>
                        <?php else: ?>
                            <form method="POST" style="margin:0"
                                onsubmit="const b=this.querySelector('button[type=submit]'); b.disabled=true; b.innerText='Saving...';">
                            <input type="hidden" name="action" value="complete">
                            <input type="hidden" name="habit_id" value="<?php echo $hid; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <button type="submit"
                                    style="background:linear-gradient(90deg,#10b981,#059669);border:none;color:#fff;padding:8px 12px;border-radius:8px;cursor:pointer;font-weight:700">
                                Mark Completed
                            </button>
                            </form>


                        <?php endif; ?>
                        <?php if (!empty($habit['streak'])): ?>
                            <div class="muted">Streak: <strong><?php echo intval($habit['streak']); ?></strong></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<script>
// render progress bar
document.addEventListener('DOMContentLoaded', function(){
    var pb = document.getElementById('progressBar');
    var val = parseInt(pb.getAttribute('aria-valuenow') || '0',10);
    setTimeout(function(){ pb.style.width = val + '%'; }, 50);

    // flash messages
    <?php if (isset($_SESSION['flash_success'])): ?>
        alert(<?php echo json_encode($_SESSION['flash_success']); ?>);
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['flash_error'])): ?>
        alert(<?php echo json_encode($_SESSION['flash_error']); ?>);
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    // build mini chart
    var labels = <?php echo json_encode($chart_labels); ?>;
    var values = <?php echo json_encode($chart_values); ?>;
    var predicted = <?php echo json_encode($predicted); ?>;
    renderMiniChart('miniChart', labels, values, predicted);
});

function renderMiniChart(containerId, labels, values, predicted) {
    var container = document.getElementById(containerId);
    container.innerHTML = '';

    if (!labels || labels.length === 0) {
        container.innerHTML = '<div class="muted">No tracking data yet.</div>';
        return;
    }

    // normalize numbers
    values = values.map(function(v){ return Number(v) || 0; });

    console.log('miniChart data', { labels: labels, values: values, predicted: predicted });

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

    // defs gradient
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

    // grid
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

    console.log('miniChart pathD:', pathD);

    var path = document.createElementNS(svgns, 'path');
    path.setAttribute('d', pathD);
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', 'url(#' + gradId + ')');
    path.setAttribute('stroke-width', '3');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('stroke-linejoin', 'round');
    // fallback styles
    path.style.stroke = '#10b981';
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

    // predicted dashed orange extension (one step ahead)
    if (predicted !== null && !isNaN(predicted)) {
        var lastIdx = values.length - 1;
        var lastX = xPos(lastIdx);
        var lastY = yPos(values[lastIdx]);

        // compute step: equal to one label step; if only one label, choose a small step
        var step = (labels.length > 1) ? (innerW / (labels.length - 1)) : Math.min(40, innerW*0.15);

        var nextX = lastX + step;
        var nextY = yPos(predicted);

        var dashLine = document.createElementNS(svgns, 'line');
        dashLine.setAttribute('x1', lastX);
        dashLine.setAttribute('y1', lastY);
        dashLine.setAttribute('x2', nextX);
        dashLine.setAttribute('y2', nextY);
        dashLine.setAttribute('stroke', '#f97316'); // orange
        dashLine.setAttribute('stroke-width', '2');
        dashLine.setAttribute('stroke-dasharray', '6 6');
        svg.appendChild(dashLine);

        // orange marker for predicted
        var pCirc = document.createElementNS(svgns, 'circle');
        pCirc.setAttribute('cx', nextX);
        pCirc.setAttribute('cy', nextY);
        pCirc.setAttribute('r', 5);
        pCirc.setAttribute('fill', '#f97316'); // orange fill
        pCirc.setAttribute('stroke', '#ffffff');
        pCirc.setAttribute('stroke-width', 1.5);
        svg.appendChild(pCirc);

        // optional small label text above predicted point
        var txt = document.createElementNS(svgns, 'text');
        txt.setAttribute('x', nextX);
        txt.setAttribute('y', Math.max(12, nextY - 8));
        txt.setAttribute('font-size', '11');
        txt.setAttribute('text-anchor', 'middle');
        txt.setAttribute('fill', '#f97316');
        txt.textContent = predicted + '%';
        svg.appendChild(txt);
    }

    // x-axis labels (compact)
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
