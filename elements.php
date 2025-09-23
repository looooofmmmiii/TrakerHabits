<?php
// elements.php — improved, accessible, responsive sidebar (v2)
// Fixed bugs: proper content shift for collapsed state, hide quick-search when collapsed,
// sticky footer spacing, robust mobile overlay, and safer z-index handling.
// Usage: same as before. Set $sidebar_mode = 'inline' before include to embed.

declare(strict_types=1);
require_once __DIR__ . '/config/db.php'; // expects $pdo (PDO)
if (session_status() === PHP_SESSION_NONE) {@session_start();}
if (!function_exists('clear_cookie')) {function clear_cookie(string $name): void {setcookie($name, '', time() - 3600, '/'); if (isset($_COOKIE[$name])) unset($_COOKIE[$name]);}}
$sidebar_mode = $sidebar_mode ?? 'fixed'; $allowed = ['fixed','inline']; if (!in_array($sidebar_mode,$allowed,true)) $sidebar_mode='fixed';

// --- Auto-login remember cookie (defensive) ---
$lifetimeDays = 365; $lifetime = $lifetimeDays * 24 * 60 * 60; $rememberCookieName = 'remember_me'; $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (empty($_SESSION['user_id']) && !empty($_COOKIE[$rememberCookieName])){
    $cookie = $_COOKIE[$rememberCookieName];
    if (strpos($cookie, ':') !== false){ list($selector,$validator)=explode(':',$cookie,2);
        if (preg_match('/^[a-f0-9]{8,}$/i',$selector)){
            $stmt = $pdo->prepare("SELECT id,user_id,validator_hash,expires_at FROM user_remember_tokens WHERE selector = ? LIMIT 1");
            $stmt->execute([$selector]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                $expires = strtotime((string)$row['expires_at']);
                $validatorHash = (string)$row['validator_hash'];
                if ($expires > time() && hash_equals($validatorHash, hash('sha256', $validator))){ $_SESSION['user_id'] = (int)$row['user_id']; $_SESSION['last_activity']=time(); }
                else clear_cookie($rememberCookieName);
            } else clear_cookie($rememberCookieName);
        } else clear_cookie($rememberCookieName);
    } else clear_cookie($rememberCookieName);
}
// goals (global active count; computed below after other counters)

mb_internal_encoding('UTF-8');
$user = ['id'=>null,'raw_name'=>'Guest','name'=>'Guest','email'=>'','role'=>'User'];
if (!empty($_SESSION['user_id'])){
    $uid = (int)$_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1"); $stmt->execute([$uid]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row){
        $possibleNameKeys=['name','username','user','full_name','display_name','first_name','email']; $displayNameRaw=null;
        foreach ($possibleNameKeys as $k){ if (isset($row[$k]) && is_string($row[$k]) && mb_strlen(trim((string)$row[$k]))>0){ $displayNameRaw = trim((string)$row[$k]); break; }}
        if ($displayNameRaw===null) $displayNameRaw='User';
        $user = ['id'=>isset($row['id'])?(int)$row['id']:$uid,'raw_name'=>$displayNameRaw,'name'=>htmlspecialchars($displayNameRaw,ENT_QUOTES,'UTF-8'),'email'=>isset($row['email'])?htmlspecialchars((string)$row['email'],ENT_QUOTES,'UTF-8'):'','role'=>isset($row['role'])?htmlspecialchars((string)$row['role'],ENT_QUOTES,'UTF-8'):'User'];
    } else { unset($_SESSION['user_id']); }
}
$current_page = basename($_SERVER['PHP_SELF']);
$initial='G'; if (!empty($user['raw_name'])){ if(function_exists('mb_substr') && function_exists('mb_strtoupper')){ $ch = mb_substr($user['raw_name'],0,1,'UTF-8'); $initial = mb_strtoupper($ch,'UTF-8'); } else { $initial = strtoupper(substr($user['raw_name'],0,1)); } $initial = htmlspecialchars($initial,ENT_QUOTES,'UTF-8'); }



// goals (global active count; schema may be single-user)
try { $s=$pdo->query('SELECT COUNT(*) FROM goals WHERE is_done = 0'); $activeGoals=(int)$s->fetchColumn(); } catch (Throwable $e){ $activeGoals=0; }
// Counts for badges (best-effort, tolerate missing tables)
try { $activeHabits=(int)($pdo->query('SELECT COUNT(*) FROM habits')->fetchColumn() ?? 0); } catch (Throwable $e){ $activeHabits=0; }
try { $pendingTasks=(int)($pdo->query('SELECT COUNT(*) FROM tasks WHERE is_done = 0')->fetchColumn() ?? 0); } catch (Throwable $e){ $pendingTasks=0; }
if (!isset($unreadNotifications)) { $unreadNotifications = 0; }

if (!defined('SIDEBAR_CSS_INCLUDED')){ define('SIDEBAR_CSS_INCLUDED',true); ?>
<style>
:root{ --myapp-sidebar-full:250px; --myapp-sidebar-collapsed:72px; }
.myapp-sidebar{
  box-sizing:border-box;
  font-family:Inter,Segoe UI,Roboto,sans-serif;
  font-size:15px;
  font-weight:500;
  color:#374151;
  background:#fff;
  border-right:1px solid #e5e7eb;
}
.myapp-sidebar--fixed{
  position:fixed;
  top:0;left:0;
  height:100vh;
  width:var(--myapp-sidebar-full);
  display:flex;flex-direction:column;
  z-index:60;
  box-shadow:0 1px 0 rgba(16,24,40,0.02);
  transition:width .14s;
}
.myapp-sidebar--inline{
  position:relative;
  width:var(--myapp-sidebar-full);
  display:flex;flex-direction:column;
  border-radius:8px;
  box-shadow:0 1px 3px rgba(16,24,40,0.03);
  overflow:hidden;
}
.myapp-sidebar .sidebar-header{
  display:flex;align-items:center;justify-content:space-between;
  padding:12px 14px;
  border-bottom:1px solid #f3f4f6;
  font-size:16px;font-weight:700;color:#111827;
}
.myapp-sidebar .sidebar-header .brand{
  display:flex;align-items:center;gap:8px;
  flex:1;overflow:hidden;
}
.myapp-sidebar .sidebar-header button{
  min-width:32px;height:32px;line-height:1;
  background:none;border:0;cursor:pointer;
  padding:6px;font-size:16px;
}
.myapp-sidebar .sidebar-menu{
  display:flex;flex-direction:column;
  padding:10px;overflow:auto;gap:4px;
}
.myapp-sidebar a.nav-item{
  display:flex;align-items:center;gap:10px;
  padding:9px 10px;margin:3px 0;
  text-decoration:none;color:#374151;
  border-radius:8px;transition:all .12s;
}
.myapp-sidebar a.nav-item:hover{background:#f3f4f6}
.myapp-sidebar a.nav-item.active{
  background:#e0e7ff;color:#1e40af;font-weight:600;
}
.myapp-sidebar .icon{
  width:28px;display:inline-flex;align-items:center;justify-content:center;font-size:16px;
}
.myapp-sidebar .label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.myapp-sidebar .badge{ display:none }

/* avatar always circle */
.myapp-sidebar .avatar{
  background:#2563eb;
  color:#fff;
  border-radius:50%;
  width:40px;
  height:40px;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:15px;
  font-weight:700;
  flex-shrink:0;
}

.myapp-sidebar .sidebar-footer-wrapper{padding:10px;margin-top:auto}
.myapp-sidebar .sidebar-footer{
  display:flex;
  align-items:center;
  gap:10px;
  padding:8px 12px;
  border-radius:10px;
  text-decoration:none;
  transition:background .12s;
  cursor:pointer;
  white-space:nowrap;
  overflow:hidden;
}
.myapp-sidebar .sidebar-footer:hover{background:#f3f4f6}
.myapp-sidebar .sidebar-footer .info{
  min-width:0;overflow:hidden;
}
.myapp-sidebar .sidebar-footer .info .name,
.myapp-sidebar .sidebar-footer .info .role{
  text-overflow:ellipsis;overflow:hidden;white-space:nowrap;
}

/* collapsed state */
.myapp-sidebar.collapsed{width:var(--myapp-sidebar-collapsed)}
.myapp-sidebar.collapsed .label{display:none}
.myapp-sidebar.collapsed .toggle-text{display:none}
.myapp-sidebar.collapsed .quick-search{display:none}
.myapp-sidebar.collapsed .sidebar-footer{
  justify-content:center;
  padding:10px 0;
  border-radius:0;
}
.myapp-sidebar.collapsed .sidebar-footer .info{display:none}

/* mobile overlay */
.myapp-sidebar-overlay{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,0.35);z-index:59;
}
.myapp-sidebar-overlay.open{display:block}

/* small screens */
@media (max-width:768px){
  .myapp-sidebar--fixed{
    transform:translateX(-100%);
    transition:transform .18s ease;width:260px;
  }
  .myapp-sidebar--fixed.open{transform:translateX(0)}
}

/* helper class for main content shift */
.myapp-main-shift{
  margin-left:var(--myapp-sidebar-width, var(--myapp-sidebar-full));
  transition:margin-left .12s;
}

/* search input */
.myapp-sidebar .quick-search{display:flex;padding:8px}
.myapp-sidebar .quick-search input{
  flex:1;padding:8px;
  border:1px solid #e5e7eb;border-radius:8px;font-size:14px;
}
</style>


<?php }

$sidebar_classes = 'myapp-sidebar ' . ($sidebar_mode === 'inline' ? 'myapp-sidebar--inline' : 'myapp-sidebar--fixed');
$menu = [ ['href'=>'dashboard.php','icon'=>'📊','label'=>'Dashboard','badge'=>0], ['href'=>'roulette.php','icon'=>'🎲','label'=>'Roulette','badge'=>0], ['href'=>'habits.php','icon'=>'🔥','label'=>'Habits','badge'=>$activeHabits], ['href'=>'tasks.php','icon'=>'✅','label'=>'Tasks','badge'=>$pendingTasks], ['href'=>'kanban.php','icon'=>'🗂','label'=>'Kanban','badge'=>0], ['href'=>'thoughts.php','icon'=>'💭','label'=>'Thoughts','badge'=>0], ['href'=>'notification.php','icon'=>'🔔','label'=>'Notifications','badge'=>$unreadNotifications] ];
?>

<?php
// Override and enhance menu with icons and Goals link
$icoDashboard = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 10l9-7 9 7v9a2 2 0 0 1-2 2h-4a2 2 0 0 1-2-2v-5H9v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-9z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$icoRoulette = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 3v9l6.5 6.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
$icoHabits = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 11l3 3L22 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
$icoTasks = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
$icoKanban = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="4" width="6" height="16" rx="1.5" stroke="currentColor" stroke-width="1.6"/><rect x="10.5" y="4" width="4.5" height="10" rx="1.5" stroke="currentColor" stroke-width="1.6"/><rect x="16" y="4" width="5" height="7" rx="1.5" stroke="currentColor" stroke-width="1.6"/></svg>';
$icoThoughts = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3 1.5-4.5A8 8 0 1 1 21 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$icoJournal = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 4h11a3 3 0 0 1 3 3v13H7a2 2 0 0 1-2-2V4z" stroke="currentColor" stroke-width="1.6"/><path d="M7 8h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
$icoGoals = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 22V3m0 0l7 3-7 3-7-3 7-3z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$icoCommunity = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="7" cy="8" r="3" stroke="currentColor" stroke-width="1.6"/><circle cx="17" cy="8" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M2 19c0-3 3-5 5-5h2c2 0 5 2 5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M10 19c0-2 2-3 3-3h1c1 0 3 1 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
$icoBell = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14v-3a6 6 0 0 0-12 0v3a2 2 0 0 1-.6 1.4L4 17h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M10 21a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';

$menu = [
  ['href'=>'dashboard.php','icon'=>$icoDashboard,'label'=>'Dashboard','badge'=>$pendingTasks],
  ['href'=>'habits.php','icon'=>$icoHabits,'label'=>'Habits','badge'=>$activeHabits],
  ['href'=>'goals.php','icon'=>$icoGoals,'label'=>'Goals','badge'=>$activeGoals],
  ['href'=>'tasks.php','icon'=>$icoTasks,'label'=>'Tasks','badge'=>$pendingTasks],
  ['href'=>'kanban.php','icon'=>$icoKanban,'label'=>'Kanban','badge'=>$pendingTasks],
  ['href'=>'journal.php','icon'=>$icoJournal,'label'=>'Journal','badge'=>0],
  ['href'=>'thoughts.php','icon'=>$icoThoughts,'label'=>'Thoughts','badge'=>0],
  ['href'=>'community.php','icon'=>$icoCommunity,'label'=>'Community','badge'=>0],
  ['href'=>'roulette.php','icon'=>$icoRoulette,'label'=>'Roulette','badge'=>0],
];
?>

<div id="myapp-sidebar-overlay" class="myapp-sidebar-overlay" tabindex="-1" aria-hidden="true"></div>
<div id="myapp-sidebar" class="<?= $sidebar_classes ?>" role="complementary" aria-label="Main sidebar" data-sidebar-mode="<?= $sidebar_mode ?>">
  <div>
    <div class="sidebar-header">
      <div class="brand">
        <div style="font-weight:700;color:#111827">Better Life</div>
      </div>
      <div style="display:flex;align-items:center;gap:6px">
        <button id="myapp-sidebar-toggle" aria-expanded="true" aria-controls="myapp-sidebar" title="Toggle sidebar" style="background:none;border:0;cursor:pointer;padding:6px;font-size:16px">☰</button>
      </div>
    </div>

    <form class="quick-search" action="search.php" method="get" role="search" aria-label="Quick search">
      <input type="search" name="q" placeholder="Search…" aria-label="Search" />
    </form>

    <nav class="sidebar-menu" aria-label="Main navigation">
      <?php foreach ($menu as $item): $isActive = $current_page === basename($item['href']); $badge = isset($item['badge']) && $item['badge']>0 ? (int)$item['badge'] : 0; ?>
      <a href="<?= htmlspecialchars($item['href'],ENT_QUOTES,'UTF-8') ?>" class="nav-item<?= $isActive ? ' active' : '' ?>" tabindex="0">
        <span class="icon" aria-hidden="true"><?= $item['icon'] ?></span>
        <span class="label"><?= htmlspecialchars($item['label'],ENT_QUOTES,'UTF-8') ?></span>
        <?php if ($badge): ?><span class="badge" aria-label="<?= $badge ?> new items"><?= $badge ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <div class="sidebar-footer-wrapper">
    <a href="<?= !empty($user['id']) ? 'profile.php' : 'login.php' ?>" class="sidebar-footer" tabindex="0">
      <div class="avatar"><?= $initial ?></div>
      <div class="info">
        <div class="name"><?= $user['name'] ?></div>
        <div class="role"><?= $user['role'] ?></div>
      </div>
    </a>
  </div>
</div>

<script>
(function(){
  const sidebar = document.getElementById('myapp-sidebar');
  const overlay = document.getElementById('myapp-sidebar-overlay');
  const toggle = document.getElementById('myapp-sidebar-toggle');
  const STORAGE_KEY = 'myapp_sidebar_collapsed';
  const FULL_W = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--myapp-sidebar-full')) || 250;
  const COLLAPSED_W = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--myapp-sidebar-collapsed')) || 72;

  function isSmall(){ return window.matchMedia('(max-width:768px)').matches; }

  function applySavedState(){
    try{
      const saved = localStorage.getItem(STORAGE_KEY);
      if(saved === '1'){
        sidebar.classList.add('collapsed');
        toggle.setAttribute('aria-expanded','false');
        document.documentElement.style.setProperty('--myapp-sidebar-width', COLLAPSED_W + 'px');
      } else {
        sidebar.classList.remove('collapsed');
        toggle.setAttribute('aria-expanded','true');
        document.documentElement.style.setProperty('--myapp-sidebar-width', FULL_W + 'px');
      }
    }catch(e){ document.documentElement.style.setProperty('--myapp-sidebar-width', FULL_W + 'px'); }
  }
  applySavedState();

  // Make sure main content is shifted according to current state (unless small screen)
  function applyMainShift(){
    if(!isSmall()){
      const widthVar = getComputedStyle(document.documentElement).getPropertyValue('--myapp-sidebar-width') || FULL_W + 'px';
      document.body.classList.add('myapp-main-shift');
      document.documentElement.style.setProperty('--myapp-sidebar-width', widthVar.trim());
    } else {
      document.body.classList.remove('myapp-main-shift');
    }
  }
  applyMainShift();

  toggle.addEventListener('click', function(e){
    if(isSmall()){
      sidebar.classList.add('open'); overlay.classList.add('open'); overlay.setAttribute('aria-hidden','false'); sidebar.setAttribute('aria-hidden','false'); toggle.setAttribute('aria-expanded','true'); document.body.style.overflow='hidden';
    } else {
      const collapsed = sidebar.classList.toggle('collapsed');
      toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      try{ localStorage.setItem(STORAGE_KEY, collapsed ? '1':'0'); }catch(e){}
      document.documentElement.style.setProperty('--myapp-sidebar-width', collapsed ? COLLAPSED_W + 'px' : FULL_W + 'px');
      applyMainShift();
    }
  });

  overlay.addEventListener('click', function(){ sidebar.classList.remove('open'); overlay.classList.remove('open'); overlay.setAttribute('aria-hidden','true'); document.body.style.overflow=''; });

  document.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ if(sidebar.classList.contains('open')){ sidebar.classList.remove('open'); overlay.classList.remove('open'); overlay.setAttribute('aria-hidden','true'); document.body.style.overflow=''; } } });

  window.addEventListener('resize', function(){ if(!isSmall()){ sidebar.classList.remove('open'); overlay.classList.remove('open'); overlay.setAttribute('aria-hidden','true'); document.body.style.overflow=''; applyMainShift(); } else { document.body.classList.remove('myapp-main-shift'); } });

})();
</script>

