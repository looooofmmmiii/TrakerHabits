<?php
declare(strict_types=1);
// auth/register.php — fixed: works with users(email,name,password,created_at)
// Requirements: PHP 7.3+, PDO $pdo from ../config/db.php, HTTPS recommended

require_once __DIR__ . '/../config/db.php'; // expects $pdo (PDO)

// --- secure session cookie params (call BEFORE session_start) ---
session_name('habit_sid');
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// --- Helpers ---
function json_response(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function generate_csrf(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

// --- AJAX availability endpoints ---
if (isset($_GET['action']) && in_array($_GET['action'], ['check_email','check_name'], true)) {
    try {
        if (!($pdo instanceof PDO)) throw new RuntimeException('DB unavailable');
        if ($_GET['action'] === 'check_email') {
            $email = filter_var($_GET['email'] ?? '', FILTER_VALIDATE_EMAIL);
            if (!$email) json_response(['ok'=>false,'error'=>'invalid']);
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            json_response(['ok'=>true,'available' => $stmt->fetchColumn() === false]);
        } else {
            $name = trim((string)($_GET['name'] ?? ''));
            if ($name === '' || strlen($name) > 255) json_response(['ok'=>false,'error'=>'invalid']);
            // optional: check duplicate display name if you want
            json_response(['ok'=>true,'available' => true]);
        }
    } catch (Throwable $ex) {
        json_response(['ok'=>false,'error'=>'server']);
    }
}

// --- Registration handling ---
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic in-session rate-limit (efficient, simple)
    if (!isset($_SESSION['reg_attempts'])) $_SESSION['reg_attempts'] = [];
    $_SESSION['reg_attempts'] = array_filter((array)$_SESSION['reg_attempts'], function($t){ return ($t + 60) >= time(); });
    if (count($_SESSION['reg_attempts']) >= 10) {
        $errors[] = 'Too many attempts — try later.';
    } else {
        $_SESSION['reg_attempts'][] = time();

        $csrf = $_POST['csrf'] ?? null;
        if (!verify_csrf($csrf)) {
            $errors[] = 'CSRF verification failed.';
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $emailRaw = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        $tos = isset($_POST['tos']) && (string)$_POST['tos'] === '1';

        $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
        if (!$email) $errors[] = 'Invalid email';
        if ($name === '' || strlen($name) < 2 || strlen($name) > 255) $errors[] = 'Name must be 2–255 chars';
        if ($password === '' || strlen($password) < 8) $errors[] = 'Password must be at least 8 characters';
        if ($password !== $confirm) $errors[] = 'Passwords do not match';
        if (!$tos) $errors[] = 'You must accept Terms';

        if (empty($errors)) {
            try {
                if (!($pdo instanceof PDO)) throw new RuntimeException('DB unavailable');

                // check existing email
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                if ($stmt->fetch()) $errors[] = 'Email already registered';

                if (empty($errors)) {
                    // secure hash (bcrypt via password_hash)
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if ($hash === false) throw new RuntimeException('Hashing failed');

                    // Insert into schema {email, name, password, created_at}
                    $ins = $pdo->prepare('INSERT INTO users (email, name, password, created_at) VALUES (?, ?, ?, NOW())');
                    $ins->execute([$email, $name, $hash]);

                    $uid = (int)$pdo->lastInsertId();

                    // set session/login (automatic login after register)
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $uid;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_name'] = $name;

                    // redirect to dashboard (use absolute or relative path)
                    header('Location: /dashboard.php');
                    exit;
                }
            } catch (Throwable $ex) {
                error_log('Register error: ' . $ex->getMessage());
                $errors[] = 'Server error — please try later.';
            }
        }
    }
}

$csrf = generate_csrf();
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Register — Create account</title>
  <style>/* minimal styles kept for brevity */ body{font-family:Inter,system-ui;} .errors{color:#b00020}</style>
</head>
<body>
  <h1>Create account</h1>

  <?php if (!empty($errors)): ?>
    <div class="errors" role="alert"><?php echo htmlspecialchars(implode(' • ', $errors), ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <form method="POST" novalidate>
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

    <label>Name<br>
      <input name="name" type="text" maxlength="255" required value="<?php echo isset($name) ? htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : ''; ?>">
    </label><br>

    <label>Email<br>
      <input name="email" type="email" required value="<?php echo isset($email) ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : ''; ?>">
    </label><br>

    <label>Password<br>
      <input name="password" type="password" required>
    </label><br>

    <label>Confirm password<br>
      <input name="confirm" type="password" required>
    </label><br>

    <label><input name="tos" type="checkbox" value="1"> I agree to Terms</label><br>

    <button type="submit">Create account</button>
  </form>
</body>
</html>
