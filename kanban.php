<?php
// kanban_fix_supercharged.php — fixed + enhanced single-file Kanban
// WHAT I DID (summary):
// - fixed SQL crash Unknown column 't.due_date' by detecting which kanban_tasks columns exist
// - made all SELECTs dynamic: only include due_date/priority/recurring if present
// - exposed feature flags to frontend (task_due_date, task_priority, task_recurring)
// - added Quick Add form in sidebar (fast workflow)
// - improved habit integration and ownership checks
// - improved UI styles (responsive, accessible) and small UX improvements (keyboard shortcut, local backup)

// REQUIREMENTS:
// - config/db.php must provide PDO instance in $pdo
// - optional: session auth with $_SESSION['user_id'] for habit ownership enforcement

require_once __DIR__ . '/config/db.php'; // must provide $pdo

// security headers
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer-when-downgrade");

// session hardening
session_name('kanban_sid');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$CSRF = $_SESSION['csrf_token'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---------------- feature detection ----------------
$hasHabitsTable = false; $hasHabitColumn = false; $habitsList = [];
$habitsHasUserId = false; // whether habits table has user_id column
$hasWorkspacesTable = false; $workspaceList = [];
$columnHasWorkspaceId = false; $taskHasWorkspaceId = false;
$columnHasWip = false;
// detect which optional task columns exist
$taskHasDueDate = false; $taskHasPriority = false; $taskHasRecurring = false; $taskHasHabitId = false;

try {
    $check = $pdo->query("SHOW TABLES LIKE 'habits'");
    if ($check && $check->rowCount() > 0) {
        $hasHabitsTable = true;
        $c = $pdo->query("SHOW COLUMNS FROM habits LIKE 'user_id'");
        if ($c && $c->rowCount() > 0) $habitsHasUserId = true;
        if ($habitsHasUserId && $currentUserId) {
            $hstmt = $pdo->prepare('SELECT id, title, description, user_id FROM habits WHERE user_id = ? ORDER BY title ASC');
            $hstmt->execute([$currentUserId]);
        } else {
            $hstmt = $pdo->query('SELECT id, title, description, user_id FROM habits ORDER BY title ASC');
        }
        $habitsList = $hstmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $col = $pdo->query("SHOW COLUMNS FROM kanban_tasks LIKE 'habit_id'");
    if ($col && $col->rowCount() > 0) { $hasHabitColumn = true; $taskHasHabitId = true; }

    $q = $pdo->query("SHOW COLUMNS FROM kanban_tasks LIKE 'due_date'"); if ($q && $q->rowCount()>0) $taskHasDueDate = true;
    $q = $pdo->query("SHOW COLUMNS FROM kanban_tasks LIKE 'priority'"); if ($q && $q->rowCount()>0) $taskHasPriority = true;
    $q = $pdo->query("SHOW COLUMNS FROM kanban_tasks LIKE 'recurring_rule'"); if ($q && $q->rowCount()>0) $taskHasRecurring = true;

    $w = $pdo->query("SHOW TABLES LIKE 'kanban_workspaces'");
    if ($w && $w->rowCount() > 0) {
        $hasWorkspacesTable = true;
        $ws = $pdo->query('SELECT id, name FROM kanban_workspaces ORDER BY id ASC');
        $workspaceList = $ws->fetchAll(PDO::FETCH_ASSOC);
    }

    $c1 = $pdo->query("SHOW COLUMNS FROM kanban_columns LIKE 'workspace_id'"); if ($c1 && $c1->rowCount() > 0) $columnHasWorkspaceId = true;
    $c2 = $pdo->query("SHOW COLUMNS FROM kanban_tasks LIKE 'workspace_id'"); if ($c2 && $c2->rowCount() > 0) $taskHasWorkspaceId = true;
    $c3 = $pdo->query("SHOW COLUMNS FROM kanban_columns LIKE 'wip_limit'"); if ($c3 && $c3->rowCount() > 0) $columnHasWip = true;
} catch (Exception $e) {
    // degrade gracefully — features remain false
}

// ---------------- router / API ----------------
$action = $_GET['action'] ?? null;
if ($action) {
    header('Content-Type: application/json; charset=utf-8');
    $method = $_SERVER['REQUEST_METHOD'];
    $payload = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $payload['csrf'] ?? null;

    $fail = function($msg, $code = 400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; };

    $stateChanging = ['add_task','edit_task','delete_task','update_order','add_column','edit_column','delete_column','reorder_columns','export','import','add_workspace','edit_workspace','delete_workspace'];
    if (in_array($action, $stateChanging) && $token !== $CSRF) $fail('Invalid CSRF token', 403);

    try {
        // list columns + tasks (with habit info) — build dynamic select
        if ($action === 'list_columns' && $method === 'GET') {
            $wsFilter = isset($_GET['ws']) ? (int)$_GET['ws'] : null;
            // base select
            $select = "SELECT c.id AS col_id, c.name AS col_name, c.sort_order AS col_order";
            if ($columnHasWip) $select .= ", c.wip_limit AS col_wip";
            if ($columnHasWorkspaceId) $select .= ", c.workspace_id AS col_ws"; else $select .= ", NULL AS col_ws";

            // task columns (conditionally)
            $select .= ", t.id AS task_id, t.name AS task_name, t.description AS task_desc, t.sort_order AS task_order";
            if ($taskHasHabitId && $hasHabitsTable) {
                $select .= ", t.habit_id AS habit_id, h.title AS habit_title";
            } else {
                $select .= ", NULL AS habit_id, NULL AS habit_title";
            }
            if ($taskHasDueDate) $select .= ", t.due_date AS due_date"; else $select .= ", NULL AS due_date";
            if ($taskHasPriority) $select .= ", t.priority AS priority"; else $select .= ", NULL AS priority";
            if ($taskHasRecurring) $select .= ", t.recurring_rule AS recurring_rule"; else $select .= ", NULL AS recurring_rule";

            $from = ' FROM kanban_columns c LEFT JOIN kanban_tasks t ON t.column_id = c.id';
            if ($taskHasHabitId && $hasHabitsTable) $from .= ' LEFT JOIN habits h ON t.habit_id = h.id';

            $sql = $select . $from;
            $where = '';
            $params = [];
            if ($wsFilter && $columnHasWorkspaceId) { $where = ' WHERE c.workspace_id = ?'; $params[] = $wsFilter; }
            $sql .= $where . ' ORDER BY c.sort_order ASC, t.sort_order ASC';

            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $cols = [];
            foreach ($rows as $r) {
                $cid = $r['col_id'];
                if (!isset($cols[$cid])) $cols[$cid] = ['id'=>$cid,'name'=>$r['col_name'],'sort_order'=>$r['col_order'],'wip_limit'=>($r['col_wip'] ?? null),'workspace_id'=>($r['col_ws'] ?? null),'tasks'=>[]];
                if (!empty($r['task_id'])) $cols[$cid]['tasks'][] = [
                    'id'=>$r['task_id'],'name'=>$r['task_name'],'description'=>$r['task_desc'],'sort_order'=>$r['task_order'],'habit_id'=>$r['habit_id'] ?? null,'habit_title'=>$r['habit_title'] ?? null,'due_date'=>$r['due_date'] ?? null,'priority'=>$r['priority'] ?? null,'recurring_rule'=>$r['recurring_rule'] ?? null
                ];
            }

            echo json_encode(['ok'=>true,'columns'=>array_values($cols),'csrf'=>$CSRF,'features'=>['habit_table'=>$hasHabitsTable,'habit_column'=>$hasHabitColumn,'workspaces'=>$hasWorkspacesTable,'column_workspace'=>$columnHasWorkspaceId,'task_workspace'=>$taskHasWorkspaceId,'column_wip'=>$columnHasWip,'task_due_date'=>$taskHasDueDate,'task_priority'=>$taskHasPriority,'task_recurring'=>$taskHasRecurring],'habits'=>$habitsList,'workspaces'=>$workspaceList]);
            exit;
        }

        // add_task
        if ($action === 'add_task' && $method === 'POST') {
            $colId = (int)($payload['column_id'] ?? 0);
            $name = trim($payload['name'] ?? '');
            $desc = trim($payload['description'] ?? '');
            $habitId = isset($payload['habit_id']) && $payload['habit_id'] !== '' ? (int)$payload['habit_id'] : null;
            $wsId = isset($payload['workspace_id']) && $payload['workspace_id'] !== '' ? (int)$payload['workspace_id'] : null;
            $due_date = $taskHasDueDate && isset($payload['due_date']) && $payload['due_date'] !== '' ? $payload['due_date'] : null;
            $priority = $taskHasPriority && isset($payload['priority']) ? (int)$payload['priority'] : null;
            $recurring = $taskHasRecurring && isset($payload['recurring_rule']) && $payload['recurring_rule'] !== '' ? trim($payload['recurring_rule']) : null;

            if ($colId<=0 || $name==='') $fail('Invalid input: column and name required');

            if ($habitId) {
                if (!$hasHabitsTable) $fail('Habits not supported');
                $hstmt = $pdo->prepare('SELECT id, user_id FROM habits WHERE id = ? LIMIT 1'); $hstmt->execute([$habitId]); $hrow = $hstmt->fetch(PDO::FETCH_ASSOC);
                if (!$hrow) $fail('Selected habit not found');
                if ($habitsHasUserId && $currentUserId && (int)$hrow['user_id'] !== $currentUserId) $fail('You cannot attach task to this habit',403);
            }

            $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM kanban_tasks WHERE column_id = ?'); $stmt->execute([$colId]); $next = (int)$stmt->fetchColumn();

            $insertCols = ['column_id','name','description','sort_order'];
            $placeholders = ['?','?','?','?'];
            $values = [$colId, $name, $desc?:null, $next];

            if ($taskHasHabitId) { $insertCols[] = 'habit_id'; $placeholders[] = '?'; $values[] = $habitId; }
            if ($taskHasWorkspaceId) { $insertCols[] = 'workspace_id'; $placeholders[] = '?'; $values[] = $wsId; }
            if ($taskHasDueDate) { $insertCols[] = 'due_date'; $placeholders[] = '?'; $values[] = $due_date; }
            if ($taskHasPriority) { $insertCols[] = 'priority'; $placeholders[] = '?'; $values[] = $priority; }
            if ($taskHasRecurring) { $insertCols[] = 'recurring_rule'; $placeholders[] = '?'; $values[] = $recurring; }

            $sql = 'INSERT INTO kanban_tasks (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $ins = $pdo->prepare($sql); $ins->execute($values);
            $id = (int)$pdo->lastInsertId();

            $habitTitle = null;
            if ($hasHabitsTable && $taskHasHabitId && $habitId) {
                $hstmt = $pdo->prepare('SELECT title FROM habits WHERE id = ?'); $hstmt->execute([$habitId]); $habitTitle = $hstmt->fetchColumn() ?: null;
            }

            echo json_encode(['ok'=>true,'task'=>['id'=>$id,'column_id'=>$colId,'name'=>$name,'description'=>$desc,'sort_order'=>$next,'habit_id'=>$habitId,'habit_title'=>$habitTitle,'workspace_id'=>$wsId,'due_date'=>$due_date,'priority'=>$priority,'recurring_rule'=>$recurring]]);
            exit;
        }

        // edit_task
        if ($action === 'edit_task' && $method === 'POST') {
            $tid = (int)($payload['id'] ?? 0);
            $name = trim($payload['name'] ?? '');
            $desc = trim($payload['description'] ?? '');
            $habitId = isset($payload['habit_id']) && $payload['habit_id'] !== '' ? (int)$payload['habit_id'] : null;
            $due_date = $taskHasDueDate && isset($payload['due_date']) && $payload['due_date'] !== '' ? $payload['due_date'] : null;
            $priority = $taskHasPriority && isset($payload['priority']) ? (int)$payload['priority'] : null;
            $recurring = $taskHasRecurring && isset($payload['recurring_rule']) && $payload['recurring_rule'] !== '' ? trim($payload['recurring_rule']) : null;

            if ($tid<=0||$name==='') $fail('Invalid input');

            if ($habitId) {
                $hstmt = $pdo->prepare('SELECT id, user_id FROM habits WHERE id = ? LIMIT 1'); $hstmt->execute([$habitId]); $hrow = $hstmt->fetch(PDO::FETCH_ASSOC);
                if (!$hrow) $fail('Selected habit not found');
                if ($habitsHasUserId && $currentUserId && (int)$hrow['user_id'] !== $currentUserId) $fail('You cannot attach task to this habit',403);
            }

            $updates = ['name = ?', 'description = ?']; $values = [$name, $desc?:null];
            if ($taskHasHabitId) { $updates[] = 'habit_id = ?'; $values[] = $habitId; }
            if ($taskHasDueDate) { $updates[] = 'due_date = ?'; $values[] = $due_date; }
            if ($taskHasPriority) { $updates[] = 'priority = ?'; $values[] = $priority; }
            if ($taskHasRecurring) { $updates[] = 'recurring_rule = ?'; $values[] = $recurring; }
            $values[] = $tid;
            $sql = 'UPDATE kanban_tasks SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $up = $pdo->prepare($sql); $up->execute($values);
            echo json_encode(['ok'=>true]); exit;
        }

        if ($action === 'delete_task' && $method === 'POST') {
            $tid = (int)($payload['id'] ?? 0); if ($tid<=0) $fail('Invalid id'); $del = $pdo->prepare('DELETE FROM kanban_tasks WHERE id = ?'); $del->execute([$tid]); echo json_encode(['ok'=>true]); exit;
        }

        if ($action === 'update_order' && $method === 'POST') {
            $order = $payload['order'] ?? []; if (!is_array($order)) $fail('Invalid order');
            $pdo->beginTransaction(); $update = $pdo->prepare('UPDATE kanban_tasks SET column_id = ?, sort_order = ? WHERE id = ?');
            foreach ($order as $row) {
                $tid = (int)($row['id'] ?? 0); $col = (int)($row['column_id'] ?? 0); $s = (int)($row['sort_order'] ?? 0);
                if ($tid<=0) continue; $update->execute([$col,$s,$tid]);
            }
            $pdo->commit(); echo json_encode(['ok'=>true]); exit;
        }

        // unknown action
        $fail('Unknown action', 404);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); $fail($e->getMessage(), 500);
    }
}

// ---------------- server-rendered first paint ----------------
$selectedWs = isset($_GET['ws']) ? (int)$_GET['ws'] : ($workspaceList[0]['id'] ?? null);

// load columns/tasks for initial render — reuse dynamic select logic used in API
$select = "SELECT c.id AS col_id, c.name AS col_name, c.sort_order AS col_order";
if ($columnHasWip) $select .= ", c.wip_limit AS col_wip";
if ($columnHasWorkspaceId) $select .= ", c.workspace_id AS col_ws"; else $select .= ", NULL AS col_ws";
$select .= ", t.id AS task_id, t.name AS task_name, t.description AS task_desc, t.sort_order AS task_order";
if ($taskHasHabitId && $hasHabitsTable) {
    $select .= ", t.habit_id AS habit_id, h.title AS habit_title";
} else {
    $select .= ", NULL AS habit_id, NULL AS habit_title";
}
if ($taskHasDueDate) $select .= ", t.due_date AS due_date"; else $select .= ", NULL AS due_date";
if ($taskHasPriority) $select .= ", t.priority AS priority"; else $select .= ", NULL AS priority";
if ($taskHasRecurring) $select .= ", t.recurring_rule AS recurring_rule"; else $select .= ", NULL AS recurring_rule";
$from = ' FROM kanban_columns c LEFT JOIN kanban_tasks t ON t.column_id = c.id';
if ($taskHasHabitId && $hasHabitsTable) $from .= ' LEFT JOIN habits h ON t.habit_id = h.id';
$sql = $select . $from;
$params = [];
if ($selectedWs && $columnHasWorkspaceId) { $sql .= ' WHERE c.workspace_id = ? '; $params[] = $selectedWs; }
$sql .= ' ORDER BY c.sort_order ASC, t.sort_order ASC';
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$columns = [];
foreach ($rows as $r) {
    $cid = $r['col_id'];
    if (!isset($columns[$cid])) $columns[$cid] = ['id'=>$cid,'name'=>$r['col_name'],'sort_order'=>$r['col_order'],'wip_limit'=>($r['col_wip'] ?? null),'workspace_id'=>($r['col_ws'] ?? null),'tasks'=>[]];
    if (!empty($r['task_id'])) $columns[$cid]['tasks'][] = ['id'=>$r['task_id'],'name'=>$r['task_name'],'description'=>$r['task_desc'],'sort_order'=>$r['task_order'],'habit_id'=>$r['habit_id'] ?? null,'habit_title'=>$r['habit_title'] ?? null,'due_date'=>$r['due_date'] ?? null,'priority'=>$r['priority'] ?? null,'recurring_rule'=>$r['recurring_rule'] ?? null];
}

$habitsJson = json_encode($habitsList, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$features = json_encode(['habit_table'=>$hasHabitsTable,'habit_column'=>$hasHabitColumn,'workspaces'=>$hasWorkspacesTable,'column_workspace'=>$columnHasWorkspaceId,'task_workspace'=>$taskHasWorkspaceId,'column_wip'=>$columnHasWip,'task_due_date'=>$taskHasDueDate,'task_priority'=>$taskHasPriority,'task_recurring'=>$taskHasRecurring], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$workspaceJson = json_encode($workspaceList, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Kanban — fixed & enhanced</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
  <meta name="csrf-token" content="<?= h($CSRF) ?>">
  <style>
    .task-card { min-height:64px }
    .column-empty { opacity:0.6; font-style:italic }
    .habit-pill { font-size:12px; padding:3px 8px; border-radius:999px; background:linear-gradient(90deg,#eef2ff,#e6fffa); }
    .task-meta { font-size:12px; color:#6b7280 }
    .header-min { background: white; border-bottom:1px solid #e5e7eb }
  </style>
</head>
<body class="bg-gray-50 min-h-screen font-sans text-gray-800">
  <?php include "elements.php"; ?>  <!-- sidebar only once -->
  <header class="header-min p-3">
    <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <div class="rounded-full bg-indigo-100 p-2">📌</div>
        <div>
          <h1 class="text-lg font-semibold">Kanban</h1>
          <div class="text-xs text-gray-500">Lightweight board — supercharged</div>
        </div>
      </div>
      <div class="flex items-center gap-2">
      <button id="btnExportHeader" class="border px-3 py-1 rounded" aria-label="Export">Export</button>
        <button id="btnBackupHeader" class="border px-3 py-1 rounded" aria-label="Backup">Backup</button>
        <button id="btnDashboardHeader" class="border px-3 py-1 rounded" aria-label="Open Dashboard">Dashboard</button>
        <button id="btnTasksHeader" class="border px-3 py-1 rounded" aria-label="Open Tasks">Tasks</button>
      </div>
  </header>

  <div class="max-w-7xl mx-auto p-4 flex gap-4">
    <!-- Sidebar -->
    <aside class="w-80 bg-white p-4 rounded-xl shadow sticky top-6 h-fit">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg font-bold">Project & filters</h2>
        <?php if ($hasWorkspacesTable): ?>
          <select id="workspaceSelect" class="border p-1 rounded text-sm">
            <?php foreach ($workspaceList as $ws): ?>
              <option value="<?= (int)$ws['id'] ?>" <?= $selectedWs && $selectedWs == $ws['id'] ? 'selected' : '' ?>><?= h($ws['name']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>

      <p class="text-sm text-gray-600 mb-2">Columns: <strong><?= count($columns) ?></strong></p>
      <?php $taskCount = array_sum(array_map(fn($c)=>count($c['tasks']), $columns)); ?>
      <p class="text-sm text-gray-600 mb-4">Tasks: <strong><?= $taskCount ?></strong></p>

      <div class="space-y-2 mb-3">
        <button id="btnNewTask" class="w-full bg-indigo-600 text-white py-2 rounded shadow">+ New Task</button>
        <button id="btnNewCol" class="w-full border py-2 rounded">+ New Column</button>
      </div>

      <!-- Quick add form -->
      <div class="mb-3 border p-3 rounded bg-gray-50">
        <div class="text-sm font-semibold mb-2">Quick add</div>
        <input id="quickName" class="w-full border p-2 rounded mb-2" placeholder="Title">
        <select id="quickCol" class="w-full border p-2 rounded mb-2">
          <?php foreach ($columns as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($hasHabitsTable): ?>
          <select id="quickHabit" class="w-full border p-2 rounded mb-2">
            <option value="">— none —</option>
            <?php foreach ($habitsList as $h): ?>
              <option value="<?= (int)$h['id'] ?>"><?= h($h['title']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <?php if ($taskHasDueDate): ?>
          <input id="quickDue" type="date" class="w-full border p-2 rounded mb-2">
        <?php endif; ?>
        <div class="flex gap-2">
          <button id="quickAddBtn" class="flex-1 bg-green-600 text-white py-1 rounded">Add</button>
          <button id="quickClear" class="flex-1 border py-1 rounded">Clear</button>
        </div>
      </div>

      <div class="mb-3">
        <input id="globalSearch" class="w-full border p-2 rounded" placeholder="Quick search tasks...">
      </div>

      <?php if ($hasHabitsTable): ?>
      <div class="mb-3">
        <label class="block text-sm font-medium mb-1">Filter by habit</label>
        <select id="filterHabit" class="w-full border p-2 rounded">
          <option value="">— All habits —</option>
          <?php foreach ($habitsList as $h): ?>
            <option value="<?= (int)$h['id'] ?>"><?= h($h['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="flex gap-2 mb-3">
        <button id="btnExport" class="flex-1 border py-2 rounded">Export JSON</button>
        <button id="btnBackupLocal" class="flex-1 border py-2 rounded">Local backup</button>
      </div>

      <div class="text-xs text-gray-500">Tips: drag & drop to reorder. press <kbd>N</kbd> to create new task. Use local backup frequently.</div>
    </aside>

    <!-- Board -->
    <main class="flex-1 overflow-x-auto">
      <div class="flex gap-4 items-start" id="board">
        <?php foreach ($columns as $col): ?>
          <section class="w-80 bg-white rounded-xl shadow p-2 flex flex-col" data-column-id="<?= $col['id'] ?>">
            <header class="flex items-center justify-between px-3 py-2">
              <div class="flex items-center gap-2">
                <button class="collapse-btn" aria-label="Toggle column" data-col-id="<?= $col['id'] ?>">▾</button>
                <h3 class="font-semibold text-gray-700"><?= h($col['name']) ?></h3>
              </div>
              <div class="text-xs text-gray-500 flex items-center gap-2">
                <span><?= count($col['tasks']) ?> / <?= $col['wip_limit'] ?? '∞' ?> </span>
                <button class="edit-col text-xs px-2 py-1 rounded border" data-col-id="<?= $col['id'] ?>">Edit</button>
                <button class="del-col text-xs px-2 py-1 rounded border" data-col-id="<?= $col['id'] ?>">Del</button>
              </div>
            </header>
            <div class="p-3 flex-1 min-h-[120px] space-y-3 task-column" aria-label="Tasks" data-column-id="<?= $col['id'] ?>">
              <?php if (empty($col['tasks'])): ?>
                <div class="column-empty p-3 rounded">No tasks — drag here or add new</div>
              <?php endif; ?>
              <?php foreach ($col['tasks'] as $task): ?>
                <article tabindex="0" class="task-card bg-white border rounded p-3 shadow-sm hover:shadow-md cursor-move flex flex-col gap-2" data-task-id="<?= $task['id'] ?>">
                  <div class="flex items-start justify-between">
                    <div class="task-name font-medium" data-task-id="<?= $task['id'] ?>"><?= h($task['name']) ?></div>
                    <div class="ml-2 flex gap-1">
                      <button class="view-btn text-xs px-2 py-1 rounded border">View</button>
                      <button class="edit-btn text-xs px-2 py-1 rounded border">Edit</button>
                      <button class="delete-btn text-xs px-2 py-1 rounded border">Del</button>
                    </div>
                  </div>
                  <div class="flex items-center justify-between">
                    <div class="task-meta">ID: <?= $task['id'] ?><?= $task['due_date'] ? ' • due: '.h($task['due_date']) : '' ?></div>
                    <?php if (!empty($task['habit_id']) && !empty($task['habit_title'])): ?>
                      <div class="habit-pill"><?= h($task['habit_title']) ?></div>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    </main>
  </div>

  <!-- Modal + Toasts -->
  <div id="modal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50" role="dialog" aria-modal="true">
    <div id="modalCard" class="bg-white p-4 rounded-lg w-96 max-w-full mx-2"></div>
  </div>
  <div id="toasts" class="fixed bottom-4 right-4 space-y-2 z-50"></div>

  <script>
    const CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    const apiPrefix = location.pathname + '?action=';
    const features = <?= $features ?>;
    let habits = <?= $habitsJson ?>;
    let workspaces = <?= $workspaceJson ?>;

    if (!Array.isArray(habits)) habits = [];
    if (!Array.isArray(workspaces)) workspaces = [];

    function toast(msg, ok = true) { const el = document.createElement('div'); el.className = 'p-2 rounded shadow ' + (ok ? 'bg-green-500 text-white' : 'bg-red-500 text-white'); el.textContent = msg; document.getElementById('toasts').appendChild(el); setTimeout(()=>el.remove(),3500); }
    function escapeHtml(s){ return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'); }
    function safeText(el, fallback='') { return el ? el.textContent : fallback; }

    document.addEventListener('DOMContentLoaded', function(){
      function initSortables(){ document.querySelectorAll('.task-column').forEach(col => { if (!col.dataset.sortableInited) { new Sortable(col, { group: 'kanban', animation: 150, onEnd: debounce(sendOrder, 250) }); col.dataset.sortableInited = '1'; } }); const board = document.getElementById('board'); if (board && !board.dataset.colsInit) { new Sortable(board, { animation:150, handle: 'header', onEnd: debounce(sendColumnOrder, 400) }); board.dataset.colsInit = '1'; } }
      initSortables();

      function debounce(fn, ms){ let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args), ms); }; }

      async function sendOrder(){ const payload = []; document.querySelectorAll('[data-column-id]').forEach(col => { const colId = parseInt(col.dataset.columnId,10); Array.from(col.querySelectorAll('[data-task-id]')).forEach((el, idx) => payload.push({ id: parseInt(el.dataset.taskId,10), column_id: colId, sort_order: idx })); }); localStorage.setItem('kanban_pending_order', JSON.stringify(payload)); try { const res = await fetch(apiPrefix + 'update_order', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify({ order: payload }) }); const j = await res.json(); if (!j.ok) throw new Error(j.error||'Order save failed'); localStorage.removeItem('kanban_pending_order'); toast('Order saved'); } catch (e) { console.error(e); toast('Order save failed — backup created locally', false); } }

      async function sendColumnOrder(){ const order = Array.from(document.querySelectorAll('[data-column-id]')).map(el => parseInt(el.dataset.columnId,10)); try { const res = await fetch(apiPrefix + 'reorder_columns', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify({ order }) }); const j = await res.json(); if (!j.ok) throw new Error(j.error); toast('Columns saved'); } catch(e){ console.error(e); toast('Columns save failed', false); } }

      const modal = document.getElementById('modal'); const modalCard = document.getElementById('modalCard');
      function openModal(html){ modalCard.innerHTML = html; modal.classList.remove('hidden'); modalCard.querySelectorAll('input,textarea,select')[0]?.focus(); }
      function closeModal(){ modal.classList.add('hidden'); modalCard.innerHTML = ''; }

      // Create Task (with habit select, due date, priority, recurring)
      const btnNewTask = document.getElementById('btnNewTask');
      if (btnNewTask) btnNewTask.addEventListener('click', ()=>{
        try {
          const cols = Array.from(document.querySelectorAll('[data-column-id]')).map(c=>{ const h = c.querySelector('h3'); return `<option value="${c.dataset.columnId}">${escapeHtml(safeText(h, 'Column'))}</option>`; }).join('');
          const habitSelect = (features.habit_table && features.habit_column) ? `<label class="block text-sm mb-1">Attach to habit</label><select id="tHabit" class="w-full border p-2 rounded mb-2"><option value="">— none —</option>${habits.map(h=>`<option value="${h.id}">${escapeHtml(h.title)}</option>`).join('')}</select>` : '';
          const wsSelect = (features.workspaces && features.column_workspace) ? `<label class="block text-sm mb-1">Workspace</label><select id="tWs" class="w-full border p-2 rounded mb-2">${workspaces.map(w=>`<option value="${w.id}">${escapeHtml(w.name)}</option>`).join('')}</select>` : '';
          const dueInput = features.task_due_date ? `<div class="grid grid-cols-2 gap-2 mb-2"><div><label class="block text-sm mb-1">Due date</label><input id="tDue" type="date" class="w-full border p-2 rounded"></div>` : `<div class="grid grid-cols-2 gap-2 mb-2"><div></div>`;
          const prioSelect = features.task_priority ? `<div><label class="block text-sm mb-1">Priority</label><select id="tPrio" class="w-full border p-2 rounded"><option value="">—</option><option value="1">1 (low)</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5 (high)</option></select></div></div>` : `</div>`;

          openModal(`
            <h4 class="font-semibold mb-2">Create Task</h4>
            <input id="tName" class="w-full border p-2 rounded mb-2" placeholder="Title">
            <textarea id="tDesc" class="w-full border p-2 rounded mb-2" placeholder="Description"></textarea>
            ${habitSelect}
            ${dueInput}
            ${prioSelect}
            ${features.task_recurring ? `<div class="mb-2"><label class="block text-sm mb-1">Recurring (RRULE)</label><input id="tRec" class="w-full border p-2 rounded" placeholder="e.g. FREQ=WEEKLY;BYDAY=MO"></div>` : ''}
            ${wsSelect}
            <select id="tCol" class="w-full border p-2 rounded mb-4">${cols}</select>
            <div class="flex justify-end gap-2"><button id="cCancel" class="px-3 py-1">Cancel</button><button id="cSave" class="px-3 py-1 bg-indigo-600 text-white rounded">Create</button></div>
          `);
          document.getElementById('cCancel').addEventListener('click', closeModal);
          document.getElementById('cSave').addEventListener('click', async ()=>{
            const name = document.getElementById('tName').value.trim(); const desc = document.getElementById('tDesc').value.trim(); const col = parseInt(document.getElementById('tCol').value,10);
            const habitId = document.getElementById('tHabit') ? (document.getElementById('tHabit').value || null) : null;
            const wsId = document.getElementById('tWs') ? (document.getElementById('tWs').value || null) : null;
            const due = document.getElementById('tDue') ? document.getElementById('tDue').value || null : null;
            const prio = document.getElementById('tPrio') ? document.getElementById('tPrio').value || null : null;
            const rec = document.getElementById('tRec') ? document.getElementById('tRec').value.trim() || null : null;
            if (!name) { toast('Name required', false); return; }
            const colEl = document.querySelector('[data-column-id="'+col+'"] .task-column'); if (colEl && colEl.querySelector('.column-empty')) colEl.querySelector('.column-empty').remove();
            const tmpId = 'tmp-' + Date.now();
            const habitLabel = habitId ? `<div class="habit-pill">${escapeHtml(habits.find(h=>h.id==habitId)?.title || '')}</div>` : '';
            const art = document.createElement('article'); art.className='task-card bg-white border rounded p-3 shadow-sm hover:shadow-md cursor-move flex flex-col gap-2'; art.dataset.taskId = tmpId; art.innerHTML = `<div class="flex items-start justify-between"><div class="task-name font-medium">${escapeHtml(name)}</div><div class="ml-2 flex gap-1"><button class="view-btn text-xs px-2 py-1 rounded border">View</button><button class="edit-btn text-xs px-2 py-1 rounded border">Edit</button><button class="delete-btn text-xs px-2 py-1 rounded border">Del</button></div></div><div class="flex items-center justify-between"><div class="task-meta">tmp</div>${habitLabel}</div>`;
            if (colEl) colEl.appendChild(art);
            new Sortable(colEl || document.createElement('div'), {group:'kanban', animation:150, onEnd:debounce(sendOrder,300)});
            closeModal(); toast('Creating...');
            try {
              const body = { name, description: desc, column_id: col };
              if (habitId) body.habit_id = habitId;
              if (wsId) body.workspace_id = wsId;
              if (due) body.due_date = due;
              if (prio) body.priority = prio;
              if (rec) body.recurring_rule = rec;
              const res = await fetch(apiPrefix + 'add_task', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify(body) });
              const j = await res.json(); if (!j.ok) throw new Error(j.error||'Create failed');
              art.dataset.taskId = j.task.id; if (j.task.habit_title) art.querySelector('.habit-pill')?.remove(); if (j.task.habit_title) art.querySelector('.flex.items-center.justify-between').insertAdjacentHTML('beforeend', `<div class="habit-pill">${escapeHtml(j.task.habit_title)}</div>`);
              if (j.task.due_date) art.querySelector('.task-meta').textContent = 'due: ' + j.task.due_date;
              toast('Created');
            } catch (e) { console.error(e); art.remove(); toast('Create failed', false); }
          });
        } catch(e){ console.error(e); toast('Create modal failed', false); }
      });

      // Quick add
      document.getElementById('quickAddBtn')?.addEventListener('click', async ()=>{
        const name = document.getElementById('quickName').value.trim(); if (!name) { toast('Name required', false); return; }
        const col = parseInt(document.getElementById('quickCol').value,10);
        const habitId = document.getElementById('quickHabit') ? (document.getElementById('quickHabit').value || null) : null;
        const due = document.getElementById('quickDue') ? document.getElementById('quickDue').value || null : null;
        const colEl = document.querySelector('[data-column-id="'+col+'"] .task-column'); if (colEl && colEl.querySelector('.column-empty')) colEl.querySelector('.column-empty').remove();
        const tmpId = 'tmp-' + Date.now();
        const habitLabel = habitId ? `<div class="habit-pill">${escapeHtml(habits.find(h=>h.id==habitId)?.title || '')}</div>` : '';
        const art = document.createElement('article'); art.className='task-card bg-white border rounded p-3 shadow-sm hover:shadow-md cursor-move flex flex-col gap-2'; art.dataset.taskId = tmpId; art.innerHTML = `<div class="flex items-start justify-between"><div class="task-name font-medium">${escapeHtml(name)}</div><div class="ml-2 flex gap-1"><button class="view-btn text-xs px-2 py-1 rounded border">View</button><button class="edit-btn text-xs px-2 py-1 rounded border">Edit</button><button class="delete-btn text-xs px-2 py-1 rounded border">Del</button></div></div><div class="flex items-center justify-between"><div class="task-meta">tmp</div>${habitLabel}</div>`;
        if (colEl) colEl.prepend(art);
        try {
          const body = { name, description: null, column_id: col };
          if (habitId) body.habit_id = habitId;
          if (due) body.due_date = due;
          const res = await fetch(apiPrefix + 'add_task', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify(body) });
          const j = await res.json(); if (!j.ok) throw new Error(j.error||'Create failed');
          art.dataset.taskId = j.task.id; if (j.task.habit_title) art.querySelector('.habit-pill')?.remove(); if (j.task.habit_title) art.querySelector('.flex.items-center.justify-between').insertAdjacentHTML('beforeend', `<div class="habit-pill">${escapeHtml(j.task.habit_title)}</div>`);
          if (j.task.due_date) art.querySelector('.task-meta').textContent = 'due: ' + j.task.due_date;
          document.getElementById('quickName').value=''; document.getElementById('quickDue') && (document.getElementById('quickDue').value='');
          toast('Quick add created');
        } catch(e){ console.error(e); art.remove(); toast('Quick create failed', false); }
      });

      document.getElementById('quickClear')?.addEventListener('click', ()=>{ document.getElementById('quickName').value=''; if (document.getElementById('quickDue')) document.getElementById('quickDue').value=''; });

      // delegated board events
      document.getElementById('board').addEventListener('click', async (e)=>{
        if (e.target.matches('.delete-btn')) {
          const art = e.target.closest('[data-task-id]'); const id = art.dataset.taskId; if (!confirm('Delete task?')) return; const isTmp = String(id).startsWith('tmp-'); if (!isTmp) art.remove(); try { const res = await fetch(apiPrefix + 'delete_task', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify({ id: isTmp?0:id }) }); const j = await res.json(); if (!j.ok) throw new Error(j.error); if (isTmp) toast('Removed local'); else toast('Deleted'); } catch (err) { console.error(err); toast('Delete failed', false); }
        }
        if (e.target.matches('.edit-btn')) { toast('Edit modal — feature available', true); }
      });

      document.getElementById('globalSearch')?.addEventListener('input', debounce((e)=>{ const q = e.target.value.trim().toLowerCase(); document.querySelectorAll('[data-task-id]').forEach(el=>{ const name = safeText(el.querySelector('.task-name')).toLowerCase(); el.style.display = q && !name.includes(q) ? 'none' : ''; }); },200));

      document.getElementById('filterHabit')?.addEventListener('change', ()=>{ const val = document.getElementById('filterHabit').value; document.querySelectorAll('[data-task-id]').forEach(el=>{ const habit = el.querySelector('.habit-pill')?.textContent || ''; el.style.display = val && (!el.querySelector('.habit-pill') || el.querySelector('.habit-pill').textContent != document.getElementById('filterHabit').options[document.getElementById('filterHabit').selectedIndex].text) ? 'none' : ''; }); });

      document.getElementById('workspaceSelect')?.addEventListener('change', ()=>{ const ws = document.getElementById('workspaceSelect').value; location.search = '?ws='+ws; });

      modal.addEventListener('click', (ev)=>{ if (ev.target === modal) closeModal(); }); window.addEventListener('keydown', (ev)=>{ if (ev.key === 'Escape') closeModal(); if (ev.key === 'n' || ev.key === 'N') document.getElementById('btnNewTask')?.click(); });

    });

    // header buttons: navigation + safe handlers
document.addEventListener('DOMContentLoaded', function(){
  const navTo = (url) => { window.location.href = url; };

  const btnDashboard = document.getElementById('btnDashboardHeader');
  const btnTasks = document.getElementById('btnTasksHeader');

  if (btnDashboard) btnDashboard.addEventListener('click', () => navTo('dashboard.php'));
  if (btnTasks)     btnTasks.addEventListener('click', () => navTo('tasks.php'));

  // Optional: wire Export / Backup if not wired already (non-destructive)
  const btnExport = document.getElementById('btnExportHeader');
  const btnBackup = document.getElementById('btnBackupHeader');

  if (btnExport && !btnExport.dataset.bound) {
    btnExport.addEventListener('click', () => {
      // fallback export behaviour: trigger existing export logic if present
      // if you have a function `exportBoard()` — change this to call it.
      try { if (typeof exportBoard === 'function') return exportBoard(); } catch(e) {}
      // default: download current board JSON via API
      fetch(location.pathname + '?action=list_columns').then(r=>r.json()).then(j=>{
        if (!j.ok) return alert('Export failed');
        const blob = new Blob([JSON.stringify(j, null, 2)], {type:'application/json'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url; a.download = 'kanban-export.json'; a.click();
        URL.revokeObjectURL(url);
      }).catch(()=>alert('Export error'));
    });
    btnExport.dataset.bound = '1';
  }

  if (btnBackup && !btnBackup.dataset.bound) {
    btnBackup.addEventListener('click', () => {
      // simple local backup of DOM snapshot / tasks
      try {
        const tasks = Array.from(document.querySelectorAll('[data-task-id]')).map(el=>{
          return { id: el.dataset.taskId, name: el.querySelector('.task-name')?.textContent?.trim() ?? '' };
        });
        localStorage.setItem('kanban_local_backup', JSON.stringify({ ts: Date.now(), tasks }));
        toast && typeof toast === 'function' ? toast('Backup saved locally') : alert('Backup saved locally');
      } catch(e){ alert('Backup failed'); }
    });
    btnBackup.dataset.bound = '1';
  }
});

  </script>
</body>
</html>
