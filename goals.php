<?php
/**
 * Goals — Single‑file app (2025)
 * - All in one: PHP + HTML + CSS + JS
 * - Create, update, delete, toggle done, inline progress
 * - Filters: search, status, due date range, sorting, pinned first
 * - Secure: CSRF, prepared statements, output escaping
 * - DB: external config/db.php must define $pdo (PDO)
 * - DDL for `goals` is included at the bottom of this file
 */

declare(strict_types=1);
session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(18));
$__CSRF = $_SESSION['csrf'];

// External DB config must create $pdo (PDO instance)
require_once __DIR__ . '/config/db.php';

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf'])) {
            http_response_code(403);
            exit('CSRF validation failed');
        }
    }
}
function parse_date(?string $d): ?string {
    $d = trim((string)$d);
    if ($d === '') return null;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
}
function json_response(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') { $_SESSION['flash'] = 'Назва обовʼязкова'; header('Location: '.$_SERVER['PHP_SELF']); exit; }
        $description = trim((string)($_POST['description'] ?? ''));
        $target_date = parse_date($_POST['target_date'] ?? null);
        $priority = in_array(($_POST['priority'] ?? 'medium'), ['low','medium','high'], true) ? (string)$_POST['priority'] : 'medium';
        $pinned = isset($_POST['pinned']) ? 1 : 0;
        $progress = max(0, min(100, (int)($_POST['progress'] ?? 0)));

        $stmt = $pdo->prepare("INSERT INTO goals (title, description, target_date, priority, pinned, progress, is_done, created_at, updated_at) VALUES (:title,:description,:target_date,:priority,:pinned,:progress,0,NOW(),NOW())");
        $stmt->execute([
            ':title'=>$title,
            ':description'=>$description,
            ':target_date'=>$target_date,
            ':priority'=>$priority,
            ':pinned'=>$pinned,
            ':progress'=>$progress,
        ]);
        $_SESSION['flash'] = 'Ціль створено';
        header('Location: '.$_SERVER['PHP_SELF']); exit;

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0); if ($id<=0) exit('Bad id');
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $target_date = parse_date($_POST['target_date'] ?? null);
        $priority = in_array(($_POST['priority'] ?? 'medium'), ['low','medium','high'], true) ? (string)$_POST['priority'] : 'medium';
        $pinned = isset($_POST['pinned']) ? 1 : 0;
        $progress = max(0, min(100, (int)($_POST['progress'] ?? 0)));

        $stmt = $pdo->prepare("UPDATE goals SET title=:title, description=:description, target_date=:target_date, priority=:priority, pinned=:pinned, progress=:progress, updated_at=NOW() WHERE id=:id");
        $stmt->execute([
            ':title'=>$title,
            ':description'=>$description,
            ':target_date'=>$target_date,
            ':priority'=>$priority,
            ':pinned'=>$pinned,
            ':progress'=>$progress,
            ':id'=>$id,
        ]);
        $_SESSION['flash'] = 'Ціль оновлено';
        header('Location: '.$_SERVER['PHP_SELF']); exit;

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0); if ($id<=0) exit('Bad id');
        $pdo->beginTransaction();
        $row = $pdo->prepare('SELECT is_done FROM goals WHERE id=:id'); $row->execute([':id'=>$id]); $r=$row->fetch();
        if ($r) {
            $new = (int)!((int)$r['is_done']);
            $stmt = $pdo->prepare('UPDATE goals SET is_done=:d, updated_at=NOW() WHERE id=:id');
            $stmt->execute([':d'=>$new, ':id'=>$id]);
            $pdo->commit();
        } else { $pdo->rollBack(); }
        $_SESSION['flash'] = 'Статус змінено';
        header('Location: '.$_SERVER['PHP_SELF']); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0); if ($id<=0) exit('Bad id');
        $stmt = $pdo->prepare('DELETE FROM goals WHERE id=:id'); $stmt->execute([':id'=>$id]);
        $_SESSION['flash'] = 'Ціль видалено';
        header('Location: '.$_SERVER['PHP_SELF']); exit;

    } elseif ($action === 'ajax_progress') {
        // Progressive enhancement endpoint
        $id = (int)($_POST['id'] ?? 0); $progress = max(0, min(100, (int)($_POST['progress'] ?? 0)));
        if ($id<=0) json_response(['ok'=>false,'error'=>'Bad id']);
        try {
            $stmt = $pdo->prepare('UPDATE goals SET progress=:p, updated_at=NOW() WHERE id=:id');
            $stmt->execute([':p'=>$progress, ':id'=>$id]);
            json_response(['ok'=>true,'progress'=>$progress]);
        } catch (Throwable $e) {
            json_response(['ok'=>false,'error'=>'DB error']);
        }
    }
}

// READ (filters)
$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'all')); // all|active|done
$dueFrom = parse_date($_GET['due_from'] ?? null);
$dueTo = parse_date($_GET['due_to'] ?? null);
$sort = trim((string)($_GET['sort'] ?? 'new')); // new|old|due_soon|due_far|title_asc|title_desc|progress_desc
$pinnedFirst = isset($_GET['pinnedfirst']) ? (int)($_GET['pinnedfirst'] === '0' ? 0 : 1) : 1;
$page = max(1, (int)($_GET['page'] ?? 1)); $perPage = 12; $offset = ($page - 1) * $perPage;

$where=[]; $params=[];
if ($q !== '') { $where[] = "(title LIKE :q OR description LIKE :q)"; $params[':q'] = "%$q%"; }
if ($status === 'active') { $where[] = 'is_done = 0'; }
elseif ($status === 'done') { $where[] = 'is_done = 1'; }
if ($dueFrom) { $where[] = 'target_date IS NOT NULL AND target_date >= :df'; $params[':df']=$dueFrom; }
if ($dueTo) { $where[] = 'target_date IS NOT NULL AND target_date <= :dt'; $params[':dt']=$dueTo; }
$where_sql = $where ? 'WHERE '.implode(' AND ', $where) : '';

switch ($sort) {
    case 'old': $order = 'created_at ASC'; break;
    case 'due_soon': $order = 'target_date IS NULL, target_date ASC'; break; // NULLs last
    case 'due_far': $order = 'target_date DESC'; break;
    case 'title_asc': $order = 'LOWER(title) ASC'; break;
    case 'title_desc': $order = 'LOWER(title) DESC'; break;
    case 'progress_desc': $order = 'progress DESC'; break;
    default: $order = 'created_at DESC'; // new
}
$orderSql = $pinnedFirst ? ("pinned DESC, ".$order) : $order;

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM goals $where_sql"); $totalStmt->execute($params); $total = (int)$totalStmt->fetchColumn();
$listSql = "SELECT * FROM goals $where_sql ORDER BY $orderSql LIMIT :limit OFFSET :offset";
$listStmt = $pdo->prepare($listSql);
foreach($params as $k=>$v) $listStmt->bindValue($k,$v);
$listStmt->bindValue(':limit',$perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset',$offset, PDO::PARAM_INT);
$listStmt->execute(); $rows = $listStmt->fetchAll();

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

?><!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Goals — 2025</title>
  <style>
    :root{
      --bg:#ffffff; --text:#0f172a; --muted:#64748b; --line:#e5e7eb;
      --card:#ffffff; --card-hover:#f9fafb; --accent:#3b82f6; --ok:#16a34a; --danger:#ef4444;
      --radius:12px; --shadow:0 4px 14px rgba(15,23,42,0.06);
    }
    .theme-dark{
      --bg:#0b1220; --text:#e5e7eb; --muted:#9aa4b2; --line:#1f2937;
      --card:#0f172a; --card-hover:#111827; --accent:#3b82f6; --ok:#22c55e; --danger:#f87171;
      --shadow:0 6px 20px rgba(0,0,0,0.35);
    }
    *{box-sizing:border-box}
    html,body{height:100%;margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial;background:var(--bg);color:var(--text)}
    .container{max-width:1080px;margin:28px auto;padding:0 20px}
    .header{display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;background:#fff;padding:12px 0;z-index:10;border-bottom:1px solid var(--line)}
    .brand{display:flex;align-items:center;gap:14px}
    .logo{width:40px;height:40px;border-radius:10px;background:var(--card);display:flex;align-items:center;justify-content:center;color:var(--accent);font-weight:800;font-size:16px;border:1px solid var(--line)}
    .title{font-weight:800;font-size:18px}
    .subtitle{font-size:12px;color:var(--muted)}
    .controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .search{display:flex;align-items:center;gap:10px;background:var(--card);padding:8px 12px;border-radius:10px;border:1px solid var(--line)}
    .search input{border:0;background:transparent;outline:none;width:220px;font-size:14px;color:var(--text)}
    .input, select, textarea{width:100%;padding:9px 12px;border-radius:10px;border:1px solid var(--line);background:var(--card);font-size:14px;color:var(--text)}
    .btn{background:var(--accent);color:#fff;border:none;padding:9px 12px;border-radius:10px;cursor:pointer;font-weight:700}
    .btn.ghost{background:transparent;border:1px solid var(--line);color:var(--muted)}
    .btn.danger{background:var(--danger);color:#fff}
    .btn.icon{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;padding:0}
    .layout{display:block;margin-top:18px}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
    .card{background:var(--card);padding:14px;border-radius:var(--radius);border:1px solid var(--line);box-shadow:var(--shadow);display:flex;flex-direction:column;min-height:120px}
    /* Minimal UI mode */
    .ui-minimal .logo,
    .ui-minimal .subtitle,
    .ui-minimal .search,
    .ui-minimal select[name="sort"],
    .ui-minimal label[title],
    .ui-minimal .btn.ghost { display:none !important; }
    .ui-minimal .header{position:static}
    .ui-minimal .container{max-width:980px}
    .small{font-size:12px;color:var(--muted)}
    .progress{height:8px;border-radius:999px;background:rgba(100,116,139,0.15);overflow:hidden}
    .progress>i{display:block;height:100%;border-radius:999px;background:var(--accent)}
    .row{display:flex;gap:8px;align-items:center}
    .row.wrap{flex-wrap:wrap}
    .pill{padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid var(--line);background:var(--card)}
    .pill.low{color:#0369a1}
    .pill.medium{color:#365314}
    .pill.high{color:#991b1b}
    .strike{ text-decoration: line-through; color:#94a3b8 }
    details summary{cursor:pointer;color:var(--muted)}
    .toolbar{display:flex;align-items:center;gap:8px}
    .theme-toggle{border:1px solid var(--line);background:var(--card);border-radius:10px}
    .empty{border:1px dashed var(--line);border-radius:var(--radius);padding:20px;text-align:center;color:var(--muted);background:var(--card)}
    @media(prefers-color-scheme: dark){:root:not(.theme-dark){} }
    @media(max-width:1040px){.container{padding:0 14px}}
  </style>
</head>
<body class="theme-init">
<?php include "elements.php"; ?>
<div class="container">
  <div class="header">
    <div class="brand">
      <div class="logo" aria-hidden="true">G</div>
      <div>
        <div class="title">Goals</div>
        <div class="subtitle">фокусуйся, плануй, досягай — 2025</div>
      </div>
    </div>
    <div class="controls toolbar">
      <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <label class="search">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden><path d="M21 21l-4.35-4.35" stroke="#9CA3AF" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <input name="q" value="<?= e($q) ?>" placeholder="Пошук по цілях" aria-label="Search" />
        </label>
        <select name="status" class="input" style="width:160px" title="Статус">
          <option value="all" <?= $status==='all'?'selected':'' ?>>Всі</option>
          <option value="active" <?= $status==='active'?'selected':'' ?>>Активні</option>
          <option value="done" <?= $status==='done'?'selected':'' ?>>Завершені</option>
        </select>
        <input class="input" type="date" name="due_from" value="<?= e($dueFrom) ?>" title="Дедлайн з"/>
        <input class="input" type="date" name="due_to" value="<?= e($dueTo) ?>" title="Дедлайн до"/>
        <select class="input" name="sort" title="Сортування">
          <option value="new" <?= $sort==='new'?'selected':'' ?>>Найновіші</option>
          <option value="old" <?= $sort==='old'?'selected':'' ?>>Найстаріші</option>
          <option value="due_soon" <?= $sort==='due_soon'?'selected':'' ?>>Найближчі дедлайни</option>
          <option value="due_far" <?= $sort==='due_far'?'selected':'' ?>>Найдальші дедлайни</option>
          <option value="title_asc" <?= $sort==='title_asc'?'selected':'' ?>>Заголовок A→Z</option>
          <option value="title_desc" <?= $sort==='title_desc'?'selected':'' ?>>Заголовок Z→A</option>
          <option value="progress_desc" <?= $sort==='progress_desc'?'selected':'' ?>>Прогрес ↓</option>
        </select>
        <label class="row" title="Закріплені перші" style="gap:6px"><input type="checkbox" name="pinnedfirst" value="1" <?= $pinnedFirst? 'checked':'' ?> onchange="this.form.submit()"> Pinned↑</label>
        <button class="btn ghost" type="submit">Застосувати</button>
        <a class="btn ghost" href="<?= e(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? $_SERVER['PHP_SELF']) ?>">Скинути</a>
      </form>
      <button class="btn icon theme-toggle" id="themeToggle" title="Змінити тему" aria-label="Toggle theme">
        <svg id="iconSun" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
        <svg id="iconMoon" style="display:none" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
      </button>
      <button class="btn ghost" id="uiMinimalToggle" title="Згорнути інтерфейс" aria-label="Toggle minimal UI">Minimal UI</button>
      <button class="btn" onclick="document.getElementById('title').focus()">+ New</button>
    </div>
  </div>

  <?php if ($flash): ?>
    <div aria-live="polite" style="margin-top:14px;padding:12px;border-radius:10px;background:var(--card);border:1px solid var(--line); font-weight:600; color:var(--muted);">
      <?= e($flash) ?>
    </div>
  <?php endif; ?>

  <div class="layout">
    <section id="new" style="margin: 16px 0 24px">
      <div class="card">
        <form method="post" class="row wrap" style="padding:12px;gap:10px" autocomplete="off">
          <input name="action" type="hidden" value="create">
          <input type="hidden" name="csrf" value="<?= e($__CSRF) ?>">
          <input id="title" name="title" class="input" placeholder="Нова ціль (наприклад, Вивчити React)" required style="flex:1 1 320px">
          <input name="target_date" type="date" class="input" style="width:180px" title="Дедлайн">
          <select name="priority" class="input" style="width:160px">
            <option value="low">Low</option>
            <option value="medium" selected>Medium</option>
            <option value="high">High</option>
          </select>
          <label class="row" style="gap:6px"><input type="checkbox" name="pinned"> Pinned</label>
          <input name="progress" type="number" min="0" max="100" value="0" class="input" style="width:110px" title="Прогрес %">
          <textarea name="description" class="input" placeholder="Короткий опис (необовʼязково)" style="flex:1 1 100%;min-height:80px"></textarea>
          <div style="margin-left:auto"><button class="btn" type="submit">Створити</button></div>
        </form>
      </div>
    </section>

    <section>
      <?php
        // Summary counts for current filter
        $whereActive = $where; $paramsActive = $params; $whereActive[] = 'is_done = 0';
        $whereDone = $where; $paramsDone = $params; $whereDone[] = 'is_done = 1';
        $sqlActive = 'SELECT COUNT(*) FROM goals '.($whereActive? 'WHERE '.implode(' AND ', $whereActive):'');
        $sqlDone = 'SELECT COUNT(*) FROM goals '.($whereDone? 'WHERE '.implode(' AND ', $whereDone):'');
        $sA=$pdo->prepare($sqlActive); foreach($paramsActive as $k=>$v) $sA->bindValue($k,$v); $sA->execute(); $countActive = (int)$sA->fetchColumn();
        $sD=$pdo->prepare($sqlDone); foreach($paramsDone as $k=>$v) $sD->bindValue($k,$v); $sD->execute(); $countDone = (int)$sD->fetchColumn();
      ?>
      <div class="row" style="justify-content:space-between;margin:10px 2px 14px">
        <div class="small">Всього: <?= $total ?> • Активні: <?= $countActive ?> • Завершені: <?= $countDone ?></div>
        <div class="small">Сортування: <?= e($sort) ?><?= $pinnedFirst? ' • Pinned↑':'' ?></div>
      </div>

      <?php if (!$rows): ?>
        <div class="empty">Нічого не знайдено. Спробуй змінити фільтри або створи нову ціль вище.</div>
      <?php endif; ?>

      <div class="grid">
        <?php foreach ($rows as $row): ?>
          <?php
            $isDone = (int)$row['is_done'] === 1;
            $due = $row['target_date'];
            $overdue = $due && (new DateTime($due) < new DateTime('today')) && !$isDone;
          ?>
          <article class="card" aria-labelledby="goal-<?= (int)$row['id'] ?>" data-id="<?= (int)$row['id'] ?>">
            <div class="row" style="justify-content:space-between">
              <div class="row" style="gap:8px;align-items:flex-start">
                <?php if ((int)$row['pinned'] === 1): ?>
                  <span title="Pinned" aria-label="Pinned" class="small" style="color:var(--accent)">
                    ★
                  </span>
                <?php endif; ?>
                <h3 id="goal-<?= (int)$row['id'] ?>" class="<?= $isDone ? 'strike':'' ?>" style="margin:0;font-size:16px;font-weight:800;">
                  <?= e($row['title']) ?>
                </h3>
              </div>
              <div class="row" style="gap:8px">
                <span class="pill <?= e($row['priority']) ?>"><?= ucfirst($row['priority']) ?></span>
                <?php if ($due): ?><span class="pill" style="color:<?= $overdue?'#991b1b':'#3b82f6' ?>">До: <?= e($due) ?></span><?php endif; ?>
              </div>
            </div>

            <?php if ($row['description']): ?>
              <div class="small" style="margin-top:8px;white-space:pre-line;"><?= e($row['description']) ?></div>
            <?php endif; ?>

            <div style="margin-top:10px">
              <div class="row" style="justify-content:space-between">
                <div class="small">Прогрес</div>
                <div id="progress-pct-<?= (int)$row['id'] ?>" class="small" style="font-weight:700"><?= (int)$row['progress'] ?>%</div>
              </div>
              <div id="progress-bar-<?= (int)$row['id'] ?>" class="progress" style="margin-top:6px"><i style="width: <?= (int)$row['progress'] ?>%"></i></div>
            </div>

            <details style="margin-top:10px">
              <summary>Редагувати</summary>
              <form method="post" class="row wrap" style="margin-top:8px;gap:8px">
                <input type="hidden" name="csrf" value="<?= e($__CSRF) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <input name="title" class="input" value="<?= e($row['title']) ?>" style="flex:1 1 280px">
                <input name="target_date" type="date" class="input" value="<?= e($row['target_date']) ?>" style="width:180px">
                <select name="priority" class="input" style="width:160px">
                  <option value="low" <?= $row['priority']==='low'?'selected':'' ?>>Low</option>
                  <option value="medium" <?= $row['priority']==='medium'?'selected':'' ?>>Medium</option>
                  <option value="high" <?= $row['priority']==='high'?'selected':'' ?>>High</option>
                </select>
                <label class="row" style="gap:6px"><input type="checkbox" name="pinned" <?= (int)$row['pinned']===1?'checked':'' ?>> Pinned</label>
                <label class="row" style="gap:6px">Прогрес % <input data-action="ajax-progress" data-id="<?= (int)$row['id'] ?>" type="number" name="progress" min="0" max="100" value="<?= (int)$row['progress'] ?>" class="input" style="width:110px"></label>
                <textarea name="description" class="input" style="flex:1 1 100%;min-height:80px"><?= e($row['description']) ?></textarea>
                <div style="margin-left:auto"><button class="btn" type="submit">Зберегти</button></div>
              </form>
            </details>

            <div class="row" style="margin-top:12px;gap:8px;justify-content:flex-end">
              <form method="post" data-confirm="Змінити статус?" style="display:inline">
                <input type="hidden" name="csrf" value="<?= e($__CSRF) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button class="btn ghost" type="submit"><?= $isDone ? 'Зробити активною' : 'Позначити виконаною' ?></button>
              </form>
              <form method="post" data-confirm="Видалити ціль?" style="display:inline">
                <input type="hidden" name="csrf" value="<?= e($__CSRF) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button class="btn danger" type="submit">Видалити</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <div style="margin-top:18px;display:flex;justify-content:space-between;align-items:center">
        <div class="small">Всього цілей: <?= $total ?></div>
        <div style="display:flex;gap:8px"><?php if ($page>1): ?><a class="btn ghost" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET,['page'=>$page-1]))) ?>">‹ Prev</a><?php endif; ?><?php if ($page * $perPage < $total): ?><a class="btn ghost" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET,['page'=>$page+1]))) ?>">Next ›</a><?php endif; ?></div>
      </div>
    </section>
  </div>
</div>

<script>
  // CSRF for AJAX
  window.__csrf = <?= json_encode($__CSRF, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

  // Inline progress update via fetch, delete confirmation
  document.addEventListener('DOMContentLoaded', () => {
    // Theme init
    try{
      const root = document.documentElement;
      const saved = localStorage.getItem('goals:theme');
      const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      const dark = saved ? (saved === 'dark') : prefersDark;
      if (dark) root.classList.add('theme-dark');
      const syncIcons = ()=>{
        const isDark = root.classList.contains('theme-dark');
        document.getElementById('iconSun').style.display = isDark ? 'none' : '';
        document.getElementById('iconMoon').style.display = isDark ? '' : 'none';
      };
      syncIcons();
      const btn = document.getElementById('themeToggle');
      if (btn) btn.addEventListener('click', ()=>{ root.classList.toggle('theme-dark'); localStorage.setItem('goals:theme', root.classList.contains('theme-dark') ? 'dark' : 'light'); syncIcons(); });
    }catch(e){}

    // Minimal UI toggle
    try{
      const root = document.documentElement;
      const uiBtn = document.getElementById('uiMinimalToggle');
      if (uiBtn) {
        uiBtn.addEventListener('click', ()=>{
          root.classList.toggle('ui-minimal');
          localStorage.setItem('goals:ui', root.classList.contains('ui-minimal') ? 'minimal' : 'full');
        });
        const savedUi = localStorage.getItem('goals:ui');
        if (savedUi === 'minimal') root.classList.add('ui-minimal');
      }
    }catch(e){}

    // Delegate ajax progress
    document.querySelectorAll('[data-action="ajax-progress"]').forEach(input => {
      input.addEventListener('change', async (e) => {
        const id = e.target.dataset.id;
        const val = Math.max(0, Math.min(100, parseInt(e.target.value||0)));
        e.target.disabled = true;
        try {
          const body = new URLSearchParams();
          body.set('action','ajax_progress');
          body.set('id', id);
          body.set('progress', String(val));
          body.set('csrf', window.__csrf);
          const resp = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body
          });
          const json = await resp.json();
          if (!json.ok) throw new Error(json.error || 'Unknown');
          const bar = document.querySelector(`#progress-bar-${id} > i`);
          if (bar) bar.style.width = json.progress + '%';
          const pct = document.querySelector(`#progress-pct-${id}`);
          if (pct) pct.textContent = json.progress + '%';
        } catch (err) {
          alert('Не вдалося оновити прогрес: ' + (err && err.message ? err.message : err));
        } finally {
          e.target.disabled = false;
        }
      });
    });

    // Confirm on destructive
    document.querySelectorAll('form[data-confirm]').forEach(form => {
      form.addEventListener('submit', (e) => {
        const msg = form.getAttribute('data-confirm') || 'Підтвердити дію?';
        if (!confirm(msg)) e.preventDefault();
      });
    });

    // Shortcuts: n=new, f=focus search
    document.addEventListener('keydown', (e)=>{
      if (e.target && /input|textarea|select/i.test(e.target.tagName)) return;
      if (e.key === 'n' || e.key === 'N') { const el=document.getElementById('title'); if (el) { el.focus(); e.preventDefault(); } }
      if (e.key === 'f' || e.key === 'F') { const el=document.querySelector('.search input'); if (el) { el.focus(); e.preventDefault(); } }
    });
  });
</script>
</body>
</html>

<?php /*
-- SQL (MySQL) — create table `goals`
CREATE TABLE IF NOT EXISTS `goals` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text NULL,
  `target_date` date DEFAULT NULL,
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `progress` tinyint unsigned NOT NULL DEFAULT 0,
  `is_done` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_target_date` (`target_date`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_pinned` (`pinned`),
  KEY `idx_is_done` (`is_done`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
*/ ?>
