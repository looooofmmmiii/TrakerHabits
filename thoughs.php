<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

// --- DB helper / simple migration notes (run once in your DB)
//
// CREATE TABLE `thoughts` (
//   `id` int NOT NULL AUTO_INCREMENT,
//   `user_id` int NOT NULL,
//   `content` text NOT NULL,
//   `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
//   PRIMARY KEY (`id`)
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
//
// CREATE TABLE `preseed_requests` (
//   `id` int NOT NULL AUTO_INCREMENT,
//   `name` varchar(255) NOT NULL,
//   `email` varchar(255) NOT NULL,
//   `amount` varchar(100) DEFAULT NULL,
//   `message` text DEFAULT NULL,
//   `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
//   PRIMARY KEY (`id`)
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

// Simple router for actions: add thought, delete thought (supports AJAX), pre-seed submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // DELETE (AJAX or regular)
    if (!empty($_POST['delete_id'])) {
        $id = (int) $_POST['delete_id'];
        $stmt = $pdo->prepare("DELETE FROM thoughts WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Add thought
    if (!empty($_POST['content'])) {
        $content = trim((string)$_POST['content']);
        if ($content !== '') {
            $stmt = $pdo->prepare("INSERT INTO thoughts (user_id, content) VALUES (:user_id, :content)");
            $stmt->execute([
                ':user_id' => 1, // hardcoded for MVP
                ':content' => $content
            ]);
        }

        // if ajax add: return json
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Pre-seed submission
    if (!empty($_POST['preseed']) && !empty($_POST['ps_name']) && !empty($_POST['ps_email'])) {
        $name = trim((string)$_POST['ps_name']);
        $email = trim((string)$_POST['ps_email']);
        $amount = trim((string)($_POST['ps_amount'] ?? ''));
        $message = trim((string)($_POST['ps_message'] ?? ''));

        $stmt = $pdo->prepare("INSERT INTO preseed_requests (name, email, amount, message) VALUES (:name, :email, :amount, :message)");
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':amount' => $amount,
            ':message' => $message
        ]);

        header("Location: " . $_SERVER['PHP_SELF'] . "?preseed=ok");
        exit;
    }
}

// Fetch thoughts
$stmt = $pdo->query("SELECT * FROM thoughts ORDER BY created_at DESC");
$thoughts = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Мої Думки — MVP → PRE-SEED</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-b from-slate-100 to-white min-h-screen py-10">
     <div>
        <div style="display:flex;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap">
        <a href="dashboard.php" class="btn" aria-label="Go Dashboard">🏠 Dashboard</a>
        <a href="habits.php" class="btn" aria-label="Manage Habits">🔥 Manage Habits</a>
        <a href="tasks.php" class="btn" aria-label="Manage Tasks">📌 Manage Tasks</a>
        <a href="kanban.php" class="btn" aria-label="Manage Tasks">📌 KanBan</a>
        <a href="thoughs.php" class="btn" aria-label="Manage Tasks">📌 Thoughs</a>
        <button id="rouletteOpen" class="spin-btn" aria-haspopup="dialog">🎲 Roulette</button>
    </div>
  <div class="max-w-4xl mx-auto px-4">
    <header class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-3xl font-extrabold text-indigo-600 flex items-center gap-3">🧠 Мої Думки <span class="text-sm bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full">MVP → PRE‑SEED</span></h1>
        <p class="text-sm text-gray-500">Швидкий блокнот думок — тепер з можливістю видаляти та формою для PRE‑SEED заявок.</p>
      </div>
      <div class="space-x-2">
        <button id="openPreseed" class="bg-amber-500 text-white px-4 py-2 rounded-xl shadow hover:brightness-95 transition">Підтримати PRE‑SEED</button>
        <a href="?" class="text-sm text-gray-600">Оновити</a>
      </div>
    </header>

    <?php if (isset($_GET['preseed']) && $_GET['preseed'] === 'ok'): ?>
      <div class="mb-4 p-4 rounded-xl bg-green-50 border border-green-100 text-green-700">Дякуємо! Ваш запит на PRE‑SEED прийнято.</div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
      <!-- left: add thought + preseed CTA -->
      <div class="md:col-span-1 space-y-4">
        <div class="bg-white p-4 rounded-2xl shadow-md">
          <form id="addForm" method="POST" class="flex flex-col gap-3">
            <label class="text-sm font-medium text-gray-600">Додати думку</label>
            <input name="content" type="text" placeholder="Введи коротку думку..." class="w-full border rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-300" required>
            <div class="flex gap-2">
              <button type="submit" class="flex-1 bg-indigo-600 text-white px-4 py-2 rounded-xl">Додати</button>
              <button type="button" id="clearInput" class="px-3 py-2 border rounded-xl">Очистити</button>
            </div>
            <p class="text-xs text-gray-400">Порада: короткі думки кращі — зберігаються швидше.</p>
          </form>
        </div>

        

        <div class="hidden md:block text-xs text-gray-400">Поради для PRE‑SEED: коротко про проблему, рішення, потенціал ринку і що потрібно зараз.</div>
      </div>

      <!-- right: thoughts list -->
      <div class="md:col-span-2 space-y-4">
        <div id="list" class="space-y-3">
          <?php if ($thoughts): foreach ($thoughts as $t): ?>
            <article id="thought-<?= (int)$t['id'] ?>" class="bg-white p-4 rounded-2xl shadow-sm flex justify-between items-start gap-4">
              <div class="flex-1">
                <div class="flex items-start gap-3">
                  <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 font-semibold"><?= htmlspecialchars(mb_substr($t['content'], 0, 1)) ?></div>
                  <div>
                    <p class="text-gray-800 text-lg break-words"><?= nl2br(htmlspecialchars($t['content'])) ?></p>
                    <div class="text-xs text-gray-400 mt-2"><?= date('d.m.Y H:i', strtotime($t['created_at'])) ?></div>
                  </div>
                </div>
              </div>

              <div class="flex flex-col items-end gap-2">
                <!-- Progressive enhancement: JS will handle deletion via AJAX, but fallback form exists -->
                <form method="POST" class="fallback-delete">
                  <input type="hidden" name="delete_id" value="<?= (int)$t['id'] ?>">
                  <button type="submit" class="bg-red-50 border border-red-100 text-red-600 px-3 py-1 rounded-lg text-sm">Видалити</button>
                </form>
                <button data-id="<?= (int)$t['id'] ?>" class="js-delete hidden bg-red-600 text-white px-3 py-1 rounded-lg text-sm">Видалити (JS)</button>
              </div>
            </article>
          <?php endforeach; else: ?>
            <div class="bg-white p-6 rounded-2xl shadow-sm text-center text-gray-500">Поки що немає думок — додай першу ✨</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Basic JS to improve UX: AJAX add and delete, dynamic removal
    document.addEventListener('DOMContentLoaded', function () {
      const addForm = document.getElementById('addForm');
      const clearBtn = document.getElementById('clearInput');
      const list = document.getElementById('list');

      // If browser supports fetch, show JS delete buttons and intercept adds
      if (window.fetch) {
        document.querySelectorAll('.js-delete').forEach(btn => btn.classList.remove('hidden'));

        // Intercept add form: send via fetch, then reload minimal (or append) — here we simply reload to keep server logic simple
        addForm.addEventListener('submit', function (e) {
          e.preventDefault();
          const fd = new FormData(addForm);
          fd.append('ajax', '1');
          fetch(window.location.href, {method: 'POST', body: fd}).then(r => r.json()).then(() => location.reload());
        });

        // Delete via AJAX
        document.addEventListener('click', function (e) {
          if (e.target.matches('.js-delete')) {
            const id = e.target.getAttribute('data-id');
            if (!confirm('Впевнені що хочете видалити цю думку?')) return;
            const fd = new FormData();
            fd.append('delete_id', id);
            fd.append('ajax', '1');
            fetch(window.location.href, {method: 'POST', body: fd}).then(r => r.json()).then(data => {
              if (data && data.success) {
                const el = document.getElementById('thought-' + id);
                if (el) el.remove();
              }
            });
          }
        });
      }

      clearBtn.addEventListener('click', function () {
        addForm.querySelector('[name="content"]').value = '';
      });

      // Open PRESEED form (scroll)
      document.getElementById('openPreseed').addEventListener('click', function () {
        document.querySelector('input[name="ps_name"]').focus();
        document.querySelector('input[name="ps_name"]').scrollIntoView({behavior: 'smooth', block: 'center'});
      });
    });
  </script>

</body>
</html>
