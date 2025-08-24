<?php
session_start();
require_once 'functions/habit_functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle Add Habit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    addHabit($user_id, $_POST['title'], $_POST['description'], $_POST['frequency']);
    header('Location: habits.php');
}

// Handle Delete Habit
if (isset($_GET['delete'])) {
    deleteHabit($_GET['delete']);
    header('Location: habits.php');
}

$habits = getHabits($user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Habits</title>
<style>
    body {
        font-family: Arial, sans-serif;
        background: #f5f7fa;
        margin: 20px;
        color: #333;
    }
    h1 {
        color: #2c3e50;
    }
    form {
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }
    input, textarea, select, button {
        display: block;
        width: 100%;
        padding: 10px;
        margin-bottom: 15px;
        border-radius: 5px;
        border: 1px solid #ccc;
        font-size: 14px;
    }
    button {
        background-color: #3498db;
        color: #fff;
        border: none;
        cursor: pointer;
    }
    button:hover {
        background-color: #2980b9;
    }
    ul {
        list-style: none;
        padding: 0;
    }
    li {
        background: #fff;
        margin-bottom: 10px;
        padding: 15px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .delete-btn {
        background-color: #e74c3c;
        color: #fff;
        padding: 5px 10px;
        border-radius: 5px;
        text-decoration: none;
    }
    .delete-btn:hover {
        background-color: #c0392b;
    }
    .habit-info {
        display: flex;
        flex-direction: column;
    }
    .frequency {
        font-size: 12px;
        color: #888;
    }
</style>
<script>
function confirmDelete(title) {
    return confirm(`Are you sure you want to delete habit "${title}"?`);
}
</script>
</head>
<body>

<h1>Manage Habits</h1>

<form method="POST">
    <input type="text" name="title" placeholder="Title" required>
    <textarea name="description" placeholder="Description"></textarea>
    <select name="frequency">
        <option value="daily">Daily</option>
        <option value="weekly">Weekly</option>
        <option value="custom">Custom</option>
    </select>
    <button type="submit" name="add">Add Habit</button>
</form>

<ul>
<?php foreach($habits as $habit): ?>
    <li>
        <div class="habit-info">
            <strong><?= htmlspecialchars($habit['title']) ?></strong>
            <span class="frequency"><?= $habit['frequency'] ?></span>
        </div>
        <a href="?delete=<?= $habit['id'] ?>" class="delete-btn" onclick="return confirmDelete('<?= htmlspecialchars($habit['title']) ?>')">Delete</a>
    </li>
<?php endforeach; ?>
</ul>

</body>
</html>
