<?php
require_once __DIR__ . '/../config/db.php';

function getHabits($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM habits WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function addHabit($user_id, $title, $description, $frequency) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO habits (user_id, title, description, frequency) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$user_id, $title, $description, $frequency]);
}

function updateHabit($habit_id, $title, $description, $frequency) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE habits SET title=?, description=?, frequency=? WHERE id=?");
    return $stmt->execute([$title, $description, $frequency, $habit_id]);
}

function deleteHabit($habit_id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM habits WHERE id=?");
    return $stmt->execute([$habit_id]);
}

function trackHabit($habit_id, $date) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO habit_tracking (habit_id, track_date, completed) VALUES (?, ?, 1)
                           ON DUPLICATE KEY UPDATE completed=1");
    return $stmt->execute([$habit_id, $date]);
}

function getHabitProgressPercentage($user_id, $days = 7) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT h.id, h.title,
        SUM(ht.completed) as completed_count,
        COUNT(ht.track_date) as total_count
        FROM habits h
        LEFT JOIN habit_tracking ht ON h.id = ht.habit_id AND ht.track_date >= CURDATE() - INTERVAL ? DAY
        WHERE h.user_id = ?
        GROUP BY h.id
    ");
    $stmt->execute([$days, $user_id]);
    return $stmt->fetchAll();
}


function getHabitProgress($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT h.id, h.title, ht.track_date, ht.completed
                           FROM habits h
                           LEFT JOIN habit_tracking ht ON h.id = ht.habit_id
                           WHERE h.user_id = ?
                           ORDER BY ht.track_date DESC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}
?>
