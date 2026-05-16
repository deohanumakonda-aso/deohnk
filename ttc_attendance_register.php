<?php
session_start();
require_once 'includes/db_connect.php';
date_default_timezone_set('Asia/Kolkata');

// --- Access control ---
if (empty($_SESSION['admin_loggedin']) && empty($_SESSION['user_type'])) {
    header('Location: index.php');
    exit;
}

// --- Date range for TTC 2026 ---
$startDate = new DateTime('2026-05-01');
$endDate   = new DateTime('2026-06-12');
$today     = new DateTime();
$reportEnd = $today < $endDate ? $today : $endDate;

// Build array of all training dates (skip Sundays)
$allDates = [];
$d = clone $startDate;
while ($d <= $reportEnd) {
    if ($d->format('N') != 7) { // 7 = Sunday
        $allDates[] = $d->format('Y-m-d');
    }
    $d->modify('+1 day');
}

// --- Filter ---
$filterTrade = trim($_GET['trade'] ?? '');
$filterName  = trim($_GET['name'] ?? '');

// --- Fetch all trades for dropdown ---
$tradesRes = $conn->query("SELECT DISTINCT TradeOpted FROM ttc_registrations WHERE approval_status='Approved' ORDER BY TradeOpted");
$allTrades = [];
while ($tr = $tradesRes->fetch_assoc()) $allTrades[] = $tr['TradeOpted'];

// --- Fetch approved students ---
$sql = "SELECT AdmNo, CandidateName, TradeOpted, CenterName FROM ttc_registrations WHERE approval_status='Approved'";
$params = [];
$types  = '';
if ($filterTrade !== '') { $sql .= " AND TradeOpted = ?"; $types .= 's'; $params[] = $filterTrade; }
if ($filterName  !== '') { $sql .= " AND CandidateName LIKE ?"; $types .= 's'; $params[] = '%' . $filterName . '%'; }
$sql .= " ORDER BY TradeOpted, AdmNo";

$students = [];
if ($stmt = $conn->prepare($sql)) {
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $students[] = $row;
    $stmt->close();
}

// --- Fetch all attendance for the date range ---
$admNos = array_column($students, 'AdmNo');
$attendance = []; // [adm_no][date] => [sessions]

if (!empty($admNos) && !empty($allDates)) {
    $placeholders = implode(',', array_fill(0, count($admNos), '?'));
    $atSql = "SELECT adm_no, attendance_date, attendance_session FROM ttc_attendance
              WHERE adm_no IN ($placeholders)
              AND attendance_date BETWEEN ? AND ?
              ORDER BY attendance_date, attendance_session";
    $atTypes = str_repeat('s', count($admNos)) . 'ss';
    $atParams = array_merge($admNos, [$startDate->format('Y-m-d'), $reportEnd->format('Y-m-d')]);
    if ($atStmt = $conn->prepare($atSql)) {
        $atStmt->bind_param($atTypes, ...$atParams);
        $atStmt->execute();
        $atRes = $atStmt->get_result();
        while ($row = $atRes->fetch_assoc()) {
            $an = strtoupper($row['adm_no']);
            $dt = $row['attendance_date'];
            $attendance[$an][$dt][] = $row['attendance_session'];
        }
        $atStmt->close();
    }
}

// Helper: attendance cell value
function getCell($attendance, $admNo, $date) {
    $sessions = $attendance[$admNo][$date] ?? [];
    if (empty($sessions)) return '';
    sort($sessions);
    return implode('/', $sessions);
}

function sessionBadge($sessions) {
    if (empty($sessions)) return '<span class="att-absent">—</span>';
    $count = count($sessions);
    $html = '<span class="att-present att-count-' . $count . '">';
    $labels = [];
    foreach ($sessions as $s) {
        if ($s === '10AM') $labels[] = '🌅';
        elseif ($s === '1PM')  $labels[] = '☀';
        elseif ($s === '4PM')  $labels[] = '🌆';
        else $labels[] = '✓';
    }
    $html .= implode(' ', $labels) . '</span>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TTC 2026 — Attendance Register | DEO Hanumakonda</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --primary: #1e3a5f;
            --accent:  #0ea5e9;
            --green:   #059669;
            --red:     #dc2626;
            --gold:    #d97706;
            --bg:      #f0f4f8;
            --card:    #ffffff;
            --border:  #cbd5e1;
            --text:    #1e293b;
            --muted:   #64748b;
        }

        /* ── Page shell ── */
        .reg-page { padding: 0 0 40px; }
        .reg-hero {
            background: linear-gradient(135deg, #1e3a5f 0%, #0f5691 60%, #0ea5e9 100%);
            color: #fff;
            padding: 28px 32px 24px;
            border-radius: 0 0 20px 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(14,165,233,.25);
        }
        .reg-hero h1 { margin: 0 0 4px; font-size: 1.6rem; font-weight: 800; letter-spacing: -.5px; }
        .reg-hero p  { margin: 0; font-size: .9rem; opacity: .85; }
        .reg-hero .hero-meta { display: flex; gap: 24px; margin-top: 14px; flex-wrap: wrap; }
        .hero-stat { background: rgba(255,255,255,.15); backdrop-filter: blur(6px);
            border-radius: 10px; padding: 10px 18px; text-align: center; }
        .hero-stat strong { display: block; font-size: 1.5rem; font-weight: 800; }
        .hero-stat span   { font-size: .75rem; opacity: .8; }

        /* ── Filter bar ── */
        .filter-bar {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 14px;
            align-items: flex-end;
            flex-wrap: wrap;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        .filter-bar label { font-size: .8rem; font-weight: 700; color: var(--muted); display: block; margin-bottom: 4px; }
        .filter-bar select, .filter-bar input {
            padding: 9px 14px; border: 1.5px solid var(--border); border-radius: 8px;
            font-size: .9rem; background: #f8fafc; color: var(--text);
            transition: border .2s;
        }
        .filter-bar select:focus, .filter-bar input:focus { border-color: var(--accent); outline: none; }
        .btn-filter {
            background: linear-gradient(135deg, var(--primary), #0f5691);
            color: #fff; padding: 9px 22px; border: none; border-radius: 8px;
            font-size: .9rem; font-weight: 700; cursor: pointer; transition: opacity .2s;
        }
        .btn-filter:hover { opacity: .88; }
        .btn-reset { background: #e2e8f0; color: var(--text); padding: 9px 18px;
            border: none; border-radius: 8px; font-size: .9rem; font-weight: 600;
            cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }

        /* ── Export buttons ── */
        .export-row { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
        .btn-export {
            padding: 8px 18px; border-radius: 8px; border: none; font-size: .85rem;
            font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            transition: transform .15s, box-shadow .15s;
        }
        .btn-export:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.15); }
        .btn-excel { background: #16a34a; color: #fff; }
        .btn-print { background: #475569; color: #fff; }
        .rec-count { font-size: .85rem; color: var(--muted); margin-left: auto; }

        /* ── Table wrapper ── */
        .table-wrap {
            width: 100%;
            overflow-x: auto;
            background: var(--card);
            border-radius: 14px;
            border: 1px solid var(--border);
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
        }

        /* ── Attendance table ── */
        .att-table {
            border-collapse: collapse;
            width: 100%;
            font-size: .78rem;
            min-width: 900px;
        }
        .att-table thead { position: sticky; top: 0; z-index: 10; }
        .att-table th {
            background: var(--primary);
            color: #fff;
            padding: 10px 6px;
            text-align: center;
            font-weight: 700;
            white-space: nowrap;
            border-right: 1px solid rgba(255,255,255,.12);
        }
        .att-table th.col-fixed { text-align: left; padding-left: 10px; min-width: 36px; }
        .att-table th.col-adm   { min-width: 88px; }
        .att-table th.col-name  { min-width: 160px; text-align: left; padding-left: 8px; }
        .att-table th.col-trade { min-width: 80px; }
        .att-table th.col-date  { min-width: 52px; font-size: .7rem; }
        .att-table th.col-total { background: #0f5691; min-width: 56px; }

        /* Month sub-header */
        .month-row th {
            background: #0f5691;
            font-size: .72rem;
            padding: 5px 4px;
            letter-spacing: .04em;
            text-transform: uppercase;
            border-top: 1px solid rgba(255,255,255,.15);
        }

        /* Day header */
        .day-row th {
            background: #1e4976;
            font-size: .68rem;
            padding: 4px 3px;
            color: #bfdbfe;
        }
        .day-row th.col-date.sun { color: #fca5a5; }
        .day-row th.col-date.sat { color: #fcd34d; }

        .att-table tbody tr { transition: background .15s; }
        .att-table tbody tr:hover { background: #eff6ff; }
        .att-table tbody tr:nth-child(even) { background: #f8fafc; }
        .att-table tbody tr:nth-child(even):hover { background: #eff6ff; }

        .att-table td {
            padding: 7px 5px;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
            text-align: center;
            color: var(--text);
        }
        .att-table td.td-sno   { color: var(--muted); font-size: .75rem; }
        .att-table td.td-adm   { font-weight: 700; font-family: monospace; font-size: .82rem; color: var(--primary); }
        .att-table td.td-name  { text-align: left; padding-left: 8px; font-weight: 600; white-space: nowrap; }
        .att-table td.td-trade { font-size: .75rem; font-weight: 600; color: var(--gold); white-space: nowrap; }
        .att-table td.td-total {
            font-weight: 800; font-size: .9rem;
            background: #eff6ff;
            color: var(--primary);
            border-left: 2px solid var(--accent);
        }
        .att-table td.td-pct {
            font-weight: 700; font-size: .8rem;
        }

        /* Attendance cell states */
        .att-absent { color: #94a3b8; font-size: .8rem; }
        .att-present {
            display: inline-flex; gap: 2px; align-items: center;
            font-size: .75rem;
            background: #dcfce7;
            color: #15803d;
            border-radius: 4px;
            padding: 1px 4px;
            font-weight: 700;
        }
        .att-count-1 { background: #dcfce7; color: #15803d; }
        .att-count-2 { background: #bbf7d0; color: #14532d; }
        .att-count-3 { background: #4ade80; color: #14532d; }

        /* Total row */
        .att-table tfoot td {
            background: #1e3a5f;
            color: #fff;
            font-weight: 800;
            padding: 8px 5px;
            text-align: center;
            font-size: .8rem;
            position: sticky;
            bottom: 0;
        }
        .att-table tfoot td.td-label { text-align: left; padding-left: 10px; }

        /* Percentage color coding */
        .pct-high   { color: #15803d; }
        .pct-med    { color: #b45309; }
        .pct-low    { color: #dc2626; }

        /* Legend */
        .legend {
            display: flex; gap: 16px; flex-wrap: wrap; align-items: center;
            background: #f8fafc; border-radius: 10px; padding: 10px 16px;
            margin-bottom: 16px; border: 1px solid var(--border); font-size: .8rem;
        }
        .legend-item { display: flex; align-items: center; gap: 5px; }
        .legend-dot  { width: 14px; height: 14px; border-radius: 3px; }

        /* No data */
        .no-data { text-align: center; padding: 60px 20px; color: var(--muted); }
        .no-data i { font-size: 3rem; display: block; margin-bottom: 10px; }

        @media print {
            .filter-bar, .export-row, .reg-hero .hero-meta { display: none !important; }
            .att-table { font-size: .65rem; }
            .table-wrap { box-shadow: none; border: 1px solid #ccc; }
        }
    </style>
</head>
<body>
<?php include 'includes/header.php'; ?>
<div class="container main-body reg-page">
<?php include 'includes/left_sidebar.php'; ?>
<main class="main-content">

    <!-- Hero -->
    <div class="reg-hero">
        <h1>📋 TTC 2026 — Attendance Register</h1>
        <p>Day-wise attendance record &nbsp;|&nbsp; 01 May 2026 to 12 June 2026</p>
        <div class="hero-meta">
            <?php
            $totalStudents = count($students);
            $totalDays     = count($allDates);
            $totalSessions = $totalDays * 3;

            // Count overall attendance
            $totalPresent = 0;
            foreach ($students as $s) {
                $an = strtoupper($s['AdmNo']);
                foreach ($allDates as $dt) {
                    $totalPresent += count($attendance[$an][$dt] ?? []);
                }
            }
            $maxSessions = $totalStudents * $totalSessions;
            $overallPct  = $maxSessions > 0 ? round($totalPresent / $maxSessions * 100, 1) : 0;
            ?>
            <div class="hero-stat"><strong><?= $totalStudents ?></strong><span>Students</span></div>
            <div class="hero-stat"><strong><?= $totalDays ?></strong><span>Training Days</span></div>
            <div class="hero-stat"><strong><?= $totalPresent ?></strong><span>Sessions Attended</span></div>
            <div class="hero-stat"><strong><?= $overallPct ?>%</strong><span>Overall Attendance</span></div>
        </div>
    </div>

    <!-- Filter -->
    <form method="GET" class="filter-bar" id="filterForm">
        <div>
            <label>🎓 Filter by Trade</label>
            <select name="trade" id="selTrade">
                <option value="">All Trades</option>
                <?php foreach ($allTrades as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $filterTrade === $t ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>🔍 Student Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($filterName) ?>" placeholder="Search name..." style="width:180px;">
        </div>
        <button type="submit" class="btn-filter">Apply Filter</button>
        <a href="ttc_attendance_register.php" class="btn-reset">✕ Reset</a>
    </form>

    <!-- Export + count row -->
    <div class="export-row">
        <button class="btn-export btn-excel" onclick="exportToExcel()">📊 Export Excel</button>
        <button class="btn-export btn-print" onclick="window.print()">🖨 Print</button>
        <span class="rec-count">Showing <strong><?= $totalStudents ?></strong> student(s) × <strong><?= $totalDays ?></strong> day(s)</span>
    </div>

    <!-- Legend -->
    <div class="legend">
        <strong>Session Legend:</strong>
        <div class="legend-item"><span class="att-present att-count-1">🌅</span> 10 AM session</div>
        <div class="legend-item"><span class="att-present att-count-1">☀</span> 1 PM session</div>
        <div class="legend-item"><span class="att-present att-count-1">🌆</span> 4 PM session</div>
        <div class="legend-item"><span class="att-absent">—</span> Absent</div>
        <div class="legend-item" style="margin-left:auto; color:#64748b; font-size:.75rem;">
            % = (sessions attended) ÷ (total sessions × 3) × 100
        </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
    <?php if (empty($students)): ?>
        <div class="no-data"><i>📭</i>No approved students found for the selected filter.</div>
    <?php elseif (empty($allDates)): ?>
        <div class="no-data"><i>📅</i>No training dates available yet in the selected range.</div>
    <?php else: ?>
    <table class="att-table" id="attTable">
        <thead>
            <!-- Month header row -->
            <tr class="month-row">
                <th colspan="4" class="col-fixed" style="text-align:center;">Student Details</th>
                <?php
                $prevMonth = '';
                $monthSpan = 0;
                $monthData = []; // [label, count]
                foreach ($allDates as $dt) {
                    $m = date('M Y', strtotime($dt));
                    if ($m !== $prevMonth) {
                        if ($prevMonth !== '') $monthData[] = [$prevMonth, $monthSpan];
                        $prevMonth = $m;
                        $monthSpan = 1;
                    } else {
                        $monthSpan++;
                    }
                }
                if ($prevMonth !== '') $monthData[] = [$prevMonth, $monthSpan];
                foreach ($monthData as [$label, $span]): ?>
                <th colspan="<?= $span ?>" class="col-date" style="text-align:center;"><?= $label ?></th>
                <?php endforeach; ?>
                <th colspan="2" class="col-total" style="text-align:center;">Summary</th>
            </tr>
            <!-- Day-of-month row -->
            <tr class="day-row">
                <th class="col-fixed" style="font-size:.7rem;">#</th>
                <th class="col-adm">Adm No</th>
                <th class="col-name" style="text-align:left; padding-left:8px;">Student Name</th>
                <th class="col-trade">Trade</th>
                <?php foreach ($allDates as $dt):
                    $dow  = date('N', strtotime($dt)); // 6=Sat
                    $cls  = $dow == 6 ? 'sat' : '';
                    $disp = date('d', strtotime($dt));
                    $dayLbl = date('D', strtotime($dt));
                ?>
                <th class="col-date <?= $cls ?>" title="<?= date('d M Y (D)', strtotime($dt)) ?>">
                    <?= $disp ?><br><span style="font-size:.6rem; opacity:.7;"><?= $dayLbl ?></span>
                </th>
                <?php endforeach; ?>
                <th class="col-total" title="Total sessions attended">Sessions</th>
                <th class="col-total" title="Attendance percentage">%</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $colTotals = array_fill_keys($allDates, 0); // sessions attended per day across all students
        $sNo = 0;
        foreach ($students as $st):
            $sNo++;
            $an  = strtoupper($st['AdmNo']);
            $studentTotal = 0;
            $row_cells = [];
            foreach ($allDates as $dt) {
                $sessions = $attendance[$an][$dt] ?? [];
                $cnt = count($sessions);
                $studentTotal += $cnt;
                $colTotals[$dt] += $cnt;
                $row_cells[$dt] = $sessions;
            }
            $maxPossible = $totalDays * 3;
            $pct = $maxPossible > 0 ? round($studentTotal / $maxPossible * 100, 1) : 0;
            $pctClass = $pct >= 75 ? 'pct-high' : ($pct >= 50 ? 'pct-med' : 'pct-low');
        ?>
        <tr>
            <td class="td-sno"><?= $sNo ?></td>
            <td class="td-adm"><?= htmlspecialchars($an) ?></td>
            <td class="td-name"><?= htmlspecialchars($st['CandidateName']) ?></td>
            <td class="td-trade"><?= htmlspecialchars($st['TradeOpted']) ?></td>
            <?php foreach ($allDates as $dt): ?>
            <td title="<?= date('d M Y', strtotime($dt)) ?>"><?= sessionBadge($row_cells[$dt]) ?></td>
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
                <td><?= $colTotals[$dt] ?></td>
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
// ── Export to Excel ──────────────────────────────────────────────────
function exportToExcel() {
    const table = document.getElementById('attTable');
    if (!table) { alert('No data to export'); return; }

    let csv = '';
    const rows = table.querySelectorAll('tr');
    rows.forEach(function(row, ri) {
        // Skip month-header & day-header rows in export (keep from 3rd header onwards + body + foot)
        if (ri === 0) return; // skip month-label row
        const cells = row.querySelectorAll('th, td');
        const rowData = [];
        cells.forEach(function(cell) {
            let txt = cell.innerText.replace(/\n/g, ' ').replace(/"/g, '""').trim();
            rowData.push('"' + txt + '"');
        });
        csv += rowData.join(',') + '\n';
    });

    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'TTC_Attendance_Register_<?= date('d-m-Y') ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// ── Auto-submit on trade change ──────────────────────────────────────
document.getElementById('selTrade').addEventListener('change', function() {
    document.getElementById('filterForm').submit();
});

// ── Highlight today's column ─────────────────────────────────────────
(function() {
    const today = new Date().toISOString().slice(0,10); // yyyy-mm-dd
    const headers = document.querySelectorAll('.day-row th.col-date');
    headers.forEach(function(th, idx) {
        const title = th.getAttribute('title');
        if (title && title.startsWith(
                ('0'+new Date(today).getDate()).slice(-2) + ' ' +
                new Date(today).toLocaleString('en-GB',{month:'short'}) + ' ' +
                new Date(today).getFullYear()
        )) {
            th.style.background = '#f59e0b';
            th.style.color = '#1e293b';
            // Highlight the body cells in that column too
            const colIdx = idx + 4; // offset: #, adm, name, trade = 4 fixed cols
            document.querySelectorAll('.att-table tbody tr').forEach(function(row) {
                const td = row.querySelectorAll('td')[colIdx];
                if (td) td.style.background = '#fef9c3';
            });
        }
    });
})();
</script>
</body>
</html>
