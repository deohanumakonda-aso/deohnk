<?php
// ── Session & DB ──────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'includes/db_connect.php';
date_default_timezone_set('Asia/Kolkata');

// ── Access control ────────────────────────────────────────────────────
// Allow DEO, ADMIN, and MEO. Redirect others.
$allowedRoles = ['ADMIN', 'DEO', 'MEO'];
$userRole = $_SESSION['user_type'] ?? '';
if (empty($_SESSION['admin_loggedin']) && !in_array($userRole, $allowedRoles)) {
    header('Location: index.php');
    exit;
}

// ── Date range ────────────────────────────────────────────────────────
$startDate = new DateTime('2026-05-01');
$endDate   = new DateTime('2026-06-12');
$today     = new DateTime();
$reportEnd = ($today < $endDate) ? clone $today : clone $endDate;

// Build array of all training dates (skip Sundays)
$allDates = [];
$d = clone $startDate;
while ($d <= $reportEnd) {
    if ($d->format('N') != 7) { // 7 = Sunday
        $allDates[] = $d->format('Y-m-d');
    }
    $d->modify('+1 day');
}

// ── Filters ───────────────────────────────────────────────────────────
$filterTrade = trim($_GET['trade'] ?? '');
$filterName  = trim($_GET['name'] ?? '');

// ── All trades for dropdown ───────────────────────────────────────────
$tradesRes = $conn->query("SELECT DISTINCT TradeOpted FROM ttc_registrations WHERE approval_status='Approved' ORDER BY TradeOpted");
$allTrades = [];
if ($tradesRes) {
    while ($tr = $tradesRes->fetch_assoc()) $allTrades[] = $tr['TradeOpted'];
}

// ── Fetch approved students ───────────────────────────────────────────
$sql    = "SELECT AdmNo, CandidateName, TradeOpted FROM ttc_registrations WHERE approval_status='Approved'";
$params = [];
$types  = '';
if ($filterTrade !== '') { $sql .= " AND TradeOpted = ?";           $types .= 's'; $params[] = $filterTrade; }
if ($filterName  !== '') { $sql .= " AND CandidateName LIKE ?";     $types .= 's'; $params[] = '%'.$filterName.'%'; }
$sql .= " ORDER BY TradeOpted, AdmNo";

$students = [];
if ($stmt = $conn->prepare($sql)) {
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $students[] = $row;
    $stmt->close();
}

// ── Fetch attendance — simple direct query (no JOIN needed) ────────────
// Strategy: fetch ALL rows in the date range from ttc_attendance,
// then match against our already-fetched $students array in PHP.
// This avoids complex JOIN failures and is easy to debug.
//
// IST date: attendance_time is UTC → add 330 min for IST.
// Fallback: if attendance_time is NULL or zero, use attendance_date as-is.

$attendance  = [];  // [UPPER(adm_no)][Y-m-d IST] => [sessions]
$dbg = [            // diagnostic data shown to admin
    'table_exists'   => false,
    'total_rows'     => 0,
    'range_rows'     => 0,
    'matched_rows'   => 0,
    'prepare_error'  => '',
    'sample'         => [],
];

// Build a Set of approved adm_nos for fast PHP-side lookup
$approvedSet = [];
foreach ($students as $s) {
    $approvedSet[strtoupper(trim($s['AdmNo']))] = true;
}

// Check table exists
$tblCheck = $conn->query("SHOW TABLES LIKE 'ttc_attendance'");
if ($tblCheck && $tblCheck->num_rows > 0) {
    $dbg['table_exists'] = true;

    // Count total rows in table
    $cntRes = $conn->query("SELECT COUNT(*) AS c FROM ttc_attendance");
    if ($cntRes) $dbg['total_rows'] = (int)$cntRes->fetch_assoc()['c'];

    // Fetch rows in IST date range — use both attendance_date AND attendance_time fallback
    $istStart = $startDate->format('Y-m-d');
    $istEnd   = $reportEnd->format('Y-m-d');

    $atSql = "SELECT
                UPPER(TRIM(adm_no)) AS adm_no,
                attendance_date,
                attendance_time,
                attendance_session
              FROM ttc_attendance
              WHERE attendance_date BETWEEN ? AND ?
                 OR (attendance_time IS NOT NULL AND
                     DATE(DATE_ADD(attendance_time, INTERVAL 330 MINUTE)) BETWEEN ? AND ?)";

    if ($atStmt = $conn->prepare($atSql)) {
        $atStmt->bind_param('ssss', $istStart, $istEnd, $istStart, $istEnd);
        $atStmt->execute();
        $atRes = $atStmt->get_result();
        $dbg['range_rows'] = $atRes->num_rows;

        while ($row = $atRes->fetch_assoc()) {
            $an = strtoupper(trim($row['adm_no']));

            // Determine the correct IST date:
            // Prefer attendance_time (UTC→IST conversion); fall back to attendance_date
            if (!empty($row['attendance_time']) && $row['attendance_time'] !== '0000-00-00 00:00:00') {
                $utcTs  = strtotime($row['attendance_time']);
                $istDt  = date('Y-m-d', $utcTs + 19800); // 19800 = 5.5 * 3600
            } else {
                $istDt = $row['attendance_date'];
            }

            // Keep a sample for debug
            if (count($dbg['sample']) < 5) {
                $dbg['sample'][] = "$an | att_date={$row['attendance_date']} | att_time={$row['attendance_time']} | ist=$istDt | session={$row['attendance_session']}";
            }

            // Only include if this student is in our approved list
            if (isset($approvedSet[$an])) {
                $attendance[$an][$istDt][] = $row['attendance_session'];
                $dbg['matched_rows']++;
            }
        }
        $atStmt->close();
    } else {
        $dbg['prepare_error'] = $conn->error;
        error_log('ttc_attendance_register prepare failed: ' . $conn->error);
    }
}

// ── Summary stats ─────────────────────────────────────────────────────
$totalStudents = count($students);
$totalDays     = count($allDates);
$totalSessions = $totalDays * 3;
$totalPresent  = 0;
foreach ($students as $s) {
    $an = strtoupper($s['AdmNo']);
    foreach ($allDates as $dt) {
        $totalPresent += count($attendance[$an][$dt] ?? []);
    }
}
$maxAll      = $totalStudents * $totalSessions;
$overallPct  = $maxAll > 0 ? round($totalPresent / $maxAll * 100, 1) : 0;

// ── Helper: render session-count cell ────────────────────────────────
// Shows 1 / 2 / 3 (number of sessions attended). Tooltip = session names.
function sessionBadge(array $sessions): string {
    if (empty($sessions)) return '<span class="att-absent">—</span>';
    sort($sessions);
    $count = count($sessions);
    $tip   = implode(', ', $sessions);   // e.g. "10AM, 4PM"
    $cls   = 'att-count-' . $count;
    return '<span class="att-present ' . $cls . '" title="Sessions: ' . htmlspecialchars($tip) . '">' . $count . '</span>';
}

// ── Page CSS (injected by header.php into <head>) ─────────────────────
$pageCss = '
<style>
:root{--primary:#1e3a5f;--accent:#0ea5e9;--green:#059669;--gold:#d97706;--bg:#f0f4f8;--card:#fff;--border:#cbd5e1;--text:#1e293b;--muted:#64748b;}
.reg-page{padding:0 0 40px;}
/* Hero */
.reg-hero{background:linear-gradient(135deg,#1e3a5f 0%,#0f5691 60%,#0ea5e9 100%);color:#fff;padding:22px 28px 18px;border-radius:0 0 16px 16px;margin-bottom:20px;box-shadow:0 4px 18px rgba(14,165,233,.22);}
.reg-hero h1{margin:0 0 4px;font-size:1.45rem;font-weight:800;letter-spacing:-.4px;}
.reg-hero p{margin:0;font-size:.87rem;opacity:.82;}
.hero-meta{display:flex;gap:16px;margin-top:14px;flex-wrap:wrap;}
.hero-stat{background:rgba(255,255,255,.15);backdrop-filter:blur(6px);border-radius:10px;padding:8px 16px;text-align:center;}
.hero-stat strong{display:block;font-size:1.4rem;font-weight:800;}
.hero-stat span{font-size:.72rem;opacity:.8;}
/* Filter */
.filter-bar{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;box-shadow:0 2px 8px rgba(0,0,0,.06);}
.filter-bar label{font-size:.78rem;font-weight:700;color:var(--muted);display:block;margin-bottom:3px;}
.filter-bar select,.filter-bar input{padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:.88rem;background:#f8fafc;color:var(--text);transition:border .2s;}
.filter-bar select:focus,.filter-bar input:focus{border-color:var(--accent);outline:none;}
.btn-filter{background:linear-gradient(135deg,var(--primary),#0f5691);color:#fff;padding:8px 20px;border:none;border-radius:8px;font-size:.88rem;font-weight:700;cursor:pointer;transition:opacity .2s;}
.btn-filter:hover{opacity:.88;}
.btn-reset{background:#e2e8f0;color:var(--text);padding:8px 16px;border:none;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:4px;}
/* Export row */
.export-row{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center;}
.btn-export{padding:7px 16px;border-radius:8px;border:none;font-size:.82rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:transform .15s,box-shadow .15s;}
.btn-export:hover{transform:translateY(-1px);box-shadow:0 4px 10px rgba(0,0,0,.14);}
.btn-excel{background:#16a34a;color:#fff;}
.btn-print{background:#475569;color:#fff;}
.rec-count{font-size:.82rem;color:var(--muted);margin-left:auto;}
/* Legend */
.legend{display:flex;gap:14px;flex-wrap:wrap;align-items:center;background:#f8fafc;border-radius:10px;padding:8px 14px;margin-bottom:14px;border:1px solid var(--border);font-size:.78rem;}
.legend strong{margin-right:4px;}
.legend-item{display:flex;align-items:center;gap:4px;}
/* Table wrapper */
.table-wrap{width:100%;overflow-x:auto;background:var(--card);border-radius:12px;border:1px solid var(--border);box-shadow:0 2px 12px rgba(0,0,0,.07);}
/* Attendance table */
.att-table{border-collapse:collapse;width:100%;font-size:.76rem;min-width:800px;}
.att-table th{background:var(--primary);color:#fff;padding:9px 5px;text-align:center;font-weight:700;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);}
.att-table th.col-name{text-align:left;padding-left:8px;}
.month-row th{background:#0f5691;font-size:.7rem;padding:5px 4px;letter-spacing:.04em;text-transform:uppercase;}
.day-row th{background:#1e4976;font-size:.67rem;padding:4px 3px;color:#bfdbfe;}
.day-row th.sat{color:#fcd34d;}
.att-table tbody tr:hover{background:#eff6ff;}
.att-table tbody tr:nth-child(even){background:#f8fafc;}
.att-table tbody tr:nth-child(even):hover{background:#eff6ff;}
.att-table td{padding:6px 4px;border-bottom:1px solid #e2e8f0;border-right:1px solid #e2e8f0;text-align:center;color:var(--text);}
.att-table td.td-sno{color:var(--muted);font-size:.72rem;}
.att-table td.td-adm{font-weight:700;font-family:monospace;font-size:.8rem;color:var(--primary);}
.att-table td.td-name{text-align:left;padding-left:8px;font-weight:600;white-space:nowrap;}
.att-table td.td-trade{font-size:.73rem;font-weight:600;color:var(--gold);white-space:nowrap;}
.att-table td.td-total{font-weight:800;font-size:.88rem;background:#eff6ff;color:var(--primary);border-left:2px solid var(--accent);}
.att-table td.td-pct{font-weight:700;font-size:.78rem;}
/* Attendance cell states */
.att-absent{color:#94a3b8;font-size:.78rem;}
.att-present{display:inline-flex;gap:2px;align-items:center;font-size:.73rem;border-radius:4px;padding:1px 4px;font-weight:700;}
.att-count-1{background:#dcfce7;color:#15803d;}
.att-count-2{background:#bbf7d0;color:#14532d;}
.att-count-3{background:#4ade80;color:#14532d;}
/* Percentage colour coding */
.pct-high{color:#15803d;}.pct-med{color:#b45309;}.pct-low{color:#dc2626;}
/* Footer row */
.att-table tfoot td{background:#1e3a5f;color:#fff;font-weight:800;padding:7px 4px;text-align:center;font-size:.78rem;}
.att-table tfoot td.td-label{text-align:left;padding-left:10px;}
/* Today highlight */
.today-col{background:#fef3c7 !important;}
.today-th{background:#f59e0b !important;color:#1e293b !important;}
/* No data */
.no-data{text-align:center;padding:50px 20px;color:var(--muted);}
@media print{.filter-bar,.export-row,.reg-hero .hero-meta{display:none!important;}.att-table{font-size:.6rem;}.table-wrap{box-shadow:none;border:1px solid #ccc;}}
</style>';
?>
<?php include 'includes/header.php'; ?>
<div class="container main-body reg-page">
<?php include 'includes/left_sidebar.php'; ?>
<main class="main-content">

    <!-- Hero banner -->
    <div class="reg-hero">
        <h1>📋 TTC 2026 — Attendance Register</h1>
        <p>Day-wise student attendance &nbsp;|&nbsp; 01 May 2026 – 12 June 2026</p>
        <div class="hero-meta">
            <div class="hero-stat"><strong><?= $totalStudents ?></strong><span>Students</span></div>
            <div class="hero-stat"><strong><?= $totalDays ?></strong><span>Training Days</span></div>
            <div class="hero-stat"><strong><?= $totalSessions ?></strong><span>Max Sessions/Student</span></div>
            <div class="hero-stat"><strong><?= $totalPresent ?></strong><span>Sessions Attended</span></div>
            <div class="hero-stat"><strong><?= $overallPct ?>%</strong><span>Overall Attendance</span></div>
        </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar" id="filterForm">
        <div>
            <label>🎓 Filter by Trade</label>
            <select name="trade" id="selTrade">
                <option value="">All Trades</option>
                <?php foreach ($allTrades as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>"<?= $filterTrade===$t?' selected':'' ?>><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>🔍 Student Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($filterName) ?>" placeholder="Search name…" style="width:175px;">
        </div>
        <button type="submit" class="btn-filter">Apply</button>
        <a href="ttc_attendance_register.php" class="btn-reset">✕ Reset</a>
    </form>

    <!-- Export + count -->
    <div class="export-row">
        <button class="btn-export btn-excel" onclick="exportCSV()">📊 Export CSV</button>
        <button class="btn-export btn-print" onclick="window.print()">🖨 Print</button>
        <span class="rec-count">
            Showing <strong><?= $totalStudents ?></strong> student(s) × <strong><?= $totalDays ?></strong> training day(s)
        </span>
    </div>

    <!-- ── Admin debug panel ── -->
    <?php if (in_array($userRole, ['ADMIN', 'DEO'])): ?>
    <details style="background:#1e293b;color:#e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:14px;font-size:.78rem;font-family:monospace;">
        <summary style="cursor:pointer;font-weight:700;color:#7dd3fc;">🔍 Attendance DB Diagnostics (Admin only — click to expand)</summary>
        <div style="margin-top:10px;line-height:1.9;">
            <div>Table exists: <b style="color:<?= $dbg['table_exists'] ? '#4ade80' : '#f87171' ?>"><?= $dbg['table_exists'] ? 'YES' : 'NO ❌' ?></b></div>
            <div>Total rows in ttc_attendance: <b style="color:#fde68a"><?= $dbg['total_rows'] ?></b></div>
            <div>Rows in date range (<?= $startDate->format('d M') ?> – <?= $reportEnd->format('d M Y') ?>): <b style="color:#fde68a"><?= $dbg['range_rows'] ?></b></div>
            <div>Rows matched to approved students: <b style="color:<?= $dbg['matched_rows'] > 0 ? '#4ade80' : '#f87171' ?>"><?= $dbg['matched_rows'] ?></b></div>
            <?php if ($dbg['prepare_error']): ?>
            <div style="color:#f87171">⚠ Prepare error: <b><?= htmlspecialchars($dbg['prepare_error']) ?></b></div>
            <?php endif; ?>
            <?php if (!empty($dbg['sample'])): ?>
            <div style="margin-top:6px;color:#94a3b8;">Sample rows (first 5):</div>
            <?php foreach ($dbg['sample'] as $s): ?>
            <div style="padding-left:12px;color:#a5f3fc;"><?= htmlspecialchars($s) ?></div>
            <?php endforeach; ?>
            <?php else: ?>
            <div style="color:#f87171;">No rows returned from query — attendance table may be empty or dates don't match.</div>
            <?php endif; ?>
            <div style="margin-top:8px;color:#64748b;">Approved student count: <?= count($approvedSet) ?> | Date range: <?= $istStart ?? '?' ?> to <?= $istEnd ?? '?' ?></div>
        </div>
    </details>
    <?php endif; ?>

    <!-- Legend -->
    <div class="legend">
        <strong>Sessions attended:</strong>
        <div class="legend-item"><span class="att-present att-count-1">1</span>&nbsp;1 session</div>
        <div class="legend-item"><span class="att-present att-count-2">2</span>&nbsp;2 sessions</div>
        <div class="legend-item"><span class="att-present att-count-3">3</span>&nbsp;All 3 sessions</div>
        <div class="legend-item"><span class="att-absent">—</span>&nbsp;Absent / no record</div>
        <div class="legend-item" style="margin-left:auto;color:#64748b;font-size:.73rem;">Dates in IST (UTC+5:30) &nbsp;|&nbsp; Hover cell for session names &nbsp;|&nbsp; % = sessions ÷ (days×3) ×100</div>
    </div>

    <!-- Attendance table -->
    <div class="table-wrap">
    <?php if (empty($students)): ?>
        <div class="no-data">📭 No approved students found for the selected filter.</div>
    <?php elseif (empty($allDates)): ?>
        <div class="no-data">📅 No training dates available yet in the selected range.</div>
    <?php else: ?>

    <?php
    // Pre-compute per-column totals
    $colTotals = array_fill_keys($allDates, 0);
    foreach ($students as $s) {
        $an = strtoupper($s['AdmNo']);
        foreach ($allDates as $dt) {
            $colTotals[$dt] += count($attendance[$an][$dt] ?? []);
        }
    }
    // Build month-span data
    $monthData = [];
    $prevMonth = ''; $span = 0;
    foreach ($allDates as $dt) {
        $m = date('M Y', strtotime($dt));
        if ($m !== $prevMonth) {
            if ($prevMonth !== '') $monthData[] = [$prevMonth, $span];
            $prevMonth = $m; $span = 1;
        } else { $span++; }
    }
    if ($prevMonth !== '') $monthData[] = [$prevMonth, $span];
    ?>

    <table class="att-table" id="attTable">
        <thead>
            <!-- Month header -->
            <tr class="month-row">
                <th colspan="4" style="text-align:center;">Student Details</th>
                <?php foreach ($monthData as [$lbl, $cnt]): ?>
                <th colspan="<?= $cnt ?>"><?= $lbl ?></th>
                <?php endforeach; ?>
                <th colspan="2" style="background:#0a4275;">Summary</th>
            </tr>
            <!-- Day-of-month header -->
            <tr class="day-row">
                <th style="min-width:28px;">#</th>
                <th style="min-width:82px;">Adm No</th>
                <th class="col-name" style="min-width:155px;">Student Name</th>
                <th style="min-width:75px;">Trade</th>
                <?php foreach ($allDates as $dt):
                    $isSat = (date('N', strtotime($dt)) == 6);
                    $dayNum = date('d', strtotime($dt));
                    $dayAbbr = date('D', strtotime($dt));
                ?>
                <th class="<?= $isSat ? 'sat' : '' ?> col-dt" data-date="<?= $dt ?>"
                    style="min-width:42px;" title="<?= date('d M Y (D)', strtotime($dt)) ?>">
                    <?= $dayNum ?><br><span style="font-size:.58rem;opacity:.7;"><?= $dayAbbr ?></span>
                </th>
                <?php endforeach; ?>
                <th style="min-width:58px;background:#0a4275;" title="Sessions attended / total">Sessions</th>
                <th style="min-width:44px;background:#0a4275;" title="Attendance %">%</th>
            </tr>
        </thead>
        <tbody>
        <?php $sNo = 0; foreach ($students as $st):
            $sNo++;
            $an = strtoupper($st['AdmNo']);
            $studentTotal = 0;
            $cellSessions = [];
            foreach ($allDates as $dt) {
                $sessions = $attendance[$an][$dt] ?? [];
                $studentTotal += count($sessions);
                $cellSessions[$dt] = $sessions;
            }
            $pct = $totalDays > 0 ? round($studentTotal / ($totalDays * 3) * 100, 1) : 0;
            $pctClass = $pct >= 75 ? 'pct-high' : ($pct >= 50 ? 'pct-med' : 'pct-low');
        ?>
        <tr>
            <td class="td-sno"><?= $sNo ?></td>
            <td class="td-adm"><?= htmlspecialchars($an) ?></td>
            <td class="td-name"><?= htmlspecialchars($st['CandidateName']) ?></td>
            <td class="td-trade"><?= htmlspecialchars($st['TradeOpted']) ?></td>
            <?php foreach ($allDates as $dt): ?>
            <td class="cell-dt" data-date="<?= $dt ?>" title="<?= date('d M Y', strtotime($dt)) ?>"><?= sessionBadge($cellSessions[$dt]) ?></td>
            <?php endforeach; ?>
            <td class="td-total"><?= $studentTotal ?>/<?= $totalSessions ?></td>
            <td class="td-pct <?= $pctClass ?>"><?= $pct ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="td-label">📊 Daily Total (Sessions)</td>
                <?php foreach ($allDates as $dt): ?>
                <td class="cell-dt" data-date="<?= $dt ?>"><?= $colTotals[$dt] ?></td>
                <?php endforeach; ?>
                <td><?= $totalPresent ?></td>
                <td><?= $overallPct ?>%</td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
    </div><!-- .table-wrap -->

</main>
<?php include 'includes/right_sidebar.php'; ?>
</div>
<?php include 'includes/footer.php'; ?>

<script>
// ── Highlight today's column ──────────────────────────────────────────
(function(){
    const pad = n => String(n).padStart(2,'0');
    const t   = new Date();
    const td  = t.getFullYear()+'-'+pad(t.getMonth()+1)+'-'+pad(t.getDate());
    document.querySelectorAll('[data-date="'+td+'"]').forEach(function(el){
        el.classList.add(el.tagName==='TH' ? 'today-th' : 'today-col');
    });
})();

// ── Auto-submit on trade change ───────────────────────────────────────
document.getElementById('selTrade').addEventListener('change', function(){
    document.getElementById('filterForm').submit();
});

// ── Export CSV ────────────────────────────────────────────────────────
function exportCSV(){
    const table = document.getElementById('attTable');
    if(!table){alert('No data to export');return;}
    let csv = '';
    Array.from(table.querySelectorAll('tr')).forEach(function(row, ri){
        if(ri === 0) return; // skip month row
        const cells = row.querySelectorAll('th, td');
        const rowData = [];
        cells.forEach(function(c){
            let txt = (c.innerText||'').replace(/\n/g,' ').replace(/"/g,'""').trim();
            rowData.push('"'+txt+'"');
        });
        csv += rowData.join(',') + '\n';
    });
    const blob = new Blob(['\ufeff'+csv],{type:'text/csv;charset=utf-8;'});
    const a = Object.assign(document.createElement('a'),{href:URL.createObjectURL(blob),download:'TTC_Attendance_<?= date('d-m-Y') ?>.csv'});
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
</script>
