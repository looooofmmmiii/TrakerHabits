<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

// Helper: JSON response
function jsonResponse(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// If AJAX re-order (POST) — expects order[]=1&order[]=3...
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order'])) {
    $order = $_POST['order']; // array of ids
    if (is_array($order)) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE tasks SET priority = :p WHERE id = :id");
            foreach ($order as $priority => $id) {
                $stmt->execute([
                    'p' => $priority + 1,
                    'id' => (int)$id
                ]);
            }
            $pdo->commit();
            jsonResponse(['status' => 'ok']);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}

// Add task (normal form POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_task'])) {
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title !== '') {
        $stmt = $pdo->query("SELECT COALESCE(MAX(priority), 0) + 1 AS next_priority FROM tasks");
        $nextPriority = (int)$stmt->fetch(PDO::FETCH_ASSOC)['next_priority'];
        $stmt = $pdo->prepare("INSERT INTO tasks (title, priority, created_at) VALUES (:title, :priority, NOW())");
        $stmt->execute(['title' => $title, 'priority' => $nextPriority]);
    }
    header('Location: tasks.php');
    exit;
}

// Toggle done via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_done'])) {
    $id = (int)($_POST['id'] ?? 0);
    $is_done = isset($_POST['is_done']) && ($_POST['is_done'] === '1' || $_POST['is_done'] === 'true') ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE tasks SET is_done = :is_done WHERE id = :id");
    $stmt->execute(['is_done' => $is_done, 'id' => $id]);
    jsonResponse(['status' => 'ok', 'id' => $id, 'is_done' => $is_done]);
}

// Edit title via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_task'])) {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    if ($id > 0 && $title !== '') {
        $stmt = $pdo->prepare("UPDATE tasks SET title = :title WHERE id = :id");
        $stmt->execute(['title' => $title, 'id' => $id]);
        jsonResponse(['status' => 'ok', 'id' => $id, 'title' => $title]);
    }
    jsonResponse(['status' => 'error', 'message' => 'Invalid data']);
}

// Delete via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_task'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = :id");
        $stmt->execute(['id' => $id]);
        jsonResponse(['status' => 'ok', 'id' => $id]);
    }
    jsonResponse(['status' => 'error', 'message' => 'Invalid id']);
}

// JSON export endpoint
if (isset($_GET['json'])) {
    $stmt = $pdo->query("SELECT id, title, priority, is_done, created_at FROM tasks ORDER BY priority ASC");
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['status' => 'ok', 'tasks' => $tasks]);
}

// Fetch tasks for page
$stmt = $pdo->query("SELECT id, title, priority, is_done FROM tasks ORDER BY priority ASC");
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

function renderTaskTitle(string $title, bool $done): string {
    if (strpos($title, "\n") !== false) {
        return '<pre>' . htmlspecialchars($title) . '</pre>';
    }
    $class = $done ? 'done-text' : '';
    return '<span class="task-title ' . $class . '" tabindex="0">' . htmlspecialchars($title) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Tasks — MVP</title>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <style>
        :root{--accent:#4f46e5;--bg:#f5f7fb}
        body{font-family:Inter, Arial, sans-serif;background:var(--bg);margin:0;padding:20px;color:#222}
        .wrap{max-width:820px;margin:18px auto;padding:20px;background:#fff;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,0.06)}
        header{display:flex;justify-content:space-between;align-items:center;gap:12px}
        h1{margin:0;font-size:20px}
        nav{display:flex;gap:12px}
        nav a{color:var(--accent);text-decoration:none;font-weight:600}
        form.add{display:flex;margin-top:16px}
        form.add input[type=text]{flex:1;padding:12px;border:1px solid #e6e6f0;border-radius:8px 0 0 8px}
        form.add button{background:var(--accent);color:#fff;padding:12px;border:none;border-radius:0 8px 8px 0;cursor:pointer}
        .controls{display:flex;gap:8px;align-items:center}
        .controls button{padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer}
        ul{list-style:none;padding:0;margin-top:18px}
        li{display:flex;align-items:flex-start;gap:10px;padding:12px;background:#fafafa;border-radius:10px;margin-bottom:10px}
        li.done{background:#eef2ff}
        .handle{cursor:grab;padding:6px}
        .content{flex:1}
        .actions{display:flex;gap:8px;align-items:center}
        .actions button{border:0;background:transparent;cursor:pointer;font-size:16px}
        span.done-text{ text-decoration:line-through;color:#888 }
        .meta{font-size:12px;color:#666}
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div>
            <h1>📌 Tasks — MVP</h1>
            <div class="meta" id="task-stats"></div>
        </div>
        <?php include "elements.php"; ?>
    </header>

    <form class="add" method="POST" onsubmit="return addTask(event)">
        <input type="text" id="new-title" name="title" placeholder="New task..." required>
        <button type="submit" name="add_task">Add</button>
    </form>

    <div class="controls" style="margin-top:12px">
        <button id="exportBtn">Export JSON</button>
        <button id="clearCompleted">Clear completed</button>
    </div>

    <ul id="task-list">
        <?php foreach ($tasks as $task): ?>
            <li data-id="<?= $task['id'] ?>" class="<?= $task['is_done'] ? 'done' : '' ?>">
                <span class="handle">☰</span>
                <div class="content">
                    <label style="display:flex;gap:8px;align-items:center">
                        <input class="toggle" type="checkbox" <?= $task['is_done'] ? 'checked' : '' ?> aria-label="Позначити виконаним">
                        <?= renderTaskTitle($task['title'], (bool)$task['is_done']); ?>
                    </label>
                </div>
                <div class="actions">
                    <button class="edit" title="Редагувати">✎</button>
                    <button class="delete" title="Видалити">✖</button>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<script>
const taskList = document.getElementById('task-list');
const stats = document.getElementById('task-stats');

function refreshStats(){
    const total = taskList.querySelectorAll('li').length;
    const done = taskList.querySelectorAll('li.done').length;
    stats.textContent = `${done} / ${total} виконано`;
}

refreshStats();

// Sortable
new Sortable(taskList, {
    handle: '.handle',
    animation: 150,
    onEnd: function () {
        let order = [];
        taskList.querySelectorAll('li').forEach(li => order.push(li.dataset.id));
        const body = order.map(id => `order[]=${encodeURIComponent(id)}`).join('&');
        fetch('tasks.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body }).then(()=>{});
    }
});

// Add task via normal form submit (fallback) — we intercept to allow AJAX add optionally
async function addTask(e){
    e.preventDefault();
    const titleEl = document.getElementById('new-title');
    const title = titleEl.value.trim();
    if(!title) return false;
    const form = new FormData();
    form.append('add_task','1');
    form.append('title', title);
    // Use fetch to submit, then reload to simplify state handling
    await fetch('tasks.php', { method: 'POST', body: form });
    window.location.reload();
    return false;
}

// Delegate events
taskList.addEventListener('click', async (e)=>{
    const li = e.target.closest('li');
    if(!li) return;
    const id = li.dataset.id;
    // Edit
    if(e.target.matches('.edit')){
        const titleEl = li.querySelector('.task-title');
        const old = titleEl.textContent;
        const newTitle = prompt('Редагувати задачу', old);
        if(newTitle === null) return; // cancelled
        const form = new FormData();
        form.append('edit_task','1');
        form.append('id', id);
        form.append('title', newTitle);
        const res = await fetch('tasks.php', { method: 'POST', body: form });
        const json = await res.json();
        if(json.status === 'ok'){
            titleEl.textContent = json.title;
        } else alert(json.message || 'Помилка');
    }
    // Delete
    if(e.target.matches('.delete')){
        if(!confirm('Видалити задачу?')) return;
        const form = new FormData();
        form.append('delete_task','1');
        form.append('id', id);
        const res = await fetch('tasks.php', { method: 'POST', body: form });
        const json = await res.json();
        if(json.status === 'ok'){
            li.remove();
            refreshStats();
        } else alert(json.message || 'Помилка');
    }
});

// Toggle done
taskList.addEventListener('change', async (e)=>{
    if(!e.target.matches('.toggle')) return;
    const li = e.target.closest('li');
    const id = li.dataset.id;
    const is_done = e.target.checked ? '1' : '0';
    const form = new FormData();
    form.append('toggle_done','1');
    form.append('id', id);
    form.append('is_done', is_done);
    const res = await fetch('tasks.php', { method: 'POST', body: form });
    const json = await res.json();
    if(json.status === 'ok'){
        if(json.is_done == 1) li.classList.add('done'); else li.classList.remove('done');
        refreshStats();
    } else {
        alert('Не вдалося оновити');
    }
});

// Export JSON (uses existing endpoint)
document.getElementById('exportBtn').addEventListener('click', async ()=>{
    const res = await fetch('tasks.php?json=1');
    const json = await res.json();
    if(json.status !== 'ok') return alert('Не вдалося експортувати');
    const blob = new Blob([JSON.stringify(json.tasks, null, 2)], {type:'application/json'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'tasks-export.json';
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
});

// Clear completed
document.getElementById('clearCompleted').addEventListener('click', async ()=>{
    if(!confirm('Видалити всі виконані задачі?')) return;
    const doneIds = Array.from(taskList.querySelectorAll('li.done')).map(li => li.dataset.id);
    for(const id of doneIds){
        const form = new FormData(); form.append('delete_task','1'); form.append('id', id);
        await fetch('tasks.php', { method:'POST', body: form });
    }
    window.location.reload();
});
</script>
</body>
</html>
