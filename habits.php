<?php declare(strict_types=1);
// habits.php - Improved Habits Manager (black theme variant)
// Notes: same logic as your original file; CSS changed to black / dark theme with softer accents.

// -----------------------------
// Config / defaults
// -----------------------------
if (!defined('VALID_FREQUENCIES')) {
    define('VALID_FREQUENCIES', ['daily', 'weekly', 'monthly']);
}
if (!defined('POST_RATE_LIMIT_SECONDS')) {
    define('POST_RATE_LIMIT_SECONDS', 2); // sensible default
}
if (!defined('SESSION_NAME')) {
    define('SESSION_NAME', 'habit_sid');
}
if (!defined('SESSION_TIMEOUT_SECONDS')) {
    define('SESSION_TIMEOUT_SECONDS', 1800); // 30 minutes
}

// -----------------------------
// Secure session cookie params (must be set before session_start)
// -----------------------------
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$cookieParams = [
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
];

session_name(SESSION_NAME);
// set cookie params in a PHP 7.3+ compatible way
session_set_cookie_params($cookieParams);
ini_set('session.use_strict_mode', '1');

// Start session asap
session_start();

// -----------------------------
// Basic security headers
// -----------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');
// X-XSS-Protection is deprecated in modern browsers but harmless to keep
header('X-XSS-Protection: 1; mode=block');

// -----------------------------
// Session timeout & fixation protection
// -----------------------------
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

$now = time();
if (isset($_SESSION['last_activity']) && ($now - (int)$_SESSION['last_activity'] > SESSION_TIMEOUT_SECONDS)) {
    // expire session
    session_unset();
    session_destroy();
    // safe redirect
    header('Location: auth/login.php?expired=1');
    exit;
}
$_SESSION['last_activity'] = $now;

// -----------------------------
// Helpers
// -----------------------------
if (!function_exists('e')) {
    function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function safe_redirect(string $url): void
{
    // Basic guard against header injection
    $url = str_replace(["\r", "\n"], '', $url);
    header('Location: ' . $url);
    exit;
}

// CSRF
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(string $token): bool
{
    return !empty($token) && hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token);
}

// Flash
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}
function get_flash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// -----------------------------
// Require habit functions (app must implement these)
// -----------------------------
require_once __DIR__ . '/functions/habit_functions.php';

// -----------------------------
// Simple auth check (redirect to login if not authenticated)
// -----------------------------
if (empty($_SESSION['user_id'])) {
    safe_redirect('auth/login.php');
}
$user_id = (int)$_SESSION['user_id'];

// -----------------------------
// Rate-limit POSTs (server-side)
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $last = (int)($_SESSION['last_post_time'] ?? 0);
    if ($now - $last < POST_RATE_LIMIT_SECONDS) {
        set_flash('error', 'Too many requests - please slow down');
        safe_redirect('habits.php');
    }
    $_SESSION['last_post_time'] = $now;
}

// -----------------------------
// Handle POST actions
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($token)) {
        set_flash('error', 'Invalid request (CSRF)');
        safe_redirect('habits.php');
    }

    try {
        // ADD
        if (isset($_POST['add'])) {
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $frequency = (string)($_POST['frequency'] ?? VALID_FREQUENCIES[0]);

            if ($title === '') {
                set_flash('error', 'Title is required');
            } elseif (!in_array($frequency, VALID_FREQUENCIES, true)) {
                set_flash('error', 'Invalid frequency');
            } else {
                addHabit($user_id, $title, $description, $frequency);
                set_flash('success', 'Habit added');
            }
            safe_redirect('habits.php');
        }

        // UPDATE
        if (isset($_POST['update'])) {
            $hid = max(0, (int)($_POST['habit_id'] ?? 0));
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $frequency = (string)($_POST['frequency'] ?? VALID_FREQUENCIES[0]);

            if ($hid <= 0 || $title === '' || !in_array($frequency, VALID_FREQUENCIES, true)) {
                set_flash('error', 'Invalid data');
            } else {
                updateHabit($hid, $user_id, $title, $description, $frequency);
                set_flash('success', 'Habit updated');
            }
            safe_redirect('habits.php');
        }

        // DELETE
        if (isset($_POST['delete'])) {
            $hid = max(0, (int)($_POST['habit_id'] ?? 0));
            if ($hid > 0) {
                deleteHabit($hid, $user_id);
                set_flash('success', 'Habit deleted');
            } else {
                set_flash('error', 'Invalid habit');
            }
            safe_redirect('habits.php');
        }

        // COMPLETE
        if (isset($_POST['complete'])) {
            $hid = max(0, (int)($_POST['habit_id'] ?? 0));
            if ($hid > 0) {
                completeHabit($hid, $user_id);
                set_flash('success', 'Marked as complete');
            } else {
                set_flash('error', 'Invalid habit');
            }
            safe_redirect('habits.php');
        }
    } catch (\Exception $ex) {
        // log exception in your app log; do not reveal internal details to user
        error_log('Habits error: ' . $ex->getMessage());
        set_flash('error', 'Server error — try again later');
        safe_redirect('habits.php');
    }
}

// -----------------------------
// GET: search / filter / edit
// -----------------------------
$q = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['filter'] ?? '');
if ($filter !== '' && !in_array($filter, VALID_FREQUENCIES, true)) {
    $filter = '';
}

$habits = getHabits($user_id, $q, $filter) ?? [];
$editId = max(0, (int)($_GET['edit'] ?? 0));
$editItem = null;
if ($editId > 0) {
    $editItem = getHabit($editId, $user_id);
}

$flash = get_flash();

// -----------------------------
// Render Page (HTML)
// -----------------------------
?><!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manage Habits — Black theme</title>
<style>
:root{
  /* white theme with soft accents */
  --bg:#f9fafb;            /* light background */
  --surface:#ffffff;       /* card surface pure white */
  --muted:#6b7280;         /* muted text (gray) */
  --text:#1f2937;          /* primary text on light bg */
  --accent-start:#18a699;  /* gentle teal */
  --accent-end:#7b6cf6;    /* soft purple */
  --danger:#dc4c4c;        /* softened red */
  --shadow:0 10px 30px rgba(0,0,0,0.08);
  --radius:12px;
  --gap:12px;
  --container-w:1100px;
  --focus:rgba(120,120,200,0.12);
  --toast-success:#0f766e;
  --toast-error:#b91c1c;
  --input-bg:rgba(0,0,0,0.02);
  --border:rgba(0,0,0,0.08);
  --glass:rgba(255,255,255,0.7);
}
*{box-sizing:border-box}
body{margin:24px;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;}
.container{max-width:var(--container-w);margin:0 auto;padding:12px}
.header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
.header h1{margin:0;font-size:22px}
.header .subtitle{color:var(--muted);font-size:13px}
.card{background:linear-gradient(180deg,var(--surface),rgba(0,0,0,0.01));border-radius:var(--radius);box-shadow:var(--shadow);padding:18px;border:1px solid var(--border)}
.form-top{display:grid;grid-template-columns:1fr 300px;gap:12px;align-items:start}
@media(max-width:900px){.form-top{grid-template-columns:1fr}}
.search-row{display:grid;grid-template-columns:1fr 140px 100px;gap:10px;align-items:center}
.search-row input[type="text"],.search-row select{height:44px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--input-bg);font-size:14px;color:var(--text);}
.search-row button{height:44px;border-radius:10px;border:1px solid var(--border);background:transparent;color:var(--text);padding:0 12px;font-weight:600}
.form-grid{display:grid;grid-template-columns:1fr 300px;gap:12px;align-items:start;margin-top:12px}
@media(max-width:900px){.form-grid{grid-template-columns:1fr}}
.input,textarea,select{width:100%;padding:12px;border-radius:10px;border:1px solid var(--border);background:var(--input-bg);font-size:14px;color:var(--text);resize:vertical;}
textarea{min-height:90px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 16px;border-radius:10px;border:none;font-weight:700;cursor:pointer;transition:transform .10s ease,box-shadow .10s ease;background:linear-gradient(90deg,var(--accent-start),var(--accent-end));color:#fff;box-shadow:0 6px 20px rgba(123,108,246,0.18);}
.btn:hover{transform:translateY(-2px)}
.btn:active{transform:translateY(0)}
.btn-sec{background:transparent;border:1px solid var(--border);padding:10px 14px;border-radius:10px;font-weight:700;color:var(--muted);cursor:pointer;}
.btn-danger{background:transparent;color:var(--danger);border:1px solid rgba(220,76,76,0.2);padding:10px 14px;border-radius:10px;font-weight:700;cursor:pointer;box-shadow:none;}
.habits-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;margin-top:18px}
.habit{display:flex;flex-direction:column;gap:12px;padding:14px;border-radius:10px;background:linear-gradient(180deg,rgba(0,0,0,0.01),rgba(0,0,0,0.005));box-shadow:var(--shadow);border:1px solid var(--border)}
.habit .row{display:flex;align-items:flex-start;gap:10px;justify-content:space-between}
.habit .left{flex:1}
.habit .title{font-weight:700;margin-bottom:6px;color:var(--text)}
.habit .desc{color:var(--muted);font-size:13px}
.habit .meta{display:flex;flex-direction:column;align-items:flex-end;gap:6px}
.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-size:12px;border:1px solid rgba(0,0,0,0.05);background:rgba(0,0,0,0.02);color:var(--muted);font-weight:600}
.streak{font-weight:700;color:var(--text);font-size:13px}
.actions{display:flex;gap:8px;align-items:center}
.actions .grow{margin-left:auto;display:flex;gap:8px}
.toast{position:fixed;right:20px;bottom:20px;background:var(--toast-success);color:#fff;padding:12px 16px;border-radius:10px;display:none;z-index:1000;box-shadow:0 12px 30px rgba(0,0,0,0.2)}
.input:focus,textarea:focus,select:focus,button:focus{outline:0;border-color:var(--accent-end);box-shadow:0 0 0 6px var(--focus);outline-offset:0}
.small{font-size:13px;color:var(--muted)}

/* subtle hover lift */
.habit:hover{transform:translateY(-6px);transition:transform .14s cubic-bezier(.2,.9,.2,1)}

/* responsive */
@media(max-width:640px){
  .search-row{grid-template-columns:1fr 120px}
  .form-top{grid-template-columns:1fr}
}

</style>
</head>
<body>
<?php include "elements.php"; ?>  <!-- sidebar only once -->
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
  <form method="GET" action="<?php echo e($_SERVER['PHP_SELF']); ?>" class="card" style="margin-bottom:12px">
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
        <input type="hidden" name="habit_id" value="<?php echo e((string)($editItem['id'] ?? '0')); ?>">
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
      <div class="habit" role="article" aria-label="<?php echo e($habit['title'] ?? ''); ?>">
        <div class="row">
          <div class="left">
            <div class="title"><?php echo e($habit['title'] ?? ''); ?></div>
            <div class="desc"><?php echo e($habit['description'] ?? ''); ?></div>
          </div>
          <div class="meta">
            <span class="badge"><?php echo e(ucfirst((string)($habit['frequency'] ?? ''))); ?></span>
            <?php if (!empty($habit['streak'])): ?>
              <div class="streak">🔥 <?php echo intval($habit['streak']); ?> streak</div>
            <?php endif; ?>
          </div>
        </div>
        <div class="actions">
          <a href="?edit=<?php echo intval($habit['id'] ?? 0); ?>" class="btn-sec" aria-label="Edit habit">Edit</a>

          <form method="POST" onsubmit="return confirm('Delete this habit?');" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="habit_id" value="<?php echo intval($habit['id'] ?? 0); ?>">
            <button type="submit" name="delete" class="btn-danger">Delete</button>
          </form>

          <form method="POST" style="margin-left:auto">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="habit_id" value="<?php echo intval($habit['id'] ?? 0); ?>">
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
  var flash = <?php echo json_encode($flash ?? null, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
  if (flash) {
    showToast(flash.message, flash.type === 'success' ? 'success' : 'error');
  }
});
function showToast(msg, type){
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.style.display = 'block';
  t.style.background = (type === 'success') ? getComputedStyle(document.documentElement).getPropertyValue('--toast-success') : getComputedStyle(document.documentElement).getPropertyValue('--toast-error');
  setTimeout(function(){ t.style.display = 'none'; }, 3200);
}
</script>
</body>
</html>
