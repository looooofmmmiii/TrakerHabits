<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

// DB migration reminder (run once if needed)
// CREATE TABLE `thoughts` (
//   `id` int NOT NULL AUTO_INCREMENT,
//   `user_id` int NOT NULL,
//   `content` text NOT NULL,
//   `images` text NULL,
//   `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
//   PRIMARY KEY (`id`)
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

// ---------------------- Helpers ----------------------
function ensure_upload_dir(string $dir = null) {
    if ($dir === null) $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function unique_filename(string $orig) {
    $ext = pathinfo($orig, PATHINFO_EXTENSION);
    $base = bin2hex(random_bytes(8));
    return $base . ($ext ? '.' . $ext : '');
}

function handle_images_upload(string $inputName = 'images') {
    $saved = [];
    $maxSize = 5 * 1024 * 1024; // 5 MB per file
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];

    if (empty($_FILES[$inputName]) || !is_array($_FILES[$inputName]['name'])) {
        return $saved;
    }

    ensure_upload_dir();

    for ($i = 0; $i < count($_FILES[$inputName]['name']); $i++) {
        if ($_FILES[$inputName]['error'][$i] !== UPLOAD_ERR_OK) continue;
        $tmp = $_FILES[$inputName]['tmp_name'][$i];
        $name = $_FILES[$inputName]['name'][$i];
        $size = $_FILES[$inputName]['size'][$i];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp);
        finfo_close($finfo);

        if (!in_array($mime, $allowed)) continue;
        if ($size > $maxSize) continue;

        $safe = unique_filename($name);
        $dest = __DIR__ . '/uploads/' . $safe;

        if (move_uploaded_file($tmp, $dest)) {
            $saved[] = 'uploads/' . $safe;
        }
    }
    return $saved;
}

// ---------------------- Render helper ----------------------
function render_thought_html(array $t): string
{
    $id = (int)$t['id'];
    $contentEsc = nl2br(htmlspecialchars($t['content'], ENT_QUOTES | ENT_SUBSTITUTE));
    $initial = htmlspecialchars(mb_substr(trim($t['content']), 0, 1) ?: '•');
    $time = date('d.m.Y H:i', strtotime($t['created_at']));

    // images gallery
    $galleryHtml = '';
    if (!empty($t['images'])) {
        $imgs = json_decode($t['images'], true);
        if (is_array($imgs) && count($imgs) > 0) {
            $galleryHtml .= '<div class="mt-4 grid grid-cols-3 gap-2">';
            foreach ($imgs as $idx => $src) {
                $idxEsc = (int)$idx;
                $srcEsc = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE);
                $galleryHtml .= "<button data-thought-id=\"{$id}\" data-index=\"{$idxEsc}\" class=\"js-open-image focus:outline-none\"><img src=\"{$srcEsc}\" alt=\"img\" class=\"w-full h-40 object-cover rounded-lg\"></button>";
            }
            $galleryHtml .= '</div>';
        }
    }

    return "<article id=\"thought-{$id}\" class=\"bg-white p-8 rounded-3xl shadow-lg flex gap-6 items-start max-w-none\">" .
           "<div class=\"w-16 h-16 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-700 font-semibold text-2xl flex-shrink-0\">{$initial}</div>" .
           "<div class=\"flex-1\">" .
           "<div class=\"prose max-w-none text-slate-900 text-lg break-words leading-7\">{$contentEsc}</div>" .
           $galleryHtml .
           "<div class=\"text-xs text-gray-400 mt-3\">{$time}</div>" .
           "</div>" .
           "<div class=\"flex flex-col gap-2 items-end\">" .
           "<button data-id=\"{$id}\" class=\"js-edit bg-amber-50 border border-amber-100 text-amber-700 px-3 py-1 rounded-lg text-sm\">Редагувати</button>" .
           "<button data-id=\"{$id}\" class=\"js-delete bg-red-50 border border-red-100 text-red-600 px-3 py-1 rounded-lg text-sm\">Видалити</button>" .
           "</div>" .
           "</article>";
}

// ---------------------- Router / Actions ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // DELETE (AJAX or regular)
    if (!empty($_POST['delete_id'])) {
        $id = (int) $_POST['delete_id'];

        // fetch images first
        $s = $pdo->prepare("SELECT images FROM thoughts WHERE id = :id");
        $s->execute([':id' => $id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['images'])) {
            $imgs = json_decode($row['images'], true);
            if (is_array($imgs)) {
                foreach ($imgs as $p) {
                    $full = __DIR__ . '/' . $p;
                    if (is_file($full)) @unlink($full);
                }
            }
        }

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

    // EDIT (only content)
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
            // handle images upload
            $imagesArr = handle_images_upload('images'); // array of relative paths
            $imagesJson = $imagesArr ? json_encode($imagesArr, JSON_UNESCAPED_SLASHES) : null;

            $stmt = $pdo->prepare("INSERT INTO thoughts (user_id, content, images) VALUES (:user_id, :content, :images)");
            $stmt->execute([
                ':user_id' => 1,
                ':content' => $content,
                ':images' => $imagesJson
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

// ---------------------- Server-side: initial page load ----------------------
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
  <title>Думай Вільно — з фото</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap">
  <style>body{font-family:Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial;}
  .prose {line-height:1.7;}
  .composer-full { position:fixed;inset:0;background:rgba(6,8,15,0.6);display:flex;align-items:center;justify-content:center;z-index:60;padding:20px; }
  .composer-card{background:#fff;border-radius:18px;max-width:1200px;width:100%;padding:28px;box-shadow:0 10px 30px rgba(2,6,23,0.2)}
  .btn-ghost{background:transparent;border:1px solid rgba(15,23,42,0.06);} </style>
</head>
<body class="bg-gradient-to-b from-slate-50 to-white min-h-screen py-10">
<?php if (file_exists(__DIR__ . '/elements.php')) include "elements.php"; ?>
  <div class="max-w-6xl mx-auto px-6">
    <header class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-3xl font-extrabold text-slate-900 flex items-center gap-3">🧭 Думай Вільно — з фото</h1>
        <p class="text-sm text-gray-500">Просте приватне місце для думок. Add images & lightbox.</p>
      </div>
      <div class="flex gap-3 items-center">
        <button id="openComposer" class="bg-slate-900 text-white px-4 py-2 rounded-xl shadow">Нова думка</button>
        <input id="search" type="search" placeholder="Шукати в думках..." class="border rounded-xl px-3 py-2 w-64" aria-label="Пошук думок">
        <a href="?" class="text-sm text-gray-600">Оновити</a>
      </div>
    </header>

    <main class="grid grid-cols-1 md:grid-cols-3 gap-6">
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
            <li>Чернетка зберігається локально automatically.</li>
          </ul>
        </div>
      </aside>

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

  <!-- Full composer overlay (wide) -->
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
          <div class="mt-3">
            <label class="text-sm font-medium">Додати фото</label>
            <input id="composerImages" name="images[]" type="file" accept="image/*" multiple class="mt-2" />
            <div id="composerThumbs" class="mt-3 flex gap-2 flex-wrap"></div>
          </div>
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

  <!-- Image lightbox / player -->
  <div id="imgLightbox" class="fixed inset-0 hidden flex items-center justify-center px-4 bg-black/80 z-50">
    <div class="relative max-w-4xl w-[min(95%,1024px)] mx-auto p-6">
      <button id="lbClose" class="absolute right-2 top-2 text-white text-xl">✕</button>
      <img id="lbImage" src="" alt="" class="mx-auto max-h-[80vh] rounded-md"/>
      <div class="flex items-center justify-center gap-4 mt-4">
        <button id="lbPrev" class="px-3 py-2 bg-white/10 text-white rounded">Prev</button>
        <button id="lbPlay" class="px-3 py-2 bg-white/10 text-white rounded">Play</button>
        <button id="lbNext" class="px-3 py-2 bg-white/10 text-white rounded">Next</button>
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
    const composerImagesInput = qs('#composerImages');
    const composerThumbs = qs('#composerThumbs');

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

    // Composer client preview for images
    function createThumb(file) {
      const wrap = document.createElement('div');
      wrap.className = 'w-24 h-24 rounded overflow-hidden relative bg-slate-100 flex items-center justify-center';
      const img = document.createElement('img');
      img.className = 'object-cover w-full h-full';
      const del = document.createElement('button');
      del.className = 'absolute top-1 right-1 bg-white/80 rounded-full text-xs px-1';
      del.textContent = '✕';
      wrap.appendChild(img);
      wrap.appendChild(del);

      const reader = new FileReader();
      reader.onload = (e) => img.src = e.target.result;
      reader.readAsDataURL(file);

      del.addEventListener('click', () => {
        wrap.dataset.removed = '1';
        wrap.remove();
      });

      return wrap;
    }

    composerImagesInput?.addEventListener('change', (e) => {
      composerThumbs.innerHTML = '';
      const files = Array.from(e.target.files || []);
      files.forEach(f => {
        const t = createThumb(f);
        composerThumbs.appendChild(t);
      });
    });

    // Save composer (AJAX)
    function saveComposer() {
      const content = composerInput.value.trim();
      if (!content) return alert('Порожній текст не можна зберегти');
      const fd = new FormData(); fd.append('content', content); fd.append('ajax', '1');

      // include image files
      const files = composerImagesInput.files ? Array.from(composerImagesInput.files) : [];
      files.forEach((file, idx) => {
        fd.append('images[]', file);
      });

      fetch(window.location.href, {method: 'POST', body: fd}).then(r => r.json()).then(json => {
        if (json && json.success) {
          const list = qs('#list');
          const tmp = document.createElement('div'); tmp.innerHTML = json.html;
          list.prepend(tmp.firstElementChild);
          localStorage.removeItem(draftKey);
          composerInput.value = '';
          composerImagesInput.value = '';
          composerThumbs.innerHTML = '';
          updateAutosaveStatus(false);
          closeComposer();
        }
      }).catch(console.error);
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && !composerFull.classList.contains('hidden')) {
        e.preventDefault(); saveComposer();
      }
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

      // gallery open
      const btn = e.target.closest('.js-open-image');
      if (btn) {
        const id = btn.getAttribute('data-thought-id');
        const idx = parseInt(btn.getAttribute('data-index') || '0', 10);
        const article = document.getElementById('thought-' + id);
        if (!article) return;
        const imgs = Array.from(article.querySelectorAll('img')).map(i => i.src);
        openLightbox(imgs, idx);
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
            if (json.count < <?php echo $perPage; ?>) this.remove();
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

    // ---------------- Lightbox ----------------
    const imgLightbox = qs('#imgLightbox');
    const lbImage = qs('#lbImage');
    let currentImages = [];
    let currentIndex = 0;
    let lbTimer = null;

    function openLightbox(images, index = 0) {
      currentImages = images;
      currentIndex = index;
      lbImage.src = images[index];
      imgLightbox.classList.remove('hidden');
    }

    function closeLightbox() {
      imgLightbox.classList.add('hidden');
      stopAutoplay();
    }

    function showIndex(i) {
      if (!currentImages.length) return;
      currentIndex = (i + currentImages.length) % currentImages.length;
      lbImage.src = currentImages[currentIndex];
    }

    function nextImage() { showIndex(currentIndex + 1); }
    function prevImage() { showIndex(currentIndex - 1); }

    function startAutoplay(interval = 2500) {
      stopAutoplay();
      lbTimer = setInterval(nextImage, interval);
      qs('#lbPlay').textContent = 'Pause';
    }
    function stopAutoplay() {
      if (lbTimer) clearInterval(lbTimer);
      lbTimer = null;
      qs('#lbPlay').textContent = 'Play';
    }

    qs('#lbClose').addEventListener('click', closeLightbox);
    qs('#lbNext').addEventListener('click', nextImage);
    qs('#lbPrev').addEventListener('click', prevImage);
    qs('#lbPlay').addEventListener('click', () => { if (lbTimer) stopAutoplay(); else startAutoplay(2500); });

  </script>
</body>
</html>
