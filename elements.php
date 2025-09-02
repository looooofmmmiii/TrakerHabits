<?php
// elements.php — white sidebar with full remember-me login integration

declare(strict_types=1);

require_once __DIR__ . '/config/db.php'; // expects $pdo (PDO)

// --- Include same session + cookie setup as in login.php ---
$lifetimeDays = 365;
$lifetime = $lifetimeDays * 24 * 60 * 60;
$rememberCookieName = 'remember_me';
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');




// --- Helper to clear cookie ---
function clear_cookie(string $name): void {
    setcookie($name, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// --- Auto-login check (reuse logic) ---
if (empty($_SESSION['user_id']) && !empty($_COOKIE[$rememberCookieName])) {
    $cookie = $_COOKIE[$rememberCookieName];
    if (strpos($cookie, ':') !== false) {
        list($selector, $validator) = explode(':', $cookie, 2);

        // basic validation of selector length to avoid weird input
        if (preg_match('/^[a-f0-9]{8,}$/i', $selector)) {
            $stmt = $pdo->prepare("SELECT id, user_id, validator_hash, expires_at FROM user_remember_tokens WHERE selector = ? LIMIT 1");
            $stmt->execute([$selector]);

            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $expires = strtotime((string)$row['expires_at']);
                $validatorHash = (string)$row['validator_hash'];

                if ($expires > time() && hash_equals($validatorHash, hash('sha256', $validator))) {
                    // success: log user in
                    $_SESSION['user_id'] = (int)$row['user_id'];
                    $_SESSION['last_activity'] = time();
                    // optionally rotate token here
                } else {
                    clear_cookie($rememberCookieName);
                }
            } else {
                clear_cookie($rememberCookieName);
            }
        } else {
            clear_cookie($rememberCookieName);
        }
    } else {
        clear_cookie($rememberCookieName);
    }
}

// --- Fetch user data (robust + mb-safe) ---
mb_internal_encoding('UTF-8');

$user = [
    'id'       => null,
    'raw_name' => 'Guest',
    'name'     => 'Guest',   // escaped for HTML
    'email'    => '',
    'role'     => 'User'
];

if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];

    // Select all columns to avoid assuming schema
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // possible keys that might hold user's name
        $possibleNameKeys = ['name', 'username', 'user', 'full_name', 'display_name', 'first_name', 'email'];
        $displayNameRaw = null;
        foreach ($possibleNameKeys as $k) {
            if (isset($row[$k]) && is_string($row[$k]) && mb_strlen(trim((string)$row[$k])) > 0) {
                $displayNameRaw = trim((string)$row[$k]);
                break;
            }
        }
        if ($displayNameRaw === null) {
            $displayNameRaw = 'User';
        }

        $user = [
            'id'       => isset($row['id']) ? (int)$row['id'] : $uid,
            'raw_name' => $displayNameRaw,
            'name'     => htmlspecialchars($displayNameRaw, ENT_QUOTES, 'UTF-8'),
            'email'    => isset($row['email']) ? htmlspecialchars((string)$row['email'], ENT_QUOTES, 'UTF-8') : '',
            'role'     => isset($row['role']) ? htmlspecialchars((string)$row['role'], ENT_QUOTES, 'UTF-8') : 'User'
        ];
    } else {
        // user missing in DB — clear session to avoid ghost login
        unset($_SESSION['user_id']);
    }
}

$current_page = basename($_SERVER['PHP_SELF']);

// compute safe initial for avatar (mb-safe) with fallback
$initial = 'G';
if (!empty($user['raw_name'])) {
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $ch = mb_substr($user['raw_name'], 0, 1, 'UTF-8');
        $initial = mb_strtoupper($ch, 'UTF-8');
    } else {
        // fallback if mbstring is not available
        $initial = strtoupper(substr($user['raw_name'], 0, 1));
    }
    $initial = htmlspecialchars($initial, ENT_QUOTES, 'UTF-8');
}
?>
<style>
/* ===== ЛІВА ПАНЕЛЬ (SaaS Style) ===== */
.sidebar {
  position: fixed;
  top: 0;
  left: 0;
  height: 100vh;
  width: 250px;
  background: #ffffff;
  border-right: 1px solid #e5e7eb;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  font-family: 'Inter', 'Segoe UI', Roboto, sans-serif;
  font-size: 15px;
  font-weight: 500;
  color: #374151;
  z-index: 999;
}

/* ЛОГО */
.sidebar-header {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px 0;
  border-bottom: 1px solid #f3f4f6;
  font-size: 18px;
  font-weight: 700;
  color: #111827;
  letter-spacing: 0.5px;
}

/* МЕНЮ */
.sidebar-menu {
  display: flex;
  flex-direction: column;
  padding: 15px;
  overflow: auto;
}

.sidebar a {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  margin: 3px 0;
  text-decoration: none;
  color: #374151;
  border-radius: 8px;
  transition: all 0.2s ease;
}

.sidebar a:hover {
  background: #f3f4f6;
}

.sidebar a.active {
  background: #e0e7ff;
  color: #1e40af;
  font-weight: 600;
}

.sidebar a .icon {
  font-size: 18px;
  color: #6b7280;
}

/* ФУТЕР */
.sidebar-footer {
  padding: 15px;
  border-top: 1px solid #f3f4f6;
  display: flex;
  align-items: center;
  gap: 10px;
}

.sidebar-footer .avatar {
  background: #2563eb;
  color: #fff;
  font-weight: bold;
  border-radius: 50%;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}

.sidebar-footer .info {
  flex: 1;
  overflow: hidden;
}

.sidebar-footer .info .name {
  font-size: 14px;
  font-weight: 600;
  color: #111827;
  white-space: nowrap;
  text-overflow: ellipsis;
  overflow: hidden;
}

.sidebar-footer .info .role {
  font-size: 12px;
  color: #6b7280;
}

.main-content {
  margin-left: 250px;
  padding: 30px;
  background: #f9fafb;
  min-height: 100vh;
}

@media (max-width: 768px) {
    .sidebar { width: 200px; }
    .main-content { margin-left: 200px; padding: 20px; }
}

</style>

<div class="sidebar">
  <div>
    <div class="sidebar-header">Better Life</div>

    <div class="sidebar-menu">
      <a href="dashboard.php" class="<?= $current_page==='dashboard.php' ? 'active' : '' ?>">📊 Dashboard</a>
      <a href="habits.php" class="<?= $current_page==='habits.php' ? 'active' : '' ?>">🔥 Manage Habits</a>
      <a href="tasks.php" class="<?= $current_page==='tasks.php' ? 'active' : '' ?>">✅ Manage Tasks</a>
      <a href="kanban.php" class="<?= $current_page==='kanban.php' ? 'active' : '' ?>">🗂 Kanban</a>
      <a href="thoughts.php" class="<?= $current_page==='thoughts.php' ? 'active' : '' ?>">💭 Thoughts</a>
      <a href="roulette.php" class="<?= $current_page==='roulette.php' ? 'active' : '' ?>">🎲 Roulette</a>
    </div>
  </div>

  <div class="sidebar-footer">
    <div class="avatar"><?= $initial ?></div>
    <div class="info">
      <div class="name"><?= $user['name'] ?></div>
      <div class="role"><?= $user['role'] ?></div>
    </div>
  </div>
</div>
