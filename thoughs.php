<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

// DB migration reminder (run once if needed)
// CREATE TABLE `thoughts` (
//   `id` int NOT NULL AUTO_INCREMENT,
//   `user_id` int NOT NULL,
//   `content` text NOT NULL,
//   `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
//   PRIMARY KEY (`id`)
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

// Simple router for AJAX and form actions: add, delete, edit
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

    // EDIT
    if (!empty($_POST['edit_id']) && isset($_POST['edit_content'])) {
        $id = (int) $_POST['edit_id'];
        $content = trim((string)$_POST['edit_content']);
        $stmt = $pdo->prepare("UPDATE thoughts SET content = :content WHERE id = :id");
        $stmt->execute([':content' => $content, ':id' => $id]);

        if (!empty($_POST['ajax'])) {
            // return updated HTML fragment
            $stmt = $pdo->prepare("SELECT * FROM thoughts WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'html' => render_thought_html($t)]);
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
                ':user_id' => 1,
                ':content' => $content
            ]);
            $newId = (int)$pdo->lastInsertId();
            if (!empty($_POST['ajax'])) {
                $stmt = $pdo->prepare("SELECT * FROM thoughts WHERE id = :id");
                $stmt->execute([':id' => $newId]);
                $t = $stmt->fetch(PDO::FETCH_ASSOC);
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'html' => render_thought_html($t), 'id' => $newId]);
                exit;
            }
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// AJAX: load page of thoughts
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['ajax'])) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 15;
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("SELECT * FROM thoughts ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $html = '';
    foreach ($rows as $r) {
        $html .= render_thought_html($r);
    }

    header('Content-Type: application/json');
    echo json_encode(['html' => $html, 'count' => count($rows)]);
    exit;
}

// Helper to render a single thought as HTML (used by AJAX responses)
function render_thought_html(array $t): string
{
    $id = (int)$t['id'];
    $contentEsc = nl2br(htmlspecialchars($t['content'], ENT_QUOTES | ENT_SUBSTITUTE));
    $initial = htmlspecialchars(mb_substr(trim($t['content']), 0, 1) ?: '•');
    $time = date('d.m.Y H:i', strtotime($t['created_at']));

    // Keep markup compact and consistent with Tailwind used in the template
    return "<article id=\"thought-{$id}\" class=\"bg-white p-8 rounded-3xl shadow-lg flex gap-6 items-start max-w-none\">" .
           "<div class=\"w-16 h-16 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-700 font-semibold text-2xl flex-shrink-0\">{$initial}</div>" .
           "<div class=\"flex-1\">" .
           "<div class=\"prose max-w-none text-slate-900 text-lg break-words leading-7\">{$contentEsc}</div>" .
           "<div class=\"text-xs text-gray-400 mt-3\">{$time}</div>" .
           "</div>" .
           "<div class=\"flex flex-col gap-2 items-end\">" .
           "<button data-id=\"{$id}\" class=\"js-edit bg-amber-50 border border-amber-100 text-amber-700 px-3 py-1 rounded-lg text-sm\">Редагувати</button>" .
           "<button data-id=\"{$id}\" class=\"js-delete bg-red-50 border border-red-100 text-red-600 px-3 py-1 rounded-lg text-sm\">Видалити</button>" .
           "</div>" .
           "</article>";
}

// Server-side: initial page load with pagination count
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->query("SELECT COUNT(*) FROM thoughts");
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM thoughts ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$thoughts = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Думай Вільно — безкоштовний</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap">
  <style>body{font-family:Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial;}
  .prose {line-height:1.7;}
  /* Composer focus overlay */
  .composer-full {
    position:fixed;inset:0;background:rgba(6,8,15,0.6);display:flex;align-items:center;justify-content:center;z-index:60;padding:20px;
  }
  .composer-card{background:#fff;border-radius:18px;max-width:1200px;width:100%;padding:28px;box-shadow:0 10px 30px rgba(2,6,23,0.2)}
  .btn-ghost{background:transparent;border:1px solid rgba(15,23,42,0.06);}
  </style>
</head>
<body class="bg-gradient-to-b from-slate-50 to-white min-h-screen py-10">

<div> <div style="display:flex;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap"> <a href="dashboard.php" class="btn" aria-label="Go Dashboard">🏠 Dashboard</a> <a href="habits.php" class="btn" aria-label="Manage Habits">🔥 Manage Habits</a> <a href="tasks.php" class="btn" aria-label="Manage Tasks">📌 Manage Tasks</a> <a href="kanban.php" class="btn" aria-label="Manage Tasks">📌 KanBan</a> <a href="thoughs.php" class="btn" aria-label="Manage Tasks">📌 Thoughs</a> <button id="rouletteOpen" class="spin-btn" aria-haspopup="dialog">🎲 Roulette</button> </div>

  <div class="max-w-6xl mx-auto px-6">
    <header class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-3xl font-extrabold text-slate-900 flex items-center gap-3">🧭 Думай Вільно — безкоштовно</h1>
        <p class="text-sm text-gray-500">Просте приватне місце для широких, глибоких думок. Без платних версій.</p>
      </div>
      <div class="flex gap-3 items-center">
        <button id="openComposer" class="bg-slate-900 text-white px-4 py-2 rounded-xl shadow">Нова думка</button>
        <input id="search" type="search" placeholder="Шукати в думках..." class="border rounded-xl px-3 py-2 w-64" aria-label="Пошук думок">
        <a href="?" class="text-sm text-gray-600">Оновити</a>
      </div>
    </header>

    <main class="grid grid-cols-1 md:grid-cols-3 gap-6">
      <!-- left: quick composer & tips -->
      <aside class="md:col-span-1 space-y-4 sticky top-6">
        <div class="bg-white p-6 rounded-2xl shadow">
          <div class="flex items-start gap-3">
            <div class="flex-1">
              <h3 class="text-sm font-semibold text-gray-700">Швидка думка</h3>
              <p class="text-xs text-gray-400 mt-1">Швидко зберегти ідею — або натиснути "Нова думка" для великого редактора.</p>
            </div>
            <button id="quickOpen" class="btn-ghost text-sm px-3 py-2 rounded-xl">Швидко</button>
          </div>
        </div>

        <div class="bg-white p-4 rounded-2xl shadow text-sm text-gray-600">
          Порадник:
          <ul class="list-disc ml-5 mt-2">
            <li>Для довгих текстів використай головний редактор — широкий і без відволікань.</li>
            <li>Натискай <kbd>Ctrl/Cmd</kbd> + <kbd>Enter</kbd> щоб швидко зберегти.</li>
            <li>Чернетка зберігається локально автоматично.</li>
          </ul>
        </div>
      </aside>

      <!-- right: list of thoughts -->
      <section class="md:col-span-2 space-y-4">
        <div id="list" class="space-y-6">
          <?php if ($thoughts): foreach ($thoughts as $t): echo render_thought_html($t); endforeach; else: ?>
            <div class="bg-white p-8 rounded-2xl shadow-sm text-center text-gray-500">Поки що немає думок — додай першу ✨</div>
          <?php endif; ?>
        </div>

        <?php if ($total > $perPage): ?>
          <div class="text-center">
            <button id="loadMore" data-next-page="2" class="bg-white border rounded-xl px-4 py-2 shadow">Завантажити ще</button>
          </div>
        <?php endif; ?>
      </section>
    </main>

  </div>

  <!-- Full composer overlay (wide, distraction-free) -->
  <div id="composerFull" class="composer-full hidden" aria-hidden="true">
    <div class="composer-card">
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
          <h2 class="text-xl font-semibold">Нова думка — фокус</h2>
          <span id="autosaveStatus" class="text-xs text-gray-400">Чернетка: не збережено</span>
        </div>
        <div class="flex items-center gap-3">
          <button id="togglePreview" class="btn-ghost px-3 py-2 rounded-xl">Прев'ю</button>
          <button id="closeComposer" class="px-3 py-2 border rounded-xl">Закрити</button>
          <button id="saveComposer" class="bg-amber-600 text-white px-4 py-2 rounded-xl">Зберегти</button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div>
          <textarea id="composerInput" rows="12" placeholder="Пиши довго — текст буде широкий і зручний для читання..." class="w-full border rounded-xl px-4 py-4 text-lg leading-8 resize-y" autofocus></textarea>
          <div class="flex items-center justify-between mt-3">
            <div class="text-xs text-gray-400">Підтримує базове форматування (новий рядок → абзац)</div>
            <div class="text-xs text-gray-400"><kbd>Ctrl/Cmd</kbd> + <kbd>Enter</kbd> зберегти</div>
          </div>
        </div>
        <div id="previewPane" class="bg-slate-50 p-4 rounded-xl hidden prose max-w-none text-slate-900"></div>
      </div>
    </div>
  </div>

  <!-- Edit modal (small) -->
  <div id="editModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-2xl w-full max-w-2xl p-6 shadow-lg">
      <h3 class="text-lg font-semibold mb-2">Редагувати думку</h3>
      <textarea id="editInput" rows="10" class="w-full border rounded-xl px-3 py-3 resize-y"></textarea>
      <div class="mt-4 flex justify-end gap-2">
        <button id="cancelEdit" class="px-3 py-2 border rounded-xl">Скасувати</button>
        <button id="saveEdit" class="bg-amber-600 text-white px-4 py-2 rounded-xl">Зберегти</button>
      </div>
    </div>
  </div>

  <script>
    // Helpers
    const qs = s => document.querySelector(s);
    const qsa = s => Array.from(document.querySelectorAll(s));

    // Composer elements
    const composerFull = qs('#composerFull');
    const composerInput = qs('#composerInput');
    const autosaveStatus = qs('#autosaveStatus');
    const previewPane = qs('#previewPane');
    const draftKey = 'thoughts_draft_v2';

    // Restore draft into wide composer when opened
    function openComposer(prefill = '') {
      composerFull.classList.remove('hidden');
      composerFull.setAttribute('aria-hidden','false');
      composerInput.focus();
      if (prefill) composerInput.value = prefill;
      else {
        const d = localStorage.getItem(draftKey);
        if (d) composerInput.value = d;
      }
      updateAutosaveStatus(false);
      renderPreview();
    }

    function closeComposer() {
      composerFull.classList.add('hidden');
      composerFull.setAttribute('aria-hidden','true');
    }

    // Autosave draft with timestamp
    let autosaveTimer = null;
    function scheduleAutosave() {
      clearTimeout(autosaveTimer);
      autosaveStatus.textContent = 'Чернетка: збереження...';
      autosaveTimer = setTimeout(() => {
        localStorage.setItem(draftKey, composerInput.value);
        updateAutosaveStatus(true);
      }, 800);
    }

    function updateAutosaveStatus(saved) {
      if (saved) autosaveStatus.textContent = 'Чернетка: збережено ' + new Date().toLocaleTimeString();
      else autosaveStatus.textContent = 'Чернетка: не збережено';
    }

    composerInput?.addEventListener('input', () => { scheduleAutosave(); renderPreview(); });

    // Preview: simple nl2br + basic replacements for **bold** and *italic*
    function renderPreview() {
      if (!previewPane) return;
      const txt = composerInput.value || '';
      let html = txt
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.*?)\*/g, '<em>$1</em>')
        .replace(/(^|\n)### (.*?)(\n|$)/g, '$1<h3>$2</h3>$3')
        .replace(/(^|\n)## (.*?)(\n|$)/g, '$1<h2>$2</h2>$3')
        .replace(/(^|\n)# (.*?)(\n|$)/g, '$1<h1>$2</h1>$3')
        .replace(/\n\n/g, '<p></p>')
        .replace(/\n/g, '<br>');
      previewPane.innerHTML = html || '<div class="text-sm text-gray-400">Пусто — немає превʼю.</div>';
    }

    // Open composer buttons
    qs('#openComposer').addEventListener('click', () => openComposer());
    qs('#quickOpen').addEventListener('click', () => openComposer());

    // Close/save composer
    qs('#closeComposer').addEventListener('click', closeComposer);
    qs('#saveComposer').addEventListener('click', saveComposer);

    // Toggle preview
    qs('#togglePreview').addEventListener('click', () => {
      previewPane.classList.toggle('hidden');
    });

    // Save composer (AJAX)
    function saveComposer() {
      const content = composerInput.value.trim();
      if (!content) return alert('Порожній текст не можна зберегти');
      const fd = new FormData(); fd.append('content', content); fd.append('ajax', '1');
      fetch(window.location.href, {method: 'POST', body: fd}).then(r => r.json()).then(json => {
        if (json && json.success) {
          const list = qs('#list');
          const tmp = document.createElement('div'); tmp.innerHTML = json.html;
          list.prepend(tmp.firstElementChild);
          localStorage.removeItem(draftKey);
          composerInput.value = '';
          updateAutosaveStatus(false);
          closeComposer();
        }
      }).catch(console.error);
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
      // Ctrl/Cmd + Enter to save when composer open
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && !composerFull.classList.contains('hidden')) {
        e.preventDefault(); saveComposer();
      }
      // Escape to close composer
      if (e.key === 'Escape' && !composerFull.classList.contains('hidden')) {
        closeComposer();
      }
    });

    // Delegated edit & delete handlers
    document.addEventListener('click', function (e) {
      // delete
      if (e.target.matches('.js-delete')) {
        const id = e.target.getAttribute('data-id');
        if (!confirm('Впевнені що хочете видалити цю думку?')) return;
        const fd = new FormData(); fd.append('delete_id', id); fd.append('ajax', '1');
        fetch(window.location.href, {method: 'POST', body: fd}).then(r => r.json()).then(data => {
          if (data && data.success) {
            const el = document.getElementById('thought-' + id);
            if (el) el.remove();
          }
        });
      }

      // edit
      if (e.target.matches('.js-edit')) {
        const id = e.target.getAttribute('data-id');
        const el = document.getElementById('thought-' + id);
        if (!el) return;
        const content = el.querySelector('.prose').innerText;
        qs('#editInput').value = content;
        qs('#editModal').classList.remove('hidden');
        qs('#editModal').dataset.editId = id;
      }
    });

    qs('#cancelEdit').addEventListener('click', () => { qs('#editModal').classList.add('hidden'); });

    qs('#saveEdit').addEventListener('click', () => {
      const id = qs('#editModal').dataset.editId;
      const content = qs('#editInput').value.trim();
      const fd = new FormData(); fd.append('edit_id', id); fd.append('edit_content', content); fd.append('ajax', '1');
      fetch(window.location.href, {method: 'POST', body: fd}).then(r => r.json()).then(json => {
        if (json && json.success) {
          const el = document.getElementById('thought-' + id);
          if (el) {
            const tmp = document.createElement('div'); tmp.innerHTML = json.html;
            el.replaceWith(tmp.firstElementChild);
          }
          qs('#editModal').classList.add('hidden');
        }
      }).catch(console.error);
    });

    // Load more pagination via AJAX
    const loadMoreBtn = qs('#loadMore');
    if (loadMoreBtn) {
      loadMoreBtn.addEventListener('click', function () {
        const next = parseInt(this.dataset.nextPage || '2', 10);
        fetch(window.location.pathname + '?ajax=1&page=' + next).then(r => r.json()).then(json => {
          if (json && json.count > 0) {
            const tmp = document.createElement('div'); tmp.innerHTML = json.html;
            const list = qs('#list');
            while (tmp.firstElementChild) list.appendChild(tmp.firstElementChild);
            this.dataset.nextPage = next + 1;
            if (json.count < <?= $perPage ?>) this.remove();
          } else {
            this.remove();
          }
        });
      });
    }

    // Client-side search/filter
    qs('#search').addEventListener('input', function () {
      const q = this.value.toLowerCase().trim();
      qsa('#list article').forEach(a => {
        const text = a.innerText.toLowerCase();
        a.style.display = text.includes(q) ? '' : 'none';
      });
    });

  </script>
</body>
</html>