<?php 
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

// Redirect helper
function redirect(string $location = 'tasks.php'): void {
    header("Location: $location");
    exit;
}

// Add task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'])) {
    $title = trim($_POST['title']);
    if ($title !== '') {
        $stmt = $pdo->prepare("INSERT INTO tasks (title) VALUES (:title)");
        $stmt->execute(['title' => $title]);
    }
    redirect();
}

// Mark complete
if (isset($_GET['done'])) {
    $id = (int)$_GET['done'];
    $stmt = $pdo->prepare("UPDATE tasks SET is_done = 1 WHERE id = :id");
    $stmt->execute(['id' => $id]);
    redirect();
}

// Delete task
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = :id");
    $stmt->execute(['id' => $id]);
    redirect();
}

// Fetch tasks
$stmt = $pdo->query("SELECT * FROM tasks ORDER BY created_at DESC");
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Tasks & Habits</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        nav {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 30px;
        }
        nav a {
            text-decoration: none;
            color: #4f46e5;
            font-weight: bold;
        }
        nav a:hover {
            color: #4338ca;
        }
        h1 { text-align: center; }
        .container {
            max-width: 700px;
            margin: auto;
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        form {
            display: flex;
            margin-bottom: 20px;
        }
        input[type="text"] {
            flex: 1;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px 0 0 8px;
            outline: none;
        }
        button {
            background: #4f46e5;
            color: #fff;
            border: none;
            padding: 12px 20px;
            border-radius: 0 8px 8px 0;
            cursor: pointer;
        }
        button:hover {
            background: #4338ca;
        }
        ul {
            list-style: none;
            padding: 0;
        }
        li {
            background: #fafafa;
            margin-bottom: 10px;
            padding: 12px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        li.done {
            text-decoration: line-through;
            color: #999;
            background: #e9ecef;
        }
        .actions a {
            margin-left: 10px;
            text-decoration: none;
            color: #4f46e5;
            font-weight: bold;
        }
        .actions a:hover {
            color: #d32f2f;
        }
    </style>
</head>
<body>
    <nav>
        <a href="dashboard.php">🏠 Dashboard</a>
        <a href="tasks.php">📌 Tasks</a>
        <a href="habits.php">🔥 Habits</a>
    </nav>

    <div class="container">
        <h1>📌 Tasks Manager</h1>
        <form method="POST">
            <input type="text" name="title" placeholder="New task..." required>
            <button type="submit">Add</button>
        </form>
        <ul>
            <?php foreach ($tasks as $task): ?>
                <li class="<?= $task['is_done'] ? 'done' : '' ?>">
                    <?= htmlspecialchars($task['title']) ?>
                    <div class="actions">
                        <?php if (!$task['is_done']): ?>
                            <a href="?done=<?= $task['id'] ?>">✔</a>
                        <?php endif; ?>
                        <a href="?delete=<?= $task['id'] ?>">✖</a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</body>
</html>
