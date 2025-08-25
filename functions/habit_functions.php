<?php
require_once __DIR__ . '/../config/db.php';

/**
 * getHabits($user_id, $q = '', $filter = '')
 * Returns array of habits. Supports optional search (q) and frequency filter.
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
 * getHabit($habit_id, $user_id)
 * Return single habit row or null.
 */
function getHabit($habit_id, $user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM habits WHERE id = ? AND user_id = ?");
    $stmt->execute([$habit_id, $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

/**
 * addHabit($user_id, $title, $description, $frequency)
 * Adds new habit.
 */
function addHabit($user_id, $title, $description, $frequency) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO habits (user_id, title, description, frequency, created_at) VALUES (?, ?, ?, ?, NOW())");
    return $stmt->execute([$user_id, $title, $description, $frequency]);
}

/**
 * updateHabit($habit_id, $user_id, $title, $description, $frequency)
 * Updates habit only if it belongs to the user.
 */
function updateHabit($habit_id, $user_id, $title, $description, $frequency) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE habits SET title = ?, description = ?, frequency = ? WHERE id = ? AND user_id = ?");
    return $stmt->execute([$title, $description, $frequency, $habit_id, $user_id]);
}

/**
 * deleteHabit($habit_id, $user_id)
 * Deletes habit only if it belongs to the user.
 */
function deleteHabit($habit_id, $user_id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM habits WHERE id = ? AND user_id = ?");
    return $stmt->execute([$habit_id, $user_id]);
}

/**
 * trackHabit($habit_id, $date)
 * Inserts or marks completed for specific date.
 * Make sure there is a UNIQUE KEY on (habit_id, track_date).
 */
function trackHabit($habit_id, $date) {
    global $pdo;
    // MySQL ON DUPLICATE KEY approach — needs unique constraint on (habit_id, track_date)
    $stmt = $pdo->prepare("
        INSERT INTO habit_tracking (habit_id, track_date, completed, created_at)
        VALUES (?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE completed = 1, updated_at = NOW()
    ");
    return $stmt->execute([$habit_id, $date]);
}

/**
 * getHabitProgressPercentage($user_id, $days = 7)
 * Example: return progress summary for each habit for the last N days.
 */
function getHabitProgressPercentage($user_id, $days = 7) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT h.id, h.title,
               SUM(IFNULL(ht.completed,0)) as completed_count,
               COUNT(ht.track_date) as total_count
        FROM habits h
        LEFT JOIN habit_tracking ht ON h.id = ht.habit_id AND ht.track_date >= (CURDATE() - INTERVAL ? DAY)
        WHERE h.user_id = ?
        GROUP BY h.id
    ");
    $stmt->execute([$days, $user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * getHabitProgress($user_id)
 * Returns recent tracking rows for user's habits (useful for dashboard).
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
