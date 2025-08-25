<?php
session_start();

// Session timeout (30 minutes)
$session_timeout = 18000000;

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

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = intval($_SESSION['user_id']);
$habits = getHabits($user_id);

// Simple CSRF token (optional but recommended)
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// Handle marking habit as completed today
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete'])) {
    // basic CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['flash_error'] = 'Invalid request';
        header('Location: dashboard.php');
        exit;
    }

    $habit_id = intval($_POST['habit_id']);
    $today = date('Y-m-d');

    // Track habit (assumes trackHabit handles duplicates internally)
    $ok = trackHabit($habit_id, $today);

    if ($ok) {
        $_SESSION['flash_success'] = 'Habit marked as completed';
    } else {
        $_SESSION['flash_error'] = 'Unable to track habit';
    }

    // redirect to avoid resubmission
    header('Location: dashboard.php');
    exit;
}

// Get all tracking progress
$progress = getHabitProgress($user_id);

// Build map of today's completion by habit id
$today = date('Y-m-d');
$todayMap = [];
foreach ($progress as $p) {
    if (!empty($p['track_date']) && $p['track_date'] === $today) {
        $todayMap[intval($p['id'])] = intval($p['completed']);
    }
}

// Stats
$totalHabits = count($habits);
$completedToday = 0;
foreach ($todayMap as $val) {
    if ($val) $completedToday++;
}
$percentDone = $totalHabits ? round(($completedToday / $totalHabits) * 100) : 0;

// Emoji / gamification hint
$emoji = "🌱";
if ($totalHabits > 0) {
    if ($completedToday == $totalHabits) $emoji = "🎉";
    elseif ($completedToday >= ceil($totalHabits / 2)) $emoji = "👍";
}

// small helper to escape
function e($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard — Habits</title>
<style>
    :root{
        --bg: #f5f7fb;
        --card: #ffffff;
        --muted: #667085;
        --accent: linear-gradient(90deg,#4f46e5,#06b6d4);
        --success-start: #34d399;
        --success-end: #10b981;
        --shadow: 0 6px 18px rgba(16,24,40,0.06);
    }
    html,body{height:100%;}
    body{
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial;
        background: var(--bg);
        margin: 0; padding: 24px; color:#0f172a;
    }
    .container{max-width:1100px;margin:0 auto}
    header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
    h1{font-size:20px;margin:0}
    .btn {
        background: linear-gradient(90deg,#6366f1,#06b6d4);
        color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:600;box-shadow:var(--shadow);display:inline-block
    }
    .top-stats{display:flex;gap:16px;margin:18px 0 22px}
    .stat{background:var(--card);padding:14px;border-radius:12px;box-shadow:var(--shadow);flex:1}
    .stat h3{margin:0;font-size:13px;color:#0f172a}
    .stat p{font-size:20px;margin:6px 0 0;font-weight:700}

    /* progress */
    .progress-wrap{background:var(--card);padding:14px;border-radius:12px;box-shadow:var(--shadow);margin-bottom:20px}
    .progress-label{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
    .progress-bar-outer{background:#eef2ff;border-radius:999px;height:18px;overflow:hidden}
    .progress-bar{height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,var(--success-start),var(--success-end));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:12px;transition:width .6s ease}

    /* grid */
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
    .habit-card{background:var(--card);padding:14px;border-radius:12px;box-shadow:var(--shadow);display:flex;flex-direction:column;gap:10px}
    .habit-card h4{margin:0;font-size:16px}
    .muted{color:var(--muted);font-size:13px}
    .actions{display:flex;gap:8px;align-items:center}

    .complete-btn{background:linear-gradient(90deg,#10b981,#059669);border:none;color:#fff;padding:8px 12px;border-radius:8px;cursor:pointer;font-weight:700}
    .complete-btn[disabled]{opacity:0.6;cursor:not-allowed}
    .chip-done{background:#ecfdf5;color:#065f46;padding:6px 10px;border-radius:999px;font-weight:600}

    .empty{padding:20px;text-align:center;color:var(--muted)}

    /* toast */
    .toast{position:fixed;right:20px;bottom:20px;background:#0f172a;color:#fff;padding:12px 16px;border-radius:10px;box-shadow:0 8px 30px rgba(2,6,23,0.4);display:none}
    .toast.show{display:block;animation:toastIn .4s ease}
    @keyframes toastIn{from{transform:translateY(6px);opacity:0}to{transform:none;opacity:1}}

    /* responsive */
    @media (max-width:640px){body{padding:16px}.top-stats{flex-direction:column}}

</style>
</head>
<body>
<div class="container">
    <header>
        <div>
            <h1>Dashboard <span style="font-size:14px;color:var(--muted);">— Habits & Progress</span></h1>
            <div style="margin-top:6px;color:var(--muted);font-size:13px">Today: <?php echo date('Y-m-d'); ?> • <?php echo $emoji; ?></div>
        </div>
        <div>
            <a href="habits.php" class="btn">Manage Habits</a>
        </div>
    </header>

    <section class="top-stats">
        <div class="stat">
            <h3>Total Habits</h3>
            <p><?php echo $totalHabits; ?></p>
        </div>
        <div class="stat">
            <h3>Completed Today</h3>
            <p><?php echo $completedToday; ?></p>
        </div>
        <div class="stat">
            <h3>Completion</h3>
            <p><?php echo $percentDone; ?>%</p>
        </div>
    </section>

    <section class="progress-wrap">
        <div class="progress-label">
            <div class="muted">Daily progress</div>
            <div style="font-weight:700"><?php echo $percentDone; ?>%</div>
        </div>
        <div class="progress-bar-outer">
            <div id="progressBar" class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $percentDone; ?>"><?php echo $percentDone; ?>%</div>
        </div>
    </section>

    <main>
        <?php if (empty($habits)): ?>
            <div class="empty">
                No habits found. Go to <a href="habits.php">Manage Habits</a> to add one and start building momentum.
            </div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($habits as $habit):
                    $hid = intval($habit['id']);
                    $isDoneToday = isset($todayMap[$hid]) && $todayMap[$hid] == 1;
                ?>
                <div class="habit-card">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start">
                        <div>
                            <h4><?php echo e($habit['title']); ?></h4>
                            <?php if (!empty($habit['description'])): ?>
                                <div class="muted" style="margin-top:6px"><?php echo e($habit['description']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div style="text-align:right">
                            <div class="muted">Today</div>
                            <div style="margin-top:6px;font-weight:700"><?php echo $today; ?></div>
                        </div>
                    </div>

                    <div class="actions" style="margin-top:8px">
                        <?php if ($isDoneToday): ?>
                            <div class="chip-done">✅ Completed</div>
                        <?php else: ?>
                            <form method="POST" style="margin:0">
                                <input type="hidden" name="habit_id" value="<?php echo $hid; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <button type="submit" name="complete" class="complete-btn" onclick="this.disabled=true;this.innerText='Saving...'">Mark Completed</button>
                            </form>
                        <?php endif; ?>
                        <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
                            <?php if (!empty($habit['streak'])): ?>
                                <div class="muted">Streak: <strong><?php echo intval($habit['streak']); ?></strong></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- toast area -->
<div id="toast" class="toast"></div>

<script>
    // animate progress bar
    document.addEventListener('DOMContentLoaded', function(){
        var pb = document.getElementById('progressBar');
        var val = parseInt(pb.getAttribute('aria-valuenow') || '0',10);
        setTimeout(function(){ pb.style.width = val + '%'; }, 50);

        // flash messages from PHP session
        <?php if (isset($_SESSION['flash_success'])): ?>
            showToast(<?php echo json_encode($_SESSION['flash_success']); ?>, 'success');
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            showToast(<?php echo json_encode($_SESSION['flash_error']); ?>, 'error');
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
    });

    function showToast(message, type){
        var t = document.getElementById('toast');
        t.textContent = message;
        t.classList.add('show');
        if (type === 'success') t.style.background = '#064e3b';
        else if (type === 'error') t.style.background = '#7f1d1d';
        else t.style.background = '#0f172a';
        setTimeout(function(){ t.classList.remove('show'); }, 3500);
    }
</script>

</body>
</html>
