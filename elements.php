<?php
// elements.php — sidebar that can be embedded (inline) or fixed (default)
// Usage:
// 1) Default fixed (old behaviour):
//      include 'elements.php';
// 2) Embed as element (won't overlay other content):
//      $sidebar_mode = 'inline'; include 'elements.php';
// Make sure session_start() was called earlier in your app.

declare(strict_types=1);

require_once __DIR__ . '/config/db.php'; // expects $pdo (PDO)

// --- Ensure session is started somewhere before including this file ---
if (session_status() === PHP_SESSION_NONE) {
    // don't force-start if app manages sessions elsewhere, but safe-guard:
    @session_start();
}

// --- small fallback for clear_cookie if not defined in your app ---
if (!function_exists('clear_cookie')) {
    function clear_cookie(string $name): void {
        setcookie($name, '', time() - 3600, '/');
        if (isset($_COOKIE[$name])) {
            unset($_COOKIE[$name]);
        }
    }
}

// --- Sidebar mode: 'fixed' (default) or 'inline' (embed as a component) ---
// You can set $sidebar_mode before including this file.
$sidebar_mode = $sidebar_mode ?? 'fixed';
$allowed = ['fixed', 'inline'];
if (!in_array($sidebar_mode, $allowed, true)) {
    $sidebar_mode = 'fixed';
}

// --- Auto-login check (reuse logic) ---
$lifetimeDays = 365;
$lifetime = $lifetimeDays * 24 * 60 * 60;
$rememberCookieName = 'remember_me';
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if (empty($_SESSION['user_id']) && !empty($_COOKIE[$rememberCookieName])) {
    $cookie = $_COOKIE[$rememberCookieName];
    if (strpos($cookie, ':') !== false) {
        list($selector, $validator) = explode(':', $cookie, 2);

        if (preg_match('/^[a-f0-9]{8,}$/i', $selector)) {
            $stmt = $pdo->prepare("SELECT id, user_id, validator_hash, expires_at FROM user_remember_tokens WHERE selector = ? LIMIT 1");
            $stmt->execute([$selector]);

            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $expires = strtotime((string)$row['expires_at']);
                $validatorHash = (string)$row['validator_hash'];

                if ($expires > time() && hash_equals($validatorHash, hash('sha256', $validator))) {
                    $_SESSION['user_id'] = (int)$row['user_id'];
                    $_SESSION['last_activity'] = time();
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

// --- Fetch user data (mb-safe) ---
mb_internal_encoding('UTF-8');

$user = [
    'id'       => null,
    'raw_name' => 'Guest',
    'name'     => 'Guest',
    'email'    => '',
    'role'     => 'User'
];

if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
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
        $initial = strtoupper(substr($user['raw_name'], 0, 1));
    }
    $initial = htmlspecialchars($initial, ENT_QUOTES, 'UTF-8');
}

// --- Prevent duplicate CSS injection when multiple includes happen ---
if (!defined('SIDEBAR_CSS_INCLUDED')) {
    define('SIDEBAR_CSS_INCLUDED', true);
    ?>
    <style>
    /* ===== Namespaced sidebar: .myapp-sidebar (to avoid global collisions) ===== */

    .myapp-sidebar {
      box-sizing: border-box;
      font-family: 'Inter', 'Segoe UI', Roboto, sans-serif;
      font-size: 15px;
      font-weight: 500;
      color: #374151;
      background: #ffffff;
      border-right: 1px solid #e5e7eb;
    }

    /* fixed (default) — like old behaviour but lower z-index to avoid overlay issues */
    .myapp-sidebar--fixed {
      position: fixed;
      top: 0;
      left: 0;
      height: 100vh;
      width: 250px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      z-index: 50; /* reduced from 999 to avoid blocking modals etc */
      box-shadow: 0 1px 0 rgba(16,24,40,0.02);
    }

    /* inline/embed mode: behave like a regular element (doesn't overlay site) */
    .myapp-sidebar--inline {
      position: relative; /* won't overlay other components */
      width: 250px;
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      border-radius: 8px;
      box-shadow: 0 1px 3px rgba(16,24,40,0.03);
      overflow: hidden;
    }

    /* header/menu/footer styles (shared) */
    .myapp-sidebar .sidebar-header {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      border-bottom: 1px solid #f3f4f6;
      font-size: 18px;
      font-weight: 700;
      color: #111827;
      letter-spacing: 0.5px;
      background: transparent;
    }

    .myapp-sidebar .sidebar-menu {
      display: flex;
      flex-direction: column;
      padding: 12px;
      overflow: auto;
      gap: 4px;
    }

    .myapp-sidebar a {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      margin: 3px 0;
      text-decoration: none;
      color: #374151;
      border-radius: 8px;
      transition: all 0.12s ease;
    }

    .myapp-sidebar a:hover { background: #f3f4f6; }

    .myapp-sidebar a.active { background: #e0e7ff; color: #1e40af; font-weight: 600; }

    .myapp-sidebar .sidebar-footer-wrapper { padding: 10px; }

    .myapp-sidebar .sidebar-footer {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 12px;
      border-radius: 10px;
      text-decoration: none;
      transition: background 0.12s ease;
      cursor: pointer;
    }
    .myapp-sidebar .sidebar-footer:hover { background: #f3f4f6; }

    .myapp-sidebar .avatar {
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

    .myapp-sidebar .info .name { font-size: 14px; font-weight: 600; color: #111827; white-space: nowrap; text-overflow: ellipsis; overflow: hidden; }
    .myapp-sidebar .info .role { font-size: 12px; color: #6b7280; }

    /* Responsive: reduce width on smaller screens */
    @media (max-width: 768px) {
      .myapp-sidebar--fixed { width: 200px; }
      .myapp-sidebar--inline { width: 200px; }
    }

    /* Optional helper for pages that want to shift main content when using fixed sidebar:
       .myapp-main-shift { margin-left: 250px; } */
    </style>
    <?php
} // end CSS include

// choose final classes based on mode
$sidebar_classes = 'myapp-sidebar ' . ($sidebar_mode === 'inline' ? 'myapp-sidebar--inline' : 'myapp-sidebar--fixed');

// output sidebar markup (safe, namespaced)
?>
<div class="<?= $sidebar_classes ?>" role="complementary" aria-label="Main sidebar">
  <div>
    <div class="sidebar-header">Better Life</div>

    <nav class="sidebar-menu" aria-label="Main navigation">
      <a href="dashboard.php" class="<?= $current_page==='dashboard.php' ? 'active' : '' ?>">📊 Dashboard</a>
      <a href="habits.php" class="<?= $current_page==='habits.php' ? 'active' : '' ?>">🔥 Manage Habits</a>
      <a href="tasks.php" class="<?= $current_page==='tasks.php' ? 'active' : '' ?>">✅ Manage Tasks</a>
      <a href="kanban.php" class="<?= $current_page==='kanban.php' ? 'active' : '' ?>">🗂 Kanban</a>
      <a href="thoughts.php" class="<?= $current_page==='thoughts.php' ? 'active' : '' ?>">💭 Thoughts</a>
      <a href="roulette.php" class="<?= $current_page==='roulette.php' ? 'active' : '' ?>">🎲 Roulette</a>
    </nav>
  </div>

  <div class="sidebar-footer-wrapper">
    <a href="<?= !empty($user['id']) ? 'profile.php' : 'login.php' ?>" class="sidebar-footer" tabindex="0">
      <div class="avatar"><?= $initial ?></div>
      <div class="info">
        <div class="name"><?= $user['name'] ?></div>
        <div class="role"><?= $user['role'] ?></div>
      </div>
    </a>
  </div>
</div>

<?php
// End of elements.php
