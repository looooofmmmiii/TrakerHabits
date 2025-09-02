<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$uid = (int)$_SESSION['user_id'];

/* ---------- SAFE FETCH USER ---------- */
$stmt = $pdo->prepare("SELECT id, email, created_at FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header('Location: logout.php'); exit; }

$displayEmail = htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8');
$initial = strtoupper(mb_substr($displayEmail, 0, 1, 'UTF-8'));

/* ---------- CORE STATS ---------- */

// Habits
$habitsCount = (int)($pdo->prepare("SELECT COUNT(*) FROM habits WHERE user_id = ?"))->execute([$uid]) ? $pdo->query("SELECT COUNT(*) FROM habits WHERE user_id = $uid")->fetchColumn() : 0;

// Completed today
$q = $pdo->prepare("
    SELECT COUNT(*) FROM habit_tracking ht
    JOIN habits h ON h.id = ht.habit_id
    WHERE h.user_id = ? AND ht.track_date = CURDATE() AND IFNULL(ht.completed,1) = 1
");
$q->execute([$uid]);
$completedHabitsToday = (int)$q->fetchColumn();

// Tasks
$q = $pdo->prepare("SELECT COUNT(*) FROM tasks");
$q->execute();
$totalTasks = (int)$q->fetchColumn();

$q = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE is_done = 1");
$q->execute();
$doneTasks = (int)$q->fetchColumn();

$tasksProgress = $totalTasks > 0 ? (int)round(($doneTasks / $totalTasks) * 100) : 0;

// Tasks by priority (0,1,2,3)
$priorityBuckets = [0=>0,1=>0,2=>0,3=>0];
$q = $pdo->query("SELECT priority, COUNT(*) c FROM tasks GROUP BY priority");
foreach ($q as $row) { $priorityBuckets[(int)$row['priority']] = (int)$row['c']; }

// Kanban
$q = $pdo->prepare("SELECT COUNT(*) FROM kanban_tasks");
$q->execute();
$kanbanTasks = (int)$q->fetchColumn();

$q = $pdo->prepare("SELECT COUNT(*) FROM kanban_columns");
$q->execute();
$kanbanColumns = (int)$q->fetchColumn();

// Member since (safe)
$memberSince = 'Unknown';
if (!empty($user['created_at'])) {
    $ts = strtotime((string)$user['created_at']);
    if ($ts) $memberSince = date('F Y', $ts);
}

/* ---------- HEATMAP (LAST 28 DAYS) ---------- */
$days = 28; // compact, mobile-friendly
$startDate = new DateTime('today');
$startDate->modify('-'.($days-1).' days'); // include today
$startStr = $startDate->format('Y-m-d');

$query = $pdo->prepare("
    SELECT ht.track_date d, SUM(IFNULL(ht.completed,1)) c
    FROM habit_tracking ht
    JOIN habits h ON h.id = ht.habit_id
    WHERE h.user_id = ? AND ht.track_date BETWEEN ? AND CURDATE()
    GROUP BY ht.track_date
");
$query->execute([$uid, $startStr]);
$rows = $query->fetchAll(PDO::FETCH_KEY_PAIR); // ['Y-m-d' => count]

$heatmap = [];
$maxDay = 0;
$cursor = clone $startDate;
for ($i=0; $i<$days; $i++) {
    $d = $cursor->format('Y-m-d');
    $val = isset($rows[$d]) ? (int)$rows[$d] : 0;
    $heatmap[] = ['date'=>$d, 'value'=>$val];
    if ($val > $maxDay) $maxDay = $val;
    $cursor->modify('+1 day');
}

/* ---------- STREAK (CONTIGUOUS DAYS) ---------- */
$streak = 0;
$cursor = new DateTime('today');
while (true) {
    $d = $cursor->format('Y-m-d');
    $has = isset($rows[$d]) && $rows[$d] > 0;
    if ($has) { $streak++; $cursor->modify('-1 day'); } else { break; }
}

/* ---------- PRODUCTIVITY SCORE (simple composite) ---------- */
$habitFactor = $habitsCount > 0 ? min(1, $completedHabitsToday / max(1, $habitsCount)) : 0;
$taskFactor  = $tasksProgress / 100;
$kanbanFactor= $kanbanTasks > 0 ? 0.6 : 0.3; // lightweight heuristic
$score = (int)round(100 * (0.45*$habitFactor + 0.45*$taskFactor + 0.10*$kanbanFactor));
$score = max(0, min(100, $score));

?>
<!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Profile — Better Life</title>
  <style>
    :root{
      --bg:#f9fafb;
      --card:#ffffff;
      --line:#e5e7eb;
      --muted:#6b7280;
      --text:#111827;
      --brand:#2563eb;
      --brand-2:#1d4ed8;
      --danger:#ef4444;
      --ok:#10b981;
      --shadow:0 4px 16px rgba(0,0,0,.06);
      --radius:16px;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family:Inter, 'Segoe UI', Roboto, system-ui, -apple-system, Arial, sans-serif;
      background:var(--bg);
      color:var(--text);
    }
    a{color:inherit}
    .main{margin-left:250px;padding:28px}
    @media(max-width:980px){.main{margin-left:0;padding:18px}}

    /* Header */
    .head{
      display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px
    }
    .profile{
      display:flex;align-items:center;gap:16px
    }
    .avatar{
      width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;
      background:var(--brand);color:#fff;font-weight:800;font-size:28px;box-shadow:var(--shadow)
    }
    .meta h1{margin:0;font-size:22px;font-weight:800;letter-spacing:.2px}
    .meta p{margin:4px 0 0;color:var(--muted);font-size:14px}
    .quick{
      display:flex;gap:10px;flex-wrap:wrap
    }
    .chip{
      display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;border:1px solid var(--line);
      background:var(--card);box-shadow:var(--shadow);font-size:13px;font-weight:600
    }

    /* Grid */
    .grid{
      display:grid;gap:18px;grid-template-columns: 1.2fr 1fr;align-items:start
    }
    @media(max-width:1200px){.grid{grid-template-columns:1fr}}
    .card{
      background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:18px
    }
    .card h3{margin:0 0 10px;font-size:15px;font-weight:800;letter-spacing:.2px}
    .sub{color:var(--muted);font-size:13px;margin:6px 0 14px}

    /* KPI */
    .kpis{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:12px}
    @media(max-width:1200px){.kpis{grid-template-columns:repeat(2,1fr)}}
    .kpi{padding:14px;border-radius:14px;border:1px solid var(--line);background:#fff}
    .kpi .label{color:var(--muted);font-size:12px}
    .kpi .value{font-size:22px;font-weight:800;margin-top:6px}
    .bar{height:8px;background:#f3f4f6;border-radius:8px;overflow:hidden;margin-top:10px}
    .bar>i{display:block;height:100%;background:var(--brand);width:0;transition:width .4s ease}

    /* Heatmap */
    .heat{display:grid;grid-template-columns:repeat(28, 1fr);gap:4px}
    .dot{
      width:100%;padding-top:100%;position:relative;border-radius:6px;border:1px solid var(--line);background:#fff;
      transition:transform .15s ease, box-shadow .15s ease
    }
    .dot:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.08)}
    .dot>span{position:absolute;inset:0;border-radius:5px}
    /* intensity */
    .lvl-0{background:#f9fafb}
    .lvl-1{background:#dbeafe}
    .lvl-2{background:#bfdbfe}
    .lvl-3{background:#93c5fd}
    .lvl-4{background:#60a5fa}
    .lvl-5{background:#3b82f6}

    /* Lists */
    .list{display:grid;gap:10px}
    .row{
      display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border:1px solid var(--line);
      border-radius:12px;background:#fff;transition:background .15s ease, box-shadow .15s ease
    }
    .row:hover{background:#f8fafc;box-shadow:var(--shadow)}
    .left{display:flex;align-items:center;gap:10px}
    .badge{font-size:12px;padding:4px 8px;border-radius:999px;background:#eef2ff;color:#1e40af;border:1px solid #e0e7ff}
    .muted{color:var(--muted);font-size:12px}

    /* Actions */
    .actions{display:flex;gap:10px;flex-wrap:wrap}
    .btn{
      display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);
      background:#fff;font-weight:600;font-size:14px;text-decoration:none;transition:transform .15s ease, background .15s ease
    }
    .btn:hover{transform:translateY(-1px);background:#f8fafc}
    .btn.primary{background:var(--brand);color:#fff;border-color:var(--brand)}
    .btn.primary:hover{background:var(--brand-2)}
    .btn.danger{background:var(--danger);color:#fff;border-color:var(--danger)}

    /* Footer hover (GPT-style) already from your sidebar: keep same UX) */
  </style>
</head>
<body>
  <?php include 'elements.php'; ?>

  <div class="main">
    <!-- Header -->
    <div class="head">
      <div class="profile">
        <div class="avatar"><?= $initial ?></div>
        <div class="meta">
          <h1><?= $displayEmail ?></h1>
          <p>Member since <strong><?= htmlspecialchars($memberSince, ENT_QUOTES, 'UTF-8') ?></strong></p>
        </div>
      </div>
      <div class="quick">
        <div class="chip">Productivity score: <span id="score" style="font-weight:800;margin-left:4px;"><?= $score ?></span></div>
        <div class="chip">Streak: <strong><?= $streak ?></strong> days</div>
        <div class="chip">Plan: <span class="badge">Free</span></div>
      </div>
    </div>

    <!-- KPI row -->
    <div class="kpis">
      <div class="kpi">
        <div class="label">Habits</div>
        <div class="value"><?= $habitsCount ?></div>
        <div class="sub">Completed today: <strong><?= $completedHabitsToday ?></strong></div>
      </div>
      <div class="kpi">
        <div class="label">Tasks</div>
        <div class="value"><?= $doneTasks ?>/<?= $totalTasks ?></div>
        <div class="bar"><i style="width: <?= $tasksProgress ?>%"></i></div>
        <div class="sub"><?= $tasksProgress ?>% completed</div>
      </div>
      <div class="kpi">
        <div class="label">Kanban Tasks</div>
        <div class="value"><?= $kanbanTasks ?></div>
        <div class="sub"><?= $kanbanColumns ?> columns</div>
      </div>
      <div class="kpi">
        <div class="label">Focus</div>
        <div class="value"><?= max(1, $habitsCount) + $doneTasks ?></div>
        <div class="sub">Daily impact (simple index)</div>
      </div>
    </div>

    <div class="grid" style="margin-top:18px">
      <!-- Heatmap + priorities -->
      <div class="card">
        <h3>Habit Heatmap</h3>
        <div class="sub">Last 28 days • darker = more completed</div>
        <div class="heat" title="Your habit activity">
          <?php
            // choose levels by quantiles against $maxDay
            foreach ($heatmap as $cell) {
              $v = $cell['value'];
              $lvl = 0;
              if ($v > 0) {
                // normalize to 1..5
                $lvl = $maxDay <= 1 ? 3 : (int)ceil(($v / max(1,$maxDay)) * 5);
                $lvl = max(1, min(5, $lvl));
              }
              $dateTitle = htmlspecialchars($cell['date'].' • completed: '.$v, ENT_QUOTES, 'UTF-8');
              echo '<div class="dot" title="'.$dateTitle.'"><span class="lvl-'.$lvl.'"></span></div>';
            }
          ?>
        </div>

        <div class="sub" style="margin-top:12px">Priorities</div>
        <div class="list">
          <div class="row">
            <div class="left"><span class="badge">P3</span> High priority</div>
            <div class="muted"><?= (int)$priorityBuckets[3] ?></div>
          </div>
          <div class="row">
            <div class="left"><span class="badge" style="background:#ecfeff;color:#155e75;border-color:#cffafe">P2</span> Medium</div>
            <div class="muted"><?= (int)$priorityBuckets[2] ?></div>
          </div>
          <div class="row">
            <div class="left"><span class="badge" style="background:#fef9c3;color:#854d0e;border-color:#fde68a">P1</span> Low</div>
            <div class="muted"><?= (int)$priorityBuckets[1] ?></div>
          </div>
          <div class="row">
            <div class="left"><span class="badge" style="background:#f3f4f6;color:#374151;border-color:#e5e7eb">P0</span> Inbox / None</div>
            <div class="muted"><?= (int)$priorityBuckets[0] ?></div>
          </div>
        </div>
      </div>

      <!-- Quick actions + recents -->
      <div class="card">
        <h3>Quick Actions</h3>
        <div class="sub">Speed up your flow</div>
        <div class="actions" style="margin-bottom:14px">
          <a class="btn primary" href="habits.php">Add Habit</a>
          <a class="btn" href="tasks.php">Add Task</a>
          <a class="btn" href="kanban.php">Open Kanban</a>
          <a class="btn" href="thoughts.php">New Thought</a>
        </div>

        <h3 style="margin-top:10px">System</h3>
        <div class="sub">Manage account & preferences</div>
        <div class="actions">
          <a class="btn" href="settings.php">Settings</a>
          <a class="btn" href="edit_profile.php">Edit Profile</a>
          <a class="btn danger" href="auth/logout.php">Logout</a>
        </div>

        <h3 style="margin-top:18px">Snapshot</h3>
        <div class="sub">Today’s summary</div>
        <div class="list">
          <div class="row">
            <div class="left">Habits completed today</div>
            <div class="muted"><?= $completedHabitsToday ?></div>
          </div>
          <div class="row">
            <div class="left">Tasks completed (all-time)</div>
            <div class="muted"><?= $doneTasks ?></div>
          </div>
          <div class="row">
            <div class="left">Active streak</div>
            <div class="muted"><?= $streak ?> days</div>
          </div>
        </div>
      </div>
    </div>

  </div>

  <script>
    // tiny enhancement: animate KPI bar on load
    window.addEventListener('load', () => {
      document.querySelectorAll('.bar>i').forEach(i=>{
        const w=i.style.width;
        i.style.width='0';
        requestAnimationFrame(()=>{ requestAnimationFrame(()=>{ i.style.width=w; }); });
      });
    });
  </script>
</body>
</html>
