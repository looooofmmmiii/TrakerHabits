<?php
require_once __DIR__ . '/../config/db.php';



function resetHabitsForToday($user_id) {
    global $pdo;

    $today = date('Y-m-d');

    // Отримуємо всі звички юзера
    $stmt = $pdo->prepare("SELECT id FROM habits WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $habits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($habits as $habit) {
        $habitId = $habit['id'];

        // Check чи є вже запис на сьогодні
        $check = $pdo->prepare("
            SELECT 1 FROM habit_tracking 
            WHERE habit_id = ? AND track_date = ?
        ");
        $check->execute([$habitId, $today]);

        // Якщо немає – створюємо з completed = 0
        if (!$check->fetch()) {
            $insert = $pdo->prepare("
                INSERT INTO habit_tracking (habit_id, track_date, completed)
                VALUES (?, ?, 0)
            ");
            $insert->execute([$habitId, $today]);
        }
    }
}

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
function trackHabit($habitId, $date = null) {
    global $pdo;

    if ($date === null) {
        $date = date('Y-m-d');
    }

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

function ensureTrackingForToday($user_id) {
    global $pdo;
    $today = date('Y-m-d');

    // всі daily звички юзера
    $stmt = $pdo->prepare("SELECT id FROM habits WHERE user_id = ? AND frequency = 'daily'");
    $stmt->execute([$user_id]);
    $habits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($habits as $habit) {
        $habitId = $habit['id'];
        // вставляємо рядок completed=0, якщо його ще нема
        $pdo->prepare("
            INSERT IGNORE INTO habit_tracking (habit_id, track_date, completed, created_at, updated_at)
            VALUES (?, ?, 0, NOW(), NOW())
        ")->execute([$habitId, $today]);
    }
}






















// safe wrapper: only declare if not already declared
if (!function_exists('e')) {
    function e($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}


// Ensure iterable
function ensure_iterable(&$v) {
    if (!is_iterable($v)) $v = [];
}

// parse textual frequency into days interval
function parseFrequencyToDays(string $freq): ?int {
    $f = strtolower(trim($freq));
    if ($f === '') return 1; // default daily
    if (preg_match('/(\d+)\s*(d|day|days)?/', $f, $m)) {
        return intval($m[1]);
    }
    if (strpos($f, 'weekly') !== false || strpos($f, 'week') !== false) return 7;
    if (strpos($f, 'daily') !== false || strpos($f, 'day') !== false) return 1;
    if (strpos($f, 'monthly') !== false || strpos($f, 'month') !== false) return 30;
    return null;
}

function getLastCompletedDate(int $hid, array $completedDatesByHabit): ?string {
    if (empty($completedDatesByHabit[$hid]) || !is_array($completedDatesByHabit[$hid])) return null;
    $dates = array_keys($completedDatesByHabit[$hid]);
    if (empty($dates)) return null;
    rsort($dates);
    return $dates[0];
}

function computeNextAvailable(?string $lastCompleted, ?int $daysInterval, DateTimeImmutable $todayObj): array {
    $nextDate = $todayObj;
    $label = 'Available now';
    if (is_int($daysInterval) && $daysInterval > 0) {
        if ($lastCompleted !== null) {
            try {
                $lastObj = new DateTimeImmutable($lastCompleted);
                $nextDate = $lastObj->add(new DateInterval('P' . $daysInterval . 'D'));
                if ($nextDate > $todayObj) {
                    $diff = (int)$todayObj->diff($nextDate)->days;
                    $label = 'in ' . $diff . ' days';
                }
            } catch (Exception $ex) {}
        }
    }
    return ['date' => $nextDate->format('Y-m-d'), 'label' => $label];
}

// convert YYYY-MM-DD to integer slot
function dateToSlot(string $dateStr, int $intervalDays): int {
    $ts = strtotime($dateStr . ' 00:00:00 UTC');
    $days = (int) floor($ts / 86400);
    return intdiv($days, max(1, $intervalDays));
}

/**
 * Return dashboard data for user
 */
/**
 * getDashboardData - returns all data needed by dashboard.php
 * @param int $user_id
 * @param PDO|null $pdo
 * @return array
 */
function getDashboardData(int $user_id, $pdo = null): array {
    // safe defaults
    $today = date('Y-m-d');
    $todayObj = new DateTimeImmutable($today);

    // make sure daily tracking rows exist
    if (function_exists('ensureTrackingForToday')) {
        try { ensureTrackingForToday($user_id); } catch (\Throwable $e) { /* ignore */ }
    }

    // fetch habits + progress using existing helpers if available
    $habits = function_exists('getHabits') ? getHabits($user_id) : [];
    if (!is_array($habits)) $habits = [];

    $progress = function_exists('getHabitProgress') ? getHabitProgress($user_id) : [];
    if (!is_array($progress)) $progress = [];

    // Build completedDatesByHabit from $progress
    $completedDatesByHabit = [];
    $dateCompletedMap = [];
    $datesSet = [];
    foreach ($progress as $p) {
        $hid = isset($p['habit_id']) ? intval($p['habit_id']) : (isset($p['id']) ? intval($p['id']) : 0);
        if ($hid <= 0) continue;
        if (!empty($p['track_date'])) {
            $d = $p['track_date'];
            $datesSet[$d] = true;
            if (intval($p['completed']) === 1) {
                $dateCompletedMap[$d] = ($dateCompletedMap[$d] ?? 0) + 1;
                $completedDatesByHabit[$hid][$d] = true;
            }
        }
    }

    // compute week range (Mon..Sun)
    $dtToday = new DateTimeImmutable($today);
    $weekStartObj = $dtToday->modify('monday this week');
    $weekEndObj = $weekStartObj->add(new DateInterval('P6D'));
    $weekStart = $weekStartObj->format('Y-m-d');
    $weekEnd = $weekEndObj->format('Y-m-d');

    // helpers (define locally if not existing)
    if (!function_exists('parseFrequencyToDays')) {
        function parseFrequencyToDays(string $freq): ?int {
            $f = strtolower(trim($freq));
            if ($f === '') return 1;
            if (preg_match('/(\d+)\s*(d|day|days)?/', $f, $m)) return intval($m[1]);
            if (strpos($f,'week')!==false) return 7;
            if (strpos($f,'day')!==false) return 1;
            if (strpos($f,'month')!==false) return 30;
            return null;
        }
    }
    if (!function_exists('getLastCompletedDate')) {
        function getLastCompletedDate(int $hid, array $completedDatesByHabit): ?string {
            if (empty($completedDatesByHabit[$hid]) || !is_array($completedDatesByHabit[$hid])) return null;
            $dates = array_keys($completedDatesByHabit[$hid]);
            if (empty($dates)) return null;
            rsort($dates);
            return $dates[0];
        }
    }
    if (!function_exists('computeNextAvailable')) {
        function computeNextAvailable(?string $lastCompleted, ?int $daysInterval, DateTimeImmutable $todayObj): array {
            $nextDate = $todayObj;
            $label = 'Available now';
            if (is_int($daysInterval) && $daysInterval > 0) {
                if ($lastCompleted !== null) {
                    try {
                        $lastObj = new DateTimeImmutable($lastCompleted);
                        $nextDate = $lastObj->add(new DateInterval('P' . $daysInterval . 'D'));
                        if ($nextDate > $todayObj) {
                            $diff = (int)$todayObj->diff($nextDate)->days;
                            $label = 'in ' . $diff . ' days';
                        }
                    } catch (\Throwable $e) {}
                }
            }
            return ['date' => $nextDate->format('Y-m-d'), 'label' => $label];
        }
    }
    if (!function_exists('dateToSlot')) {
        function dateToSlot(string $dateStr, int $intervalDays): int {
            $ts = strtotime($dateStr . ' 00:00:00 UTC');
            $days = (int) floor($ts / 86400);
            return intdiv($days, max(1, $intervalDays));
        }
    }

    // compute per-habit done/nextAvailable
    $habitDoneMap = [];
    $habitNextAvailableMap = [];
    foreach ($habits as $h) {
        $hid = intval($h['id']);
        $freqRaw = (string)($h['frequency'] ?? $h['recurrence'] ?? $h['period'] ?? 'daily');
        $daysInterval = parseFrequencyToDays($freqRaw);
        $lastCompleted = getLastCompletedDate($hid, $completedDatesByHabit);
        $done = 0;
        if (is_int($daysInterval) && $daysInterval > 0) {
            if ($lastCompleted !== null) {
                try {
                    $lastObj = new DateTimeImmutable($lastCompleted);
                    $diffDays = (int)$todayObj->diff($lastObj)->days;
                    $done = ($diffDays < $daysInterval) ? 1 : 0;
                } catch (\Throwable $e) { $done = 0; }
            } else { $done = 0; }
            $habitNextAvailableMap[$hid] = computeNextAvailable($lastCompleted, $daysInterval, $todayObj);
        } else {
            // legacy weekly or daily
            $freq = strtolower(trim($freqRaw));
            if ($freq === 'weekly' || $freq === 'week') {
                $found = false;
                if (!empty($completedDatesByHabit[$hid])) {
                    foreach ($completedDatesByHabit[$hid] as $d => $_) {
                        if ($d >= $weekStart && $d <= $weekEnd) { $found = true; break; }
                    }
                }
                $done = $found ? 1 : 0;
                $nextObj = DateTimeImmutable::createFromFormat('Y-m-d', $weekEnd)->add(new DateInterval('P1D'));
                $daysUntil = (int)$todayObj->diff($nextObj)->days;
                $habitNextAvailableMap[$hid] = ['date'=>$nextObj->format('Y-m-d'),'label'=>($nextObj <= $todayObj ? 'Available now' : 'in '.$daysUntil.' days')];
            } else {
                // daily default
                $done = (!empty($completedDatesByHabit[$hid]) && !empty($completedDatesByHabit[$hid][$today])) ? 1 : 0;
                if ($done === 1) {
                    $nextObj = $todayObj->add(new DateInterval('P1D'));
                    $habitNextAvailableMap[$hid] = ['date'=>$nextObj->format('Y-m-d'),'label'=>'in 1 day'];
                } else {
                    $habitNextAvailableMap[$hid] = ['date'=>$todayObj->format('Y-m-d'),'label'=>'Available now'];
                }
            }
        }
        $habitDoneMap[$hid] = $done;
    }

    // build displayHabits: incomplete first, then completed
    $incompleteDisplay = []; $completedDisplay = [];
    foreach ($habits as $h) {
        $hid = intval($h['id'] ?? 0);
        $done = isset($habitDoneMap[$hid]) ? intval($habitDoneMap[$hid]) : 0;
        $title = trim((string)($h['title'] ?? ''));
        $h['_title_sort'] = function_exists('mb_strtolower') ? mb_strtolower($title) : strtolower($title);
        if ($done === 0) $incompleteDisplay[] = $h; else $completedDisplay[] = $h;
    }
    usort($incompleteDisplay, function($a,$b){ return ($a['_title_sort'] ?? '') <=> ($b['_title_sort'] ?? ''); });
    usort($completedDisplay, function($a,$b){ return ($a['_title_sort'] ?? '') <=> ($b['_title_sort'] ?? ''); });
    $displayHabits = array_merge($incompleteDisplay, $completedDisplay);
    foreach ($displayHabits as &$hh) { unset($hh['_title_sort']); } unset($hh);
    if (!is_array($displayHabits) || empty($displayHabits)) $displayHabits = $habits;

    // completedToday / missed / efficiency
    $totalHabits = count($habits);
    $completedToday = 0;
    foreach ($habits as $h) {
        $hid = intval($h['id'] ?? 0);
        if ($hid <= 0) continue;
        $done = 0;
        if (array_key_exists($hid, $habitDoneMap)) $done = intval($habitDoneMap[$hid]);
        else {
            if (in_array((string)$hid, array_keys($habitDoneMap), true)) $done = intval($habitDoneMap[(string)$hid]);
        }
        if ($done === 1) $completedToday++;
    }
    $missedToday = max(0, $totalHabits - $completedToday);
    $efficiency = ($totalHabits > 0) ? (int) round(($completedToday / $totalHabits) * 100) : 0;

    // streaks
    $habitStreakMap = []; $longestStreak = 0;
    foreach ($habits as $h) {
        $hid = intval($h['id']);
        $freqRaw = (string)($h['frequency'] ?? $h['recurrence'] ?? $h['period'] ?? 'daily');
        $intervalDays = parseFrequencyToDays($freqRaw);
        if (!is_int($intervalDays) || $intervalDays <= 0) $intervalDays = 1;
        $dates = [];
        if (!empty($completedDatesByHabit[$hid]) && is_array($completedDatesByHabit[$hid])) {
            $dates = array_keys($completedDatesByHabit[$hid]);
            $dates = array_values(array_unique($dates));
            sort($dates);
        }
        if (empty($dates)) { $habitStreakMap[$hid] = ['current'=>0,'longest'=>0]; continue; }
        $slotSet = [];
        foreach ($dates as $d) { $slotSet[ dateToSlot($d, $intervalDays) ] = true; }
        $slots = array_keys($slotSet); sort($slots);
        $maxRun = 0; $run = 0; $prev = null;
        foreach ($slots as $s) {
            if ($prev === null || $s !== $prev + 1) $run = 1; else $run++;
            if ($run > $maxRun) $maxRun = $run;
            $prev = $s;
        }
        $todaySlot = dateToSlot($today, $intervalDays);
        $currentRun = 0; $cursor = $todaySlot;
        while (isset($slotSet[$cursor])) { $currentRun++; $cursor--; }
        $habitStreakMap[$hid] = ['current'=>$currentRun,'longest'=>$maxRun];
        if ($maxRun > $longestStreak) $longestStreak = $maxRun;
    }

    // chart time-series last 7 days (try using $pdo if provided for aggregated query)
    $series = []; $chart_labels = []; $chart_values = []; $predicted = null; $predictedDate = null;
    $endDate = new DateTimeImmutable('now');
    $startDate = $endDate->sub(new DateInterval('P6D'));
    $datesRange = [];
    $period = new DatePeriod($startDate, new DateInterval('P1D'), $endDate->add(new DateInterval('P1D')));
    foreach ($period as $d) $datesRange[] = $d->format('Y-m-d');

    try {
        if (!empty($pdo) && ($pdo instanceof PDO)) {
            $sql = <<<'SQL'
SELECT 
    ht.track_date AS track_date,
    ROUND(AVG(ht.completed) * 100, 2) AS efficiency,
    COUNT(ht.id) AS total_tracked,
    SUM(ht.completed) AS completed_count
FROM habit_tracking ht
JOIN habits h ON h.id = ht.habit_id
WHERE h.user_id = ?
  AND ht.track_date BETWEEN ? AND ?
GROUP BY ht.track_date
ORDER BY ht.track_date ASC
SQL;
            $startStr = $startDate->format('Y-m-d');
            $endStr = $endDate->format('Y-m-d');
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id, $startStr, $endStr]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) $rows = [];
            $map = [];
            foreach ($rows as $r) {
                if (!isset($r['track_date'])) continue;
                $map[$r['track_date']] = [
                    'efficiency' => isset($r['efficiency']) ? floatval($r['efficiency']) : 0.0,
                    'total' => intval($r['total_tracked']),
                    'completed' => intval($r['completed_count'])
                ];
            }
            foreach ($datesRange as $d) {
                if (isset($map[$d])) {
                    $val = max(0.0, min(100.0, $map[$d]['efficiency']));
                    $series[] = ['date' => $d, 'value' => round($val,2), 'total' => $map[$d]['total'], 'completed' => $map[$d]['completed']];
                } else {
                    $series[] = ['date'=>$d,'value'=>0.0,'total'=>0,'completed'=>0];
                }
            }
            $chart_labels = array_map(fn($i) => $i['date'], $series);
            $chart_values = array_map(fn($i) => $i['value'], $series);
            $n = count($chart_values);
            if ($n >= 2) {
                $deltas = [];
                for ($i = 1; $i < $n; $i++) $deltas[] = $chart_values[$i] - $chart_values[$i-1];
                $avgDelta = array_sum($deltas) / max(1, count($deltas));
                $lastVal = floatval($chart_values[$n-1]);
                $predicted = $lastVal + $avgDelta;
            } elseif ($n === 1) $predicted = floatval($chart_values[0]);
            if (!is_null($predicted)) {
                $predicted = max(0.0, min(100.0, round($predicted,2)));
                $lastLabel = end($chart_labels) ?: $endDate->format('Y-m-d');
                $lastDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $lastLabel) ?: $endDate;
                $predictedDate = $lastDateObj->add(new DateInterval('P1D'))->format('Y-m-d');
            }
        } else {
            // fallback compute from $progress
            foreach ($datesRange as $d) {
                $total = 0; $completed = 0;
                foreach ($progress as $p) {
                    if (($p['track_date'] ?? '') === $d) {
                        $total++;
                        if (intval($p['completed']) === 1) $completed++;
                    }
                }
                $val = ($total>0) ? round(($completed / $total) * 100, 2) : 0.0;
                $series[] = ['date'=>$d,'value'=>$val,'total'=>$total,'completed'=>$completed];
            }
            $chart_labels = array_map(fn($i)=>$i['date'],$series);
            $chart_values = array_map(fn($i)=>$i['value'],$series);
            $predicted = null; $predictedDate = null;
        }
    } catch (\Throwable $ex) {
        // swallow, but return safe defaults
        error_log('getDashboardData chart error: '.$ex->getMessage());
        $series = []; $chart_labels = []; $chart_values = []; $predicted = null; $predictedDate = null;
    }

    return [
        'habits'=>$habits,
        'displayHabits'=>$displayHabits,
        'progress'=>$progress,
        'completedDatesByHabit'=>$completedDatesByHabit,
        'dateCompletedMap'=>$dateCompletedMap,
        'habitDoneMap'=>$habitDoneMap,
        'habitNextAvailableMap'=>$habitNextAvailableMap,
        'totalHabits'=>$totalHabits,
        'completedToday'=>$completedToday,
        'missedToday'=>$missedToday,
        'efficiency'=>$efficiency,
        'habitStreakMap'=>$habitStreakMap,
        'longestStreak'=>$longestStreak,
        'chart'=>['labels'=>$chart_labels,'values'=>$chart_values,'series'=>$series,'predicted'=>$predicted,'predictedDate'=>$predictedDate],
    ];
}

?>