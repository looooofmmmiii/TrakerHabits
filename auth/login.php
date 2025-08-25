<?php
// auth/login.php — improved: persistent session 30 days, security + rate-limit
declare(strict_types=1);

// --- CONFIG: adjust if needed ---
$lifetimeDays = 30; // session lifetime in days (requested)
$lifetime = $lifetimeDays * 24 * 60 * 60; // seconds
// ------------------------------------------------

// set secure cookie params BEFORE session_start
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('habit_sid');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax' // choose 'Strict' if you need stricter policy
]);
session_start();

// basic security headers (helpful for login page)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-XSS-Protection: 1; mode=block');

require_once __DIR__ . '/../config/db.php'; // expects $pdo (PDO) to be available

// SESSION timeout aligned with cookie lifetime
$session_timeout = $lifetime; // 30 days in seconds

if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > $session_timeout)) {
    session_unset();
    session_destroy();
    // clear cookie explicitly
    setcookie(session_name(), '', time() - 3600, '/');
    header("Location: login.php?expired=1");
    exit;
}
$_SESSION['last_activity'] = time();

// Simple login rate-limit (in-session). For production consider Redis/IP-based throttle.
$maxAttempts = 6;
$windowSec = 300; // rolling window for attempts (5 minutes)

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}

// cleanup old attempts
$_SESSION['login_attempts'] = array_filter(
    (array)$_SESSION['login_attempts'],
    function($ts) use ($windowSec) { return ($ts + $windowSec) >= time(); }
);

$blocked = (count($_SESSION['login_attempts']) >= $maxAttempts);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($blocked) {
        $error = 'Too many attempts — try again later';
    } else {
        $emailRaw = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
        if (!$email || $password === '') {
            $error = 'Invalid input';
        } else {
            try {
                if (!($pdo instanceof PDO)) {
                    throw new RuntimeException('Database unavailable');
                }
                $stmt = $pdo->prepare("SELECT id, password FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && isset($user['password']) && password_verify($password, $user['password'])) {
                    // successful login
                    session_regenerate_id(true); // mitigate session fixation
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['last_activity'] = time();
                    // reset attempts on success
                    $_SESSION['login_attempts'] = [];
                    // redirect to dashboard
                    header('Location: ../dashboard.php');
                    exit;
                } else {
                    // failed attempt: record timestamp for throttle
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
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login</title>
  <style>
    body{font-family:Inter,system-ui,Arial;margin:24px;background:#f7fafc;color:#0f172a}
    .card{background:#fff;padding:20px;border-radius:10px;max-width:420px;margin:40px auto;box-shadow:0 6px 18px rgba(2,6,23,0.06)}
    label{display:block;margin-top:10px;font-weight:600}
    input{width:100%;padding:8px;border-radius:8px;border:1px solid #e6e9ef;margin-top:6px}
    button{margin-top:14px;background:linear-gradient(90deg,#6366f1,#06b6d4);color:#fff;border:none;padding:10px 12px;border-radius:10px;cursor:pointer}
    .muted{color:#667085;font-size:13px;margin-top:8px}
    .error{color:#b91c1c;margin-top:8px}
  </style>
</head>
<body>
  <div class="card" role="main" aria-labelledby="login-title">
    <h2 id="login-title">Login</h2>
    <form method="POST" autocomplete="on" novalidate>
        <label for="email">Email</label>
        <input id="email" name="email" type="email" required value="<?php echo isset($emailRaw) ? htmlspecialchars($emailRaw, ENT_QUOTES, 'UTF-8') : ''; ?>">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required>

        <button type="submit">Login</button>

        <?php if (!empty($error)): ?>
            <div class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($blocked): ?>
            <div class="muted">Too many attempts — please wait <?php echo $windowSec; ?> seconds</div>
        <?php endif; ?>

        <div class="muted">Session lifetime: <?php echo $lifetimeDays; ?> days (persistent)</div>
    </form>
  </div>
</body>
</html>
