<?php
/**
 * journal.php
 * Ultra-polished Journal — "100x" design upgrade
 * Single-file UI + logic (uses external config/db.php for PDO $pdo)
 * - Place config/db.php (your file) next to this.
 * - Create uploads/ writable folder.
 * - Run SQL at the end of the file to create the table.
 */

declare(strict_types=1);
session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(18));

// External DB config must create $pdo (PDO instance)
require_once __DIR__ . '/config/db.php';

$UPLOAD_DIR = __DIR__ . '/uploads';
@mkdir($UPLOAD_DIR, 0755);
$MAX_UPLOAD_BYTES = 8 * 1024 * 1024; // 8 MB
$ALLOWED_MIMES = ['image/jpeg','image/png','image/webp','application/pdf'];

function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
            http_response_code(403);
            exit('CSRF validation failed');
        }
    }
}

// Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $tags = trim((string)($_POST['tags'] ?? ''));
        $mood = $_POST['mood'] ?? 'neutral';
        $visibility = ($_POST['visibility'] ?? 'private') === 'public' ? 'public' : 'private';
        $pinned = isset($_POST['pinned']) ? 1 : 0;

        $attachment_path = null;
        if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['attachment'];
            if ($f['error'] === UPLOAD_ERR_OK && $f['size'] <= $MAX_UPLOAD_BYTES) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $f['tmp_name']);
                finfo_close($finfo);
                if (in_array($mime, $ALLOWED_MIMES, true)) {
                    $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
                    $newname = bin2hex(random_bytes(12)) . ($ext ? '.' . $ext : '');
                    $dest = $UPLOAD_DIR . '/' . $newname;
                    if (move_uploaded_file($f['tmp_name'], $dest)) {
                        $attachment_path = 'uploads/' . $newname;
                    }
                }
            }
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO journal_entries (title, content, tags, mood, visibility, pinned, attachment_path, created_at, updated_at) VALUES (:title, :content, :tags, :mood, :visibility, :pinned, :attachment, NOW(), NOW())");
            $stmt->execute([':title'=>$title,':content'=>$content,':tags'=>$tags,':mood'=>$mood,':visibility'=>$visibility,':pinned'=>$pinned,':attachment'=>$attachment_path]);
            $_SESSION['flash'] = 'Запис створено';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) exit('Invalid id');
            if ($attachment_path) {
                $old = $pdo->prepare('SELECT attachment_path FROM journal_entries WHERE id=:id');
                $old->execute([':id'=>$id]);
                $r = $old->fetch(); if ($r && $r['attachment_path']) { $pfile = __DIR__ . '/' . $r['attachment_path']; if (file_exists($pfile)) @unlink($pfile); }
                $stmt = $pdo->prepare("UPDATE journal_entries SET title=:title, content=:content, tags=:tags, mood=:mood, visibility=:visibility, pinned=:pinned, attachment_path=:attachment, updated_at=NOW() WHERE id=:id");
                $stmt->execute([':title'=>$title,':content'=>$content,':tags'=>$tags,':mood'=>$mood,':visibility'=>$visibility,':pinned'=>$pinned,':attachment'=>$attachment_path,':id'=>$id]);
            } else {
                $stmt = $pdo->prepare("UPDATE journal_entries SET title=:title, content=:content, tags=:tags, mood=:mood, visibility=:visibility, pinned=:pinned, updated_at=NOW() WHERE id=:id");
                $stmt->execute([':title'=>$title,':content'=>$content,':tags'=>$tags,':mood'=>$mood,':visibility'=>$visibility,':pinned'=>$pinned,':id'=>$id]);
            }
            $_SESSION['flash'] = 'Запис оновлено';
        }

        header('Location: ' . $_SERVER['PHP_SELF']); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT attachment_path FROM journal_entries WHERE id=:id"); $stmt->execute([':id'=>$id]); $row = $stmt->fetch(); if ($row && $row['attachment_path']) { $p = __DIR__ . '/' . $row['attachment_path']; if (file_exists($p)) @unlink($p); }
            $stmt = $pdo->prepare("DELETE FROM journal_entries WHERE id=:id"); $stmt->execute([':id'=>$id]); $_SESSION['flash'] = 'Запис видалено';
        }
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
}

// READ / LIST
$q = trim((string)($_GET['q'] ?? ''));
$tagFilter = trim((string)($_GET['tag'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1)); $perPage = 12; $offset = ($page - 1) * $perPage;
$where=[]; $params=[];
if ($q !== '') { $where[] = "(title LIKE :q OR content LIKE :q)"; $params[':q'] = "%$q%"; }
if ($tagFilter !== '') { $where[] = "(FIND_IN_SET(:tag, tags) OR tags LIKE :tag_like)"; $params[':tag'] = $tagFilter; $params[':tag_like'] = "%".$tagFilter."%"; }
$where_sql = $where ? 'WHERE '.implode(' AND ', $where) : '';
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entries $where_sql"); $totalStmt->execute($params); $total = (int)$totalStmt->fetchColumn();
$listSql = "SELECT * FROM journal_entries $where_sql ORDER BY pinned DESC, updated_at DESC LIMIT :limit OFFSET :offset";
$listStmt = $pdo->prepare($listSql); foreach($params as $k=>$v) $listStmt->bindValue($k,$v); $listStmt->bindValue(':limit',$perPage,PDO::PARAM_INT); $listStmt->bindValue(':offset',$offset,PDO::PARAM_INT); $listStmt->execute(); $entries = $listStmt->fetchAll();

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// OUTPUT: ultra-polished light UI
?><!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Journal — Refined</title>
<style>
:root{
  --bg:#f5f7fb; --card:#ffffff; --muted:#6b7280; --accent:#0a84ff; --accent-2:#7c5cff;
  --glass: rgba(15,23,36,0.04); --radius:16px; --shadow:0 10px 30px rgba(12,20,28,0.06);
}
*{box-sizing:border-box}
html,body{height:100%;margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial;background:var(--bg);color:#0f1724}
.container{max-width:1200px;margin:28px auto;padding:24px}
.header{display:flex;align-items:center;justify-content:space-between;gap:18px}
.brand{display:flex;align-items:center;gap:14px}
.logo{width:64px;height:64px;border-radius:14px;background:linear-gradient(135deg,#ffffff, #f2f6ff);display:flex;align-items:center;justify-content:center;color:var(--accent);font-weight:800;font-size:22px;box-shadow:var(--shadow);border:1px solid rgba(12,20,28,0.04)}
.title{font-weight:800;font-size:20px}
.subtitle{font-size:13px;color:var(--muted)}
.controls{display:flex;align-items:center;gap:12px}
.search{display:flex;align-items:center;gap:10px;background:var(--card);padding:10px 14px;border-radius:12px;border:1px solid var(--glass);box-shadow:0 4px 18px rgba(12,20,28,0.03)}
.search input{border:0;background:transparent;outline:none;width:260px;font-size:14px}
.btn{background:var(--accent);color:#fff;border:none;padding:10px 14px;border-radius:12px;cursor:pointer;font-weight:700;box-shadow:0 8px 20px rgba(10,132,255,0.12)}
.btn.ghost{background:transparent;border:1px solid var(--glass);color:var(--muted);box-shadow:none}
.layout{display:grid;grid-template-columns:260px 1fr;gap:22px;margin-top:22px}
.sidebar{background:var(--card);border-radius:var(--radius);padding:16px;border:1px solid var(--glass);box-shadow:var(--shadow);height:calc(100vh - 160px)}
.menu-item{display:flex;align-items:center;gap:12px;padding:12px;border-radius:12px;cursor:pointer;transition:all .18s}
.menu-item:hover{transform:translateX(4px);background:#f8fbff}
.menu-quick{display:flex;align-items:center;gap:10px}
.hint{font-size:13px;color:var(--muted);margin-top:12px}
.content{min-height:420px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px}
.card{background:var(--card);padding:16px;border-radius:14px;border:1px solid var(--glass);box-shadow:0 8px 30px rgba(12,20,28,0.04);display:flex;flex-direction:column;min-height:180px}
.card .meta{display:flex;align-items:center;justify-content:space-between}
.card .title{font-weight:700;font-size:16px}
.card .excerpt{color:var(--muted);margin-top:10px;flex:1}
.card .chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.chip{background:#f1f5ff;padding:6px 10px;border-radius:999px;font-size:13px;color:var(--accent-2)}
.card .footer{display:flex;align-items:center;gap:8px;margin-top:12px}
.small{font-size:13px;color:var(--muted)}
.pager{display:flex;gap:8px}
.fab{position:fixed;right:28px;bottom:28px;background:linear-gradient(180deg,var(--accent),#0066d6);color:#fff;width:68px;height:68px;border-radius:20px;border:none;font-size:30px;display:flex;align-items:center;justify-content:center;box-shadow:0 20px 50px rgba(10,132,255,0.18);cursor:pointer}
.fab:hover{transform:translateY(-4px)}
.slide-panel{position:fixed;right:0;top:0;height:100%;width:520px;background:var(--card);border-left:1px solid var(--glass);box-shadow:-40px 0 80px rgba(12,20,28,0.06);transform:translateX(110%);transition:transform .32s cubic-bezier(.2,.9,.2,1);padding:22px;display:flex;flex-direction:column}
.slide-panel.open{transform:translateX(0)}
.form-row{display:flex;gap:10px}
.input,textarea,select{width:100%;padding:12px;border-radius:10px;border:1px solid var(--glass);background:#fff;font-size:14px}
textarea{min-height:220px}
.file-info{font-size:13px;color:var(--muted);margin-top:6px}
.preview-box{background:#fbfdff;border-radius:10px;padding:10px;border:1px dashed rgba(10,132,255,0.06);margin-top:10px;min-height:120px;overflow:auto}
.action-row{display:flex;justify-content:space-between;align-items:center;margin-top:12px}
.switch{display:inline-flex;align-items:center;gap:8px}
.switch input{width:18px;height:18px}
@media(max-width:940px){.layout{grid-template-columns:1fr}}
</style>
</head>
<body>
  <?php include "elements.php"; ?>  <!-- sidebar only once -->
<div class="container">
  <div class="header">
    <div class="brand">
      <div class="logo">J</div>
      <div>
        <div class="title">Journal</div>
        <div class="subtitle">clean & focused — refined for 2025</div>
      </div>
    </div>

    <div class="controls">
      <form method="get" style="display:flex">
        <label class="search">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden><path d="M21 21l-4.35-4.35" stroke="#9CA3AF" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <input name="q" value="<?= e($q) ?>" placeholder="Пошук..." aria-label="Search" />
        </label>
      </form>
      <button class="btn" id="openNew">+ New</button>
    </div>
  </div>

  <?php if ($flash): ?>
    <div style="margin-top:14px;padding:12px;border-radius:12px;background:#e6f6ff;border:1px solid rgba(10,132,255,0.12);"><?= e($flash) ?></div>
  <?php endif; ?>

  <div class="layout">
    <aside class="sidebar">
      <div style="font-weight:800;margin-bottom:10px">Menu</div>
      <div class="menu-item" onclick="openPanel('quick')"><div class="menu-quick"><div style="width:10px;height:10px;border-radius:50%;background:var(--accent)"></div><div style="font-weight:700">Quick Note</div></div></div>
      <div class="menu-item" onclick="openPanel('full')"><div style="display:flex;align-items:center;gap:10px"><div style="width:10px;height:10px;border-radius:50%;background:#34c759"></div><div style="font-weight:700">Full Entry</div></div></div>
      <div class="hint">Pro tip: Quick Note saves to local draft instantly. Use Full Entry for attachments, tags, and visibility.</div>
      <hr style="margin:12px 0;border:none;border-top:1px solid rgba(15,23,36,0.04)">
      <div style="font-weight:700;margin-bottom:8px">Tags</div>
      <div id="tagList" style="display:flex;gap:8px;flex-wrap:wrap"></div>
    </aside>

    <main class="content">
      <div class="grid" id="entryGrid">
        <?php foreach ($entries as $row): ?>
          <article class="card" data-id="<?= $row['id'] ?>" data-title="<?= e($row['title']) ?>" data-content="<?= e($row['content']) ?>" data-tags="<?= e($row['tags']) ?>">
            <div class="meta"><div class="title"><?= e($row['title'] ?: '(Без заголовку)') ?></div><div class="small"><?= e($row['tags']) ?: '—' ?></div></div>
            <div class="excerpt"><?= nl2br(e(mb_substr(strip_tags($row['content']),0,280))) ?><?php if (mb_strlen($row['content'])>280) echo '...'; ?></div>
            <div class="chips">
              <?php if ($row['tags']): foreach (array_filter(array_map('trim', explode(',', $row['tags']))) as $t): ?>
                <div class="chip" onclick="filterTag('<?= e($t) ?>')"><?= e($t) ?></div>
              <?php endforeach; endif; ?>
            </div>
            <div class="footer"><div class="small">Оновлено: <?= e($row['updated_at']) ?></div>
              <div style="margin-left:auto;display:flex;gap:8px"><button class="btn ghost btn-edit">Edit</button>
                <form method="post" onsubmit="return confirm('Видалити запис?')"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn ghost" type="submit">Delete</button></form></div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <div style="margin-top:18px;display:flex;justify-content:space-between;align-items:center"><div class="small">Всього записів: <?= $total ?></div>
        <div class="pager"><?php if ($page>1): ?><a class="btn ghost" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET,['page'=>$page-1]))) ?>">‹ Prev</a><?php endif; ?><?php if ($page * $perPage < $total): ?><a class="btn ghost" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET,['page'=>$page+1]))) ?>">Next ›</a><?php endif; ?></div>
      </div>
    </main>
  </div>
</div>

<button class="fab" id="fab">＋</button>

<!-- Slide panel -->
<aside class="slide-panel" id="panel" aria-hidden="true">
  <form id="entryForm" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="create" id="formAction">
    <input type="hidden" name="id" value="" id="entryId">

    <div style="display:flex;justify-content:space-between;align-items:center">
      <div style="font-weight:800;font-size:16px" id="panelTitle">New Entry</div>
      <div style="display:flex;gap:8px"><button type="button" class="btn ghost" id="closePanel">Close</button></div>
    </div>

    <div style="margin-top:12px" class="form-row"><input id="inputTitle" name="title" class="input" placeholder="Заголовок"></div>
    <div style="margin-top:12px"><textarea id="inputContent" name="content" class="input" placeholder="Запис... (підтримка базового Markdown)"></textarea></div>

    <div style="display:flex;gap:8px;margin-top:12px;align-items:center">
      <input id="inputTags" name="tags" class="input" placeholder="теги через кому">
      <select id="inputMood" name="mood" class="input" style="width:140px">
        <option value="neutral">Neutral</option>
        <option value="happy">Happy</option>
        <option value="focused">Focused</option>
        <option value="sad">Sad</option>
      </select>
      <label class="switch" title="Заблокувати зверху"><input id="inputPinned" type="checkbox" name="pinned"> Pin</label>
    </div>

    <div style="display:flex;gap:8px;margin-top:10px;align-items:center">
      <label class="small">File <input id="inputFile" name="attachment" type="file"></label>
      <div class="file-info" id="fileInfo">Файл не вибрано</div>
      <label style="margin-left:auto">Visibility <select name="visibility" id="inputVisibility"><option value="private">Private</option><option value="public">Public</option></select></label>
    </div>

    <div style="margin-top:12px"><div class="small">Preview</div><div id="mdPreview" class="preview-box">(preview)</div></div>

    <div class="action-row"><div><button class="btn" type="submit">Save</button><button type="button" class="btn ghost" id="discardDraft">Discard</button></div><div class="small">Autosave • local</div></div>
  </form>
</aside>

<script>
// DOM refs
const panel = document.getElementById('panel');
const openNew = document.getElementById('openNew');
const fab = document.getElementById('fab');
const closePanel = document.getElementById('closePanel');
const form = document.getElementById('entryForm');
const formAction = document.getElementById('formAction');
const entryId = document.getElementById('entryId');
const panelTitle = document.getElementById('panelTitle');
const inputTitle = document.getElementById('inputTitle');
const inputContent = document.getElementById('inputContent');
const inputTags = document.getElementById('inputTags');
const inputFile = document.getElementById('inputFile');
const fileInfo = document.getElementById('fileInfo');
const mdPreview = document.getElementById('mdPreview');
const tagList = document.getElementById('tagList');

// helpers
function openPanel(mode='full', data={}){
  formAction.value = data.id ? 'update' : 'create';
  entryId.value = data.id || '';
  panelTitle.textContent = data.id ? 'Edit Entry' : (mode==='quick' ? 'Quick Note' : 'New Entry');
  inputTitle.value = data.title || '';
  inputContent.value = data.content || '';
  inputTags.value = data.tags || '';
  mdPreview.innerHTML = renderPreview(inputContent.value);
  fileInfo.textContent = '(preview)';
  panel.classList.add('open');
}
function closePanelFn(){ panel.classList.remove('open'); }
openNew.addEventListener('click', ()=> openPanel('full'));
fab.addEventListener('click', ()=> openPanel('quick'));
closePanel.addEventListener('click', closePanelFn);

inputContent.addEventListener('input', ()=> mdPreview.innerHTML = renderPreview(inputContent.value));
inputFile.addEventListener('change', ()=>{ const f = inputFile.files[0]; fileInfo.textContent = f ? `${f.name} • ${Math.round(f.size/1024)} KB` : 'Файл не вибрано'; });

// simple markdown-like preview
function renderPreview(src){ if(!src) return '<i style="color:#9aa4b2">(preview)</i>'; let html = e(src).replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>').replace(/\*(.*?)\*/g,'<em>$1</em>').replace(/`(.*?)`/g,'<code>$1</code>').replace(/
/g,'<br>'); return html; }
function e(t){ return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Edit buttons
document.querySelectorAll('.btn-edit').forEach(b=> b.addEventListener('click', e=>{
  const card = e.target.closest('.card'); openPanel('full',{id: card.dataset.id, title: card.dataset.title, content: card.dataset.content, tags: card.dataset.tags});
}));

// tag extraction & list
(function buildTags(){ const chips = document.querySelectorAll('.chip'); const tags = new Set(); chips.forEach(c=> tags.add(c.textContent)); tagList.innerHTML = ''; tags.forEach(t=>{ const el=document.createElement('div'); el.className='chip'; el.textContent=t; el.onclick=()=>{ window.location.search = '?tag='+encodeURIComponent(t); }; tagList.appendChild(el); }); })();
function filterTag(t){ window.location.search = '?tag='+encodeURIComponent(t); }

// autosave draft
const DRAFT = 'journal:draft:v3'; function saveDraft(){ const d={title:inputTitle.value,content:inputContent.value,tags:inputTags.value}; localStorage.setItem(DRAFT,JSON.stringify(d)); }
setInterval(saveDraft,1500); document.addEventListener('visibilitychange', ()=>{ if(document.hidden) saveDraft(); });
// restore
document.addEventListener('DOMContentLoaded', ()=>{ const raw=localStorage.getItem(DRAFT); if(raw){ try{ const d=JSON.parse(raw); inputTitle.value = inputTitle.value || d.title || ''; inputContent.value = inputContent.value || d.content || ''; inputTags.value = inputTags.value || d.tags || ''; mdPreview.innerHTML = renderPreview(inputContent.value); }catch(e){} } });

document.getElementById('discardDraft').addEventListener('click', ()=>{ localStorage.removeItem(DRAFT); inputTitle.value=''; inputContent.value=''; inputTags.value=''; mdPreview.innerHTML=renderPreview(''); fileInfo.textContent='Файл не вибрано'; });

</script>
</body>
</html>

