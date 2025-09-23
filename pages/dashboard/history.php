<?php declare(strict_types=1);

session_name('habit_sid');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-XSS-Protection: 1; mode=block');

require_once dirname(__DIR__, 2) . '/functions/habit_functions.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

$session_timeout = 180000000;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_unset();
    session_destroy();
    header('Location: ../../auth/login.php?expired=1');
    exit;
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$user_id = (int)$_SESSION['user_id'];
$today = date('Y-m-d');

try {
    $data = getDashboardData($user_id, $pdo);
    if (!is_array($data)) {
        $data = [];
    }
} catch (Throwable $e) {
    $data = [];
}

$chart_labels = $data['chart']['labels'] ?? [];
$chart_values = $data['chart']['values'] ?? [];
$predicted = $data['chart']['predicted'] ?? null;

$historyData = $data['history'] ?? [];
$historySeries = $historyData['series'] ?? [];
if (!is_array($historySeries)) $historySeries = [];

if (!function_exists('formatHistoryDateLabel')) {
    function formatHistoryDateLabel(?string $date): string {
        if (empty($date)) {
            return '—';
        }
        try {
            $dt = new DateTimeImmutable($date);
            return $dt->format('M d, Y');
        } catch (Throwable $e) {
            return $date;
        }
    }
}

if (!empty($historySeries)) {
    usort($historySeries, static function ($a, $b) {
        return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
    });
}

$historyStart = $historyData['start'] ?? $today;
$historyEnd = $historyData['end'] ?? $today;
$historyActiveDays = isset($historyData['active_days']) ? (int)$historyData['active_days'] : 0;
$historyTotalsTracked = isset($historyData['total_tracked']) ? (int)$historyData['total_tracked'] : 0;
$historyTotalsCompleted = isset($historyData['total_completed']) ? (int)$historyData['total_completed'] : 0;
$historyEfficiency = isset($historyData['efficiency']) ? (float)$historyData['efficiency'] : 0.0;

$habits = $data['habits'] ?? [];
if (!is_array($habits)) $habits = [];
$habitStreakMap = $data['habitStreakMap'] ?? [];
if (!is_array($habitStreakMap)) $habitStreakMap = [];
$habitTitleMap = [];
foreach ($habits as $habit) {
    $hid = (int)($habit['id'] ?? 0);
    if ($hid > 0) {
        $habitTitleMap[$hid] = $habit['title'] ?? ('Habit #' . $hid);
    }
}

$topHabitTitle = '—';
$topHabitStreakValue = 0;
foreach ($habitStreakMap as $hid => $info) {
    $longest = (int)($info['longest'] ?? 0);
    if ($longest > $topHabitStreakValue) {
        $topHabitStreakValue = $longest;
        $topHabitTitle = $habitTitleMap[(int)$hid] ?? ('Habit #' . (int)$hid);
    }
}

$maxCompleted = -1;
$bestCompletionDay = null;
$maxEfficiency = -1.0;
$bestEfficiencyDay = null;
$activeDaysForAverage = 0;
$totalCompletedForAverage = 0;
foreach ($historySeries as $row) {
    $completed = (int)($row['completed'] ?? 0);
    $eff = isset($row['efficiency']) ? (float)$row['efficiency'] : 0.0;
    $totalTracked = (int)($row['total'] ?? 0);
    if ($completed > $maxCompleted) {
        $maxCompleted = $completed;
        $bestCompletionDay = $row;
    }
    if ($eff > $maxEfficiency) {
        $maxEfficiency = $eff;
        $bestEfficiencyDay = $row;
    }
    if ($totalTracked > 0 || $completed > 0) {
        $activeDaysForAverage++;
        $totalCompletedForAverage += $completed;
    }
}

$bestCompletionValue = $maxCompleted > 0 ? $maxCompleted : 0;
$bestCompletionDateFormatted = $bestCompletionDay ? formatHistoryDateLabel($bestCompletionDay['date'] ?? '') : '—';
$bestEfficiencyValue = $maxEfficiency > 0 ? $maxEfficiency : 0.0;
$bestEfficiencyDateFormatted = $bestEfficiencyDay ? formatHistoryDateLabel($bestEfficiencyDay['date'] ?? '') : '—';
$averageCompletedPerDay = $activeDaysForAverage > 0 ? $totalCompletedForAverage / $activeDaysForAverage : 0.0;

$weeklyComparison = $data['comparisons']['weekly'] ?? [];
$monthlyComparison = $data['comparisons']['monthly'] ?? [];
$allTimeSummary = $data['summary']['all_time'] ?? [];
if (!is_array($weeklyComparison)) $weeklyComparison = [];
if (!is_array($monthlyComparison)) $monthlyComparison = [];
if (!is_array($allTimeSummary)) $allTimeSummary = [];
$weeklyCurrent = is_array($weeklyComparison['current'] ?? null) ? $weeklyComparison['current'] : [];
$weeklyPrevious = is_array($weeklyComparison['previous'] ?? null) ? $weeklyComparison['previous'] : [];
$weeklyDelta = is_array($weeklyComparison['delta'] ?? null) ? $weeklyComparison['delta'] : [];
$monthlyCurrent = is_array($monthlyComparison['current'] ?? null) ? $monthlyComparison['current'] : [];
$monthlyPrevious = is_array($monthlyComparison['previous'] ?? null) ? $monthlyComparison['previous'] : [];
$monthlyDelta = is_array($monthlyComparison['delta'] ?? null) ? $monthlyComparison['delta'] : [];

$allTimeActiveDays = $allTimeSummary['active_days'] ?? $historyActiveDays;
$allTimeTotal = $allTimeSummary['total'] ?? $historyTotalsTracked;
$allTimeCompleted = $allTimeSummary['completed'] ?? $historyTotalsCompleted;
$allTimeEfficiency = $allTimeSummary['efficiency'] ?? $historyEfficiency;

?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php $dashCssPath = dirname(__DIR__, 2) . "/assets/styles/dashboard.css"; $dashCssVer = is_file($dashCssPath) ? (string)filemtime($dashCssPath) : (string)time(); ?><link rel="stylesheet" href="../../assets/styles/dashboard.css?v=<?php echo htmlspecialchars($dashCssVer, ENT_QUOTES, "UTF-8"); ?>">
<title>Habit History</title>
<style>
:root{--bg:#f5f7fb;--card:#fff;--muted:#667085;--shadow:0 6px 18px rgba(16,24,40,0.06)}
body{background:var(--bg);color:#0f172a;}
.history-page{padding-top:10px;padding-bottom:40px}
.history-header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.history-header h1{margin:0;font-size:1.75rem}
.history-header a{color:#4f46e5;text-decoration:none;font-weight:600;font-size:0.9rem}
.history-surface{background:var(--card);padding:20px;border-radius:16px;box-shadow:0 20px 40px rgba(15,23,42,0.08);display:flex;flex-direction:column;gap:18px}
.history-summary{display:flex;flex-wrap:wrap;gap:16px;background:#f8fafc;padding:16px;border-radius:12px;border:1px solid #e2e8f0}
.history-summary .summary-item{flex:1 1 180px;min-width:160px}
.history-summary .label{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px;font-weight:600}
.history-summary .value{font-weight:700;font-size:18px;color:#0f172a}
.history-highlights{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px}
.highlight-card{background:var(--card);border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 10px 24px rgba(15,23,42,0.08);display:flex;flex-direction:column;gap:6px}
.highlight-card .card-label{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);font-weight:600}
.highlight-card .card-value{font-size:20px;font-weight:700;color:#0f172a}
.highlight-card .card-note{font-size:13px;color:#475569}
.history-controls{display:flex;flex-wrap:wrap;gap:8px}
.range-btn{border:1px solid #d1d5db;background:#fff;color:#374151;padding:8px 16px;border-radius:999px;font-weight:600;font-size:13px;cursor:pointer;transition:background .18s ease,color .18s ease,border-color .18s ease,box-shadow .18s ease}
.range-btn:hover{background:#eef2ff;color:#1d4ed8;border-color:#c7d2fe}
.range-btn.active{background:#eef2ff;color:#1d4ed8;border-color:#c7d2fe;box-shadow:none}
.history-custom-controls{font-size:12px;display:flex;align-items:center;gap:6px;font-weight:600;color:var(--muted)}
.history-custom-controls span{white-space:nowrap}
.history-custom-controls input[type="date"]{padding:6px 8px;border:1px solid #d1d5db;border-radius:8px;font-size:12px;background:#fff}
.history-custom-controls button{margin-left:4px}
.history-chart{min-height:320px;background:var(--card);border-radius:12px;box-shadow:var(--shadow);padding:12px;position:relative}
.history-chart .empty-state{display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);font-size:14px}
.comparison-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}
.comparison-card{background:var(--card);border-radius:12px;border:1px solid #e2e8f0;padding:16px;box-shadow:0 12px 32px rgba(15,23,42,0.08);display:flex;flex-direction:column;gap:10px}
.comparison-title{font-weight:700;font-size:15px}
.comparison-metric,.comparison-sub,.comparison-note{display:flex;justify-content:space-between;align-items:center}
.comparison-metric .label,.comparison-sub .label,.comparison-note .label{color:var(--muted);font-size:12px;font-weight:600}
.comparison-metric .value,.comparison-note .value{font-weight:700;font-size:18px;color:#0f172a}
.comparison-sub .value{font-weight:700;font-size:16px;color:#0f172a}
.comparison-delta,.comparison-sub .delta{font-weight:600;font-size:12px;color:var(--muted)}
.comparison-delta.positive,.comparison-sub .delta.positive{color:#047857}
.comparison-delta.negative,.comparison-sub .delta.negative{color:#b91c1c}
.back-link{color:#4f46e5;text-decoration:none;font-weight:600}
.all-time-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:600}
@media (max-width:720px){
    .history-surface{padding:16px}
    .history-chart{min-height:260px}
    .history-custom-controls{flex-direction:column;align-items:flex-start;gap:4px}
}
</style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/elements.php'; ?>
<div class="main-content">
    <div class="container history-page">
        <div class="history-header">
            <h1>Habit history</h1>
            <a href="../../dashboard.php" class="back-link" aria-label="Back to dashboard"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px"><polyline points="15 18 9 12 15 6"/><path d="M20 12H9"/></svg>Back to dashboard</a>
        </div>
        <section class="history-surface" id="historyPage">
            <div class="all-time-pill">All time efficiency: <?php echo number_format($allTimeEfficiency, 1); ?>%</div>
            <div class="history-summary" id="historySummary">
                <div class="summary-item">
                    <div class="label">Range</div>
                    <div class="value" id="historyRangeLabel"><?php echo htmlspecialchars($historyStart, ENT_QUOTES, 'UTF-8'); ?> to <?php echo htmlspecialchars($historyEnd, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Active days</div>
                    <div class="value" id="historyActiveDays"><?php echo $allTimeActiveDays; ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Completions</div>
                    <div class="value" id="historyCompletionCount"><?php echo $allTimeCompleted; ?> / <?php echo $allTimeTotal; ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Avg efficiency</div>
                    <div class="value" id="historyEfficiencyValue"><?php echo number_format($historyEfficiency, 1); ?>%</div>
                </div>
            </div>
            <div class="highlight-grid">
                <article class="highlight-card">
                    <div class="card-label">Most completions</div>
                    <div class="card-value" id="highlight-best-completed-value"><?php echo number_format($bestCompletionValue); ?></div>
                    <div class="card-note" id="highlight-best-completed-date"><?php echo htmlspecialchars($bestCompletionDateFormatted, ENT_QUOTES, 'UTF-8'); ?></div>
                </article>
                <article class="highlight-card">
                    <div class="card-label">Peak efficiency</div>
                    <div class="card-value" id="highlight-best-eff-value"><?php echo number_format($bestEfficiencyValue, 1); ?>%</div>
                    <div class="card-note" id="highlight-best-eff-date"><?php echo htmlspecialchars($bestEfficiencyDateFormatted, ENT_QUOTES, 'UTF-8'); ?></div>
                </article>
                <article class="highlight-card">
                    <div class="card-label">Avg completions / day</div>
                    <div class="card-value" id="highlight-avg-completed-value"><?php echo number_format($averageCompletedPerDay, 1); ?></div>
                    <div class="card-note">Across active days</div>
                </article>
                <article class="highlight-card">
                    <div class="card-label">Longest habit streak</div>
                    <div class="card-value"><?php echo number_format($topHabitStreakValue); ?> days</div>
                    <div class="card-note"><?php echo htmlspecialchars($topHabitTitle, ENT_QUOTES, 'UTF-8'); ?></div>
                </article>
            </div>
            <div class="history-controls" role="group" aria-label="History range">
                <button type="button" class="range-btn" data-range="7">7 days</button>
                <button type="button" class="range-btn" data-range="30">30 days</button>
                <button type="button" class="range-btn" data-range="90">90 days</button>
                <button type="button" class="range-btn active" data-range="all">All time</button>
                <button type="button" class="range-btn" data-range="custom">Custom</button>
                <label class="history-custom-controls">
                    <span>From</span>
                    <input type="date" id="historyCustomStart">
                    <span>to</span>
                    <input type="date" id="historyCustomEnd">
                    <button type="button" id="historyApplyCustom" class="ghost-btn small">Apply</button>
                </label>
            </div>
                <label class="compare-toggle"><input type="checkbox" id="historyCompareToggle"> Compare previous period</label>
            <div id="historyChart" class="history-chart" aria-hidden="false"></div>
            <div class="comparison-grid">
                <div class="comparison-card" aria-live="polite">
                    <div class="comparison-title">Week over week</div>
                    <div class="comparison-metric">
                        <span class="label" id="weekly-current-label"><?php echo htmlspecialchars($weeklyCurrent['title'] ?? 'This week', ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="value" id="weekly-current-eff"><?php echo number_format((float)($weeklyCurrent['efficiency'] ?? 0), 1); ?>%</span>
                    </div>
                    <div class="comparison-delta" id="weekly-eff-delta"><?php echo number_format((float)($weeklyDelta['efficiency'] ?? 0), 1); ?>%</div>
                    <div class="comparison-sub">
                        <span class="label">Completed</span>
                        <span class="value" id="weekly-current-completed"><?php echo (int)($weeklyCurrent['completed'] ?? 0); ?></span>
                        <span class="delta" id="weekly-completed-delta"><?php echo (int)($weeklyDelta['completed'] ?? 0); ?></span>
                    </div>
                    <div class="comparison-note">
                        <span class="label" id="weekly-previous-label"><?php echo htmlspecialchars($weeklyPrevious['title'] ?? 'Last week', ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="value" id="weekly-previous-eff"><?php echo number_format((float)($weeklyPrevious['efficiency'] ?? 0), 1); ?>%</span>
                    </div>
                </div>
                <div class="comparison-card" aria-live="polite">
                    <div class="comparison-title">30 day trend</div>
                    <div class="comparison-metric">
                        <span class="label" id="monthly-current-label"><?php echo htmlspecialchars($monthlyCurrent['title'] ?? 'Last 30 days', ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="value" id="monthly-current-eff"><?php echo number_format((float)($monthlyCurrent['efficiency'] ?? 0), 1); ?>%</span>
                    </div>
                    <div class="comparison-delta" id="monthly-eff-delta"><?php echo number_format((float)($monthlyDelta['efficiency'] ?? 0), 1); ?>%</div>
                    <div class="comparison-sub">
                        <span class="label">Completed</span>
                        <span class="value" id="monthly-current-completed"><?php echo (int)($monthlyCurrent['completed'] ?? 0); ?></span>
                        <span class="delta" id="monthly-completed-delta"><?php echo (int)($monthlyDelta['completed'] ?? 0); ?></span>
                    </div>
                    <div class="comparison-note">
                        <span class="label" id="monthly-previous-label"><?php echo htmlspecialchars($monthlyPrevious['title'] ?? 'Previous 30 days', ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="value" id="monthly-previous-eff"><?php echo number_format((float)($monthlyPrevious['efficiency'] ?? 0), 1); ?>%</span>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<script>
window.__DASHBOARD_CONFIG__ = {
    csrf: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>,
    chart_labels: <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); ?>,
    chart_values: <?php echo json_encode($chart_values, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); ?>,
    predicted: <?php echo json_encode($predicted, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); ?>,
    incomplete: [],
    history_series: <?php echo json_encode($historySeries, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); ?>,
    history_start: <?php echo json_encode($historyStart, JSON_UNESCAPED_UNICODE); ?>,
    history_end: <?php echo json_encode($historyEnd, JSON_UNESCAPED_UNICODE); ?>,
    history_active_days: <?php echo json_encode($historyActiveDays, JSON_UNESCAPED_UNICODE); ?>,
    history_totals: <?php echo json_encode(['tracked' => $historyTotalsTracked, 'completed' => $historyTotalsCompleted, 'efficiency' => $historyEfficiency], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); ?>,
    weekly_comparison: <?php echo json_encode($weeklyComparison, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); ?>,
    monthly_comparison: <?php echo json_encode($monthlyComparison, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); ?>,
    all_time_summary: <?php echo json_encode($allTimeSummary, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); ?>,
    flash_success: null,
    flash_error: null
};
</script>
<?php $dashJsPath = dirname(__DIR__, 2) . "/assets/scripts/dashboard.js"; $dashJsVer = is_file($dashJsPath) ? (string)filemtime($dashJsPath) : (string)time(); ?>
<script src="../../assets/scripts/dashboard.js?v=<?php echo htmlspecialchars($dashJsVer, ENT_QUOTES, "UTF-8"); ?>" defer></script>
</body>
</html>





