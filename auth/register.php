<?php
// auth/register.php — modern registration page + simple backend
// Requirements: PHP 7.3+, PDO $pdo from ../config/db.php, HTTPS recommended
// Notes: assumes `users` table with at least (id, username, email, password, is_verified, email_token, created_at)
// Optional SQL example:
// CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE, email VARCHAR(255) UNIQUE, password VARCHAR(255), is_verified TINYINT(1) DEFAULT 0, email_token VARCHAR(128) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);

declare(strict_types=1);
require_once __DIR__ . '/../config/db.php'; // expects $pdo (PDO)

session_name('habit_sid');
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

// --- Simple API for AJAX email/username availability checks ---
if (isset($_GET['action']) && in_array($_GET['action'], ['check_email', 'check_username'], true)) {
    try {
        if (!($pdo instanceof PDO)) throw new RuntimeException('DB unavailable');
        if ($_GET['action'] === 'check_email') {
            $email = filter_var($_GET['email'] ?? '', FILTER_VALIDATE_EMAIL);
            if (!$email) json_response(['ok' => false, 'error' => 'invalid']);
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            json_response(['ok' => true, 'available' => $stmt->fetchColumn() === false]);
        } else {
            $username = trim((string)($_GET['username'] ?? ''));
            if ($username === '' || strlen($username) > 50) json_response(['ok' => false, 'error' => 'invalid']);
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            json_response(['ok' => true, 'available' => $stmt->fetchColumn() === false]);
        }
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'error' => 'server']);
    }
}

// --- Registration handling ---
$errors = [];
success: $success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic in-session rate-limit
    if (!isset($_SESSION['reg_attempts'])) $_SESSION['reg_attempts'] = [];
    $_SESSION['reg_attempts'] = array_filter((array)$_SESSION['reg_attempts'], function($t) { return ($t + 60) >= time(); });
    if (count($_SESSION['reg_attempts']) >= 10) {
        $errors[] = 'Too many attempts — try later.';
    } else {
        $_SESSION['reg_attempts'][] = time();

        $csrf = $_POST['csrf'] ?? null;
        if (!verify_csrf($csrf)) {
            $errors[] = 'CSRF verification failed.';
        }

        $username = trim((string)($_POST['username'] ?? ''));
        $emailRaw = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        $tos = isset($_POST['tos']) && (string)$_POST['tos'] === '1';

        $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
        if (!$email) $errors[] = 'Invalid email';
        if ($username === '' || strlen($username) < 3 || strlen($username) > 50) $errors[] = 'Username must be 3–50 chars';
        if ($password === '' || strlen($password) < 8) $errors[] = 'Password must be at least 8 characters';
        if ($password !== $confirm) $errors[] = 'Passwords do not match';
        if (!$tos) $errors[] = 'You must accept Terms';

        // server-side checks for uniqueness
        if (empty($errors)) {
            try {
                if (!($pdo instanceof PDO)) throw new RuntimeException('DB unavailable');

                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                if ($stmt->fetch()) $errors[] = 'Email already registered';

                $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                if ($stmt->fetch()) $errors[] = 'Username already taken';

                if (empty($errors)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $token = bin2hex(random_bytes(32));

                    $ins = $pdo->prepare('INSERT INTO users (username, email, password, is_verified, email_token, created_at) VALUES (?, ?, ?, 0, ?, NOW())');
                    $ins->execute([$username, $email, $hash, $token]);

                    // Optionally send verification email (simple stub). Adjust to your mailer.
                    try {
                        $verifyLink = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : (isset($_SERVER['HTTPS']) ? 'https' : 'http'))
                            . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/auth/verify.php?token=' . urlencode($token);
                        $subject = 'Verify your account';
                        $message = "Hello $username,\n\nClick to verify: $verifyLink\n\nIf you didn't create an account, ignore this email.";
                        // @mail($email, $subject, $message); // uncomment when configured
                    } catch (Throwable $ex) {
                        // non-fatal
                    }

                    $success = true;
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
  <meta name="referrer" content="no-referrer-when-downgrade">
  <style>
    :root{
      --bg-1: #0b1220;
      --glass: rgba(255,255,255,0.06);
      --card-bg: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
      --accent1: #7c3aed;
      --accent2: #06b6d4;
      --muted: #98a0b3;
      --success: #10b981;
      --danger: #ef4444;
      --radius: 14px;
      --shadow: 0 12px 40px rgba(2,6,23,0.28);
      --maxw: 900px;
      font-family: Inter,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;
    }
    html,body{height:100%;margin:0;background: radial-gradient(800px 400px at 10% 10%, rgba(124,58,237,0.06), transparent 10%), radial-gradient(700px 300px at 90% 90%, rgba(6,182,212,0.04), transparent 10%), linear-gradient(180deg,var(--bg-1) 0%, #07142a 100%);color:#e6eef8;display:flex;align-items:center;justify-content:center;padding:28px;}

    .layout{width:100%;max-width:var(--maxw);display:grid;grid-template-columns:1fr 420px;gap:28px;align-items:center}
    .panel{border-radius:var(--radius);padding:28px;background:linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02));backdrop-filter: blur(8px) saturate(120%);box-shadow:var(--shadow);border:1px solid rgba(255,255,255,0.04)}

    .hero{padding:40px;display:flex;flex-direction:column;gap:18px}
    .logo{display:inline-flex;align-items:center;gap:12px}
    .logo-mark{width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--accent1),var(--accent2));display:inline-flex;align-items:center;justify-content:center;font-weight:700;color:white}
    h1{margin:0;font-size:28px;letter-spacing:-0.4px}
    p.lead{margin:0;color:var(--muted);max-width:52ch}

    .form{padding:6px 0}
    label{display:block;font-size:13px;margin-top:14px;color:#e6eef8;font-weight:600}
    .row{display:flex;gap:12px}
    .field{flex:1}
    input[type="text"],input[type="email"],input[type="password"]{width:100%;padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,0.04);background:rgba(255,255,255,0.015);color:inherit;font-size:14px;box-sizing:border-box;outline:none;transition:box-shadow .12s ease, transform .08s ease}
    input::placeholder{color:rgba(230,238,248,0.32)}
    input:focus{box-shadow:0 10px 30px rgba(124,58,237,0.08);transform:translateY(-2px);border-color:rgba(124,58,237,0.9)}

    .hint{font-size:13px;color:var(--muted);margin-top:8px}
    .password-meter{height:8px;border-radius:8px;background:rgba(255,255,255,0.04);overflow:hidden;margin-top:8px}
    .password-meter > i{display:block;height:100%;width:0%;transition:width .28s ease}

    .checkbox{display:flex;align-items:center;gap:10px;margin-top:12px}
    .submit{margin-top:18px;display:flex;gap:12px}
    button.primary{flex:1;padding:12px 16px;border-radius:12px;border:none;font-weight:700;cursor:pointer;background:linear-gradient(90deg,var(--accent1),var(--accent2));box-shadow:0 12px 30px rgba(6,182,212,0.08);color:white;transition:transform .08s ease,box-shadow .12s ease}
    button.ghost{padding:12px 16px;border-radius:12px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:inherit;cursor:pointer}

    .muted{color:var(--muted);font-size:13px}
    .errors{margin-top:12px;color:var(--danger);font-size:13px}
    .success{margin-top:12px;color:var(--success);font-size:14px;font-weight:600}

    .socials{display:flex;gap:10px;margin-top:18px}
    .socials button{padding:10px;border-radius:10px;border:none;background:rgba(255,255,255,0.02);cursor:pointer}

    @media (max-width:980px){ .layout{grid-template-columns:1fr;padding:18px} .hero{order:2} }
  </style>
</head>
<body>
  <div class="layout" role="main">
    <div class="panel hero" aria-hidden="false">
      <div class="logo">
        <div class="logo-mark">VN</div>
        <div>
          <h1>Create your account</h1>
          <p class="lead">Join quickly with a secure, privacy-first sign up flow — built for 2025 modern web patterns.</p>
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:18px">
        <div class="muted">Secure by design</div>
        <div class="muted">Privacy-first</div>
      </div>

      <div style="margin-top:26px;" class="muted">Tips: use a strong password, enable 2FA later in your account settings for extra resilience.</div>
    </div>

    <form class="panel form" method="POST" novalidate autocomplete="on">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

      <?php if ($success): ?>
        <div class="success">Account created successfully. Check your email for verification (if configured).</div>
        <div style="margin-top:12px"><a class="muted" href="login.php">Back to login</a></div>
      <?php else: ?>

        <?php if (!empty($errors)): ?>
          <div class="errors" role="alert"><?php echo htmlspecialchars(implode(' • ', $errors), ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <label for="username">Username</label>
        <div class="row">
          <div class="field">
            <input id="username" name="username" type="text" placeholder="your_nickname" required maxlength="50" value="<?php echo isset($username) ? htmlspecialchars($username,ENT_QUOTES,'UTF-8') : ''; ?>">
            <div id="username-hint" class="hint">3–50 characters. Letters, numbers, _ allowed.</div>
          </div>
        </div>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" placeholder="you@example.com" required value="<?php echo isset($email) ? htmlspecialchars($email,ENT_QUOTES,'UTF-8') : ''; ?>">
        <div id="email-hint" class="hint">We will send a verification email. Your email is private.</div>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" placeholder="Minimum 8 characters" required>
        <div class="password-meter" aria-hidden="false"><i id="pwbar"></i></div>
        <div id="pw-hint" class="hint">Use a mix of upper, lower, numbers and symbols for better strength.</div>

        <label for="confirm">Confirm password</label>
        <input id="confirm" name="confirm" type="password" placeholder="Repeat password" required>

        <label class="checkbox"><input id="tos" name="tos" type="checkbox" value="1"> <span class="muted">I agree to the <a href="#">Terms</a> and <a href="#">Privacy</a></span></label>

        <div class="submit">
          <button type="submit" class="primary">Create account</button>
          <button type="button" class="ghost" onclick="location.href='login.php'">Sign in</button>
        </div>

        <div class="socials">
          <button type="button" aria-label="Continue with Google">G</button>
          <button type="button" aria-label="Continue with GitHub">GH</button>
        </div>

      <?php endif; ?>
    </form>
  </div>

  <script>
    (function(){
      // password strength (basic estimator)
      const pw = document.getElementById('password');
      const bar = document.getElementById('pwbar');
      const confirm = document.getElementById('confirm');
      const username = document.getElementById('username');
      const email = document.getElementById('email');

      function strengthScore(s){
        let score = 0;
        if (!s) return 0;
        if (s.length >= 8) score += 1;
        if (s.length >= 12) score += 1;
        if (/[a-z]/.test(s)) score += 1;
        if (/[A-Z]/.test(s)) score += 1;
        if (/[0-9]/.test(s)) score += 1;
        if (/[^A-Za-z0-9]/.test(s)) score += 1;
        return Math.min(score,6);
      }

      function updateBar(){
        const s = strengthScore(pw.value);
        const pct = Math.round((s/6)*100);
        bar.style.width = pct + '%';
        bar.style.background = pct < 40 ? 'linear-gradient(90deg, #ef4444, #f97316)' : (pct < 70 ? 'linear-gradient(90deg, #f59e0b, #fbbf24)' : 'linear-gradient(90deg, #10b981, #06b6d4)');
      }

      pw.addEventListener('input', updateBar);

      // show/hide feature (accessibility)
      function makeToggle(id){
        const el = document.getElementById(id);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ghost';
        btn.style.padding = '8px';
        btn.style.marginTop = '10px';
        btn.textContent = 'Show';
        btn.addEventListener('click', () => {
          if (el.type === 'password') { el.type = 'text'; btn.textContent = 'Hide'; }
          else { el.type = 'password'; btn.textContent = 'Show'; }
        });
        el.parentNode.insertBefore(btn, el.nextSibling);
      }
      makeToggle('password'); makeToggle('confirm');

      // client-side availability checks (debounced)
      function debounce(fn, wait){ let t; return function(){ clearTimeout(t); t = setTimeout(() => fn.apply(this, arguments), wait); }; }

      const usernameHint = document.getElementById('username-hint');
      username.addEventListener('input', debounce(function(){
        const v = username.value.trim(); if (v.length < 3) { usernameHint.textContent = 'Too short'; return; }
        fetch('?action=check_username&username=' + encodeURIComponent(v)).then(r=>r.json()).then(j=>{
          if (j.ok) usernameHint.textContent = j.available ? 'Available' : 'Taken';
          else usernameHint.textContent = 'Error checking';
        }).catch(()=> usernameHint.textContent = 'Error');
      }, 450));

      const emailHint = document.getElementById('email-hint');
      email.addEventListener('input', debounce(function(){
        const v = email.value.trim(); if (v.indexOf('@') === -1) { emailHint.textContent = 'Please enter a valid email'; return; }
        fetch('?action=check_email&email=' + encodeURIComponent(v)).then(r=>r.json()).then(j=>{
          if (j.ok) emailHint.textContent = j.available ? 'Looks good' : 'Already in use';
          else emailHint.textContent = 'Error checking';
        }).catch(()=> emailHint.textContent = 'Error');
      }, 450));

      // small client-side form validation to improve UX
      document.querySelector('form').addEventListener('submit', function(e){
        const p = pw.value;
        if (p.length < 8){ e.preventDefault(); alert('Password must be at least 8 characters'); return; }
        if (p !== confirm.value){ e.preventDefault(); alert('Passwords do not match'); return; }
      });

      // progressive enhancement: keyboard submit
      document.querySelectorAll('input').forEach(i => i.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' && ev.target.tagName !== 'TEXTAREA') {
          // let form submit normally
        }
      }));

    })();
  </script>
</body>
</html>
