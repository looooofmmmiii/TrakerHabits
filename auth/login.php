<?php
// auth/login.php — full, production-ready-ish remember-me login (selector/validator + token rotation + UI)
// Requirements: PHP 7.3+, PDO $pdo from ../config/db.php, HTTPS recommended
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php'; // expects $pdo (PDO)

// --- CONFIG ---
$lifetimeDays = 365; // persistent cookie lifetime (days) — adjust to taste
$lifetime = (int)$lifetimeDays * 24 * 60 * 60;
$rememberCookieName = 'remember_me';
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('habit_sid');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// --- Helpers ---
function set_secure_cookie(string $name, string $value, int $expires, bool $secure): void {
    // PHP 7.3+ options array
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

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

// --- Try auto-login via remember cookie if session missing ---
try {
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('DB unavailable');
    }

    if (empty($_SESSION['user_id']) && !empty($_COOKIE[$rememberCookieName])) {
        $cookie = $_COOKIE[$rememberCookieName];
        if (strpos($cookie, ':') !== false) {
            list($selector, $validator) = explode(':', $cookie, 2);
            $selector = trim($selector);
            $validator = trim($validator);

            $stmt = $pdo->prepare("SELECT id, user_id, validator_hash, expires_at FROM user_remember_tokens WHERE selector = ? LIMIT 1");
            $stmt->execute([$selector]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $expires = strtotime((string)$row['expires_at']);
                if ($expires < time()) {
                    // expired -> remove
                    $del = $pdo->prepare("DELETE FROM user_remember_tokens WHERE id = ?");
                    $del->execute([(int)$row['id']]);
                    clear_cookie($rememberCookieName);
                } else {
                    $calcHash = hash('sha256', $validator);
                    if (hash_equals((string)$row['validator_hash'], $calcHash)) {
                        // valid -> create session + rotate validator
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = (int)$row['user_id'];
                        $_SESSION['last_activity'] = time();

                        // rotate validator (reduce theft window)
                        $newValidator = bin2hex(random_bytes(33));
                        $newHash = hash('sha256', $newValidator);
                        $newExpires = date('Y-m-d H:i:s', time() + $lifetime);

                        $upd = $pdo->prepare("UPDATE user_remember_tokens SET validator_hash = ?, expires_at = ?, user_agent = ?, ip = ? WHERE id = ?");
                        $upd->execute([$newHash, $newExpires, $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null, (int)$row['id']]);

                        set_secure_cookie($rememberCookieName, $selector . ':' . $newValidator, time() + $lifetime, $secure);
                    } else {
                        // token mismatch -> possible theft/tamper -> delete token and clear cookie
                        $del = $pdo->prepare("DELETE FROM user_remember_tokens WHERE selector = ?");
                        $del->execute([$selector]);
                        clear_cookie($rememberCookieName);
                    }
                }
            } else {
                // unknown selector
                clear_cookie($rememberCookieName);
            }
        } else {
            clear_cookie($rememberCookieName);
        }
    }
} catch (Throwable $ex) {
    error_log('Remember-me check error: ' . $ex->getMessage());
}

// --- Session timeout (align with cookie lifetime optionally) ---
$session_timeout = $lifetime;
if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > $session_timeout)) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    session_destroy();
    header("Location: login.php?expired=1");
    exit;
}
$_SESSION['last_activity'] = time();

// --- Simple in-session rate-limit ---
$maxAttempts = 6;
$windowSec = 300; // 5 minutes
if (!isset($_SESSION['login_attempts'])) { $_SESSION['login_attempts'] = []; }
$_SESSION['login_attempts'] = array_filter((array)$_SESSION['login_attempts'], function($ts) use ($windowSec) {
    return ($ts + $windowSec) >= time();
});
$blocked = (count($_SESSION['login_attempts']) >= $maxAttempts);
$error = '';
$emailRaw = '';

// --- Handle POST login ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($blocked) {
        $error = 'Too many attempts — try again later';
    } else {
        $emailRaw = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']) && (string)$_POST['remember'] === '1';

        $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
        if (!$email || $password === '') {
            $error = 'Invalid input';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, password FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && isset($user['password']) && password_verify($password, $user['password'])) {
                    // success
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['last_activity'] = time();
                    $_SESSION['login_attempts'] = [];

                    if ($remember) {
                        $selector = bin2hex(random_bytes(9)); // 18 chars
                        $validator = bin2hex(random_bytes(33));
                        $validatorHash = hash('sha256', $validator);
                        $expiresAt = date('Y-m-d H:i:s', time() + $lifetime);

                        // limit tokens per user: keep last 5 (optional)
                        $ins = $pdo->prepare("INSERT INTO user_remember_tokens (user_id, selector, validator_hash, expires_at, user_agent, ip) VALUES (?, ?, ?, ?, ?, ?)");
                        $ins->execute([(int)$user['id'], $selector, $validatorHash, $expiresAt, $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null]);

                        set_secure_cookie($rememberCookieName, $selector . ':' . $validator, time() + $lifetime, $secure);
                    }

                    header('Location: ../dashboard.php');
                    exit;
                } else {
                    $_SESSION['login_attempts'][] = time();
                    $error = 'Invalid credentials';
                }
            } catch (Throwable $ex) {
                error_log('Login error: ' . $ex->getMessage());
                $error = 'Server error — please try later';
            }
        }
    }
}

// --- HTML + CSS + JS output ---
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login</title>
  <meta name="referrer" content="no-referrer-when-downgrade">
  <style>
    :root{
      --bg:#0f172a;
      --card:#0f172a00;
      --surface:#ffffff;
      --muted:#667085;
      --accent1:#6366f1;
      --accent2:#06b6d4;
      --danger:#ef4444;
      --radius:12px;
      --shadow: 0 8px 30px rgba(2,6,23,0.12);
      --maxw:420px;
      --gap:12px;
    }
    html,body{height:100%;margin:0;font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;background: linear-gradient(180deg,#0f172a 0%,#07142a 50%), radial-gradient(1200px 400px at 10% 10%, rgba(99,102,241,0.08), transparent 10%), radial-gradient(800px 300px at 90% 90%, rgba(6,182,212,0.05), transparent 10%);color:#0f172a;display:flex;align-items:center;justify-content:center;padding:28px;}
    .card{width:100%;max-width:var(--maxw);background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(250,250,255,0.96));border-radius:var(--radius);box-shadow:var(--shadow);padding:26px;box-sizing:border-box;border:1px solid rgba(15,23,42,0.04);backdrop-filter: blur(6px) saturate(120%);}
    #login-title{margin:0 0 6px 0;font-size:20px;font-weight:700;color:#0b1220;letter-spacing:-0.2px;}
    .card p.lead{margin:0 0 12px 0;color:var(--muted);font-size:13px;}
    label{display:block;margin-top:var(--gap);font-weight:600;font-size:13px;color:#0b1220;}
    .input-group{display:flex;align-items:center;gap:8px;margin-top:8px;}
    input[type="email"], input[type="password"]{width:100%;padding:11px 12px;border-radius:10px;border:1px solid rgba(15,23,42,0.06);background:transparent;font-size:14px;box-sizing:border-box;outline:none;transition: box-shadow .18s ease, border-color .12s ease, transform .08s ease;box-shadow: 0 1px 0 rgba(2,6,23,0.02) inset;}
    input::placeholder{ color:#98a0b3; }
    input:focus{ border-color: rgba(99,102,241,0.9); box-shadow: 0 6px 18px rgba(99,102,241,0.06); transform: translateY(-1px);}
    .input-icon{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:8px;background: linear-gradient(180deg, rgba(99,102,241,0.06), rgba(6,182,212,0.03));flex:0 0 38px;}
    .show-pass{cursor:pointer;font-size:12px;color:var(--muted);user-select:none;padding:6px 8px;border-radius:8px;}
    .checkbox{display:flex;align-items:center;gap:10px;margin-top:12px;font-size:13px;color:#0b1220;}
    .checkbox input[type="checkbox"]{appearance:none;width:18px;height:18px;border-radius:6px;border:1px solid rgba(15,23,42,0.08);display:inline-block;position:relative;cursor:pointer;transition:background .12s ease, border-color .12s ease;}
    .checkbox input[type="checkbox"]:checked{background:linear-gradient(90deg,var(--accent1),var(--accent2));border-color: transparent;}
    .checkbox input[type="checkbox"]:checked::after{content:"";position:absolute;left:5px;top:3px;width:5px;height:9px;border:2px solid white;border-left:0;border-top:0;transform:rotate(45deg);}
    button[type="submit"]{margin-top:14px;width:100%;padding:11px 14px;font-weight:700;font-size:15px;color:white;border:none;border-radius:10px;cursor:pointer;background: linear-gradient(90deg,var(--accent1),var(--accent2));box-shadow: 0 8px 18px rgba(6,182,212,0.12);transition: transform .08s ease, box-shadow .12s ease, opacity .12s ease;}
    button[type="submit"]:active{ transform: translateY(1px) scale(.998); } button[type="submit"]:hover{ box-shadow: 0 14px 36px rgba(6,182,212,0.14); }
    .muted{ color:var(--muted); font-size:13px; margin-top:10px; }
    .error{ color:var(--danger); margin-top:10px; font-size:13px; }
    .footer-hint{ margin-top:14px; font-size:12px; color:var(--muted); text-align:center; }
    @media (max-width:480px){ .card{ padding:18px; border-radius:10px; } .input-icon{ width:36px; height:36px; } button[type="submit"]{ padding:10px; font-size:14px; } }
  </style>
</head>
<body>
  <div class="card" role="main" aria-labelledby="login-title">
    <h2 id="login-title">Login</h2>
    <p class="lead">Enter your credentials to access your account.</p>

    <form method="POST" autocomplete="on" novalidate>
      <label for="email">Email</label>
      <div class="input-group">
        <span class="input-icon" aria-hidden="true">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 8l9 6 9-6"/></svg>
        </span>
        <input id="email" name="email" type="email" required placeholder="you@domain.com" value="<?php echo isset($emailRaw) ? htmlspecialchars($emailRaw, ENT_QUOTES, 'UTF-8') : ''; ?>">
      </div>

      <label for="password">Password</label>
      <div class="input-group" style="align-items:center;">
        <span class="input-icon" aria-hidden="true">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </span>
        <input id="password" name="password" type="password" required>
        <div class="show-pass" role="button" tabindex="0" aria-label="Toggle password visibility">Show</div>
      </div>

      <label class="checkbox"><input type="checkbox" name="remember" value="1"> Keep me logged in</label>

      <button type="submit">Login</button>

      <?php if (!empty($error)): ?>
          <div class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if ($blocked): ?>
          <div class="muted">Too many attempts — please wait <?php echo $windowSec; ?> seconds</div>
      <?php endif; ?>

      <div class="footer-hint">Persistent login: <?php echo $lifetimeDays; ?> days</div>
    </form>
  </div>

  <script>
    (function(){
      document.addEventListener('click', function(e){
        if(e.target && e.target.classList && e.target.classList.contains('show-pass')){
          var pass = document.getElementById('password');
          if(!pass) return;
          if(pass.type === 'password'){ pass.type = 'text'; e.target.textContent = 'Hide'; }
          else { pass.type = 'password'; e.target.textContent = 'Show'; }
        }
      }, false);

      // keyboard accessibility: Enter to toggle when focused
      document.addEventListener('keydown', function(e){
        var active = document.activeElement;
        if(active && active.classList && active.classList.contains('show-pass') && (e.key === 'Enter' || e.key === ' ')){
          e.preventDefault();
          active.click();
        }
      }, false);
    })();
  </script>
</body>
</html>
