<?php
require_once __DIR__ . '/../config/db.php';

/**
 * Get all habits of user with optional search and filter
 */
function getHabits($user_id, $q = '', $filter = '') {
    global $pdo;
    $params = [$user_id];
    $sql = "SELECT * FROM habits WHERE user_id = ?";

    if ($q !== '') {
        $sql .= " AND (title LIKE ? OR description LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }

    if ($filter !== '') {
        $sql .= " AND frequency = ?";
        $params[] = $filter;
    }

    $sql .= " ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get single habit
 */
function getHabit($habit_id, $user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM habits WHERE id = ? AND user_id = ?");
    $stmt->execute([$habit_id, $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Add new habit
 */
function addHabit($user_id, $title, $description, $frequency) {
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO habits (user_id, title, description, frequency)
        VALUES (?, ?, ?, ?)
    ");
    return $stmt->execute([$user_id, $title, $description, $frequency]);
}

/**
 * Update existing habit
 */
function updateHabit($habit_id, $user_id, $title, $description, $frequency) {
    global $pdo;
    $stmt = $pdo->prepare("
        UPDATE habits 
        SET title = ?, description = ?, frequency = ?
        WHERE id = ? AND user_id = ?
    ");
    return $stmt->execute([$title, $description, $frequency, $habit_id, $user_id]);
}

/**
 * Delete habit
 */
function deleteHabit($habit_id, $user_id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM habits WHERE id = ? AND user_id = ?");
    return $stmt->execute([$habit_id, $user_id]);
}

/**
 * Track habit for specific date
 */
function trackHabit($habitId, $date) {
    global $pdo;

    $stmt = $pdo->prepare("
        INSERT INTO habit_tracking (habit_id, track_date, completed)
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE completed = 1
    ");
    return $stmt->execute([$habitId, $date]);
}


/**
 * Progress percentage by habit (last N days)
 */
function getHabitProgressPercentage($user_id, $days = 7) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT h.id, h.title,
               SUM(IFNULL(ht.completed,0)) AS completed_count,
               COUNT(DISTINCT ht.track_date) AS total_count
        FROM habits h
        LEFT JOIN habit_tracking ht 
            ON h.id = ht.habit_id 
           AND ht.track_date >= (CURDATE() - INTERVAL ? DAY)
        WHERE h.user_id = ?
        GROUP BY h.id
    ");
    $stmt->execute([$days, $user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function getEfficiencyByDay($pdo) {
    $stmt = $pdo->query("
        SELECT 
            track_date,
            ROUND(AVG(completed) * 100, 2) AS efficiency
        FROM habit_tracking
        GROUP BY track_date
        ORDER BY track_date ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Get recent tracking activity
 */
function getHabitProgress($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT h.id, h.title, ht.track_date, ht.completed
        FROM habits h
        LEFT JOIN habit_tracking ht ON h.id = ht.habit_id
        WHERE h.user_id = ?
        ORDER BY ht.track_date DESC
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
