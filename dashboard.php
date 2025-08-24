<?php
session_start();
require_once 'functions/habit_functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$habits = getHabits($user_id);

// Handle marking habit as completed today
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete'])) {
    $habit_id = intval($_POST['habit_id']);
    $today = date('Y-m-d');
    trackHabit($habit_id, $today);
    // redirect to avoid resubmission
    header('Location: dashboard.php');
    exit;
}

// Get all tracking progress (existing function)
$progress = getHabitProgress($user_id);

// Build map of today's completion by habit id
$today = date('Y-m-d');
$todayMap = [];
foreach ($progress as $p) {
    // note: getHabitProgress() returns habit id as 'id'
    if (!empty($p['track_date']) && $p['track_date'] === $today) {
        $todayMap[intval($p['id'])] = intval($p['completed']);
    }
}

// Stats
$totalHabits = count($habits);
$completedToday = 0;
foreach ($todayMap as $val) {
    if ($val) $completedToday++;
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<title>My Dashboard</title>
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
    a.button {
        background-color: #3498db;
        color: #fff;
        padding: 10px 15px;
        text-decoration: none;
        border-radius: 5px;
        margin-bottom: 20px;
        display: inline-block;
    }
    a.button:hover { background-color: #2980b9; }

    .stats { display: flex; gap: 20px; margin-bottom: 20px; }
    .stat-box {
        background: #fff;
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        text-align: center;
        flex: 1;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    th, td { padding: 12px 15px; text-align: left; }
    th { background-color: #3498db; color: #fff; }
    tr:nth-child(even) { background-color: #f2f2f2; }

    .completed-yes { color: green; font-weight: bold; }
    .completed-no { color: #555; font-weight: bold; }

    button.complete-btn {
        background-color: #2ecc71;
        color: #fff;
        border: none;
        padding: 6px 10px;
        border-radius: 5px;
        cursor: pointer;
        font-size: 14px;
    }
    button.complete-btn:hover { background-color: #27ae60; }

    .small-muted { font-size: 12px; color: #777; }
</style>
</head>
<body>

<h1>My Dashboard</h1>

<a href="habits.php" class="button">Manage Habits</a>

<div class="stats">
    <div class="stat-box">
        <h3>Total Habits</h3>
        <p><?= $totalHabits ?></p>
    </div>
    <div class="stat-box">
        <h3>Completed Today</h3>
        <p><?= $completedToday ?></p>
    </div>
</div>

<table>
    <tr><th>Habit</th><th>Date</th><th>Completed</th></tr>

    <?php if (empty($habits)): ?>
        <tr><td colspan="3" class="small-muted">No habits found. Go to <a href="habits.php">Manage Habits</a> to add some.</td></tr>
    <?php endif; ?>

    <?php foreach ($habits as $habit): 
        $hid = intval($habit['id']);
        $isDoneToday = isset($todayMap[$hid]) && $todayMap[$hid] == 1;
    ?>
    <tr>
        <td><?= htmlspecialchars($habit['title']) ?></td>
        <td><?= $today ?></td>
        <td>
            <?php if ($isDoneToday): ?>
                <span class="completed-yes">Yes</span>
            <?php else: ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="habit_id" value="<?= $hid ?>">
                    <button type="submit" name="complete" class="complete-btn">Mark Completed</button>
                </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

</body>
</html>
