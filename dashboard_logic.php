<?php
// dashboard_view.improved.php — refactored + UX improvements (v2)
if (!defined('DASHBOARD_LOADED')) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard — Habits & Progress</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg-0: #071029; --bg-1:#081021; --text:#e6eef8; --muted:#9aa4b2;
  --accent-start:#4CAF50; --accent-mid:#06b6d4; --accent-end:#7c3aed; --cta-purple:#AB47BC;
  --success:#4CAF50; --danger:#ef4444; --glass:rgba(255,255,255,0.02);
  --radius:14px; --shadow:0 10px 30px rgba(2,6,23,0.45);
  --base-font:16px; --small-font:13px;
}
*{box-sizing:border-box}
html,body{height:100%}
body{font-family:Inter,system-ui,Arial,Helvetica,sans-serif;margin:0;padding:20px;background:linear-gradient(180deg,var(--bg-0) 0%, var(--bg-1) 100%);color:var(--text);-webkit-font-smoothing:antialiased;font-size:var(--base-font);}
.container{max-width:1160px;margin:0 auto}
.header{display:flex;justify-content:space-between;align-items:center;gap:12px}
.title{margin:0;font-weight:700;font-size:20px}
.muted{color:var(--muted);font-size:var(--small-font)}
.btn{background:linear-gradient(90deg,var(--accent-end),var(--accent-mid));color:#fff;padding:10px 14px;border-radius:12px;text-decoration:none;display:inline-flex;gap:8px;align-items:center;border:none;cursor:pointer;transition:transform .12s, box-shadow .12s}
.btn:hover{transform:translateY(-3px)}
.btn-secondary{background:transparent;border:1px solid rgba(255,255,255,0.06);color:var(--muted);padding:10px 12px;border-radius:10px}
.btn-primary-green{background:linear-gradient(90deg,var(--accent-start),#2e7d32);color:#fff;padding:10px 12px;border-radius:12px;border:none}
.header-controls{display:flex;gap:8px;align-items:center}
.top-stats{display:flex;gap:16px;margin:18px 0 14px;flex-wrap:wrap;align-items:flex-start}
.stat{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));padding:14px;border-radius:12px;box-shadow:var(--shadow);flex:1;min-width:170px;backdrop-filter: blur(6px);transition:transform .12s}
.stat h3{margin:0 0 6px 0;font-size:14px;font-weight:600}
.stat p{font-size:20px;margin:0;font-weight:800;color:#fff}
.stat .helper{font-size:12px;color:var(--muted);margin-top:6px}
.collapsible{cursor:pointer}
.layout{display:grid;grid-template-columns:1fr 380px;gap:18px}
.main-col{min-width:0}
.card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));padding:12px;border-radius:12px;box-shadow:var(--shadow)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
.habit-card{background:linear-gradient(180deg, rgba(255,255,255,0.01), rgba(255,255,255,0.005));padding:14px;border-radius:14px;box-shadow:0 8px 30px rgba(2,6,23,0.45);cursor:pointer;outline:none;border:1px solid rgba(255,255,255,0.02);transition:transform .14s, box-shadow .14s}
.habit-card:hover{transform:translateY(-6px);box-shadow:0 20px 50px rgba(2,6,23,0.6)}
.habit-title{display:flex;justify-content:space-between;align-items:center;gap:8px}
.small{font-size:var(--small-font);color:var(--muted)}
.big-done { background:var(--success); color:#04281f; width:48px; height:48px; border-radius:50%; border:none; font-weight:800; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; box-shadow:0 8px 20px rgba(2,6,23,0.35); transition:transform .12s }
.big-done:active{ transform:scale(.98) }
.tag-chip { display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; font-size:13px; font-weight:700; color:#04111a; margin-right:6px; background:rgba(255,255,255,0.04) }
.icon-btn{background:transparent;border:1px solid rgba(255,255,255,0.04);padding:10px;border-radius:12px;color:var(--muted);cursor:pointer;transition:transform .12s, background .12s}
.icon-btn:hover{transform:translateY(-3px);border-color:rgba(255,255,255,0.08);background:rgba(255,255,255,0.015)}
.chart-svg{width:100%;height:220px}
.legend { display:flex;gap:12px;align-items:center;font-size:13px;color:var(--muted) }
.weekly-list{display:flex;flex-direction:column;gap:10px}
.weekly-item{display:flex;justify-content:space-between;align-items:center;padding:12px;border-radius:12px;background:rgba(255,255,255,0.01);border:1px solid rgba(255,255,255,0.02)}
.cta-empty { background:linear-gradient(90deg,var(--cta-purple),var(--accent-mid)); color:#fff; padding:12px 14px; border-radius:12px; font-weight:800; cursor:pointer; display:inline-flex; gap:8px; align-items:center; box-shadow:0 8px 30px rgba(2,6,23,0.45) }
.helper-strong { color:#fff; font-weight:700; font-size:13px }
[data-tooltip]{ position:relative; }
[data-tooltip]::after{opacity:0;transition:opacity .18s, transform .18s;transform:translateY(-6px)}
[data-tooltip]:hover::after{opacity:1;transform:translateY(0)}
[data-tooltip]:hover::after{ content: attr(data-tooltip); position: absolute; right: 0; top: -40px; background: rgba(2,6,23,0.9); color: #fff; padding:8px 10px; border-radius:8px; font-size:12px; white-space:nowrap; box-shadow:0 8px 20px rgba(2,6,23,0.6); }
/* confetti canvas overlay */
#confetti-canvas{ position:fixed; left:0; top:0; width:100%; height:100%; pointer-events:none; z-index:9999; }
/* collapse animation */
.collapse { max-height: 9999px; transition: max-height .4s ease; overflow:hidden }
.collapse.hidden { max-height:0 }
/* focus on feed: single column on small screens */
@media (max-width:980px){ .layout{grid-template-columns:1fr;} .grid{grid-template-columns:1fr} .chart-svg{height:160px} }
</style>
</head>
<body>
<div class="container">
    <header class="header">
        <div>
            <h1 class="title">Dashboard — Habits & Progress</h1>
            <div class="muted" style="margin-top:6px">Today: <?php echo e(date('Y-m-d')); ?> • <span id="tz-note" class="small">UTC</span></div>
        </div>
        <div class="header-controls">
            <button id="toggleStats" class="icon-btn" data-tooltip="Collapse/Expand stats">Toggle stats</button>
            <button id="themeToggle" class="icon-btn" data-tooltip="Light / Dark theme">Theme</button>
            <a href="habits.php" class="btn" aria-label="Manage Habits">Manage Habits</a>
            <button id="planWeekBtn" class="btn pulse" title="Plan your weekly tasks">Plan week</button>
        </div>
    </header>

    <section id="statsWrap" class="top-stats collapse" role="region" aria-label="Top statistics">
        <div class="stat collapsible">
            <h3>Total Habits</h3>
            <p id="totalHabits"><?php echo intval($totalHabits); ?></p>
            <div class="helper">Active goals to manage</div>
        </div>

        <div class="stat collapsible">
            <h3>Completed Today</h3>
            <?php if ($completedToday <= 0): ?>
                <p id="completedToday">—</p>
                <div class="helper">
                    Твій старт — <span class="helper-strong">complete your first task</span> сьогодні!
                </div>
            <?php else: ?>
                <p id="completedToday"><?php echo intval($completedToday); ?></p>
                <div class="helper"><?php echo intval($completedToday); ?> / <?php echo intval($totalHabits); ?> tasks</div>
            <?php endif; ?>
        </div>

        <div class="stat collapsible">
            <h3>Day Efficiency</h3>
            <?php if ($totalHabits === 0): ?>
                <p id="efficiencyVal">—</p>
                <div class="helper">Add habits to track progress</div>
            <?php else: ?>
                <p id="efficiencyVal"><?php echo intval($efficiency); ?>%</p>
                <div class="helper">Keep it sustainable — <?php echo intval($efficiency); ?>% complete</div>
            <?php endif; ?>
        </div>

        <div class="stat collapsible">
            <h3>Best Streak</h3>
            <?php if ($longestStreak <= 0): ?>
                <p id="bestStreak">—</p>
                <div class="helper">No streaks yet — build one!</div>
            <?php else: ?>
                <p id="bestStreak"><?php echo intval($longestStreak); ?></p>
                <div class="helper">Longest streak across habits</div>
            <?php endif; ?>
        </div>
    </section>

    <div class="layout">
        <div class="main-col">
            <section class="card" style="margin-bottom:12px" aria-label="Daily progress">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <div>
                        <div class="muted">Daily progress</div>
                        <div style="font-weight:700" id="progressPct"><?php echo ($totalHabits>0) ? intval($efficiency).'%' : '—'; ?></div>
                    </div>
                    <div>
                        <button id="focusFeed" class="btn-secondary" data-tooltip="Focus on tasks">Focus feed</button>
                    </div>
                </div>
                <div class="progress-bar-outer" aria-hidden="true">
                    <div id="progressBar" class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo intval($efficiency); ?>" style="width:<?php echo ($totalHabits>0) ? intval($efficiency).'%' : '0%'; ?>;height:18px;border-radius:999px;background:linear-gradient(90deg,var(--accent-start),#2e7d32)"><?php echo ($totalHabits>0) ? intval($efficiency).'%' : ''; ?></div>
                </div>
            </section>

            <section class="card" aria-label="Efficiency by day">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <strong>Efficiency by day</strong>
                    <div class="legend">
                        <div><span style="width:12px;height:12px;border-radius:3px;display:inline-block;background:linear-gradient(90deg,var(--accent-start),var(--accent-mid));margin-right:6px;"></span> Actual</div>
                        <div><span style="width:12px;height:6px;background:#f97316;display:inline-block;border-radius:2px;margin-right:6px"></span> Predicted</div>
                        <div style="margin-left:10px;color:var(--muted)">Last 7 days</div>
                    </div>
                </div>

                <div id="miniChart" class="chart-svg" role="img" aria-label="Efficiency chart showing last 7 days"></div>
                <div class="muted" style="margin-top:8px;font-size:13px">Hover legend <span data-tooltip="Y-axis shows percent (0–100%). Dashed = forecast">?</span> for details.</div>
            </section>

            <main style="margin-top:12px">
                <?php if (empty($habits)): ?>
                    <div class="card" style="text-align:center;padding:28px">
                        <div style="font-size:18px;font-weight:700;margin-bottom:10px">You have no habits yet</div>
                        <div class="muted" style="margin-bottom:14px">Start by creating a habit — tracking small wins increases retention.</div>
                        <a href="habits.php" class="cta-empty">Create first habit</a>
                    </div>
                <?php else: ?>
                    <div class="grid" id="habitGrid" aria-live="polite">
                        <?php foreach ($habits as $habit):
                            $hid = intval($habit['id']);
                            $isDoneToday = isset($habit['done_today']) ? boolval($habit['done_today']) : (isset($todayMap[$hid]) && $todayMap[$hid]==1);
                            $streak = !empty($habit['streak']) ? intval($habit['streak']) : 0;
                            $category = strtolower(trim($habit['category'] ?? ($habit['tag'] ?? 'general')));
                            $icon = 'ico-general'; if (in_array($category, ['work','health','study','personal'])) { $icon = 'ico-'.$category; }
                            $tagLabel = ucfirst($category);
                        ?>
                        <article draggable="true" class="habit-card" role="article" tabindex="0" aria-labelledby="habit-title-<?php echo $hid; ?>" data-hid="<?php echo $hid; ?>" data-done="<?php echo $isDoneToday?1:0; ?>">
                            <div class="habit-title">
                                <div style="display:flex;align-items:center;gap:10px">
                                    <svg width="36" height="32" aria-hidden><use xlink:href="#<?php echo $icon; ?>"/></svg>
                                    <div>
                                        <div style="display:flex;align-items:center;gap:8px">
                                            <span class="tag-chip"><?php echo e($tagLabel); ?></span>
                                            <h4 id="habit-title-<?php echo $hid; ?>" style="margin:0;font-size:15px"><?php echo e($habit['title']); ?></h4>
                                        </div>
                                        <?php if (!empty($habit['description'])): ?>
                                            <div class="muted" style="margin-top:6px;max-width:48ch;overflow:hidden;text-overflow:ellipsis;font-size:14px"><?php echo e($habit['description']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="text-align:right">
                                    <div class="small muted">Today</div>
                                    <div style="margin-top:6px;font-weight:700" class="small"><?php echo e($today); ?></div>
                                </div>
                            </div>

                            <div style="margin-top:12px;display:flex;gap:8px;align-items:center;justify-content:space-between">
                                <div style="display:flex;gap:12px;align-items:center">
                                    <?php if ($isDoneToday): ?>
                                        <div class="small" aria-live="polite">✅ Completed</div>
                                    <?php else: ?>
                                        <form class="complete-form" method="POST" data-hid="<?php echo $hid; ?>" onsubmit="return false;" style="margin:0">
                                            <input type="hidden" name="action" value="complete">
                                            <input type="hidden" name="habit_id" value="<?php echo $hid; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="button" class="big-done" data-hid="<?php echo $hid; ?>" aria-label="Mark done"><span class="check">✔</span></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($streak>0): ?>
                                        <div class="muted">Streak: <strong><?php echo $streak; ?></strong></div>
                                    <?php endif; ?>
                                </div>

                                <div class="kv">
                                    <button class="icon-btn" data-action="edit" data-hid="<?php echo $hid; ?>" data-tooltip="Редагувати" title="Edit"><svg width="16" height="16" aria-hidden><use xlink:href="#ico-edit"/></svg></button>
                                    <button class="icon-btn" data-action="history" data-hid="<?php echo $hid; ?>" data-tooltip="History" title="History"><svg width="16" height="16" aria-hidden><use xlink:href="#ico-history"/></svg></button>
                                    <button class="icon-btn" data-action="delete" data-hid="<?php echo $hid; ?>" data-tooltip="Видалити" title="Delete"><svg width="16" height="16" aria-hidden><use xlink:href="#ico-delete"/></svg></button>
                                </div>
                            </div>

                            <div id="details-<?php echo $hid; ?>" class="habit-details small" style="margin-top:10px;display:none" aria-hidden="true">
                                <div class="muted"><?php echo !empty($habit['description']) ? e($habit['description']) : 'No description provided.'; ?></div>
                                <div class="habit-actions" style="margin-top:8px">
                                    <a href="habit_history.php?id=<?php echo $hid; ?>" onclick="event.stopPropagation();">View history</a>
                                    <a href="habits.php?edit=<?php echo $hid; ?>" onclick="event.stopPropagation();">Edit</a>
                                    <a href="habits.php?delete=<?php echo $hid; ?>" onclick="event.stopPropagation();return confirm('Delete this habit?');">Delete</a>
                                </div>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>

        <aside style="position:relative">
            <section class="card">
                <h3 style="margin-top:0">This week • Weekly tasks</h3>
                <div class="weekly-list" id="weeklyList">
                    <?php if (!empty($weeklyTasks)): ?>
                        <?php foreach ($weeklyTasks as $w): $wid = intval($w['id']); ?>
                        <div class="weekly-item" data-wid="<?php echo $wid; ?>">
                            <div>
                                <div style="font-weight:700"><?php echo e($w['title']); ?></div>
                                <div class="small muted"><?php echo isset($w['due']) ? e($w['due']) : 'No due' ; ?></div>
                            </div>
                            <div>
                                <button class="icon-btn week-toggle" data-wid="<?php echo $wid; ?>" title="Toggle done"><?php echo (!empty($w['done'])) ? '✅' : '◻'; ?></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align:center;padding:14px">
                            <div style="font-weight:700;margin-bottom:8px">No weekly tasks yet</div>
                            <div class="muted" style="margin-bottom:12px">Plan your week to stay focused — add a task now.</div>
                            <button id="planWeekCTA" class="cta-empty">Start planning now</button>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card" style="margin-top:12px">
                <h4 style="margin:0 0 8px 0">Quick actions</h4>
                <div style="display:flex;flex-direction:column;gap:8px">
                    <button class="btn-primary-green" id="bulkCompleteBtn">Complete all today</button>
                    <button class="btn-secondary" id="exportBtn">Export progress (CSV)</button>
                </div>
            </section>

            <section class="card" style="margin-top:12px">
                <h4 style="margin:0 0 8px 0">Suggested for you</h4>
                <div class="muted">Personalized suggestions coming — algorithm will recommend habits based on your history.</div>
                <div style="margin-top:10px"><button class="btn" id="discoverBtn">Discover recommended habits</button></div>
            </section>
        </aside>
    </div>
</div>

<canvas id="confetti-canvas" aria-hidden="true"></canvas>

<!-- SVG sprite -->
<svg style="display:none">
  <symbol id="ico-general" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12" rx="2" fill="#94a3b8"/></symbol>
  <symbol id="ico-work" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="10" rx="2" fill="#fbbf24"/></symbol>
  <symbol id="ico-health" viewBox="0 0 24 24"><path d="M12 21s-7-4.5-9-7A6 6 0 0 1 12 3a6 6 0 0 1 9 11c-2 2.5-9 7-9 7z" fill="#34d399"/></symbol>
  <symbol id="ico-study" viewBox="0 0 24 24"><path d="M3 6l9 5 9-5v10l-9 5-9-5z" fill="#60a5fa"/></symbol>
  <symbol id="ico-personal" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3" fill="#a78bfa"/><path d="M5 20c1-4 6-6 7-6s6 2 7 6" fill="#7c3aed"/></symbol>
  <symbol id="ico-edit" viewBox="0 0 24 24"><path d="M3 21v-3l11-11 3 3L6 21H3z" fill="#9aa4b2"/></symbol>
  <symbol id="ico-history" viewBox="0 0 24 24"><path d="M12 8v5l3 3" stroke="#9aa4b2" stroke-width="1.5" fill="none"/><path d="M21 12a9 9 0 1 1-3-6.5" stroke="#9aa4b2" stroke-width="1.5" fill="none"/></symbol>
  <symbol id="ico-delete" viewBox="0 0 24 24"><path d="M3 6h18M8 6v14a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2V6M10 6V4h4v2" stroke="#ef4444" stroke-width="1.4" fill="none"/></symbol>
</svg>

<script>
(function(){
    // helpers
    const qs = (s, r=document)=> r.querySelector(s);
    const qsa = (s, r=document)=> Array.from((r||document).querySelectorAll(s));

    try{ document.getElementById('tz-note').textContent = Intl.DateTimeFormat().resolvedOptions().timeZone }catch(e){}

    // initial states
    document.addEventListener('DOMContentLoaded', ()=>{
        // collapse stats initially (focus on feed)
        const stats = document.getElementById('statsWrap');
        if (stats) stats.classList.add('hidden');

        // animate progress bar smoothly
        const pb = document.getElementById('progressBar');
        if (pb){ const val = parseInt(pb.getAttribute('aria-valuenow')||0,10); setTimeout(()=>{ pb.style.transition='width .9s cubic-bezier(.2,.9,.2,1)'; pb.style.width = Math.max(0, Math.min(100, val)) + '%'; },60); }

        // chart
        const labels = <?php echo json_encode($chart_labels ?: []); ?>;
        const values = <?php echo json_encode($chart_values ?: []); ?>;
        const predicted = <?php echo json_encode($predicted !== null ? $predicted : null); ?>;
        renderMiniChart('miniChart', labels, values, predicted);

        // toggles
        qs('#toggleStats').addEventListener('click', ()=>{ stats.classList.toggle('hidden'); });
        qs('#themeToggle').addEventListener('click', toggleTheme);
        qs('#focusFeed').addEventListener('click', ()=>{ // collapse stats and sidebar for focus
            stats.classList.add('hidden'); const aside = document.querySelector('aside'); if (aside) aside.style.display = 'none'; document.querySelector('.layout').style.gridTemplateColumns='1fr'; showFlash('Feed focus: on', 'success');
        });

        // buttons
        qsa('.big-done').forEach(b=> b.addEventListener('click', e=>{ e.preventDefault(); e.stopPropagation(); handleComplete(b); }));
        qsa('.icon-btn[data-action]').forEach(b=> b.addEventListener('click', e=>{ e.stopPropagation(); handleAction(e.currentTarget); }));
        qsa('.week-toggle').forEach(w=> w.addEventListener('click', e=>{ e.preventDefault(); e.stopPropagation(); toggleWeekly(w); }));
        const bulk = qs('#bulkCompleteBtn'); if (bulk) bulk.addEventListener('click', bulkComplete);
        const planWeekBtn = qs('#planWeekBtn'); if (planWeekBtn) planWeekBtn.addEventListener('click', ()=>{ const t = prompt('Add weekly task title'); if (t) createWeekly(t); });
        const planCTA = qs('#planWeekCTA'); if (planCTA) planCTA.addEventListener('click', ()=>{ const t = prompt('Add weekly task title'); if (t) createWeekly(t); });
        const exportBtn = qs('#exportBtn'); if (exportBtn) exportBtn.addEventListener('click', ()=>{ window.location='export.php?type=csv'; });

        // drag & drop reorder
        enableDragReorder();

        // small tactile feedback for mobile
        document.body.addEventListener('pointerdown', function(e){ const t = e.target.closest('button'); if (!t) return; t.style.transform='scale(.99)'; setTimeout(()=>t.style.transform='';,120); });
    });

    // theme toggle - simple variable swap
    function toggleTheme(){ const root=document.documentElement; const light = root.getAttribute('data-theme')==='light'; if (light){ root.removeAttribute('data-theme'); showFlash('Dark theme', 'success'); } else { root.setAttribute('data-theme','light'); showFlash('Light theme', 'success'); }}

    // optimistic complete + confetti + micro animation
    function handleComplete(btn){ const hid = btn.getAttribute('data-hid'); const form = btn.closest('.complete-form'); const token = form ? (form.querySelector('input[name="csrf_token"]')||{}).value : ''; const article = btn.closest('.habit-card'); if (!article) return; // optimistic UI
        markCompletedUI(article); animateDone(btn); updateCountersAfterComplete();
        fetch('/api/habit/complete.php',{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ habit_id:hid, csrf_token:token }) }).then(r=>r.json()).then(j=>{ if (j && j.ok){ if (j.confetti) fireConfetti(); if (j.streak!==undefined){ const st = article.querySelector('.muted strong'); if (st) st.textContent = j.streak; } } else { revertCompleteUI(article); showFlash('Server error', 'error'); } }).catch(err=>{ revertCompleteUI(article); console.error(err); showFlash('Network error', 'error'); }); }

    function animateDone(btn){ // small pop + confetti client fallback
        btn.animate([{ transform:'scale(1)' },{ transform:'scale(1.18)' },{ transform:'scale(1)' }], { duration:350, easing:'cubic-bezier(.2,.9,.2,1)' }); fireConfetti(); }

    // confetti (lightweight)
    function fireConfetti(){ try{ const canvas = document.getElementById('confetti-canvas'); if (!canvas) return; const ctx = canvas.getContext('2d'); canvas.width = window.innerWidth; canvas.height = window.innerHeight; const pieces = []; const colors=['#FF8A65','#FFD54F','#81C784','#4FC3F7','#B39DDB']; for (let i=0;i<60;i++){ pieces.push({ x: Math.random()*canvas.width, y: -20 - Math.random()*200, vx: (Math.random()-0.5)*6, vy: Math.random()*6+2, r: Math.random()*6+4, color: colors[Math.floor(Math.random()*colors.length)], life: Math.random()*80+60 }); }
            let anim;
            function frame(){ ctx.clearRect(0,0,canvas.width,canvas.height); pieces.forEach(p=>{ p.x += p.vx; p.y += p.vy; p.vy += 0.15; p.life--; ctx.fillStyle=p.color; ctx.beginPath(); ctx.ellipse(p.x,p.y,p.r, p.r*0.6, Math.random()*Math.PI, 0, Math.PI*2); ctx.fill(); }); pieces.filter(p=>p.life>0); if (pieces.some(p=>p.life>0)) anim=requestAnimationFrame(frame); else cancelAnimationFrame(anim); }
            frame(); setTimeout(()=>{ ctx.clearRect(0,0,canvas.width,canvas.height); }, 1800);
        }catch(e){ console.warn('confetti failed', e); }
    }

    function markCompletedUI(article){ const existing = article.querySelector('.big-done, .complete-btn'); if (existing) existing.remove(); const done = document.createElement('div'); done.className='small'; done.setAttribute('aria-live','polite'); done.textContent='✅ Completed'; const area = article.querySelector('.habit-title'); if (area) area.appendChild(done); article.setAttribute('data-done','1'); }
    function revertCompleteUI(article){ article.setAttribute('data-done','0'); window.location.reload(); }

    function updateCountersAfterComplete(){ const total = Number(document.getElementById('totalHabits').textContent)||0; const completedEl = document.getElementById('completedToday'); const effEl = document.getElementById('efficiencyVal'); const progressBar = document.getElementById('progressBar'); if (!completedEl) return; let c = (completedEl.textContent.trim()==='—')?0:Number(completedEl.textContent)||0; c = c + 1; completedEl.textContent = c; const eff = Math.round((c/Math.max(1,total))*100); if (effEl) effEl.textContent = eff + '%'; if (progressBar){ progressBar.style.width = eff + '%'; progressBar.textContent = eff + '%'; progressBar.setAttribute('aria-valuenow', eff); } const pct = document.getElementById('progressPct'); if (pct) pct.textContent = eff + '%'; }

    function handleAction(btn){ const action = btn.getAttribute('data-action'); const hid = btn.getAttribute('data-hid'); if (action==='edit') return window.location='habits.php?edit='+hid; if (action==='history') return window.location='habit_history.php?id='+hid; if (action==='delete'){ if (!confirm('Delete this habit?')) return; fetch('/api/habit/delete.php',{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({habit_id:hid, csrf_token:'<?php echo $csrf_token; ?>'}) }).then(r=>r.json()).then(j=>{ if (j && j.ok){ showFlash('Deleted', 'success'); setTimeout(()=>location.reload(),400); } else showFlash('Delete failed', 'error'); }).catch(()=>showFlash('Delete failed','error')); } }

    function toggleWeekly(btn){ const wid = btn.getAttribute('data-wid'); const done = btn.textContent.trim()==='✅'; btn.textContent = done ? '◻' : '✅'; fetch('/api/weekly/toggle.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ id: wid, csrf_token:'<?php echo $csrf_token; ?>' })}).then(r=>r.json()).then(j=>{ if (!(j && j.ok)){ btn.textContent = done ? '✅' : '◻'; showFlash('Could not update weekly task','error'); } }).catch(()=>{ btn.textContent = done ? '✅' : '◻'; showFlash('Could not update weekly task','error'); }); }

    function bulkComplete(){ if (!confirm('Mark all habits completed for today?')) return; fetch('/api/habit/bulk_complete.php',{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ csrf_token:'<?php echo $csrf_token; ?>' }) }).then(r=>r.json()).then(j=>{ if (j && j.ok){ showFlash('All done!','success'); setTimeout(()=>location.reload(),450); } else showFlash('Bulk complete failed','error'); }).catch(()=>showFlash('Bulk complete failed','error')); }
    function createWeekly(title){ fetch('/api/weekly/create.php',{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ title:title, csrf_token:'<?php echo $csrf_token; ?>' }) }).then(r=>r.json()).then(j=>{ if (j && j.ok){ showFlash('Added','success'); setTimeout(()=>location.reload(),300); } else showFlash('Could not add','error'); }).catch(()=>showFlash('Could not add','error')); }

    function showFlash(text, kind){ const d = document.createElement('div'); d.textContent=text; d.style.position='fixed'; d.style.right='18px'; d.style.bottom='18px'; d.style.padding='10px 14px'; d.style.borderRadius='10px'; d.style.zIndex=9999; d.style.background = kind==='success' ? '#064e3b' : '#58151c'; d.style.color='#fff'; document.body.appendChild(d); setTimeout(()=>{ d.style.opacity='0'; d.style.transition='opacity .4s'; setTimeout(()=>d.remove(),400); },2500); }

    // simple drag & drop reorder
    function enableDragReorder(){ let dragSrc=null; qsa('.habit-card').forEach(card=>{
        card.addEventListener('dragstart', function(e){ dragSrc=this; this.style.opacity='0.5'; e.dataTransfer.effectAllowed='move'; });
        card.addEventListener('dragend', function(){ this.style.opacity='1'; });
        card.addEventListener('dragover', function(e){ e.preventDefault(); e.dataTransfer.dropEffect='move'; });
        card.addEventListener('drop', function(e){ e.stopPropagation(); if (dragSrc!==this){ const grid = document.getElementById('habitGrid'); grid.insertBefore(dragSrc, this.nextSibling); // optimistic reorder
                // send order to server
                const ids = Array.from(grid.querySelectorAll('.habit-card')).map(n=>n.getAttribute('data-hid'));
                fetch('/api/habit/reorder.php',{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ order:ids, csrf_token:'<?php echo $csrf_token; ?>' }) }).then(r=>r.json()).then(j=>{ if (!(j && j.ok)) showFlash('Could not save order','error'); }).catch(()=>showFlash('Could not save order','error'));
        } return false; });
    }); }

    // Chart rendering (same as before + draw animation)
    function renderMiniChart(containerId, labels, values, predicted){ const container = document.getElementById(containerId); if (!container) return; container.innerHTML=''; labels = labels||[]; values=(values||[]).map(v=>Number(v)||0); if (labels.length===0){ container.innerHTML='<div class="muted">No tracking data yet.</div>'; return; }
        const W = Math.max(container.clientWidth||600,420); const H=220; const pad={l:50,r:16,t:12,b:34}; const innerW=W-pad.l-pad.r; const innerH=H-pad.t-pad.b; const maxY=100;
        const xPos=i=>pad.l+(innerW)* (i/Math.max(labels.length-1,1)); const yPos=v=>pad.t+innerH - ((Math.max(0,Math.min(maxY,v))/maxY)*innerH);
        const svgns='http://www.w3.org/2000/svg'; const svg=document.createElementNS(svgns,'svg'); svg.setAttribute('width','100%'); svg.setAttribute('viewBox','0 0 '+W+' '+H);
        // defs gradient
        const defs=document.createElementNS(svgns,'defs'); const gid='g'+Math.floor(Math.random()*999999); const lg=document.createElementNS(svgns,'linearGradient'); lg.setAttribute('id',gid); lg.setAttribute('x1','0%'); lg.setAttribute('x2','100%'); const st1=document.createElementNS(svgns,'stop'); st1.setAttribute('offset','0%'); st1.setAttribute('stop-color',getComputedStyle(document.documentElement).getPropertyValue('--accent-start')||'#4CAF50'); const st2=document.createElementNS(svgns,'stop'); st2.setAttribute('offset','60%'); st2.setAttribute('stop-color',getComputedStyle(document.documentElement).getPropertyValue('--accent-mid')||'#06b6d4'); const st3=document.createElementNS(svgns,'stop'); st3.setAttribute('offset','100%'); st3.setAttribute('stop-color',getComputedStyle(document.documentElement).getPropertyValue('--accent-end')||'#7c3aed'); lg.appendChild(st1); lg.appendChild(st2); lg.appendChild(st3); defs.appendChild(lg); svg.appendChild(defs);
        for (let t=0;t<=4;t++){ const yy=pad.t+(innerH/4)*t; const line=document.createElementNS(svgns,'line'); line.setAttribute('x1',pad.l); line.setAttribute('x2',pad.l+innerW); line.setAttribute('y1',yy); line.setAttribute('y2',yy); line.setAttribute('stroke','rgba(255,255,255,0.03)'); line.setAttribute('stroke-width','1'); svg.appendChild(line); const lab=document.createElementNS(svgns,'text'); lab.setAttribute('x',pad.l-10); lab.setAttribute('y',yy+4); lab.setAttribute('font-size','11'); lab.setAttribute('text-anchor','end'); lab.setAttribute('fill','rgba(255,255,255,0.6)'); lab.textContent=(100-(t*25))+'%'; svg.appendChild(lab); }
        let d=''; for (let i=0;i<values.length;i++){ const xi=xPos(i), yi=yPos(values[i]); d += (i===0? 'M '+xi+' '+yi : ' L '+xi+' '+yi); }
        const path=document.createElementNS(svgns,'path'); path.setAttribute('d',d); path.setAttribute('fill','none'); path.setAttribute('stroke','url(#'+gid+')'); path.setAttribute('stroke-width','3'); path.setAttribute('stroke-linecap','round'); path.setAttribute('style','stroke-dasharray:1000;stroke-dashoffset:1000;animation: dash 1s forwards'); svg.appendChild(path);
        svg.style.overflow='visible';
        for (let j=0;j<values.length;j++){ const cx=xPos(j), cy=yPos(values[j]); const circle=document.createElementNS(svgns,'circle'); circle.setAttribute('cx',cx); circle.setAttribute('cy',cy); circle.setAttribute('r',4.5); circle.setAttribute('fill','#081021'); circle.setAttribute('stroke',getComputedStyle(document.documentElement).getPropertyValue('--accent-start')||'#34d399'); circle.setAttribute('stroke-width',1.6); svg.appendChild(circle); }
        if (predicted!==null && !isNaN(predicted)){ const lastIdx=values.length-1; const lastX=xPos(lastIdx); const lastY=yPos(values[lastIdx]); const step=(labels.length>1)?(innerW/(labels.length-1)):Math.min(40,innerW*0.15); const nextX=lastX+step; const nextY=yPos(predicted); const dashLine=document.createElementNS(svgns,'line'); dashLine.setAttribute('x1',lastX); dashLine.setAttribute('y1',lastY); dashLine.setAttribute('x2',nextX); dashLine.setAttribute('y2',nextY); dashLine.setAttribute('stroke','#f97316'); dashLine.setAttribute('stroke-width','2'); dashLine.setAttribute('stroke-dasharray','6 6'); svg.appendChild(dashLine); const pCirc=document.createElementNS(svgns,'circle'); pCirc.setAttribute('cx',nextX); pCirc.setAttribute('cy',nextY); pCirc.setAttribute('r',5); pCirc.setAttribute('fill','#f97316'); pCirc.setAttribute('stroke','#081021'); pCirc.setAttribute('stroke-width',1.5); svg.appendChild(pCirc); const txt=document.createElementNS(svgns,'text'); txt.setAttribute('x',nextX); txt.setAttribute('y',Math.max(12,nextY-10)); txt.setAttribute('font-size','11'); txt.setAttribute('text-anchor','middle'); txt.setAttribute('fill','#f97316'); txt.textContent=(predicted||0)+'%'; svg.appendChild(txt); }
        for (let i=0;i<labels.length;i++){ const x=xPos(i); const text=document.createElementNS(svgns,'text'); text.setAttribute('x',x); text.setAttribute('y',H-8); text.setAttribute('font-size','10'); text.setAttribute('text-anchor','middle'); text.setAttribute('fill','rgba(255,255,255,0.6)'); let lbl=labels[i]; const parts=String(lbl).split('-'); if (parts.length===3) lbl = parts[1]+'-'+parts[2]; text.textContent=lbl; svg.appendChild(text); }
        container.appendChild(svg);
    }

})();
</script>

</body>
</html>
