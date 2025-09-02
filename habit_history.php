<?php
// habit_history-improved.php
// Improved UI, functionality and vibe for habit history
// Features added:
// - Aggregated daily efficiency and inline SVG mini line chart with predicted dashed extension
// - AJAX-friendly toggle/delete actions (returns JSON for XHR) and graceful fallback to POST+redirect
// - CSV export includes UTF-8 BOM and better column labels
// - Flash messages with auto-dismiss, accessible controls
// - Improved responsive styling, icons, tooltips and aria attributes
// - Filtering, pagination, and performance-aware aggregation queries

declare(strict_types=1);

session_name('habit_sid');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
ini_set('session.cookie_httponly', '1');
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');

require_once 'functions/habit_functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}
$user_id = intval($_SESSION['user_id']);

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

function json_response(array $data, int $status = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// get habit id
$habit_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['habit_id'] ?? 0);
if ($habit_id <= 0) {
    $_SESSION['flash_error'] = 'Invalid habit id';
    header('Location: dashboard.php'); exit;
}

$habit = getHabit($habit_id, $user_id);
if (!$habit) {
    global $pdo;
    if (!empty($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare('SELECT * FROM habits WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$habit_id, $user_id]);
        $habit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!$habit) {
    $_SESSION['flash_error'] = 'Habit not found or access denied';
    header('Location: dashboard.php'); exit;
}

// Detect AJAX
$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (isset($_GET['ajax']) && $_GET['ajax']=='1');

// ---------- POST actions (toggle / delete) with AJAX support ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        if ($isAjax) json_response(['ok'=>false,'error'=>'Invalid CSRF'], 400);
        $_SESSION['flash_error'] = 'Invalid request';
        header("Location: habit_history.php?id={$habit_id}"); exit;
    }

    $entry_id = intval($_POST['entry_id'] ?? 0);
    if ($entry_id <= 0) {
        if ($isAjax) json_response(['ok'=>false,'error'=>'Invalid entry id'], 400);
        $_SESSION['flash_error'] = 'Invalid entry id';
        header("Location: habit_history.php?id={$habit_id}"); exit;
    }

    $ok = false;
    if ($action === 'toggle') {
        if (function_exists('toggleHabitEntry')) {
            $ok = (bool)toggleHabitEntry($entry_id, $user_id);
        } else {
            global $pdo;
            if (!empty($pdo) && $pdo instanceof PDO) {
                $check = $pdo->prepare('SELECT ht.id FROM habit_tracking ht JOIN habits h ON ht.habit_id = h.id WHERE ht.id = ? AND h.user_id = ? LIMIT 1');
                $check->execute([$entry_id, $user_id]);
                if ($check->fetch()) {
                    $update = $pdo->prepare('UPDATE habit_tracking SET completed = 1 - completed WHERE id = ?');
                    $ok = (bool)$update->execute([$entry_id]);
                }
            }
        }
        $msg = $ok ? 'Entry updated' : 'Unable to update entry';
    } elseif ($action === 'delete') {
        if (function_exists('deleteHabitEntry')) {
            $ok = (bool)deleteHabitEntry($entry_id, $user_id);
        } else {
            global $pdo;
            if (!empty($pdo) && $pdo instanceof PDO) {
                $del = $pdo->prepare('DELETE t FROM habit_tracking t JOIN habits h ON t.habit_id = h.id WHERE t.id = ? AND h.user_id = ?');
                $ok = (bool)$del->execute([$entry_id, $user_id]);
            }
        }
        $msg = $ok ? 'Entry deleted' : 'Unable to delete entry';
    } else {
        if ($isAjax) json_response(['ok'=>false,'error'=>'Unknown action'], 400);
        $_SESSION['flash_error'] = 'Unknown action';
        header("Location: habit_history.php?id={$habit_id}"); exit;
    }

    if ($isAjax) {
        json_response(['ok'=>$ok,'message'=>$msg]);
    }

    $_SESSION['flash_' . ($ok ? 'success' : 'error')] = $msg;
    header("Location: habit_history.php?id={$habit_id}"); exit;
}

// ---------- Filters ----------
$date_from = isset($_GET['from']) ? trim($_GET['from']) : null;
$date_to = isset($_GET['to']) ? trim($_GET['to']) : null;
if ($date_from === '') $date_from = null;
if ($date_to === '') $date_to = null;

$end = new DateTimeImmutable('today');
$start = $end->sub(new DateInterval('P89D'));
if ($date_from) {
    $tmp = DateTimeImmutable::createFromFormat('Y-m-d', $date_from);
    if ($tmp) $start = $tmp;
}
if ($date_to) {
    $tmp = DateTimeImmutable::createFromFormat('Y-m-d', $date_to);
    if ($tmp) $end = $tmp;
}
$startStr = $start->format('Y-m-d');
$endStr = $end->format('Y-m-d');

// pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// ---------- CSV export (with BOM) ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    global $pdo;
    $rows = [];
    if (!empty($pdo) && $pdo instanceof PDO) {
        $sql = 'SELECT ht.id, ht.track_date, ht.completed, ht.created_at, ht.updated_at FROM habit_tracking ht JOIN habits h ON ht.habit_id = h.id WHERE ht.habit_id = ? AND h.user_id = ? AND ht.track_date BETWEEN ? AND ? ORDER BY ht.track_date DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$habit_id, $user_id, $startStr, $endStr]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else if (function_exists('getHabitHistory')) {
        $rows = getHabitHistory($habit_id, $user_id, $startStr, $endStr, 0, 0);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="habit-' . $habit_id . '-history.csv"');
    // BOM for Excel
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'track_date', 'completed', 'created_at', 'updated_at']);
    foreach ($rows as $r) {
        fputcsv($out, [ $r['id'], $r['track_date'], intval($r['completed']), $r['created_at'] ?? '', $r['updated_at'] ?? '' ]);
    }
    fclose($out);
    exit;
}

// ---------- Main data fetch with aggregation for chart ----------
$history = [];
$totalRows = 0;
$dailyStats = []; // date => ['total'=>int,'completed'=>int]

global $pdo;
if (!empty($pdo) && $pdo instanceof PDO) {
    $countSql = "SELECT COUNT(*) FROM habit_tracking ht JOIN habits h ON ht.habit_id = h.id WHERE ht.habit_id = ? AND h.user_id = ? AND ht.track_date BETWEEN ? AND ?";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute([$habit_id, $user_id, $startStr, $endStr]);
    $totalRows = (int)$stmt->fetchColumn();

    $sql = "SELECT ht.id, ht.track_date, ht.completed, ht.created_at, ht.updated_at
            FROM habit_tracking ht
            JOIN habits h ON ht.habit_id = h.id
            WHERE ht.habit_id = ? AND h.user_id = ? AND ht.track_date BETWEEN ? AND ?
            ORDER BY ht.track_date DESC LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $habit_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $user_id, PDO::PARAM_INT);
    $stmt->bindValue(3, $startStr, PDO::PARAM_STR);
    $stmt->bindValue(4, $endStr, PDO::PARAM_STR);
    $stmt->bindValue(5, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(6, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // aggregated daily stats for chart and summary (faster query)
    $aggSql = 'SELECT ht.track_date, COUNT(*) as total, SUM(ht.completed) as completed FROM habit_tracking ht JOIN habits h ON ht.habit_id = h.id WHERE ht.habit_id = ? AND h.user_id = ? AND ht.track_date BETWEEN ? AND ? GROUP BY ht.track_date ORDER BY ht.track_date ASC';
    $stmt = $pdo->prepare($aggSql);
    $stmt->execute([$habit_id, $user_id, $startStr, $endStr]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $dailyStats[$r['track_date']] = ['total'=>intval($r['total']), 'completed'=>intval($r['completed'])];
    }
} else {
    if (function_exists('getHabitHistory')) {
        $history = getHabitHistory($habit_id, $user_id, $startStr, $endStr, $perPage, $offset);
        $totalRows = count($history);
        // best-effort dailyStats from returned rows
        foreach ($history as $r) {
            $d = $r['track_date'];
            if (!isset($dailyStats[$d])) $dailyStats[$d] = ['total'=>0,'completed'=>0];
            $dailyStats[$d]['total']++;
            $dailyStats[$d]['completed'] += intval($r['completed']);
        }
    }
}

// compute simple stats for display
$totalTracked = $totalRows;
$completedCount = 0;
foreach ($history as $r) { if (intval($r['completed']) === 1) $completedCount++; }
$eff = ($totalTracked>0) ? round(($completedCount/$totalTracked)*100) : 0;

// prepare series for chart (dates from start..end)
$period = new DatePeriod(new DateTimeImmutable($startStr), new DateInterval('P1D'), (new DateTimeImmutable($endStr))->modify('+1 day'));
$labels = []; $series = [];
foreach ($period as $dt) {
    $d = $dt->format('Y-m-d');
    $labels[] = $d;
    if (isset($dailyStats[$d])) {
        $t = $dailyStats[$d]['total'];
        $c = $dailyStats[$d]['completed'];
        $series[] = ($t>0)? round(($c/$t)*100,2): 0;
    } else {
        $series[] = 0;
    }
}
// predicted next day: simple linear last-two-days trend if available
$predicted = null;
$vals = array_values($series);
$ln = count($vals);
if ($ln >= 2) {
    $last = $vals[$ln-1];
    $prev = $vals[$ln-2];
    $predicted = min(100, max(0, round($last + ($last - $prev), 2)));
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Habit history — <?php echo e($habit['title'] ?? 'Habit'); ?></title>
<style>
:root{--bg:#0f172a;--muted:#667085;--card:#fff;--accent:#6366f1;--success:#10b981;--danger:#ef4444}
*{box-sizing:border-box}
body{font-family:Inter,system-ui,Arial,Helvetica,sans-serif;padding:20px;background:#f1f5f9;color:var(--bg)}
.container{max-width:1100px;margin:0 auto}
.header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}
.h1{font-size:20px;font-weight:700}
.muted{color:var(--muted);font-size:13px}
.card{background:var(--card);padding:14px;border-radius:12px;box-shadow:0 8px 20px rgba(2,6,23,0.06);margin-bottom:12px}
.controls{display:flex;gap:8px;align-items:center}
.btn{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:10px;text-decoration:none;background:var(--accent);color:#fff;border:0;cursor:pointer}
.btn.ghost{background:transparent;color:var(--accent);border:1px solid rgba(99,102,241,0.12)}
.btn.small{padding:6px 8px;border-radius:8px;font-size:13px}
.grid{display:grid;grid-template-columns:1fr 320px;gap:12px}
@media (max-width:880px){.grid{grid-template-columns:1fr}}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:10px;border-bottom:1px solid #eef2ff;text-align:left}
.table th{font-size:13px;color:var(--muted)}
.badge{background:#f1f5f9;padding:6px 8px;border-radius:999px;font-weight:600}
.icon{width:16px;height:16px;display:inline-block}
.flash{padding:10px;border-radius:8px;margin-bottom:12px}
.flash.success{background:#ecfdf5;color:#065f46}
.flash.error{background:#ffebe9;color:#7f1d1d}
.pager{display:flex;gap:6px;flex-wrap:wrap}
.search{padding:8px 10px;border-radius:8px;border:1px solid #e6eefc}
.form-inline{display:flex;gap:8px;align-items:center}
.chart-wrap{padding:10px;background:linear-gradient(180deg,#ffffff,#fbfdff);border-radius:10px}
.small{font-size:13px}
</style>
</head>
<body>
<?php include "elements.php"; ?>  <!-- sidebar only once -->

<div class="container">
    <header class="header">
        <div>
            <div class="h1">Habit history — <?php echo e($habit['title']); ?></div>
            <div class="muted">ID: <?php echo intval($habit['id']); ?> · Frequency: <?php echo e($habit['frequency'] ?? ($habit['recurrence'] ?? 'daily')); ?></div>
        </div>
        <div class="controls">
            <a class="btn ghost" href="dashboard.php" aria-label="Back to dashboard">⟵ Back</a>
            <a class="btn" href="habit_history.php?id=<?php echo $habit_id; ?>&export=csv&from=<?php echo e($startStr); ?>&to=<?php echo e($endStr); ?>">Export CSV</a>
        </div>
    </header>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="flash success" id="flash-success"><?php echo e($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="flash error" id="flash-error"><?php echo e($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>

    <div class="grid">
        <main>
            <section class="card">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <div class="muted">Showing</div>
                        <div style="font-weight:700"><?php echo e($startStr); ?> — <?php echo e($endStr); ?></div>
                        <div class="muted small" style="margin-top:6px">Total rows: <?php echo $totalRows; ?> · Efficiency: <?php echo $eff; ?>%</div>
                    </div>
                    <form method="GET" action="habit_history.php" class="form-inline" aria-label="Filter form">
                        <input type="hidden" name="id" value="<?php echo $habit_id; ?>">
                        <label class="muted">From <input class="search" type="date" name="from" value="<?php echo e($startStr); ?>"></label>
                        <label class="muted">To <input class="search" type="date" name="to" value="<?php echo e($endStr); ?>"></label>
                        <button class="btn small" type="submit">Filter</button>
                    </form>
                </div>
            </section>

            <section class="card" aria-labelledby="history-table">
                <table class="table" role="table" aria-describedby="history-desc">
                    <thead>
                        <tr><th>Date</th><th>Completed</th><th>Created / Updated</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($history)): ?>
                            <tr><td colspan="4" class="muted">No entries found for this period.</td></tr>
                        <?php else: foreach ($history as $row): ?>
                            <tr data-entry-id="<?php echo intval($row['id']); ?>">
                                <td><?php echo e($row['track_date']); ?></td>
                                <td><?php echo (intval($row['completed'])===1) ? '✅' : '—'; ?></td>
                                <td><?php echo e(($row['created_at'] ?? '') . (isset($row['updated_at']) ? ' / ' . $row['updated_at'] : '')); ?></td>
                                <td>
                                    <button class="btn small" data-action="toggle" data-id="<?php echo intval($row['id']); ?>" aria-label="Toggle completed">Toggle</button>
                                    <button class="btn small" data-action="delete" data-id="<?php echo intval($row['id']); ?>" style="background:var(--danger)" aria-label="Delete entry">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <?php if ($totalRows > $perPage):
                    $pages = (int)ceil($totalRows / $perPage);
                ?>
                    <div style="margin-top:12px;display:flex;justify-content:flex-end" class="pager">
                        <?php for ($p=1;$p<=$pages;$p++): ?>
                            <a href="habit_history.php?id=<?php echo $habit_id; ?>&from=<?php echo e($startStr); ?>&to=<?php echo e($endStr); ?>&page=<?php echo $p; ?>" class="btn small" style="background:<?php echo ($p===$page) ? 'var(--bg)' : 'var(--accent)'; ?>;"><?php echo $p; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>

            </section>
        </main>

        <aside>
            <section class="card chart-wrap" aria-label="Efficiency chart">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <div class="muted">Efficiency (last <?php echo count($labels); ?> days)</div>
                        <div style="font-weight:700"><?php echo $eff; ?>%</div>
                        <div class="muted small">Completed <?php echo $completedCount; ?> / Tracked <?php echo $totalTracked; ?></div>
                    </div>
                    <div style="text-align:right">
                        <a class="btn small ghost" href="#" id="download-chart">Save image</a>
                    </div>
                </div>
                <div style="margin-top:10px">
                    <!-- Inline SVG mini line chart -->
                    <div id="mini-chart" style="width:100%;height:120px"></div>
                    <div class="muted small" style="margin-top:8px">Predicted next day: <?php echo ($predicted===null) ? '—' : $predicted . '%'; ?></div>
                </div>
            </section>

            <section class="card">
                <div class="muted">Quick actions</div>
                <div style="margin-top:8px;display:flex;gap:8px">
                    <button class="btn small" id="mark-today">Mark today completed</button>
                    <a class="btn small ghost" href="#" id="open-export">Advanced export</a>
                </div>
            </section>
        </aside>
    </div>
</div>

<script>
// Flash auto-dismiss
setTimeout(()=>{let f=document.getElementById('flash-success'); if(f) f.remove(); let fe=document.getElementById('flash-error'); if(fe) setTimeout(()=>fe.remove(),5000);},4000);

const csrf = '<?php echo $csrf; ?>';

async function postAction(action, entryId){
    const payload = new URLSearchParams();
    payload.append('action', action);
    payload.append('entry_id', entryId);
    payload.append('csrf_token', csrf);
    payload.append('habit_id', '<?php echo $habit_id; ?>');
    try {
        const res = await fetch(location.pathname + '?id=<?php echo $habit_id; ?>&ajax=1', {
            method: 'POST', headers: {'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'}, body: payload
        });
        const data = await res.json();
        if (data.ok) {
            // optimistic UI: toggle row mark
            const tr = document.querySelector('tr[data-entry-id="'+entryId+'"]');
            if (action === 'toggle' && tr) {
                const td = tr.children[1];
                td.textContent = td.textContent.trim() === '✅' ? '—' : '✅';
            }
            if (action === 'delete' && tr) tr.remove();
            return data;
        } else {
            alert(data.error || data.message || 'Error');
            return data;
        }
    } catch (err) { alert('Network error'); }
}

document.addEventListener('click', (e)=>{
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const action = btn.dataset.action;
    const id = btn.dataset.id;
    if (!confirm(action === 'delete' ? 'Delete this entry?' : 'Toggle completed?')) return;
    postAction(action, id);
});

// Render mini SVG line chart (simple)
(function renderChart(){
    const labels = <?php echo json_encode($labels); ?>;
    const series = <?php echo json_encode($series); ?>;
    const predicted = <?php echo json_encode($predicted); ?>;
    const w = 560, h = 120, pad = 12;
    const svgNS = 'http://www.w3.org/2000/svg';
    const div = document.getElementById('mini-chart');
    const svg = document.createElementNS(svgNS,'svg'); svg.setAttribute('viewBox',`0 0 ${w} ${h}`); svg.setAttribute('width','100%'); svg.setAttribute('height','120');
    // scale
    const max = 100;
    const stepX = (w - pad*2) / Math.max(1, series.length);
    let points = [];
    series.forEach((v,i)=>{ const x = pad + i*stepX; const y = h - pad - (v/max)*(h - pad*2); points.push([x,y]); });
    // line path
    const path = document.createElementNS(svgNS,'path');
    const d = points.map((p,i)=> (i===0? 'M':'L') + p[0] + ' ' + p[1]).join(' ');
    path.setAttribute('d', d);
    path.setAttribute('fill','none');
    path.setAttribute('stroke','#6366f1');
    path.setAttribute('stroke-width','2');
    svg.appendChild(path);
    // circles
    points.forEach(p=>{ const c = document.createElementNS(svgNS,'circle'); c.setAttribute('cx',p[0]); c.setAttribute('cy',p[1]); c.setAttribute('r',2.4); c.setAttribute('fill','#6366f1'); svg.appendChild(c); });
    // predicted dashed
    if (predicted !== null) {
        const last = points[points.length-1];
        const x1 = last[0];
        const y1 = last[1];
        const x2 = x1 + stepX; const y2 = h - pad - (predicted/max)*(h - pad*2);
        const pd = document.createElementNS(svgNS,'path');
        pd.setAttribute('d', `M ${x1} ${y1} L ${x2} ${y2}`);
        pd.setAttribute('stroke','#a78bfa'); pd.setAttribute('stroke-width','1.6'); pd.setAttribute('stroke-dasharray','4 4'); pd.setAttribute('fill','none'); svg.appendChild(pd);
        const c = document.createElementNS(svgNS,'circle'); c.setAttribute('cx',x2); c.setAttribute('cy',y2); c.setAttribute('r',2.4); c.setAttribute('fill','#a78bfa'); svg.appendChild(c);
    }
    // grid lines (subtle)
    for (let i=0;i<4;i++){ const gy = pad + i*((h-pad*2)/3); const gl = document.createElementNS(svgNS,'line'); gl.setAttribute('x1',pad); gl.setAttribute('x2',w-pad); gl.setAttribute('y1',gy); gl.setAttribute('y2',gy); gl.setAttribute('stroke','rgba(14,165,233,0.03)'); gl.setAttribute('stroke-width','1'); svg.appendChild(gl); }
    div.appendChild(svg);
})();
</script>
</body>
</html>
