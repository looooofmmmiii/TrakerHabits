<?php
session_start();

// Session timeout and protection (align with dashboard)
$session_timeout = 1800; // 30 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_unset();
    session_destroy();
    header("Location: auth/login.php?expired=1");
    exit;
}
$_SESSION['last_activity'] = time();
if (!isset($_SESSION['initiated'])) { session_regenerate_id(true); $_SESSION['initiated'] = true; }

require_once 'functions/habit_functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = intval($_SESSION['user_id']);

// CSRF token
if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$csrf_token = $_SESSION['csrf_token'];

// Helpers
function e($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Flash helper
function set_flash($key, $msg){ $_SESSION[$key] = $msg; }

// Handle Add / Update / Delete (POST-only for state change)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        set_flash('flash_error', 'Invalid request');
        header('Location: habits.php'); exit;
    }

    // Add
    if (isset($_POST['add'])) {
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $frequency = $_POST['frequency'] ?? 'daily';
        if ($title === '') {
            set_flash('flash_error', 'Title required');
        } else {
            addHabit($user_id, $title, $desc, $frequency);
            set_flash('flash_success', 'Habit added');
        }
        header('Location: habits.php'); exit;
    }

    // Update
    if (isset($_POST['update'])) {
        $hid = intval($_POST['habit_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $frequency = $_POST['frequency'] ?? 'daily';
        if ($hid <= 0 || $title === '') {
            set_flash('flash_error', 'Invalid data');
        } else {
            updateHabit($hid, $user_id, $title, $desc, $frequency);
            set_flash('flash_success', 'Habit updated');
        }
        header('Location: habits.php'); exit;
    }

    // Delete
    if (isset($_POST['delete'])) {
        $hid = intval($_POST['habit_id'] ?? 0);
        if ($hid > 0) {
            deleteHabit($hid, $user_id);
            set_flash('flash_success', 'Habit deleted');
        } else {
            set_flash('flash_error', 'Invalid habit');
        }
        header('Location: habits.php'); exit;
    }
}

// Search / filter
$q = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? '';
$habits = getHabits($user_id, $q, $filter); // assume function supports optional params

// Edit mode data
$editId = intval($_GET['edit'] ?? 0);
$editItem = null;
if ($editId > 0) {
    // get single habit (assume getHabit exists)
    $editItem = getHabit($editId, $user_id);
}

?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manage Habits</title>
<style>
:root{--bg:#f5f7fb;--card:#fff;--muted:#6b7280;--accent1:#6366f1;--accent2:#06b6d4;--danger:#ef4444;--shadow:0 8px 30px rgba(2,6,23,0.06)}
body{font-family:Inter,system-ui,Segoe UI,Roboto,Arial;margin:20px;background:var(--bg);color:#0f172a}
.container{max-width:1000px;margin:0 auto}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
.btn{background:linear-gradient(90deg,var(--accent1),var(--accent2));color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:700}
.card{background:var(--card);padding:14px;border-radius:12px;box-shadow:var(--shadow);}
.form-grid{display:grid;grid-template-columns:1fr 220px;gap:12px}
input,textarea,select{width:100%;padding:10px;border-radius:8px;border:1px solid #e6e9ef}
textarea{min-height:86px}
.actions{display:flex;gap:8px;align-items:center}
.habits-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-top:12px}
.habit{background:var(--card);padding:12px;border-radius:12px;box-shadow:var(--shadow);display:flex;flex-direction:column;gap:8px}
.habit .meta{display:flex;justify-content:space-between;align-items:center}
.small{color:var(--muted);font-size:13px}
.btn-sec{background:#fff;border:1px solid #e6e9ef;padding:8px 10px;border-radius:8px}
.btn-danger{background:var(--danger);color:#fff;padding:8px 10px;border-radius:8px;border:none}
.search-row{display:flex;gap:8px;margin-bottom:12px}
.toast{position:fixed;right:20px;bottom:20px;background:#064e3b;color:#fff;padding:12px 16px;border-radius:10px;display:none}
/* responsive */
@media(max-width:700px){.form-grid{grid-template-columns:1fr}.header{flex-direction:column;align-items:flex-start;gap:8px}}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1 style="margin:0;font-size:20px">Manage Habits</h1>
            <div class="small">Create, edit and keep your daily routine on track</div>
        </div>
        <div class="actions">
            <a href="dashboard.php" class="btn">Back to Dashboard</a>
        </div>
    </div>

    <div class="card">
        <?php if ($editItem): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="habit_id" value="<?php echo e($editItem['id']); ?>">
                <div class="form-grid">
                    <div>
                        <label class="small">Title</label>
                        <input type="text" name="title" required value="<?php echo e($editItem['title']); ?>">
                        <label class="small">Description</label>
                        <textarea name="description"><?php echo e($editItem['description'] ?? ''); ?></textarea>
                    </div>
                    <div>
                        <label class="small">Frequency</label>
                        <select name="frequency">
                            <option value="daily" <?php echo ($editItem['frequency']=='daily')?'selected':''; ?>>Daily</option>
                            <option value="weekly" <?php echo ($editItem['frequency']=='weekly')?'selected':''; ?>>Weekly</option>
                            <option value="custom" <?php echo ($editItem['frequency']=='custom')?'selected':''; ?>>Custom</option>
                        </select>
                        <div style="margin-top:12px;display:flex;gap:8px">
                            <button type="submit" name="update" class="btn">Save</button>
                            <a href="habits.php" class="btn-sec">Cancel</a>
                        </div>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <form method="GET" action="habits.php">
                <div class="search-row">
                    <input type="text" name="q" placeholder="Search habits..." value="<?php echo e($q); ?>">
                    <select name="filter">
                        <option value="" <?php echo ($filter=='')?'selected':''; ?>>All</option>
                        <option value="daily" <?php echo ($filter=='daily')?'selected':''; ?>>Daily</option>
                        <option value="weekly" <?php echo ($filter=='weekly')?'selected':''; ?>>Weekly</option>
                        <option value="custom" <?php echo ($filter=='custom')?'selected':''; ?>>Custom</option>
                    </select>
                    <button type="submit" class="btn-sec">Search</button>
                </div>

            </form>

            <form method="POST" style="margin-top:12px">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="form-grid">
                    <div>
                        <input type="text" name="title" placeholder="Title" required>
                        <textarea name="description" placeholder="Description (optional)"></textarea>
                    </div>
                    <div>
                        <select name="frequency">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="custom">Custom</option>
                        </select>

                        <div style="margin-top:12px;display:flex;gap:8px">
                            <button type="submit" name="add" class="btn">Add Habit</button>
                            <button type="reset" class="btn-sec">Reset</button>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <div class="habits-list">
        <?php if (empty($habits)): ?>
            <div class="card">No habits yet. Add one to get started.</div>
        <?php else: foreach ($habits as $habit): ?>
            <div class="habit">
                <div class="meta">
                    <div>
                        <strong><?php echo e($habit['title']); ?></strong>
                        <div class="small"><?php echo e($habit['description'] ?? ''); ?></div>
                    </div>
                    <div style="text-align:right">
                        <div class="small"><?php echo e($habit['frequency']); ?></div>
                        <?php if (!empty($habit['streak'])): ?>
                            <div class="small">Streak: <strong><?php echo intval($habit['streak']); ?></strong></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display:flex;gap:8px;margin-top:8px">
                    <a href="?edit=<?php echo intval($habit['id']); ?>" class="btn-sec">Edit</a>

                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this habit?');">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="habit_id" value="<?php echo intval($habit['id']); ?>">
                        <button type="submit" name="delete" class="btn-danger">Delete</button>
                    </form>

                    <form method="POST" action="dashboard.php" style="margin-left:auto;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="habit_id" value="<?php echo intval($habit['id']); ?>">
                        <button type="submit" name="complete" class="btn">Mark Done</button>
                    </form>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

</div>

<div id="toast" class="toast"></div>
<script>
// show flash from session
window.addEventListener('DOMContentLoaded', function(){
    <?php if (isset($_SESSION['flash_success'])): ?>
        showToast(<?php echo json_encode($_SESSION['flash_success']); ?>, 'success');
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['flash_error'])): ?>
        showToast(<?php echo json_encode($_SESSION['flash_error']); ?>, 'error');
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>
});
function showToast(msg, type){
    var t = document.getElementById('toast');
    t.textContent = msg; t.style.display='block';
    if (type==='success') t.style.background='#064e3b'; else t.style.background='#7f1d1d';
    setTimeout(function(){ t.style.display='none'; }, 3200);
}
</script>
</body>
</html>
