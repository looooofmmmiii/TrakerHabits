<?php declare(strict_types=1);
// roulette.php — standalone page to spin incomplete habits/tasks
// Based on dashboard.php (extracted wheel logic + data prep)

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
require_once 'config/db.php'; // expects $pdo

// Session timeout (mirror dashboard settings)
$session_timeout = 180000000;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_unset();
    session_destroy();
    header("Location: auth/login.php?expired=1");
    exit;
}
$_SESSION['last_activity'] = time();

// protect against fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];

// Helpers
if (!function_exists('ensure_iterable')) {
    function ensure_iterable(&$v) {
        if (!is_iterable($v)) $v = [];
    }
}
if (!function_exists('e')) {
    function e($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// --- Obtain data (reuse getDashboardData if available) ---
$data = [];
try {
    if (function_exists('getDashboardData')) {
        $data = getDashboardData($user_id, $pdo);
        if (!is_array($data)) $data = [];
    } else {
        ensureTrackingForToday($user_id);
        $habits = getHabits($user_id);
        ensure_iterable($habits);

        // normalize ordering
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

        // build minimal payload
        $data = [
            'habits' => $habits,
            'progress' => $progress,
            'habitDoneMap' => [],
        ];

        // try to fill habitDoneMap if progress structure provides it
        if (is_array($progress) && isset($progress['done_map']) && is_array($progress['done_map'])) {
            $data['habitDoneMap'] = $progress['done_map'];
        }
    }
} catch (Throwable $ex) {
    error_log('roulette getDashboardData error: ' . $ex->getMessage());
    $data = [];
}

$habits = $data['habits'] ?? [];
ensure_iterable($habits);
$habitDoneMap = $data['habitDoneMap'] ?? [];
ensure_iterable($habitDoneMap);

// Build incomplete list (only tasks not done today)
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

// Page ready — render
$today = date('Y-m-d');
?><!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Roulette — pick a task</title>
<link rel="stylesheet" href="assets/styles/dashboard.css">
<style>
/* minimal page layout for roulette */
body{font-family:system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial; background:#f5f7fb; color:#0f172a;}
.container{max-width:960px;margin:28px auto;padding:16px}
.card{background:#fff;border-radius:12px;padding:18px;box-shadow:0 6px 18px rgba(16,24,40,0.06)}
.wheel-wrap{display:flex;flex-direction:column;align-items:center;gap:12px;padding:18px}
.wheel{width:300px;height:300px;border-radius:50%;position:relative;overflow:hidden;box-shadow:0 8px 24px rgba(2,6,23,0.08)}
.pointer{width:0;height:0;border-left:14px solid transparent;border-right:14px solid transparent;border-bottom:18px solid #111;position:relative;margin-bottom:-12px}
.spin-btn{background:linear-gradient(90deg,#10b981,#059669);color:#fff;border:none;padding:10px 14px;border-radius:8px;cursor:pointer;font-weight:700}
.btn{display:inline-block;padding:8px 12px;border-radius:8px;background:#eef2ff;border:none;text-decoration:none;color:#1e293b}
.wheel-label{font-weight:700}
.wheel-label-item{font-weight:600}
</style>
</head>
<body>
    <?php include "elements.php"; ?>
    <div class="container">
        <div class="card">
            <header style="display:flex;align-items:center;justify-content:space-between">
                <h1 style="margin:0">Roulette — pick a task</h1>
                <div class="muted">Today: <?php echo e($today); ?></div>
            </header>

            <div class="wheel-wrap" style="margin-top:18px">
                <div class="pointer" aria-hidden="true"></div>
                <div id="wheel" class="wheel" aria-hidden="false"></div>
                <div class="wheel-label" id="wheelLabel">Press Spin</div>
                <div style="margin-top:10px">
                    <button id="spinBtn" class="spin-btn">Spin</button>
                    <a id="goToTask" class="btn" style="margin-left:8px;display:none" href="#">Go to task</a>
                    <a href="dashboard.php" class="btn" style="margin-left:8px">Back</a>
                </div>
            </div>

            <div style="margin-top:12px;color:#475569">Total incomplete tasks: <strong><?php echo count($incompleteHabits); ?></strong></div>

            <?php if (count($incompleteHabits) > 0): ?>
                <ul style="margin-top:12px">
                    <?php foreach ($incompleteHabits as $it): ?>
                        <li><?php echo e($it['title']); ?> — <a href="habit_history.php?id=<?php echo intval($it['id']); ?>">history</a></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div style="margin-top:12px" class="muted">No incomplete tasks — try add more in <a href="habits.php">Manage Habits</a></div>
            <?php endif; ?>

        </div>
    </div>

<script>
// expose incomplete items to JS
var INCOMPLETE = <?php echo json_encode(array_values($incompleteHabits), JSON_UNESCAPED_UNICODE); ?> || [];

function buildWheel(items, container) {
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

    container.style.background = 'conic-gradient(' + stops.join(',') + ')';
    container.style.transform = 'rotate(0deg)';
    container.style.transition = 'transform 4s cubic-bezier(.17,.67,.34,1)';

    for (var i = 0; i < n; i++) {
        var lbl = document.createElement('div');
        lbl.className = 'wheel-label-item';
        lbl.setAttribute('role','presentation');
        lbl.style.position = 'absolute';
        lbl.style.left = '50%';
        lbl.style.top = '50%';
        lbl.style.transformOrigin = '0 0';
        var angle = (i + 0.5) * seg;
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
    if (_spinning) return;
    if (!items || items.length === 0) return;
    _spinning = true;

    var n = items.length;
    var seg = 360 / n;
    var index = Math.floor(Math.random() * n);
    var rounds = Math.floor(Math.random() * 3) + 4;
    var randOffset = (Math.random() - 0.5) * (seg * 0.6);
    var targetMid = index * seg + seg/2;
    var targetAngle = rounds * 360 + (360 - (targetMid + randOffset));

    container.style.transition = 'transform 4.2s cubic-bezier(.17,.67,.34,1)';
    requestAnimationFrame(function(){ container.style.transform = 'rotate(' + targetAngle + 'deg)'; });

    labelEl.textContent = 'Spinning...';

    setTimeout(function(){
        _spinning = false;
        var final = targetAngle % 360;
        container.style.transition = 'none';
        container.style.transform = 'rotate(' + (final) + 'deg)';

        var landed = Math.floor(((360 - final + seg/2) % 360) / seg);
        landed = (landed + n) % n;

        var item = items[landed] || items[index] || {title: 'Unknown', id: null};
        labelEl.textContent = item.title || 'Selected';
        alert('Selected: ' + item.title);

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

// init
document.addEventListener('DOMContentLoaded', function(){
    var wheel = document.getElementById('wheel');
    var spinBtn = document.getElementById('spinBtn');
    var label = document.getElementById('wheelLabel');
    var goTo = document.getElementById('goToTask');

    if (!INCOMPLETE || INCOMPLETE.length === 0) {
        label.textContent = 'No incomplete tasks';
        spinBtn.disabled = true;
        spinBtn.style.opacity = '0.6';
        return;
    }

    buildWheel(INCOMPLETE, wheel);

    spinBtn.addEventListener('click', function(){
        spinWheel(INCOMPLETE, wheel, label, goTo);
    });
});
</script>
</body>
</html>
