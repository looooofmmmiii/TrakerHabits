<?php
declare(strict_types=1);

// kanban.php - спрощена версія з динамічними полями due_date/priority

require_once __DIR__ . '/config/db.php'; // має задавати $pdo (PDO)

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
session_name('kanban_sid');
session_set_cookie_params([
    'lifetime' => 60*60*24*30,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$CSRF = $_SESSION['csrf_token'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Detect optional columns (due_date, priority)
$taskHasDueDate = $taskHasPriority = false;
try {
    $c = $pdo->query("SHOW COLUMNS FROM kanban_tasks LIKE 'due_date'");
    if ($c && $c->rowCount() > 0) $taskHasDueDate = true;
    $c = $pdo->query("SHOW COLUMNS FROM kanban_tasks LIKE 'priority'");
    if ($c && $c->rowCount() > 0) $taskHasPriority = true;
} catch (Exception $e) {
    // ignore detection errors, treat as absent
}

// Basic router for ajax actions
$action = $_GET['action'] ?? null;
if ($action) {
    header('Content-Type: application/json; charset=utf-8');
    $method = $_SERVER['REQUEST_METHOD'];
    $payload = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $payload['csrf'] ?? null;

    $fail = function($m, $c = 400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; };
    $mutating = ['add_task','edit_task','delete_task','update_order','add_column','reorder_columns'];

    if (in_array($action, $mutating) && $token !== $CSRF) $fail('Invalid CSRF',403);

    try {
        // LIST COLUMNS + TASKS (AJAX)
        if ($action === 'list_columns' && $method === 'GET') {
            // build task select columns dynamically
            $taskCols = "t.id AS task_id, t.name AS task_name, t.description AS task_desc, t.sort_order AS task_order, t.column_id AS task_column";
            if ($taskHasDueDate) $taskCols .= ", t.due_date AS due_date";
            if ($taskHasPriority) $taskCols .= ", t.priority AS priority";

            $sql = "SELECT c.id AS col_id, c.name AS col_name, c.sort_order AS col_order, c.wip_limit AS col_wip, {$taskCols}
                    FROM kanban_columns c
                    LEFT JOIN kanban_tasks t ON t.column_id = c.id
                    ORDER BY c.sort_order ASC, t.sort_order ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $cols = [];
            foreach ($rows as $r) {
                $cid = (int)$r['col_id'];
                if (!isset($cols[$cid])) {
                    $cols[$cid] = [
                        'id' => $cid,
                        'name' => $r['col_name'],
                        'sort_order' => (int)$r['col_order'],
                        'wip_limit' => $r['col_wip'],
                        'tasks' => []
                    ];
                }
                if (!empty($r['task_id'])) {
                    $cols[$cid]['tasks'][] = [
                        'id' => (int)$r['task_id'],
                        'name' => $r['task_name'],
                        'description' => $r['task_desc'],
                        'sort_order' => (int)$r['task_order'],
                        'column_id' => (int)$r['task_column'],
                        'due_date' => $taskHasDueDate ? ($r['due_date'] ?? null) : null,
                        'priority' => $taskHasPriority ? ($r['priority'] ?? null) : null
                    ];
                }
            }
            echo json_encode(['ok'=>true,'columns'=>array_values($cols),'csrf'=>$CSRF,'features'=>['due_date'=>$taskHasDueDate,'priority'=>$taskHasPriority]]);
            exit;
        }

        // ADD TASK
        if ($action === 'add_task' && $method === 'POST') {
            $colId = (int)($payload['column_id'] ?? 0);
            $name = trim((string)($payload['name'] ?? ''));
            $desc = trim((string)($payload['description'] ?? ''));
            $due = $taskHasDueDate && !empty($payload['due_date']) ? $payload['due_date'] : null;
            $priority = $taskHasPriority && isset($payload['priority']) ? (int)$payload['priority'] : null;

            if ($colId <= 0 || $name === '') $fail('Bad input',422);

            $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),-1)+1 FROM kanban_tasks WHERE column_id = ?');
            $stmt->execute([$colId]);
            $next = (int)$stmt->fetchColumn();

            $cols = ['column_id','name','description','sort_order'];
            $ph = ['?','?','?','?'];
            $vals = [$colId, $name, $desc !== '' ? $desc : null, $next];

            if ($taskHasDueDate) { $cols[] = 'due_date'; $ph[]='?'; $vals[] = $due; }
            if ($taskHasPriority) { $cols[] = 'priority'; $ph[]='?'; $vals[] = $priority; }

            $sql = 'INSERT INTO kanban_tasks (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
            $pdo->prepare($sql)->execute($vals);
            $id = (int)$pdo->lastInsertId();

            echo json_encode(['ok'=>true,'task'=>[
                'id'=>$id,'column_id'=>$colId,'name'=>$name,'description'=>$desc,'due_date'=>$due,'priority'=>$priority,'sort_order'=>$next
            ]]);
            exit;
        }

        // DELETE TASK
        if ($action === 'delete_task' && $method === 'POST') {
            $tid = (int)($payload['id'] ?? 0);
            if ($tid <= 0) $fail('Invalid id',422);
            $pdo->prepare('DELETE FROM kanban_tasks WHERE id = ?')->execute([$tid]);
            echo json_encode(['ok'=>true]); exit;
        }

        // UPDATE ORDER (tasks)
        if ($action === 'update_order' && $method === 'POST') {
            $order = $payload['order'] ?? [];
            if (!is_array($order)) $fail('Invalid order',422);
            $pdo->beginTransaction();
            $up = $pdo->prepare('UPDATE kanban_tasks SET column_id = ?, sort_order = ? WHERE id = ?');
            foreach ($order as $r) {
                $up->execute([(int)$r['column_id'], (int)$r['sort_order'], (int)$r['id']]);
            }
            $pdo->commit();
            echo json_encode(['ok'=>true]); exit;
        }

        // ADD COLUMN
        if ($action === 'add_column' && $method === 'POST') {
            $name = trim((string)($payload['name'] ?? ''));
            if ($name === '') $fail('Invalid',422);
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),-1)+1 FROM kanban_columns');
            $stmt->execute();
            $next = (int)$stmt->fetchColumn();
            $pdo->prepare('INSERT INTO kanban_columns (name, sort_order) VALUES (?,?)')->execute([$name,$next]);
            echo json_encode(['ok'=>true,'column'=>['id'=>(int)$pdo->lastInsertId(),'name'=>$name,'sort_order'=>$next]]); exit;
        }

        // REORDER COLUMNS
        if ($action === 'reorder_columns' && $method === 'POST') {
            $order = $payload['order'] ?? [];
            if (!is_array($order)) $fail('Invalid',422);
            $pdo->beginTransaction();
            $up = $pdo->prepare('UPDATE kanban_columns SET sort_order = ? WHERE id = ?');
            foreach ($order as $idx => $id) {
                $up->execute([(int)$idx, (int)$id]);
            }
            $pdo->commit();
            echo json_encode(['ok'=>true]); exit;
        }

        $fail('Unknown action',404);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $fail('Server error: '.$e->getMessage(),500);
    }
}

// --- Server render: load columns and tasks (lightweight)
try {
    $stmt = $pdo->prepare('SELECT id, name, sort_order, wip_limit FROM kanban_columns ORDER BY sort_order ASC');
    $stmt->execute();
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $columns = [];
    foreach ($cols as $c) {
        $columns[] = ['id'=> (int)$c['id'], 'name'=> $c['name'], 'sort_order'=>(int)$c['sort_order'], 'wip_limit'=>$c['wip_limit'], 'tasks'=>[]];
    }

    // dynamic task select
    $taskSelect = 'SELECT id, name, description, column_id, sort_order' . ($taskHasDueDate ? ', due_date' : '') . ($taskHasPriority ? ', priority' : '') . ' FROM kanban_tasks ORDER BY sort_order ASC';
    $taskStmt = $pdo->prepare($taskSelect);
    $taskStmt->execute();
    $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tasks as $t) {
        foreach ($columns as &$c) {
            if ($c['id'] === (int)$t['column_id']) {
                $c['tasks'][] = [
                    'id' => (int)$t['id'],
                    'name' => $t['name'],
                    'description' => $t['description'],
                    'sort_order' => (int)$t['sort_order'],
                    'due_date' => $taskHasDueDate ? ($t['due_date'] ?? null) : null,
                    'priority' => $taskHasPriority ? ($t['priority'] ?? null) : null
                ];
                break;
            }
        }
    }
} catch (Exception $e) {
    // fail gracefully for page render
    $columns = [];
}

$columnsJson = json_encode($columns, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$featuresJson = json_encode(['due_date'=>$taskHasDueDate,'priority'=>$taskHasPriority]);
?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Simple Kanban</title>
<meta name="csrf-token" content="<?= h($CSRF) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.0/dist/tailwind.min.css">
<script src="https://unpkg.com/alpinejs@3.16.1/dist/cdn.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js" defer></script>
</head>
<body class="bg-gray-50 text-gray-900">
<header class="bg-white shadow p-3">
  <div class="max-w-6xl mx-auto flex justify-between items-center">
    <h1 class="text-lg font-semibold">Kanban — Simple</h1>
    <button @click="openNewTask()" class="bg-indigo-600 text-white px-3 py-1 rounded" x-data>New</button>
  </div>
</header>

<main class="max-w-6xl mx-auto p-4" x-data="kanbanApp()" x-init="init()">
  <div class="flex gap-4 overflow-x-auto">
    <template x-for="col in columns" :key="col.id">
      <div class="w-80 bg-white rounded shadow p-3 flex flex-col" :data-column-id="col.id">
        <div class="flex justify-between items-center mb-2">
          <h2 class="font-medium" x-text="col.name"></h2>
          <div class="text-sm text-gray-500" x-text="col.tasks.length + (col.wip_limit ? ' / '+col.wip_limit : '')"></div>
        </div>
        <div class="space-y-2 task-column min-h-[120px]">
          <template x-if="col.tasks.length === 0">
            <div class="text-sm text-gray-400 italic">No tasks</div>
          </template>
          <template x-for="task in col.tasks" :key="task.id">
            <div class="p-2 bg-gray-50 border rounded" :data-task-id="task.id" tabindex="0">
              <div class="flex justify-between">
                <div>
                  <div class="font-medium truncate" x-text="task.name"></div>
                  <div class="text-xs text-gray-500 truncate" x-text="task.description"></div>
                </div>
                <div class="text-right">
                  <div class="text-xs text-gray-500" x-text="task.due_date ? 'due: '+task.due_date : ''"></div>
                  <div class="text-xs text-gray-500" x-text="task.priority !== null ? 'prio: '+task.priority : ''"></div>
                </div>
              </div>
              <div class="mt-2 flex gap-2 justify-end">
                <button @click="openEdit(task)" class="text-xs px-2 py-1 border rounded">Edit</button>
                <button @click="deleteTask(task)" class="text-xs px-2 py-1 border rounded">Del</button>
              </div>
            </div>
          </template>
        </div>
        <div class="mt-3 flex justify-between items-center">
          <button @click="openNewInColumn(col)" class="text-indigo-600 text-sm">+ Add</button>
          <button @click="applyTemplate(col)" class="text-sm text-gray-400">template</button>
        </div>
      </div>
    </template>
  </div>

  <!-- Modal -->
  <div x-show="modal.open" x-cloak class="fixed inset-0 z-40 flex items-center justify-center">
    <div class="absolute inset-0 bg-black/40" @click="modalClose()"></div>
    <div class="bg-white rounded p-4 z-50 w-full max-w-md">
      <h3 class="font-semibold mb-2" x-text="modal.title"></h3>
      <div>
        <input x-model="modal.form.name" class="w-full border p-2 rounded mb-2" placeholder="Title">
        <textarea x-model="modal.form.description" class="w-full border p-2 rounded mb-2" rows="3" placeholder="Description"></textarea>
        <template x-if="features.due_date">
          <input type="date" x-model="modal.form.due_date" class="w-full border p-2 rounded mb-2">
        </template>
        <template x-if="features.priority">
          <input type="number" x-model.number="modal.form.priority" class="w-full border p-2 rounded mb-2" placeholder="Priority (number)">
        </template>
        <div class="flex justify-end gap-2">
          <button @click="modalClose()" class="px-3 py-1 border rounded">Cancel</button>
          <button @click="saveTask()" class="px-3 py-1 bg-indigo-600 text-white rounded">Save</button>
        </div>
      </div>
    </div>
  </div>

</main>

<script>
function kanbanApp(){
  return {
    csrf: document.querySelector('meta[name="csrf-token"]').content,
    apiPrefix: location.pathname + '?action=',
    columns: <?= $columnsJson ?>,
    features: <?= $featuresJson ?>,
    modal: { open:false, title:'', form:{} },

    init(){
      this.hookSortables();
    },

    hookSortables(){
      setTimeout(()=> {
        document.querySelectorAll('.task-column').forEach(col => {
          if (!col.dataset.inited) {
            new Sortable(col, {
              group: 'kanban',
              animation: 150,
              onEnd: (evt) => this.onDragEnd(evt)
            });
            col.dataset.inited = '1';
          }
        });
      }, 120);
    },

    onDragEnd(evt){
      const payload = [];
      document.querySelectorAll('[data-column-id]').forEach(col => {
        const colId = parseInt(col.dataset.columnId,10);
        Array.from(col.querySelectorAll('[data-task-id]')).forEach((el, idx) => {
          payload.push({ id: parseInt(el.datasetTaskId || el.dataset.taskId,10), column_id: colId, sort_order: idx });
        });
      });
      // fallback for dataset property name differences
      const normalized = payload.map(p => ({ id: p.id, column_id: p.column_id, sort_order: p.sort_order }));
      localStorage.setItem('kanban_pending_order', JSON.stringify(normalized));
      this.syncOrder(normalized);
    },

    syncOrder(order){
      fetch(this.apiPrefix + 'update_order', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-Token': this.csrf},
        body: JSON.stringify({order})
      }).then(r=>r.json()).then(j=>{
        if (j.ok) {
          localStorage.removeItem('kanban_pending_order');
          this.toast('Order saved');
        } else this.toast('Save failed', false);
      }).catch(()=>this.toast('Save failed', false));
    },

    openNewTask(){ this.modal.open=true; this.modal.title='Create task'; this.modal.form={name:'',description:'',column_id: this.columns[0]?this.columns[0].id:null}; },
    openNewInColumn(col){ this.modal.open=true; this.modal.title='Create task'; this.modal.form={name:'',description:'',column_id: col.id}; },
    openEdit(task){ this.modal.open=true; this.modal.title='Edit task'; this.modal.form=Object.assign({}, task); },

    modalClose(){ this.modal.open=false; this.modal.form={}; },

    saveTask(){
      const f = this.modal.form;
      if (!f.name || !f.column_id) { this.toast('Name and column required', false); return; }
      fetch(this.apiPrefix + 'add_task', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-CSRF-Token': this.csrf},
        body: JSON.stringify(f)
      }).then(r=>r.json()).then(j=>{
        if(!j.ok){ this.toast(j.error||'Save failed', false); return; }
        // insert client-side (simple push to column)
        const col = this.columns.find(c => c.id == j.task.column_id);
        if (col) col.tasks.unshift(j.task);
        this.modalClose();
        this.toast('Saved');
        this.hookSortables();
      }).catch(()=>this.toast('Save failed', false));
    },

    deleteTask(task){
      if (!confirm('Delete task?')) return;
      fetch(this.apiPrefix + 'delete_task', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-CSRF-Token': this.csrf},
        body: JSON.stringify({id: task.id})
      }).then(r=>r.json()).then(j=>{
        if(!j.ok) { this.toast('Delete failed', false); return; }
        this.columns.forEach(c => c.tasks = c.tasks.filter(t => t.id !== task.id));
        this.toast('Deleted');
      }).catch(()=>this.toast('Delete failed', false));
    },

    applyTemplate(col){
      // placeholder: just add a sample task
      fetch(this.apiPrefix + 'add_task', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-CSRF-Token': this.csrf},
        body: JSON.stringify({column_id: col.id, name: 'Sample task from template', description: ''})
      }).then(r=>r.json()).then(j=>{
        if (j.ok) { col.tasks.unshift(j.task); this.toast('Template applied'); this.hookSortables(); }
      });
    },

    toast(msg, ok=true){
      const el = document.createElement('div');
      el.className = 'p-2 px-3 rounded ' + (ok ? 'bg-green-600 text-white' : 'bg-red-600 text-white');
      el.style.position = 'fixed';
      el.style.right = '16px';
      el.style.bottom = (16 + (document.querySelectorAll('.kanban-toast').length * 48)) + 'px';
      el.style.zIndex = '9999';
      el.classList.add('kanban-toast');
      el.textContent = msg;
      document.body.appendChild(el);
      setTimeout(()=> el.remove(), 2600);
    }
  };
}
</script>
</body>
</html>
