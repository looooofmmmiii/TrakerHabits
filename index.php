<?php
// index.php — Modernized landing for TrackerHabits (UPDATED: style fixes, Alpine bindings, accessibility)
// Save this file to the repo root (replaces existing index.php)
?>
<!doctype html>
<html lang="en" x-data="app()" :class="{ 'dark': dark }" x-init="init()">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>TrackerHabits — Build Better Habits</title>
  <meta name="description" content="TrackerHabits — Lightweight habit tracker focused on simplicity, progress and motivation." />
  <!-- Inter font for nicer typography -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    :root { --max-width: 1120px; }
    html,body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial; }
    /* small custom tweaks without breaking Tailwind CDN use */
    .glass { background: linear-gradient( rgba(255,255,255,0.55), rgba(255,255,255,0.35) ); backdrop-filter: blur(6px); }
    .dark .glass { background: linear-gradient( rgba(15,23,42,0.55), rgba(15,23,42,0.35) ); }
    .fancy-underline { background-image: linear-gradient(90deg,#7c3aed,#06b6d4); background-repeat:no-repeat; background-size:100% 0.28em; background-position:0 90%; }
    /* subtle entrance animation */
    .appear { transform: translateY(10px); opacity: 0; transition: all 500ms cubic-bezier(.2,.9,.3,1); }
    .appear.show { transform: translateY(0); opacity: 1; }
    /* ensure focus styles are visible */
    :focus { outline: 3px solid rgba(99,102,241,0.15); outline-offset: 2px; }
    /* keep layout max width consistent */
    .site-max { max-width: 1120px; }
  </style>
</head>
<body class="antialiased text-slate-900 dark:text-slate-100 bg-gradient-to-br from-indigo-50 via-white to-sky-50 dark:from-slate-900 dark:via-slate-800 dark:to-slate-900 min-h-screen">

<!-- NAV -->
<header class="sticky top-0 z-40 backdrop-blur-sm glass border-b border-transparent dark:border-slate-800">
  <div class="site-max mx-auto px-6 py-4 flex items-center justify-between">
    <a href="#" class="flex items-center gap-3 no-underline">
      <div class="w-10 h-10 bg-gradient-to-tr from-indigo-500 to-cyan-400 rounded-lg flex items-center justify-center text-white font-bold shadow-lg">TH</div>
      <div>
        <div class="text-lg font-extrabold text-slate-900 dark:text-white">TrackerHabits</div>
        <div class="text-xs text-slate-500 dark:text-slate-300">Minimal • Reliable • Focused</div>
      </div>
    </a>

    <nav class="hidden md:flex items-center gap-6 text-sm">
      <a href="#features" class="hover:text-indigo-500 focus:text-indigo-500">Features</a>
      <a href="#how" class="hover:text-indigo-500 focus:text-indigo-500">How it works</a>
      <a href="#pricing" class="hover:text-indigo-500 focus:text-indigo-500">Pricing</a>
      <a href="dashboard.php" class="ml-2 bg-indigo-600 text-white px-4 py-2 rounded-md shadow hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-400">Open App</a>
      <button @click="toggleDark" class="ml-3 p-2 rounded-md border dark:border-slate-700 bg-white/60 dark:bg-slate-800/60" aria-label="Toggle color theme">
        <span x-text="dark ? '🌙' : '☀️'"></span>
      </button>
    </nav>

    <div class="md:hidden flex items-center gap-2">
      <button @click="toggleDark" class="p-2 rounded-md bg-white/60 dark:bg-slate-800/60" aria-label="Toggle color theme"><span x-text="dark ? '🌙' : '☀️'"></span></button>
      <button @click="mobileOpen = !mobileOpen" class="p-2 rounded-md border dark:border-slate-700 bg-white/40 dark:bg-slate-800/40" aria-label="Toggle menu">☰</button>
    </div>
  </div>
  <!-- Mobile menu -->
  <div x-show="mobileOpen" x-transition class="md:hidden px-6 pb-4">
    <div class="flex flex-col gap-2">
      <a href="#features" class="py-2">Features</a>
      <a href="#how" class="py-2">How it works</a>
      <a href="#pricing" class="py-2">Pricing</a>
      <a href="dashboard.php" class="py-2 bg-indigo-600 text-white rounded-md text-center">Open App</a>
    </div>
  </div>
</header>

<!-- HERO -->
<section class="site-max mx-auto px-6 py-20 grid grid-cols-1 lg:grid-cols-2 gap-10 items-center">
  <div>
    <p class="uppercase text-sm tracking-wider text-indigo-600 font-semibold">Simple. Focused. Effective.</p>
    <h1 class="mt-6 text-4xl md:text-5xl font-extrabold leading-tight text-slate-900 dark:text-white">
      Build higher-performing habits
      <span class="fancy-underline">without the noise</span>
    </h1>
    <p class="mt-6 text-lg text-slate-600 dark:text-slate-300 max-w-xl">TrackerHabits helps you create, track and keep momentum. Minimal interface, meaningful stats, and fast daily flow — designed so habits actually stick.</p>

    <div class="mt-8 flex flex-wrap gap-3">
      <a href="signup.php" class="inline-flex items-center gap-3 bg-gradient-to-r from-indigo-600 to-cyan-500 text-white px-5 py-3 rounded-xl shadow-lg hover:scale-[1.02] transition focus:outline-none focus:ring-2 focus:ring-indigo-400">Get started — it's free</a>
      <a href="#features" class="inline-flex items-center gap-2 border border-slate-200 dark:border-slate-700 px-4 py-3 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-200">See features</a>
    </div>

    <div class="mt-8 grid grid-cols-3 gap-4 max-w-sm">
      <div class="p-3 bg-white dark:bg-slate-800 rounded-xl shadow text-center">
        <div class="text-2xl font-bold">0.9s</div>
        <div class="text-xs text-slate-500 dark:text-slate-300">Avg daily flow</div>
      </div>
      <div class="p-3 bg-white dark:bg-slate-800 rounded-xl shadow text-center">
        <div class="text-2xl font-bold">+12%</div>
        <div class="text-xs text-slate-500 dark:text-slate-300">Avg week retention</div>
      </div>
      <div class="p-3 bg-white dark:bg-slate-800 rounded-xl shadow text-center">
        <div class="text-2xl font-bold">Minimal</div>
        <div class="text-xs text-slate-500 dark:text-slate-300">Zero fluff UI</div>
      </div>
    </div>
  </div>

  <!-- Mockup card -->
  <div class="flex justify-center lg:justify-end">
    <div class="w-full max-w-md p-6 rounded-2xl shadow-2xl glass border border-transparent dark:border-slate-700">
      <div class="flex items-center justify-between mb-4">
        <div class="text-sm font-semibold">Today</div>
        <div class="text-xs text-slate-500 dark:text-slate-300">Aug <?php echo date('j'); ?>, <?php echo date('Y'); ?></div>
      </div>
      <div class="space-y-3">
        <div class="p-3 rounded-lg bg-white dark:bg-slate-900 shadow">
          <div class="flex items-center justify-between">
            <div>
              <div class="font-medium text-slate-900 dark:text-slate-100">Morning Run</div>
              <div class="text-xs text-slate-500 dark:text-slate-400">Daily • 30 mins</div>
            </div>
            <button class="px-3 py-1 bg-emerald-500 text-white rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-emerald-300">Done</button>
          </div>
        </div>
        <div class="p-3 rounded-lg bg-white dark:bg-slate-900 shadow">
          <div class="flex items-center justify-between">
            <div>
              <div class="font-medium text-slate-900 dark:text-slate-100">Read 20 pages</div>
              <div class="text-xs text-slate-500 dark:text-slate-400">Daily • 20 pages</div>
            </div>
            <button class="px-3 py-1 border border-slate-200 dark:border-slate-700 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-indigo-200">Mark</button>
          </div>
        </div>
        <div class="p-3 rounded-lg bg-white dark:bg-slate-900 shadow">
          <div class="flex items-center justify-between">
            <div>
              <div class="font-medium text-slate-900 dark:text-slate-100">No Sugar</div>
              <div class="text-xs text-slate-500 dark:text-slate-400">Weekly</div>
            </div>
            <div class="text-xs text-slate-500 dark:text-slate-400">2 / 7</div>
          </div>
        </div>
      </div>

      <div class="mt-4 text-xs text-slate-500 dark:text-slate-400">Quick actions: add habit • reorder • view history</div>
    </div>
  </div>
</section>

<!-- FEATURES -->
<section id="features" class="site-max mx-auto px-6 py-16">
  <h2 class="text-3xl font-bold text-center text-slate-900 dark:text-white">Features that keep you consistent</h2>
  <p class="text-center text-slate-500 dark:text-slate-400 mt-3 max-w-2xl mx-auto">Designed around fast daily flow, clarity and honest progress metrics.</p>

  <div class="mt-10 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <template x-for="(f, i) in features" :key="i">
      <div class="p-6 bg-white dark:bg-slate-800 rounded-2xl shadow hover:shadow-lg transition appear" x-bind:class="i<3? 'show' : ''">
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 rounded-md flex items-center justify-center bg-indigo-50 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-300" aria-hidden="true"><span x-text="f.icon"></span></div>
          <div>
            <div class="font-semibold text-slate-900 dark:text-slate-100" x-text="f.title"></div>
            <div class="text-sm text-slate-500 dark:text-slate-400 mt-1" x-text="f.desc"></div>
          </div>
        </div>
      </div>
    </template>
  </div>
</section>

<!-- HOW IT WORKS -->
<section id="how" class="bg-gradient-to-b from-white to-indigo-50 dark:from-slate-900 dark:to-slate-800 py-16">
  <div class="max-w-6xl mx-auto px-6 text-center">
    <h3 class="text-2xl font-bold text-slate-900 dark:text-white">How it works</h3>
    <p class="mt-3 text-slate-500 dark:text-slate-400 max-w-2xl mx-auto">Simple steps to quick daily wins.</p>

    <div class="mt-8 grid grid-cols-1 md:grid-cols-3 gap-6">
      <div class="p-6 bg-white dark:bg-slate-800 rounded-2xl shadow">
        <div class="text-xl font-bold">1</div>
        <div class="mt-2 font-semibold text-slate-900 dark:text-slate-100">Create a few habits</div>
        <div class="text-sm text-slate-500 dark:text-slate-400 mt-1">Keep them specific and small — focus beats volume.</div>
      </div>
      <div class="p-6 bg-white dark:bg-slate-800 rounded-2xl shadow">
        <div class="text-xl font-bold">2</div>
        <div class="mt-2 font-semibold text-slate-900 dark:text-slate-100">Mark progress daily</div>
        <div class="text-sm text-slate-500 dark:text-slate-400 mt-1">Fast interactions let the habit become the easy choice.</div>
      </div>
      <div class="p-6 bg-white dark:bg-slate-800 rounded-2xl shadow">
        <div class="text-xl font-bold">3</div>
        <div class="mt-2 font-semibold text-slate-900 dark:text-slate-100">Track momentum</div>
        <div class="text-sm text-slate-500 dark:text-slate-400 mt-1">Streaks, percentages and quick history help you stay honest.</div>
      </div>
    </div>
  </div>
</section>

<!-- PRICING / CTA -->
<section id="pricing" class="site-max mx-auto px-6 py-16">
  <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-center">
    <div>
      <h3 class="text-3xl font-bold text-slate-900 dark:text-white">Free forever — for individuals</h3>
      <p class="mt-3 text-slate-500 dark:text-slate-400">No paywalls for core habit tracking. Optional premium features later (analytics export, teams).</p>
      <ul class="mt-6 space-y-3 text-slate-600 dark:text-slate-300">
        <li>• Unlimited habits</li>
        <li>• Daily tracking & history</li>
        <li>• Light / Dark mode</li>
      </ul>
      <div class="mt-6">
        <a href="signup.php" class="inline-block bg-indigo-600 text-white px-6 py-3 rounded-xl shadow focus:outline-none focus:ring-2 focus:ring-indigo-400">Create free account</a>
      </div>
    </div>
    <div class="p-6 bg-white dark:bg-slate-800 rounded-2xl shadow">
      <div class="text-sm text-slate-500 dark:text-slate-400">Community picks</div>
      <div class="mt-4 font-semibold text-lg text-slate-900 dark:text-slate-100">Loved by builders who want less distractions.</div>
      <div class="mt-6 text-sm text-slate-500 dark:text-slate-400">Join the closed beta or contribute on GitHub — open source friendly.</div>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer class="bg-slate-900 text-slate-300 py-8">
  <div class="site-max mx-auto px-6 flex flex-col md:flex-row items-center justify-between gap-4">
    <div>
      <div class="font-semibold text-white">TrackerHabits</div>
      <div class="text-sm text-slate-400">Minimal habit tracking — built with clarity</div>
    </div>
    <div class="text-sm">© <?php echo date('Y'); ?> TrackerHabits</div>
  </div>
</footer>

<!-- small Alpine app data -->
<script>
function app(){
  return {
    mobileOpen: false,
    dark: (localStorage.getItem('th_dark') === '1'),
    features: [
      { icon: '✓', title: 'Quick daily flow', desc: 'Mark completed with one click and stay focused.' },
      { icon: '📊', title: 'Progress & history', desc: 'See recent completion rates and streaks.' },
      { icon: '⚡', title: 'Lightweight', desc: 'Fast frontend, small DB footprint.' },
      { icon: '🔒', title: 'Secure', desc: 'Session-based auth and PDO prepared statements.' },
      { icon: '🔁', title: 'Reorder & Kanban', desc: 'Reorder habits or use kanban for bigger flows.' },
      { icon: '🧩', title: 'Extensible', desc: 'Ready for AJAX, SPA or mobile wrap.' }
    ],
    init(){
      // progressive reveal for first 3 cards
      setTimeout(()=>{
        document.querySelectorAll('.appear').forEach((el,i)=>{ if(i<3) el.classList.add('show') })
      },120);
    },
    toggleDark(){ this.dark = !this.dark; localStorage.setItem('th_dark', this.dark ? '1' : '0') }
  }
}
</script>
</body>
</html>