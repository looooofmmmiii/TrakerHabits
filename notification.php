<?php
/**
 * notification.php
 *
 * Невеликий файл-розширення для dashboard.php, який підраховує і рендерить
 * інформативні (неінтерактивні) сповіщення для користувача.
 *
 * ОНОВЛЕННЯ:
 *  - Тепер сповіщення автоматично зникають через кілька секунд (включаючи плавний фейд)
 *  - Додається просте збереження в БД (таблиця `user_notifications`) для історії
 *  - Якщо ви заходите на цю сторінку напряму (notification.php), вона відображає
 *    повну сторінку з історією сповіщень (фільтри/пагінація)
 *  - Повторні покази одних і тих самих сповіщень на перезавантаженнях тепер блокуються
 *    через сесію та серверне дедуплікаційне правило (DEDUP_SECONDS)
 *
 * Вставте у dashboard.php в місці, де хочете показувати сповіщення, наприклад
 * після include "elements.php" або перед <header>:
 *
 *   <?php include 'notification.php'; ?>
 *
 * ПРИМІТКИ:
 *  - Файл читає $user_id та $pdo з глобальної області (як в dashboard.php)
 *  - Якщо таблиця `user_notifications` відсутня, скрипт намагається створити її (CREATE TABLE IF NOT EXISTS)
 *  - Потрібні права на створення таблиць, якщо ви вперше запускаєте (або застосуйте нижченаведений SQL вручну)
 */

if (!defined('NOTIFICATION_INCLUDED')) define('NOTIFICATION_INCLUDED', true);

// require global variables (dashboard.php повинен надавати $user_id, $pdo)
if (!isset($pdo)) {
    // якщо підключили окремо — намагаймося підключити config
    if (file_exists(__DIR__ . '/config/db.php')) {
        require_once __DIR__ . '/config/db.php';
    }
}

// Ensure we use the same session name as dashboard
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('habit_sid');
    session_start();
}

if (!isset($user_id) && isset($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
}

// Якщо не авторизований — нічого не показуємо
if (empty($user_id)) {
    // Якщо це standalone — покажемо сторінку з проханням увійти
    if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
        http_response_code(403);
        echo "<!doctype html><meta charset=\"utf-8\"><title>Notifications</title><p>Please log in to view notifications.</p>";
        exit;
    }
    return;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    // standalone friendly error
    if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
        http_response_code(500);
        echo "<p>Database connection missing. Please ensure config/db.php is available and provides $pdo (PDO).</p>";
        exit;
    }
    return;
}

// ---------- CONFIG (можна налаштувати) ----------
$UPCOMING_DAYS = 3;       // вважати "скоро" цю кількість днів
$NEW_DAYS = 7;            // вважати "новими" за останні N днів
$STREAK_MILESTONES = [3, 7, 14, 30]; // які стрики вважати milestone
$EFFICIENCY_WARNING = 50; // нижче цього — показати попередження
$AUTO_DISMISS_MS = 6000;  // час в мс до автоскривання (6s)
$HISTORY_DAYS = 90;       // скільки днів назад показувати на сторінці історії
$DEDUP_SECONDS = 3600;    // не зберігати в БД дублікати з такими ж type+title+message якщо створені в останні N секунд
// -------------------------------------------------

// ensure table exists (best-effort)
try {
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS user_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  nid VARCHAR(128) DEFAULT NULL,
  type VARCHAR(32) DEFAULT NULL,
  title VARCHAR(255) DEFAULT NULL,
  message TEXT,
  hint TEXT,
  link VARCHAR(255) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX (nid),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL
    );
} catch (Throwable $e) {
    // якщо не вдалось створити таблицю — продовжимо без історії
    error_log('notification: could not ensure user_notifications table: '.$e->getMessage());
}

$notifications = [];
// Ensure session array for shown notifications (prevents re-showing during same session)
if (!isset($_SESSION['seen_notifications']) || !is_array($_SESSION['seen_notifications'])) {
    $_SESSION['seen_notifications'] = [];
}

// отримати dashboard-пакет даних, якщо доступно
$data = [];
try {
    if (function_exists('getDashboardData')) {
        $data = getDashboardData((int)$user_id, $pdo);
    }
} catch (Throwable $e) {
    error_log('notification: getDashboardData failed: ' . $e->getMessage());
    $data = [];
}

// fallback: прості вибірки якщо getDashboardData не повернув потрібні дані
$habits = $data['habits'] ?? null;
$habitDoneMap = $data['habitDoneMap'] ?? null;
$habitNextAvailableMap = $data['habitNextAvailableMap'] ?? null;
$completedToday = $data['completedToday'] ?? null;
$totalHabits = $data['totalHabits'] ?? null;
$efficiency = $data['efficiency'] ?? null;

if (!is_array($habits)) {
    // fetch minimal habit info as fallback
    try {
        $stmt = $pdo->prepare('SELECT id, title, created_at, frequency FROM habits WHERE user_id = ? ORDER BY COALESCE(sort_order,0) ASC, id ASC');
        $stmt->execute([(int)$user_id]);
        $habits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $habits = [];
    }
}
if (!is_array($habitDoneMap)) $habitDoneMap = [];
if (!is_array($habitNextAvailableMap)) $habitNextAvailableMap = [];
if (!is_int($totalHabits)) $totalHabits = count($habits);
if (!is_int($completedToday)) $completedToday = null;
if (!is_int($efficiency)) {
    // quick efficiency calc fallback for today
    if ($totalHabits > 0 && is_null($completedToday)) {
        // try to query completed count
        try {
            $stmt = $pdo->prepare('SELECT COUNT(DISTINCT ht.habit_id) FROM habit_tracking ht JOIN habits h ON h.id = ht.habit_id WHERE h.user_id = ? AND ht.track_date = CURDATE() AND ht.completed = 1');
            $stmt->execute([(int)$user_id]);
            $completedToday = intval($stmt->fetchColumn() ?: 0);
            $efficiency = (int) round(($completedToday / max(1, $totalHabits)) * 100);
        } catch (Throwable $e) {
            $efficiency = 0;
        }
    } else {
        $efficiency = 0;
    }
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$todayObj = new DateTimeImmutable($today);

// 1) Missed today — list first few incomplete habits that are due now
if (is_array($habits)) {
    $missedList = [];
    foreach ($habits as $h) {
        $hid = intval($h['id'] ?? $h['id']);
        $done = isset($habitDoneMap[$hid]) ? intval($habitDoneMap[$hid]) : null;
        if ($done === null) {
            try {
                $stmt = $pdo->prepare('SELECT completed FROM habit_tracking WHERE habit_id = ? AND track_date = ? LIMIT 1');
                $stmt->execute([$hid, $today]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                $done = ($r && intval($r['completed']) === 1) ? 1 : 0;
            } catch (Throwable $e) { $done = 0; }
        }

        $nextDate = null;
        if (isset($habitNextAvailableMap[$hid]['date'])) {
            $nextDate = $habitNextAvailableMap[$hid]['date'];
        }
        if ($nextDate === null) {
            $nextDate = $today;
        }

        if ($done === 0) {
            try {
                $nd = new DateTimeImmutable($nextDate);
            } catch (Throwable $e) { $nd = $todayObj; }
            if ($nd <= $todayObj) {
                $missedList[] = ['id'=>$hid, 'title'=>($h['title'] ?? 'Untitled')];
            }
        }
    }

    if (count($missedList) > 0) {
        $count = count($missedList);
        $titles = array_map(fn($x)=>htmlspecialchars($x['title'], ENT_QUOTES, 'UTF-8'), array_slice($missedList, 0, 5));
        $msg = ($count === 1) ? "У вас 1 пропущена звичка сьогодні." : "У вас $count пропущених звичок сьогодні.";
        $hint = '';
        if (!empty($titles)) $hint = implode(', ', $titles) . (count($missedList) > 5 ? ', …' : '');
        $notifications[] = [
            'id' => 'missed_today',
            'type' => 'warning',
            'title' => 'Пропущені сьогодні',
            'message' => $msg,
            'hint' => $hint,
            'link' => 'habit_history.php'
        ];
    }
}

// 2) Upcoming soon (next $UPCOMING_DAYS days) — remind which habits стануть доступні
$upcoming = [];
foreach ($habits as $h) {
    $hid = intval($h['id'] ?? 0);
    if ($hid <= 0) continue;
    $next = $habitNextAvailableMap[$hid]['date'] ?? null;
    $done = isset($habitDoneMap[$hid]) ? intval($habitDoneMap[$hid]) : 0;
    if (empty($next)) continue;
    try { $nd = new DateTimeImmutable($next); } catch (Throwable $e) { continue; }
    $diff = (int)$todayObj->diff($nd)->days;
    if ($nd > $todayObj && $diff <= $UPCOMING_DAYS && $done === 0) {
        $upcoming[] = ['id'=>$hid,'title'=>($h['title'] ?? 'Untitled'),'in'=>$diff,'date'=>$nd->format('Y-m-d')];
    }
}
if (!empty($upcoming)) {
    usort($upcoming, fn($a,$b)=>($a['in']<=>$b['in']));
    $items = array_map(fn($x)=>htmlspecialchars($x['title'], ENT_QUOTES, 'UTF-8') . ' (in ' . $x['in'] . 'd)', array_slice($upcoming,0,5));
    $notifications[] = [
        'id' => 'upcoming_soon',
        'type' => 'info',
        'title' => 'Скоро доступні',
        'message' => 'Деякі звички стануть доступні найближчими днями.',
        'hint' => implode(', ', $items),
        'link' => 'habits.php'
    ];
}

// 3) Streak milestones
$streaks = $data['habitStreakMap'] ?? null;
if (is_array($streaks)) {
    $milestonesFound = [];
    foreach ($streaks as $hid => $s) {
        $current = intval($s['current'] ?? 0);
        foreach ($STREAK_MILESTONES as $m) {
            if ($current === $m) {
                $title = 'Untitled';
                foreach ($habits as $h) if (intval($h['id']) === intval($hid)) { $title = $h['title'] ?? $title; break; }
                $milestonesFound[] = htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . " — $current дн.";
            }
        }
    }
    if (!empty($milestonesFound)) {
        $notifications[] = [
            'id' => 'streak_milestone',
            'type' => 'success',
            'title' => 'Milestone у стриках',
            'message' => 'Вітаємо! Є поточні стрики, що досягли мілестонів.',
            'hint' => implode(', ', array_slice($milestonesFound,0,5)),
            'link' => 'habit_history.php'
        ];
    }
}

// 4) Low efficiency warning
if (is_int($efficiency) && $efficiency < $EFFICIENCY_WARNING && $totalHabits > 0) {
    $notifications[] = [
        'id' => 'low_eff',
        'type' => 'warning',
        'title' => 'Низька ефективність',
        'message' => "Ваша ефективність сьогодні $efficiency% — нижче порога $EFFICIENCY_WARNING%.",
        'hint' => 'Подивіться на розклад та пріоритети, щоб зробити прогрес стійким.',
        'link' => 'dashboard.php'
    ];
}

// 5) Нові звички за останні $NEW_DAYS днів
try {
    $stmt = $pdo->prepare('SELECT id, title, created_at FROM habits WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY created_at DESC');
    $stmt->execute([(int)$user_id, (int)$NEW_DAYS]);
    $newRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($newRows)) {
        $titles = array_map(fn($r)=>htmlspecialchars($r['title'] ?? 'Untitled', ENT_QUOTES, 'UTF-8'), array_slice($newRows,0,5));
        $notifications[] = [
            'id' => 'new_habits',
            'type' => 'info',
            'title' => 'Нові звички',
            'message' => 'Ви додали нові звички за останні ' . intval($NEW_DAYS) . ' дні.',
            'hint' => implode(', ', $titles),
            'link' => 'habits.php'
        ];
    }
} catch (Throwable $e) {
    // ignore
}

// --- Persist notifications to DB (lightweight) with DB dedupe ---
try {
    if (!empty($notifications)) {
        $sel = $pdo->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND type = ? AND title = ? AND message = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)');
        $ins = $pdo->prepare('INSERT INTO user_notifications (user_id, nid, type, title, message, hint, link, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        foreach ($notifications as $n) {
            // session dedupe: skip notifications already shown in this session
            $sessionKey = md5(($n['id'] ?? '') . '|' . ($n['title'] ?? '') . '|' . ($n['message'] ?? ''));
            if (isset($_SESSION['seen_notifications'][$sessionKey])) continue;

            $type = $n['type'] ?? null;
            $title = $n['title'] ?? null;
            $message = $n['message'] ?? null;
            $sel->execute([(int)$user_id, $type, $title, $message, (int)$DEDUP_SECONDS]);
            $cnt = intval($sel->fetchColumn() ?: 0);
            if ($cnt > 0) continue; // don't insert duplicate within DEDUP_SECONDS

            $nid = substr(($n['id'] ?? '') . '_' . bin2hex(random_bytes(4)), 0, 127);
            $ins->execute([(int)$user_id, $nid, $type, $title, $message, $n['hint'] ?? null, $n['link'] ?? null]);
        }
    }
} catch (Throwable $e) {
    error_log('notification: could not persist notifications: '.$e->getMessage());
}

// Render notifications (non-interactive). Minimal styles to match dashboard.
$isStandalone = (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME']));

if ($isStandalone) {
    // Render full page with history (simple)
    $page = intval($_GET['page'] ?? 1);
    if ($page < 1) $page = 1;
    $perPage = 25;
    $offset = ($page - 1) * $perPage;

    // filter by days
    $days = intval($_GET['days'] ?? $HISTORY_DAYS);
    $days = max(1, min(365, $days));
    $from = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d 00:00:00');

    try {
        $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND created_at >= ?');
        $cntStmt->execute([(int)$user_id, $from]);
        $total = intval($cntStmt->fetchColumn() ?: 0);

        $stmt = $pdo->prepare('SELECT * FROM user_notifications WHERE user_id = ? AND created_at >= ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
        $stmt->bindValue(1, (int)$user_id, PDO::PARAM_INT);
        $stmt->bindValue(2, $from, PDO::PARAM_STR);
        $stmt->bindValue(3, (int)$perPage, PDO::PARAM_INT);
        $stmt->bindValue(4, (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
        $total = 0;
    }

    $totalPages = (int) ceil($total / $perPage);

    ?><!doctype html>
    <html lang="uk">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Notifications — History</title>
        <style>body{font-family:Inter,system-ui,Arial,sans-serif;margin:18px;color:#0f172a} .card{border:1px solid #e6edf3;padding:12px;border-radius:10px;margin-bottom:10px;background:#fff} .muted{color:#475569;font-size:13px}</style>
    </head>
    <body>
        <?php include 'elements.php'; ?>
        <h1>Історія сповіщень</h1>
        <p class="muted">Показано за останні <?php echo intval($days); ?> днів. <a href="dashboard.php">Повернутися до панелі</a></p>

        <?php if (empty($rows)): ?>
            <div class="card"><div class="muted">Немає сповіщень за вибраний період.</div></div>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
                <div class="card">
                    <div style="font-weight:700"><?php echo htmlspecialchars($r['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="muted" style="margin-top:6px"><?php echo htmlspecialchars($r['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if (!empty($r['hint'])): ?><div style="margin-top:8px;color:#334155;font-size:13px"><?php echo htmlspecialchars($r['hint'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                    <?php if (!empty($r['link'])): ?><div style="margin-top:8px;font-size:13px;color:#0f172a">→ <?php echo htmlspecialchars($r['link'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                    <div class="muted" style="margin-top:8px;font-size:12px"><?php echo htmlspecialchars($r['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endforeach; ?>

            <div style="margin-top:12px">
                <div class="muted">Page <?php echo $page; ?> / <?php echo max(1,$totalPages); ?></div>
                <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&days=<?php echo $days; ?>">← Prev</a><?php endif; ?>
                <?php if ($page < $totalPages): ?> <a href="?page=<?php echo $page+1; ?>&days=<?php echo $days; ?>">Next →</a><?php endif; ?>
            </div>
        <?php endif; ?>

    </body>
    </html>
    <?php
    exit;
}

// If we are included (dashboard), render compact notifications with auto-dismiss
// Only render notifications that haven't been shown in this session.
$toRender = [];
foreach ($notifications as $n) {
    $sessionKey = md5(($n['id'] ?? '') . '|' . ($n['title'] ?? '') . '|' . ($n['message'] ?? ''));
    if (isset($_SESSION['seen_notifications'][$sessionKey])) continue;
    $n['__session_key'] = $sessionKey;
    $toRender[] = $n;
}

if (!empty($toRender)):
    ?>
    <div class="notification-area" aria-live="polite" aria-atomic="true" style="position:fixed;left:18px;bottom:18px;z-index:99998;max-width:360px">
        <?php foreach ($toRender as $idx => $n):
            $type = $n['type'] ?? 'info';
            $bg = ($type === 'success') ? '#ecfdf5' : (($type === 'warning') ? '#fff7ed' : '#eef2ff');
            $border = ($type === 'success') ? '#10b981' : (($type === 'warning') ? '#f59e0b' : '#6366f1');
            $nid = htmlspecialchars($n['id'] ?? ('n'.$idx), ENT_QUOTES, 'UTF-8');
            ?>
            <div class="notif" role="status" data-notif-id="<?php echo $nid; ?>" data-session-key="<?php echo htmlspecialchars($n['__session_key'], ENT_QUOTES, 'UTF-8'); ?>" style="background:<?php echo $bg; ?>;border-left:4px solid <?php echo $border; ?>;padding:12px;margin-bottom:10px;border-radius:10px;box-shadow:0 6px 18px rgba(16,24,40,0.06);opacity:1;transition:opacity .45s ease,transform .45s ease">
                <div style="font-weight:700;margin-bottom:4px"><?php echo htmlspecialchars($n['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                <div style="font-size:13px;color:#0f172a;margin-bottom:6px"><?php echo htmlspecialchars($n['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if (!empty($n['hint'])): ?><div class="muted" style="color:#475569;font-size:13px"><?php echo $n['hint']; ?></div><?php endif; ?>
                <?php if (!empty($n['link'])): ?>
                    <div style="margin-top:6px;font-size:12px;color:#334155">→ <?php echo htmlspecialchars($n['link'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div style="font-size:12px;color:#6b7280;margin-top:6px">Всі сповіщення збережені в історії. <a href="notification.php" style="color:inherit">Переглянути історію →</a></div>
    </div>

    <script>
        (function(){
            const AUTO = <?php echo json_encode((int)$AUTO_DISMISS_MS); ?>;
            const area = document.querySelector('.notification-area');
            if (!area) return;
            // для кожного елементу — автоматичне скриття
            const notifs = Array.from(area.querySelectorAll('.notif'));
            notifs.forEach((el, i) => {
                // add stagger to avoid all dismiss at exact same time
                const delay = AUTO + (i * 300);
                setTimeout(() => {
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(8px)';
                    setTimeout(() => { try{ el.remove(); } catch(e){} }, 500);
                }, delay);
            });
        })();
    </script>
    <?php
    // mark notifications as seen in session so reloads won't show them again during this session
    foreach ($toRender as $n) {
        if (!empty($n['__session_key'])) {
            $_SESSION['seen_notifications'][ $n['__session_key'] ] = time();
        }
    }
endif;

// expose $notifications to including script if needed
if (!isset($GLOBALS['notifications'])) $GLOBALS['notifications'] = $notifications;

return;
