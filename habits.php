<?php
declare(strict_types=1);

// habits.php - Full improved Habits Manager
// Requirements:
// - functions/habit_functions.php must provide:
//   addHabit(int $user_id, string $title, string $desc, string $freq): void
//   updateHabit(int $id, int $user_id, string $title, string $desc, string $freq): void
//   deleteHabit(int $id, int $user_id): void
//   getHabits(int $user_id, string $q = '', string $filter = ''): array
//   getHabit(int $id, int $user_id): ?array
//   completeHabit(int $id, int $user_id): void
//
// Deploy notes: ensure habit_functions uses prepared statements and proper auth checks.

header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer-when-downgrade");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';");

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

const SESSION_TIMEOUT = 1800; // seconds (30 minutes)
const POST_RATE_LIMIT_SECONDS = 1;
const VALID_FREQUENCIES = ['daily', 'weekly', 'custom'];

require_once __DIR__ . '/functions/habit_functions.php';

// session timeout & regen
if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    header('Location: auth/login.php?expired=1');
    exit;
}
$_SESSION['last_activity'] = time();
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// auth
if (empty($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}
$user_id = (int) $_SESSION['user_id'];

// csrf helpers
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_check(string $token): bool {
    return hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token);
}

// flash
function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}
function get_flash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// escape
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// basic POST rate-limit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $last = (int)($_SESSION['last_post_time'] ?? 0);
    if (time() - $last < POST_RATE_LIMIT_SECONDS) {
        set_flash('error', 'Too many requests - slow down');
        header('Location: habits.php');
        exit;
    }
    $_SESSION['last_post_time'] = time();
}

// handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($token)) {
        set_flash('error', 'Invalid request (CSRF)');
        header('Location: habits.php');
        exit;
    }

    // add
    if (isset($_POST['add'])) {
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $frequency = (string)($_POST['frequency'] ?? 'daily');

        if ($title === '') {
            set_flash('error', 'Title is required');
        } elseif (!in_array($frequency, VALID_FREQUENCIES, true)) {
            set_flash('error', 'Invalid frequency');
        } else {
            addHabit($user_id, $title, $description, $frequency);
            set_flash('success', 'Habit added');
        }
        header('Location: habits.php');
        exit;
    }

    // update
    if (isset($_POST['update'])) {
        $hid = max(0, (int)($_POST['habit_id'] ?? 0));
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $frequency = (string)($_POST['frequency'] ?? 'daily');

        if ($hid <= 0 || $title === '' || !in_array($frequency, VALID_FREQUENCIES, true)) {
            set_flash('error', 'Invalid data');
        } else {
            updateHabit($hid, $user_id, $title, $description, $frequency);
            set_flash('success', 'Habit updated');
        }
        header('Location: habits.php');
        exit;
    }

    // delete
    if (isset($_POST['delete'])) {
        $hid = max(0, (int)($_POST['habit_id'] ?? 0));
        if ($hid > 0) {
            deleteHabit($hid, $user_id);
            set_flash('success', 'Habit deleted');
        } else {
            set_flash('error', 'Invalid habit');
        }
        header('Location: habits.php');
        exit;
    }

    // complete
    if (isset($_POST['complete'])) {
        $hid = max(0, (int)($_POST['habit_id'] ?? 0));
        if ($hid > 0) {
            completeHabit($hid, $user_id);
            set_flash('success', 'Marked as complete');
        } else {
            set_flash('error', 'Invalid habit');
        }
        header('Location: habits.php');
        exit;
    }
}

// GET: search / filter / edit
$q = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['filter'] ?? '');
if ($filter !== '' && !in_array($filter, VALID_FREQUENCIES, true)) {
    $filter = '';
}
$habits = getHabits($user_id, $q, $filter);

$editId = max(0, (int)($_GET['edit'] ?? 0));
$editItem = null;
if ($editId > 0) {
    $editItem = getHabit($editId, $user_id);
}

$flash = get_flash();

?><!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Manage Habits</title>
  <style>
  :root{
    --bg:#f6f8fb;
    --card:#ffffff;
    --muted:#6b7280;
    --accent-start:#6366f1;
    --accent-end:#06b6d4;
    --danger:#ef4444;
    --shadow: 0 10px 30px rgba(15,23,42,0.06);
    --radius:14px;
    --gap:14px;
    --container-w:1100px;
    --text:#0f172a;
  }

  *{box-sizing:border-box}
  body{
    margin:24px;
    font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;
    background:var(--bg);
    color:var(--text);
    -webkit-font-smoothing:antialiased;
    -moz-osx-font-smoothing:grayscale;
  }

  .container{max-width:var(--container-w);margin:0 auto;padding:12px}
  .header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
  .header h1{margin:0;font-size:22px}
  .header .subtitle{color:var(--muted);font-size:13px}

  .card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:18px}
  .form-top{display:grid;grid-template-columns: 1fr 300px;gap:12px;align-items:start}
  @media (max-width:900px){ .form-top{grid-template-columns:1fr} }

  .search-row{display:grid;grid-template-columns: 1fr 140px 100px;gap:10px;align-items:center}
  .search-row input[type="text"], .search-row select{
    height:44px;padding:10px;border-radius:10px;border:1px solid #e6e9ef;background:#fff;font-size:14px;
    box-shadow: 0 1px 0 rgba(0,0,0,0.02);
  }
  .search-row button{height:44px;border-radius:10px;border:1px solid #e6e9ef;background:#fff;padding:0 12px;font-weight:600}

  .form-grid{display:grid;grid-template-columns:1fr 300px;gap:12px;align-items:start;margin-top:12px}
  @media (max-width:900px){ .form-grid{grid-template-columns:1fr} }

  .input, textarea, select{
    width:100%;padding:12px;border-radius:12px;border:1px solid #e6e9ef;background:#fff;font-size:14px;
    resize:vertical;
  }
  textarea{min-height:90px}

  .btn{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    padding:10px 16px;border-radius:12px;border:none;font-weight:700;cursor:pointer;
    transition:transform .13s ease, box-shadow .12s ease;
    background:linear-gradient(90deg,var(--accent-start),var(--accent-end));
    color:#fff; box-shadow: 0 6px 18px rgba(99,102,241,0.12);
  }
  .btn:hover{transform:translateY(-2px)}
  .btn:active{transform:translateY(0)}

  .btn-sec{
    background:#fff;border:1px solid #e6e9ef;padding:10px 14px;border-radius:12px;font-weight:700;cursor:pointer;
  }
  .btn-danger{
    background:var(--danger);color:#fff;padding:10px 14px;border-radius:12px;border:none;font-weight:700;cursor:pointer;
  }

  .habits-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;margin-top:18px}
  .habit{display:flex;flex-direction:column;gap:12px;padding:14px;border-radius:12px;background:var(--card);box-shadow:var(--shadow)}
  .habit .row{display:flex;align-items:flex-start;gap:10px;justify-content:space-between}
  .habit .left{flex:1}
  .habit .title{font-weight:800;margin-bottom:6px}
  .habit .desc{color:var(--muted);font-size:13px}
  .habit .meta{display:flex;flex-direction:column;align-items:flex-end;gap:6px}
  .badge{
    display:inline-block;padding:6px 10px;border-radius:999px;font-size:12px;border:1px solid rgba(0,0,0,0.04);
    background:#f8fafc;color:var(--muted)
  }
  .streak{font-weight:800;color:var(--text);font-size:13px}

  .actions{display:flex;gap:8px;align-items:center}
  .actions .grow{margin-left:auto;display:flex;gap:8px}

  .toast{position:fixed;right:20px;bottom:20px;background:#064e3b;color:#fff;padding:12px 16px;border-radius:10px;display:none;z-index:1000;box-shadow:0 8px 30px rgba(2,6,23,0.25)}

  .input:focus, textarea:focus, select:focus, button:focus{outline:3px solid rgba(99,102,241,0.12);outline-offset:2px}
  .small{font-size:13px;color:var(--muted)}
  </style>
</head>
<body>
<div class="container">
  <div class="header">
    <div>
      <h1>Manage Habits</h1>
      <div class="subtitle">Create, edit and keep your daily routine on track</div>
    </div>
    <div class="actions">
      <a href="dashboard.php" class="btn" aria-label="Back to Dashboard">Back to Dashboard</a>
    </div>
  </div>

  <!-- Search + Back -->
  <form method="GET" action="habits.php" class="card" style="margin-bottom:12px">
    <div class="form-top">
      <div>
        <div class="search-row" style="margin-bottom:12px">
          <input type="text" name="q" placeholder="Search habits..." value="<?php echo e($q); ?>" class="input" aria-label="Search habits">
          <select name="filter" class="input" aria-label="Filter by frequency">
            <option value="" <?php echo ($filter=='')?'selected':''; ?>>All</option>
            <?php foreach (VALID_FREQUENCIES as $f): ?>
              <option value="<?php echo e($f); ?>" <?php echo ($filter===$f)?'selected':''; ?>><?php echo e(ucfirst($f)); ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn-sec">Search</button>
        </div>
        <div class="small">Manage and prioritize your habits — quick search for fast access</div>
      </div>

      <div style="display:flex;justify-content:flex-end;align-items:center">
        <!-- optional extra controls could go here -->
      </div>
    </div>
  </form>

  <!-- Add / Edit card -->
  <div>
    <?php if ($editItem): ?>
      <form method="POST" class="card" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="habit_id" value="<?php echo e((string)$editItem['id']); ?>">
        <div class="form-grid">
          <div>
            <input class="input" type="text" name="title" placeholder="Title" required aria-label="Habit title" value="<?php echo e((string)($editItem['title'] ?? '')); ?>">
            <textarea class="input" name="description" placeholder="Description (optional)"><?php echo e((string)($editItem['description'] ?? '')); ?></textarea>
          </div>
          <div>
            <select name="frequency" class="input" aria-label="Frequency">
              <?php foreach (VALID_FREQUENCIES as $f): ?>
                <option value="<?php echo e($f); ?>" <?php echo (($editItem['frequency'] ?? '') === $f) ? 'selected' : ''; ?>><?php echo e(ucfirst($f)); ?></option>
              <?php endforeach; ?>
            </select>

            <div style="margin-top:12px;display:flex;gap:10px;justify-content:flex-end">
              <button type="submit" name="update" class="btn">Save</button>
              <a href="habits.php" class="btn-sec">Cancel</a>
            </div>
          </div>
        </div>
      </form>
    <?php else: ?>
      <form method="POST" class="card" style="margin-top:0" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
        <div class="form-grid">
          <div>
            <input class="input" type="text" name="title" placeholder="Title" required aria-label="Habit title">
            <textarea class="input" name="description" placeholder="Description (optional)"></textarea>
          </div>
          <div>
            <select name="frequency" class="input" aria-label="Frequency">
              <?php foreach (VALID_FREQUENCIES as $f): ?>
                <option value="<?php echo e($f); ?>"><?php echo e(ucfirst($f)); ?></option>
              <?php endforeach; ?>
            </select>

            <div style="margin-top:12px;display:flex;gap:10px;justify-content:flex-end">
              <button type="submit" name="add" class="btn">Add Habit</button>
              <button type="reset" class="btn-sec">Reset</button>
            </div>
          </div>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <!-- Habits list -->
  <div class="habits-list">
    <?php if (empty($habits)): ?>
      <div class="card">No habits yet. Add one to get started.</div>
    <?php else: foreach ($habits as $habit): ?>
      <div class="habit" role="article" aria-label="<?php echo e($habit['title']); ?>">
        <div class="row">
          <div class="left">
            <div class="title"><?php echo e($habit['title']); ?></div>
            <div class="desc"><?php echo e($habit['description'] ?? ''); ?></div>
          </div>
          <div class="meta">
            <span class="badge"><?php echo e(ucfirst($habit['frequency'])); ?></span>
            <?php if (!empty($habit['streak'])): ?>
              <div class="streak">🔥 <?php echo intval($habit['streak']); ?> streak</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="actions">
          <a href="?edit=<?php echo intval($habit['id']); ?>" class="btn-sec" aria-label="Edit habit">Edit</a>

          <form method="POST" onsubmit="return confirm('Delete this habit?');" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="habit_id" value="<?php echo intval($habit['id']); ?>">
            <button type="submit" name="delete" class="btn-danger">Delete</button>
          </form>

          <form method="POST" style="margin-left:auto">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="habit_id" value="<?php echo intval($habit['id']); ?>">
            <button type="submit" name="complete" class="btn">Mark Done</button>
          </form>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var flash = <?php echo json_encode($flash ?? null); ?>;
  if (flash) { showToast(flash.message, flash.type === 'success' ? 'success' : 'error'); }
});

function showToast(msg, type){
  var t = document.getElementById('toast');
  t.textContent = msg; t.style.display = 'block';
  t.style.background = (type === 'success') ? '#064e3b' : '#7f1d1d';
  setTimeout(function(){ t.style.display = 'none'; }, 3200);
}
</script>
</body>
</html>
