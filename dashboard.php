<?php declare(strict_types=1);
/**
 * Updated dashboard.php — Drag-and-drop reorder (grid) using SortableJS
 * - use this file to replace your existing dashboard.php
 * - now handles reorder requests itself (no separate reorder_habits.php)
 */

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
require_once 'config/db.php'; // ensure $pdo

// Session timeout
$session_timeout = 180000000;
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

// helper: ensure iterable to avoid foreach warnings (safe fallback)
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
$user_id = (int)$_SESSION['user_id'];

$today = date('Y-m-d');

// Simple CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// --- NEW: Handle reorder via JSON POST to dashboard.php ---
// Client posts JSON: { order: [id1, id2, ...], csrf_token: '...' }
// --- NEW: Handle reorder via JSON POST to dashboard.php ---
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false
) {
    header('Content-Type: application/json; charset=utf-8');

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    $token = (string)($payload['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $order = $payload['order'] ?? null;
    if (!is_array($order)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid order array']);
        exit;
    }

    // sanitize -> ints, remove zeros/duplicates while preserving order
    $clean = [];
    foreach ($order as $v) {
        $id = intval($v);
        if ($id > 0 && !in_array($id, $clean, true)) $clean[] = $id;
    }

    if (count($clean) === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Empty order']);
        exit;
    }

    try {
        // fetch all user's habit ids (stable canonical set)
        $stmt = $pdo->prepare('SELECT id FROM habits WHERE user_id = :uid ORDER BY COALESCE(sort_order, 0) ASC, id ASC');
        $stmt->execute([':uid' => $user_id]);
        $existing = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        // keep only ids that actually belong to this user (ignore malicious ids)
        $clean = array_values(array_filter($clean, function($id) use ($existing) {
            return in_array($id, $existing, true);
        }));

        // final order = client-sent IDs first (in that order), then any remaining habits preserved
        $final = $clean;
        foreach ($existing as $eid) {
            if (!in_array($eid, $final, true)) $final[] = $eid;
        }

        // if nothing changed, return current canonical order
        $same = true;
        if (count($final) === count($existing)) {
            for ($i = 0; $i < count($final); $i++) {
                if ($final[$i] !== $existing[$i]) { $same = false; break; }
            }
        } else {
            $same = false;
        }
        if ($same) {
            // return canonical order from DB
            echo json_encode(['ok' => true, 'order' => $existing]);
            exit;
        }

        // perform a single UPDATE with CASE WHEN ... THEN ... END for atomicity & efficiency
        $cases = [];
        $params = [];
        foreach ($final as $i => $hid) {
            $pos = $i + 1; // positions start at 1
            // We'll add pairs: WHEN id = ? THEN ?
            $cases[] = "WHEN id = ? THEN ?";
            $params[] = $hid;
            $params[] = $pos;
        }
        $sql = "UPDATE habits SET sort_order = CASE " . implode(" ", $cases) . " ELSE sort_order END WHERE user_id = ?";
        $params[] = $user_id;

        $pdo->beginTransaction();
        $upd = $pdo->prepare($sql);
        $upd->execute($params);

        // After update, fetch canonical order from DB to return to client
        $stmt2 = $pdo->prepare('SELECT id FROM habits WHERE user_id = :uid ORDER BY COALESCE(sort_order, 0) ASC, id ASC');
        $stmt2->execute([':uid' => $user_id]);
        $canonical = array_map('intval', $stmt2->fetchAll(PDO::FETCH_COLUMN));

        $pdo->commit();

        echo json_encode(['ok' => true, 'order' => $canonical]);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('reorder error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Server error']);
        exit;
    }
}


// Handle POST (complete habit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Invalid request';
        header('Location: dashboard.php'); exit;
    }

    $last_mark = $_SESSION['last_mark_time'] ?? 0;
    if (time() - $last_mark < 1) {
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

    $ok = trackHabit($habit_id, date('Y-m-d'));

    // If successfully tracked — move to end (set sort_order = max+1)
    if ($ok) {
        try {
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) AS m FROM habits WHERE user_id = :uid');
            $stmt->execute([':uid' => $user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $max = intval($row['m'] ?? 0);
            $upd = $pdo->prepare('UPDATE habits SET sort_order = :pos WHERE id = :id AND user_id = :uid');
            $upd->execute([':pos' => $max + 1, ':id' => $habit_id, ':uid' => $user_id]);
        } catch (Throwable $e) {
            error_log('move-to-end error: '.$e->getMessage());
        }
    }

    $_SESSION['flash_' . ($ok ? 'success' : 'error')] = $ok ? 'Habit marked as completed' : 'Unable to track habit';
    header('Location: dashboard.php'); exit;
}



// --- Obtain dashboard data (prefer centralized function) ---
$data = [];
try {
    if (function_exists('getDashboardData')) {
        $data = getDashboardData($user_id, $pdo);
        if (!is_array($data)) $data = [];
    } else {
        ensureTrackingForToday($user_id);
        // IMPORTANT: getHabits must ORDER BY sort_order ASC, id ASC — but if it doesn't, we'll enforce ordering in PHP
        $habits = getHabits($user_id); // ensure getHabits uses ORDER BY sort_order
        ensure_iterable($habits);

        // Normalize sort_order and ensure stable ordering: any missing sort_order go to the end, tie-breaker by id
        foreach ($habits as &$__h) {
            if (!isset($__h['sort_order']) || $__h['sort_order'] === null || $__h['sort_order'] === '') {
                // push unknown order to a large number but keep original id tie-breaker
                $__h['sort_order'] = PHP_INT_MAX;
            } else {
                $__h['sort_order'] = intval($__h['sort_order']);
            }
            // ensure id is int
            $__h['id'] = intval($__h['id'] ?? 0);
        }
        unset($__h);

        usort($habits, function($a, $b){
            if ($a['sort_order'] === $b['sort_order']) return $a['id'] <=> $b['id'];
            return $a['sort_order'] <=> $b['sort_order'];
        });

        $progress = getHabitProgress($user_id);
        ensure_iterable($progress);

        $data = [
            'habits' => $habits,
            'progress' => $progress,
            'displayHabits' => $habits,
            'completedDatesByHabit' => [],
            'habitDoneMap' => [],
            'habitNextAvailableMap' => [],
            'completedToday' => 0,
            'totalHabits' => count($habits),
            'missedToday' => count($habits),
            'efficiency' => 0,
            'habitStreakMap' => [],
            'longestStreak' => 0,
            'chart' => ['labels' => [], 'values' => [], 'series' => [], 'predicted' => null, 'predictedDate' => null]
        ];
    }
} catch (Throwable $ex) {
    error_log('getDashboardData error: ' . $ex->getMessage());
    $data = [];
}

// --- map dashboard data -> local variables (safe fallbacks) ---
$habits = $data['habits'] ?? [];
ensure_iterable($habits);
$displayHabits = $data['displayHabits'] ?? $habits;
ensure_iterable($displayHabits);
$progress = $data['progress'] ?? [];
ensure_iterable($progress);
$completedDatesByHabit = $data['completedDatesByHabit'] ?? [];
$habitDoneMap = $data['habitDoneMap'] ?? [];
$habitNextAvailableMap = $data['habitNextAvailableMap'] ?? [];
$completedToday = isset($data['completedToday']) ? intval($data['completedToday']) : 0;
$totalHabits = isset($data['totalHabits']) ? intval($data['totalHabits']) : count($habits);
$missedToday = isset($data['missedToday']) ? intval($data['missedToday']) : max(0, $totalHabits - $completedToday);
$efficiency = isset($data['efficiency']) ? intval($data['efficiency']) : 0;
$habitStreakMap = $data['habitStreakMap'] ?? [];
$longestStreak = isset($data['longestStreak']) ? intval($data['longestStreak']) : 0;

// chart & series
$chart_labels = $data['chart']['labels'] ?? [];
$chart_values = $data['chart']['values'] ?? [];
$series = $data['chart']['series'] ?? [];
$predicted = $data['chart']['predicted'] ?? null;
$predictedDate = $data['chart']['predictedDate'] ?? null;

// compute current best streak and historical longest if not in payload
if (!is_array($habitStreakMap)) $habitStreakMap = [];
if (!isset($longestStreak) || $longestStreak === null) $longestStreak = 0;
// compute current best active streak
$currentBestStreak = 0;
foreach ($habitStreakMap as $hs) {
    $c = intval($hs['current'] ?? 0);
    if ($c > $currentBestStreak) $currentBestStreak = $c;
}

// Build incompleteHabits for roulette (safe, uses habitDoneMap)
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
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/styles/dashboard.css">
<title>Dashboard — Habits</title>
<style>
/* (styles omitted here for brevity in the editor) */
:root{--bg:#f5f7fb;--card:#fff;--muted:#667085;--success-start:#34d399;--success-end:#10b981;--shadow:0 6px 18px rgba(16,24,40,0.06)}
/* ... rest of CSS from original file ... */
</style>
</head>
<body>
    <?php include "elements.php"; ?>  <!-- sidebar only once -->
    
    <div class="main-content">
        <div class="container">
            <header style="display:flex;justify-content:space-between;align-items:center">
                <div>
                    <h1 style="margin:0">Dashboard — Habits & Progress</h1>
                    <div class="muted" style="margin-top:6px">Today: <?php echo e($today); ?></div>
                </div>
            </header>

    <section class="top-stats" role="region" aria-label="Top statistics">
        <div class="stat">
            <h3>Total Habits</h3>
            <p><?php echo intval($totalHabits); ?></p>
            <div class="muted" style="margin-top:6px">Active goals to manage</div>
        </div>

        <div class="stat">
            <h3>Completed Today</h3>
            <p><?php echo intval($completedToday); ?></p>
            <div class="muted" style="margin-top:6px"><?php echo intval($completedToday); ?> / <?php echo intval($totalHabits); ?> tasks</div>
        </div>

        <div class="stat">
            <h3>Missed Today</h3>
            <p><?php echo intval($missedToday); ?></p>
            <div class="muted" style="margin-top:6px">Opportunity to improve</div>
        </div>

        <div class="stat">
            <h3>Day Efficiency</h3>
            <p><?php echo intval($efficiency); ?>%</p>
            <div class="muted" style="margin-top:6px">
                <?php if ($efficiency === 100 && $totalHabits>0): ?>
                    <span style="display:inline-block;padding:6px 10px;border-radius:999px;background:#eef2ff;font-weight:700">All done — Great job!</span>
                <?php else: ?>
                    Keep it sustainable — <?php echo intval($efficiency); ?>% complete
                <?php endif; ?>
            </div>
        </div>

        <div class="stat">
        <h3>Best Streak</h3>
        <p><?php echo intval($longestStreak); ?> days now</p>
        <div class="muted">Longest streak across habits</div>
    </div>

    </section>

    <section class="progress-wrap" aria-label="Daily progress">
        <div class="progress-label">
            <div class="muted">Daily progress</div>
            <div style="font-weight:700"><?php echo intval($efficiency); ?>%</div>
        </div>
        <div class="progress-bar-outer" aria-hidden="true">
            <?php
                if ($efficiency >= 75) $grad = "linear-gradient(90deg,#34d399,#10b981)";
                elseif ($efficiency >= 40) $grad = "linear-gradient(90deg,#f59e0b,#f97316)";
                else $grad = "linear-gradient(90deg,#ef4444,#f43f5e)";
            ?>
            <div id="progressBar"
                class="progress-bar"
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="<?php echo intval($efficiency); ?>"
                style="background: <?php echo $grad; ?>;">
            </div>
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
                            <div style="display:flex;align-items:center;gap:8px;">
                              <h4 id="habit-title-<?php echo $hid; ?>" style="margin:0"><?php echo e($habit['title']); ?></h4>
                              <button type="button" class="drag-handle" aria-label="Drag to reorder" title="Hold & drag to reorder">☰</button>
                            </div>
                            <?php if (!empty($habit['description'])): ?>
                                <div class="muted habit-desc">
                                    <div class="desc-text" id="desc-full-<?php echo $hid; ?>">
                                        <?php echo function_exists('linkify') ? linkify($habit['description']) : e($habit['description']); ?>
                                    </div>
                                    <button type="button" class="desc-toggle" aria-expanded="false" aria-controls="desc-full-<?php echo $hid; ?>" onclick="event.stopPropagation(); toggleDesc('full-<?php echo $hid; ?>');">
                                        Show more
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="muted">No description provided.</div>
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
                                    $ndObj = DateTimeImmutable::createFromFormat('Y-m-d', $nextDate) ?: new DateTimeImmutable($nextDate);
                                    if ($ndObj <= new DateTimeImmutable($today)) {
                                        echo 'Available now';
                                    } else {
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
<script src="assets\scripts\dashboard.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<!-- Sortable init for grid (uses .drag-handle) -->
<script>
(function(){
  function saveOrderToServer() {
  var cards = Array.from(document.querySelectorAll('.grid .habit-card'));
  var order = cards.map(function(c){ return parseInt(c.dataset.hid,10) || 0; }).filter(Boolean);

  // Важливо: явно відправляємо credentials, щоб сесійна кука дійшла до сервера
  fetch('dashboard.php', {   // <- now posts back to dashboard.php
    method: 'POST',
    credentials: 'same-origin',   // <- критично для сесії
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ order: order, csrf_token: <?php echo json_encode($csrf_token); ?> })
  })
  .then(function(resp){
    if (!resp.ok) {
      return resp.text().then(function(t){
        throw new Error('Server ' + resp.status + ': ' + (t || resp.statusText));
      });
    }
    var ct = resp.headers.get('content-type') || '';
    if (ct.indexOf('application/json') === -1) {
      return resp.text().then(function(t){
        throw new Error('Invalid JSON response: ' + t);
      });
    }
    return resp.json();
  })
  .then(function(json){
    if (json && json.ok) {
      // If server returned canonical order, reorder DOM to match it (ensures client UI matches DB immediately)
      try {
        if (json.order && Array.isArray(json.order) && json.order.length) {
          var grid = document.querySelector('.grid');
          if (grid) {
            var map = {};
            Array.from(grid.querySelectorAll('.habit-card')).forEach(function(c){ var id = parseInt(c.dataset.hid,10)||0; if (id) map[id]=c; });
            // append in server order (this moves existing nodes)
            json.order.forEach(function(id){ var el = map[id]; if (el) grid.appendChild(el); });
          }
        }
      } catch(e) {
        console.error('reorder-dom error', e);
      }
      showFlash('Order saved', 'success');
    } else {
      showFlash((json && json.error) ? json.error : 'Save failed', 'error');
    }
  })
  .catch(function(err){
    console.error('saveOrder error', err);
    showFlash('Network error while saving order — ' + (err && err.message ? err.message : 'check console'), 'error');
  });
}


  document.addEventListener('DOMContentLoaded', function(){
    var grid = document.querySelector('.grid');
    if (!grid) return;

    if (typeof Sortable === 'undefined') {
      console.warn('SortableJS not loaded — reorder disabled');
      return;
    }

    Sortable.create(grid, {
      animation: 180,
      handle: '.drag-handle',
      draggable: '.habit-card',
      chosenClass: 'sortable-chosen',
      ghostClass: 'sortable-ghost',
      onEnd: function(evt) {
        saveOrderToServer();
      }
    });
  });
})();
</script>
<script>




// Показати / сховати опис (toggle)
function toggleDesc(id) {
    // id може бути або число (hid) або string 'full-<hid>' — у коді ми використовуємо id як частину id елемента
    var elId = (typeof id === 'number') ? 'desc-' + id : 'desc-' + id;
    var wrapper = document.querySelector('#' + elId)?.closest('.habit-desc');
    if (!wrapper) {
        // спробуємо альтернативний id для повного блоку
        elId = (typeof id === 'number') ? 'desc-full-' + id : 'desc-' + id;
        wrapper = document.querySelector('#' + elId)?.closest('.habit-desc');
        if (!wrapper) return;
    }
    var btn = wrapper.querySelector('.desc-toggle');
    var expanded = wrapper.classList.toggle('expanded');
    if (btn) {
        btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        btn.textContent = expanded ? 'Show less' : 'Show more';
    }
}

// Показувати кнопку тільки якщо текст обрізаний
function initDescriptionToggles() {
    document.querySelectorAll('.habit-desc').forEach(function(wrap){
        var txt = wrap.querySelector('.desc-text');
        var btn = wrap.querySelector('.desc-toggle');
        if (!txt || !btn) return;

        // якщо скрол більше висоти — значить обрізаний
        var isClipped = txt.scrollHeight > txt.clientHeight + 1;
        if (isClipped) {
            btn.style.display = 'inline-block';
        } else {
            btn.style.display = 'none';
        }

        // запобігати спливанню при кліку на посилання всередині опису
        wrap.querySelectorAll('a').forEach(function(a){
            a.addEventListener('click', function(ev){
                ev.stopPropagation();
            });
        });
    });
}

// виклик при завантаженні
document.addEventListener('DOMContentLoaded', function(){
    // ...Ваш існуючий код...
    initDescriptionToggles();

    // Також, після ресайзу перерахувати (корисно, якщо верстка зміниться)
    window.addEventListener('resize', function(){
        // debounce простий
        clearTimeout(window._descResizeTimer);
        window._descResizeTimer = setTimeout(initDescriptionToggles, 250);
    });
});

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
   ------------------------ */
function renderMiniChart(elId, labels, values, predicted) {
    var container = document.getElementById(elId);
    if (!container) return;
    container.innerHTML = '';

    if (!values || values.length === 0) {
        container.innerHTML = '<div class="muted" style="padding:18px;text-align:center">No data</div>';
        return;
    }

    // Отримуємо реальні розміри (включно з padding)
    var rect = container.getBoundingClientRect();
    var w = Math.max(320, Math.floor(rect.width)) || 600;
    var h = Math.max(120, Math.floor(rect.height)) || 140;
    var padding = {l:28, r:12, t:12, b:22};
    var plotW = w - padding.l - padding.r;
    var plotH = h - padding.t - padding.b;

    var pts = values.map(function(v){ return clamp(parseFloat(v) || 0, 0, 100); });
    var maxV = 100;

    var stepX = plotW / Math.max(1, pts.length - 1);
    var poly = [];
    for (var i=0;i<pts.length;i++){
        var x = padding.l + i * stepX;
        var y = padding.t + (1 - (pts[i]/maxV)) * plotH;
        poly.push({x:x,y:y,v:pts[i],label: (labels && labels[i]) ? labels[i] : ''});
    }

    var svgNS = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(svgNS, 'svg');
    svg.setAttribute('width','100%');
    svg.setAttribute('height', h);
    svg.setAttribute('viewBox','0 0 '+w+' '+h);
    svg.setAttribute('preserveAspectRatio','xMinYMin meet');
    svg.setAttribute('role','img');
    svg.setAttribute('aria-label','Efficiency by day chart');

    // grid lines
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

    // path
    var pathD = poly.map(function(p,i){ return (i===0? 'M':'L') + p.x + ' ' + p.y; }).join(' ');
    var path = document.createElementNS(svgNS,'path');
    path.setAttribute('d', pathD);
    path.setAttribute('fill','none');
    path.setAttribute('stroke','#4f46e5');
    path.setAttribute('stroke-width','2');
    svg.appendChild(path);

    // points
    poly.forEach(function(p,i){
        var c = document.createElementNS(svgNS,'circle');
        c.setAttribute('cx', p.x);
        c.setAttribute('cy', p.y);
        c.setAttribute('r', 4);
        c.setAttribute('fill', '#4f46e5');
        c.setAttribute('tabindex', '0');
        c.setAttribute('aria-label', (p.label||'') + ': ' + p.v + '%');
        svg.appendChild(c);

        c.addEventListener('mouseenter', function(){ showTooltip(container, p.x, p.y, p.label, p.v); });
        c.addEventListener('focus', function(){ showTooltip(container, p.x, p.y, p.label, p.v); });
        c.addEventListener('mouseleave', hideTooltip);
        c.addEventListener('blur', hideTooltip);
    });

    // predicted line (опціонально)
    if (typeof predicted === 'number') {
        var last = poly[poly.length-1];
        var xPred = padding.l + (poly.length) * stepX;
        var yPred = padding.t + (1 - (clamp(predicted,0,100)/maxV)) * plotH;

        var d = 'M' + last.x + ' ' + last.y + ' L ' + xPred + ' ' + yPred;
        var pPred = document.createElementNS(svgNS,'path');
        pPred.setAttribute('d', d);
        pPred.setAttribute('fill','none');
        pPred.setAttribute('stroke','#10b981');
        pPred.setAttribute('stroke-width','1.5');
        pPred.setAttribute('stroke-dasharray','6 6');
        svg.appendChild(pPred);

        var cp = document.createElementNS(svgNS,'circle');
        cp.setAttribute('cx', xPred);
        cp.setAttribute('cy', yPred);
        cp.setAttribute('r', 3.5);
        cp.setAttribute('fill', '#10b981');
        svg.appendChild(cp);

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


function hideTooltip(){ try{ if(_tt && _tt.parentNode) _tt.parentNode.removeChild(_tt); _tt = null; }catch(e){} }




</script>
</body>
</html>
