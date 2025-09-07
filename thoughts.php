<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

// DB migration reminder (run once if needed)
// CREATE TABLE `thoughts` (
//   `id` int NOT NULL AUTO_INCREMENT,
//   `user_id` int NOT NULL,
//   `content` text NOT NULL,
//   `images` text NULL, // JSON array of relative paths
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

function media_type_from_path(string $p): string {
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    if (in_array($ext, ['mp4','webm','mov','m4v'])) return 'video';
    if (in_array($ext, ['mp3','wav','ogg','m4a'])) return 'audio';
    if (in_array($ext, ['jpg','jpeg','png','gif','webp','avif'])) return 'image';
    return 'other';
}

function handle_images_upload(string $inputName = 'images') {
    $saved = [];

    $limits = [
        'image' => 5 * 1024 * 1024,
        'audio' => 12 * 1024 * 1024,
        'video' => 80 * 1024 * 1024,
    ];

    $allowed = [
        'image/jpeg','image/png','image/webp','image/gif','image/avif',
        'video/mp4','video/webm','video/quicktime','video/x-m4v',
        'audio/mpeg','audio/mp3','audio/wav','audio/ogg','audio/m4a'
    ];

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

        $type = 'other';
        if (str_starts_with($mime, 'image/')) $type = 'image';
        if (str_starts_with($mime, 'video/')) $type = 'video';
        if (str_starts_with($mime, 'audio/')) $type = 'audio';

        $max = $limits[$type] ?? (5 * 1024 * 1024);
        if ($size > $max) continue;

        $safe = unique_filename($name);
        $dest = __DIR__ . '/uploads/' . $safe;

        if (move_uploaded_file($tmp, $dest)) {
            $saved[] = 'uploads/' . $safe;
        }
    }
    return $saved;
}

function render_media_preview_html(string $src, string $sizeClass = 'h-24') : string {
    $type = media_type_from_path($src);
    $srcEsc = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE);

    if ($type === 'image') {
        return "<img src=\"{$srcEsc}\" alt=\"img\" class=\"w-full {$sizeClass} object-cover rounded-lg\">";
    }
    if ($type === 'video') {
        return "<div class=\"w-full {$sizeClass} rounded-lg overflow-hidden bg-black\"><video class=\"w-full h-full object-cover\" muted playsinline preload=\"metadata\"><source src=\"{$srcEsc}\"></video></div>";
    }
    if ($type === 'audio') {
        return "<div class=\"flex items-center gap-3 p-2 bg-slate-50 rounded-lg\"><svg xmlns=\"http://www.w3.org/2000/svg\" class=\"h-6 w-6\" fill=\"none\" viewBox=\"0 0 24 24\" stroke=\"currentColor\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M9 19V6l12-2v13\"/></svg><div class=\"text-sm text-slate-600\">Audio file</div></div>";
    }
    return "<a href=\"{$srcEsc}\" target=\"_blank\" class=\"text-sm text-slate-600\">Open file</a>";
}

function render_thought_html(array $t): string
{
    $id = (int)$t['id'];
    $contentEsc = nl2br(htmlspecialchars($t['content'], ENT_QUOTES | ENT_SUBSTITUTE));
    $initial = htmlspecialchars(mb_substr(trim($t['content']), 0, 1) ?: '•');
    $time = date('d.m.Y H:i', strtotime($t['created_at']));

    $galleryHtml = '';
    if (!empty($t['images'])) {
        $imgs = json_decode($t['images'], true);
        if (is_array($imgs) && count($imgs) > 0) {
            $galleryHtml .= '<div class="mt-4 grid grid-cols-3 gap-2 media-grid">';
            foreach ($imgs as $idx => $src) {
                $idxEsc = (int)$idx;
                $srcEsc = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE);
                $preview = render_media_preview_html($src, 'h-40');
                $galleryHtml .= "<button data-thought-id=\"{$id}\" data-index=\"{$idxEsc}\" class=\"js-open-image focus:outline-none\">{$preview}</button>";
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
    if (!empty($_POST['delete_id'])) {
        $id = (int) $_POST['delete_id'];

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

    if (!empty($_POST['edit_id']) && isset($_POST['edit_content'])) {
        $id = (int) $_POST['edit_id'];
        $content = trim((string)$_POST['edit_content']);
        $stmt = $pdo->prepare("UPDATE thoughts SET content = :content WHERE id = :id");
        $stmt->execute([':content' => $content, ':id' => $id]);

        if (!empty($_POST['ajax'])) {
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

    if (!empty($_POST['content'])) {
        $content = trim((string)$_POST['content']);
        if ($content !== '') {
            $imagesArr = handle_images_upload('images');
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
  <title>Думай Вільно — медіа + фільтр</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap">
  <style>body{font-family:Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial;}
  .prose {line-height:1.7;}
  .composer-full { position:fixed;inset:0;background:rgba(6,8,15,0.6);display:flex;align-items:center;justify-content:center;z-index:60;padding:20px; }
  .composer-card{background:#fff;border-radius:18px;max-width:1200px;width:100%;padding:28px;box-shadow:0 10px 30px rgba(2,6,23,0.2)}
  .btn-ghost{background:transparent;border:1px solid rgba(15,23,42,0.06);} 
  .media-only-overlay{position:fixed;inset:0;background:rgba(6,8,15,0.6);z-index:70;display:flex;align-items:center;justify-content:center;padding:20px}
  </style>
</head>
<?php if (file_exists(__DIR__ . '/elements.php')) include "elements.php"; ?>
<body class="bg-gradient-to-b from-slate-50 to-white min-h-screen py-10">

  <div class="max-w-6xl mx-auto px-6">
    <header class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-3xl font-extrabold text-slate-900 flex items-center gap-3">🧭 Думай Вільно — медіа</h1>
        <p class="text-sm text-gray-500">Просте приватне місце для думок + фото/відео/аудіо. Використай фільтр для перегляду лише медіа.</p>
      </div>
      <div class="flex gap-3 items-center">
        <button id="openComposer" class="bg-slate-900 text-white px-4 py-2 rounded-xl shadow">Нова думка</button>
        <input id="search" type="search" placeholder="Шукати в думках..." class="border rounded-xl px-3 py-2 w-64" aria-label="Пошук думок">
        <a href="?" class="text-sm text-gray-600">Оновити</a>
      </div>
    </header>

    <main class="grid grid-cols-1 md:grid-cols-3 gap-6">
      <aside class="md:col-span-1 space-y-4 sticky top-6">
        <div class="bg-white p-4 rounded-2xl shadow flex gap-2">
          <button class="filter-btn px-3 py-1 rounded-xl bg-indigo-50 text-indigo-700" data-filter="all">Все</button>
          <button class="filter-btn px-3 py-1 rounded-xl bg-gray-50 text-gray-700" data-filter="media">Медіа</button>
        </div>

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

      <section class="md:col-span-2 space-y-4 relative">
        <div id="mediaToolbar" class="hidden bg-white p-3 rounded-2xl shadow flex items-center justify-between">
          <div class="flex items-center gap-3">
            <strong class="text-sm">Медіа — перегляд</strong>
            <div class="text-xs text-gray-500">Показані лише елементи з медіа</div>
          </div>
          <div class="flex items-center gap-2">
            <button id="openMediaGallery" class="px-3 py-2 rounded-lg border">Галерея медіа</button>
            <button id="clearFilter" class="px-3 py-2 rounded-lg">Повернутись</button>
          </div>
        </div>

        <div id="list" class="space-y-6 mt-3">
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
          <h2 class="text-xl font-semibold">Нова думка — медіа</h2>
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
            <label class="text-sm font-medium">Додати медіа</label>
            <input id="composerImages" name="images[]" type="file" accept="image/*,video/*,audio/*" multiple class="mt-2" />
            <div id="composerThumbs" class="mt-3 flex gap-2 flex-wrap"></div>
            <div class="mt-2 text-xs text-gray-400">Підтримуються: jpg/png/webp/mp4/webm/mp3/wav. Максимальний розмір: фото 5MB, аудіо 12MB, відео 80MB (налаштувати php.ini за потреби).</div>
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
<div id="editModal" class="fixed inset-0 hidden flex items-center justify-center bg-black/40 p-4" style="z-index: 80;">
  <div role="dialog" aria-modal="true" aria-labelledby="editModalTitle" class="bg-white rounded-2xl w-full max-w-2xl p-6 shadow-lg">
    <h3 id="editModalTitle" class="text-lg font-semibold mb-2">Редагувати думку</h3>
    <textarea id="editInput" rows="10" class="w-full border rounded-xl px-3 py-3 resize-y"></textarea>
    <div class="mt-4 flex justify-end gap-2">
      <button id="cancelEdit" class="px-3 py-2 border rounded-xl">Скасувати</button>
      <button id="saveEdit" class="bg-amber-600 text-white px-4 py-2 rounded-xl">Зберегти</button>
    </div>
  </div>
</div>

  <!-- Media gallery overlay -->
  <div id="mediaOverlay" class="media-only-overlay hidden" aria-hidden="true">
    <div class="bg-white rounded-2xl max-w-6xl w-full p-6 overflow-auto">
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
          <h3 class="text-lg font-semibold">Галерея медіа</h3>
          <div id="mediaCount" class="text-sm text-gray-500"></div>
        </div>
        <div class="flex items-center gap-2">
          <button id="closeMediaOverlay" class="px-3 py-2 rounded-lg border">Закрити</button>
        </div>
      </div>
      <div id="mediaGrid" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3"></div>
    </div>
  </div>

  <!-- Image lightbox / player -->
  <div id="imgLightbox" class="fixed inset-0 hidden flex items-center justify-center px-4 bg-black/90 z-80" style="z-index:90;">
    <div class="relative max-w-5xl w-[min(98%,1200px)] mx-auto p-4">
      <button id="lbClose" class="absolute right-2 top-2 text-white text-2xl">✕</button>
      <div id="lbPlayer" class="mx-auto max-h-[80vh] flex items-center justify-center"></div>
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
    const draftKey = 'thoughts_draft_v4';
    const composerImagesInput = qs('#composerImages');
    const composerThumbs = qs('#composerThumbs');

    // Media UI
    const mediaToolbar = qs('#mediaToolbar');
    const mediaOverlay = qs('#mediaOverlay');
    const mediaGrid = qs('#mediaGrid');
    const mediaCount = qs('#mediaCount');
    let collectedMedia = [];

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

    // Composer client preview for images/videos/audios
    function createThumb(file) {
      const wrap = document.createElement('div');
      wrap.className = 'w-24 h-24 rounded overflow-hidden relative bg-slate-100 flex items-center justify-center p-1';
      const del = document.createElement('button');
      del.className = 'absolute top-1 right-1 bg-white/80 rounded-full text-xs px-1';
      del.textContent = '✕';
      wrap.appendChild(del);

      const type = file.type || '';
      if (type.startsWith('image/')) {
        const img = document.createElement('img');
        img.className = 'object-cover w-full h-full';
        wrap.appendChild(img);
        const reader = new FileReader();
        reader.onload = (e) => img.src = e.target.result;
        reader.readAsDataURL(file);
      } else if (type.startsWith('video/')) {
        const vid = document.createElement('video');
        vid.className = 'object-cover w-full h-full';
        vid.muted = true; vid.playsInline = true; vid.preload = 'metadata';
        const reader = new FileReader();
        reader.onload = (e) => { vid.src = e.target.result; };
        reader.readAsDataURL(file);
        wrap.appendChild(vid);
      } else if (type.startsWith('audio/')) {
        const ico = document.createElement('div');
        ico.className = 'flex items-center justify-center w-full h-full text-xs text-gray-600';
        ico.textContent = 'AUDIO';
        wrap.appendChild(ico);
      } else {
        const ico = document.createElement('div');
        ico.className = 'flex items-center justify-center w-full h-full text-xs text-gray-600';
        ico.textContent = file.type || 'FILE';
        wrap.appendChild(ico);
      }

      del.addEventListener('click', () => {
        wrap.dataset.removed = '1';
        wrap.remove();
      });

      wrap._file = file;
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
      if (e.key === 'Escape') {
        if (!composerFull.classList.contains('hidden')) closeComposer();
        if (!mediaOverlay.classList.contains('hidden')) closeMediaOverlay();
        if (!qs('#imgLightbox').classList.contains('hidden')) closeLightbox();
      }
      // lightbox nav
      const lb = qs('#imgLightbox');
      if (!lb.classList.contains('hidden')) {
        if (e.key === 'ArrowRight') qs('#lbNext').click();
        if (e.key === 'ArrowLeft') qs('#lbPrev').click();
        if (e.key === ' ') {
          e.preventDefault(); qs('#lbPlay').click();
        }
      }
    });

    // Delegated edit & delete handlers
    document.addEventListener('click', function (e) {
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

      if (e.target.matches('.js-edit')) {
        const id = e.target.getAttribute('data-id');
        const el = document.getElementById('thought-' + id);
        if (!el) return;
        const content = el.querySelector('.prose').innerText;
        qs('#editInput').value = content;
        qs('#editModal').classList.remove('hidden');
        qs('#editModal').dataset.editId = id;
      }

      const btn = e.target.closest('.js-open-image');
      if (btn) {
        const id = btn.getAttribute('data-thought-id');
        const idx = parseInt(btn.getAttribute('data-index') || '0', 10);
        const article = document.getElementById('thought-' + id);
        if (!article) return;
        const nodes = Array.from(article.querySelectorAll('img, video, audio'));
        const items = nodes.map(n => ({src: n.src, type: n.tagName.toLowerCase()}));
        openLightbox(items, idx);
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

    // Filter buttons
    qsa('.filter-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const filter = btn.dataset.filter;
        qsa('.filter-btn').forEach(b => b.classList.remove('bg-indigo-50','text-indigo-700'));
        if (filter === 'all') {
          btn.classList.add('bg-indigo-50','text-indigo-700');
          mediaToolbar.classList.add('hidden');
          qsa('#list article').forEach(a => a.style.display = '');
        } else {
          btn.classList.add('bg-indigo-50','text-indigo-700');
          // show only articles that contain media
          qsa('#list article').forEach(a => {
            const hasMedia = a.querySelector('img,video,audio');
            a.style.display = hasMedia ? '' : 'none';
          });
          mediaToolbar.classList.remove('hidden');
        }
      });
    });

    // Collect all media from visible articles
    function collectVisibleMedia() {
      collectedMedia = [];
      qsa('#list article').forEach(article => {
        if (article.style.display === 'none') return;
        const nodes = Array.from(article.querySelectorAll('img,video,audio'));
        nodes.forEach(n => collectedMedia.push({src: n.src, type: n.tagName.toLowerCase()}));
      });
      return collectedMedia;
    }

    // Open media overlay / gallery
    qs('#openMediaGallery').addEventListener('click', () => {
      const items = collectVisibleMedia();
      renderMediaGrid(items);
      mediaOverlay.classList.remove('hidden');
      mediaOverlay.setAttribute('aria-hidden','false');
    });

    qs('#closeMediaOverlay').addEventListener('click', closeMediaOverlay);
    function closeMediaOverlay() { mediaOverlay.classList.add('hidden'); mediaOverlay.setAttribute('aria-hidden','true'); mediaGrid.innerHTML = ''; }

    qs('#clearFilter').addEventListener('click', () => { qsa('.filter-btn[data-filter="all"]').forEach(b=>b.click()); });

    function renderMediaGrid(items) {
      mediaGrid.innerHTML = '';
      mediaCount.textContent = ` — ${items.length} items`;
      items.forEach((it, i) => {
        const btn = document.createElement('button');
        btn.className = 'rounded overflow-hidden';
        btn.innerHTML = (() => {
          if (it.type === 'img' || it.type === 'image') return `<img src="${it.src}" class="w-full h-36 object-cover"/>`;
          if (it.type === 'video' || it.type === 'video') return `<video src="${it.src}" class="w-full h-36 object-cover" muted playsinline></video>`;
          if (it.type === 'audio' || it.type === 'audio') return `<div class=\"flex items-center justify-center h-36 bg-slate-50\">Audio</div>`;
          return `<div class=\"h-36 flex items-center justify-center\">File</div>`;
        })();
        btn.addEventListener('click', () => openLightbox(items.map(x=>({src:x.src,type:x.type})), i));
        mediaGrid.appendChild(btn);
      });
    }

    // ---------------- Lightbox ----------------
    const imgLightbox = qs('#imgLightbox');
    const lbPlayer = qs('#lbPlayer');
    let lbItems = [];
    let lbIndex = 0;
    let lbPlaying = false;

    function renderLbItem(item) {
      lbPlayer.innerHTML = '';
      if (!item) return;
      if (item.type === 'img' || item.type === 'image') {
        const img = document.createElement('img'); img.src = item.src; img.className = 'mx-auto max-h-[80vh] rounded-md'; lbPlayer.appendChild(img);
      } else if (item.type === 'video' || item.type === 'video') {
        const v = document.createElement('video'); v.src = item.src; v.controls = true; v.className = 'mx-auto max-h-[80vh] rounded-md'; v.autoplay = lbPlaying; lbPlayer.appendChild(v);
      } else if (item.type === 'audio' || item.type === 'audio') {
        const a = document.createElement('audio'); a.src = item.src; a.controls = true; a.className = 'w-full'; a.autoplay = lbPlaying; lbPlayer.appendChild(a);
      } else {
        const a = document.createElement('a'); a.href = item.src; a.target = '_blank'; a.textContent = 'Open file'; lbPlayer.appendChild(a);
      }
    }

    function openLightbox(items, index = 0) {
      lbItems = items;
      lbIndex = index;
      lbPlaying = false;
      renderLbItem(lbItems[lbIndex]);
      imgLightbox.classList.remove('hidden');
    }

    function closeLightbox() { imgLightbox.classList.add('hidden'); lbPlayer.innerHTML = ''; lbItems = []; }

    function showLbIndex(i) {
      if (!lbItems.length) return;
      lbIndex = (i + lbItems.length) % lbItems.length;
      lbPlaying = false;
      renderLbItem(lbItems[lbIndex]);
    }

    qs('#lbNext').addEventListener('click', () => showLbIndex(lbIndex + 1));
    qs('#lbPrev').addEventListener('click', () => showLbIndex(lbIndex - 1));

    qs('#lbPlay').addEventListener('click', () => {
      const node = lbPlayer.querySelector('video, audio');
      if (!node) return;
      if (node.paused) { node.play(); qs('#lbPlay').textContent = 'Pause'; } else { node.pause(); qs('#lbPlay').textContent = 'Play'; }
    });

    qs('#lbClose').addEventListener('click', closeLightbox);

  </script>
</body>
</html>