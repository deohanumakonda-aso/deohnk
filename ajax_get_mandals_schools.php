<?php
require_once 'includes/db_connect.php';
$district = isset($_GET['district']) ? trim($_GET['district']) : '';
$mandal = isset($_GET['mandal']) ? trim($_GET['mandal']) : '';
$division = isset($_GET['division']) ? trim($_GET['division']) : '';
// optional school param to fetch school codes
$schoolParam = isset($_GET['school']) ? trim($_GET['school']) : '';

// helper: check if a column exists in teacherdata
function column_exists($conn, $col) {
    $col = $conn->real_escape_string($col);
    $q = $conn->query("SHOW COLUMNS FROM teacherdata LIKE '" . $col . "'");
    return ($q && $q->num_rows > 0);
}

$out = ['schmandals'=>[], 'schools'=>[], 'schcodes'=>[]];

// Primary flow: use SchMandal and SchName (these are present in your DB)
if ($division !== '') {
    $stmt = $conn->prepare("SELECT DISTINCT COALESCE(NULLIF(SchMandal,''),'Unknown') AS schmandal FROM teacherdata WHERE division = ? ORDER BY schmandal");
    if ($stmt) { $stmt->bind_param('s', $division); $stmt->execute(); $res = $stmt->get_result(); while($r=$res->fetch_assoc()) $out['schmandals'][] = $r['schmandal']; $stmt->close(); }

    $sql = "SELECT DISTINCT COALESCE(NULLIF(SchName,''),'Unknown') AS school FROM teacherdata WHERE division = ?" . ($mandal !== '' ? ' AND SchMandal = ?' : '') . " ORDER BY school";
    if ($stmt = $conn->prepare($sql)) {
        if ($mandal !== '') { $stmt->bind_param('ss', $division, $mandal); } else { $stmt->bind_param('s', $division); }
        $stmt->execute(); $res = $stmt->get_result(); while($r=$res->fetch_assoc()) $out['schools'][] = $r['school']; $stmt->close();
    }

    // If a specific school is requested, return matching SchCode(s)
    if ($schoolParam !== '') {
        $sqlc = "SELECT DISTINCT COALESCE(NULLIF(SchCode,''),'Unknown') AS schcode FROM teacherdata WHERE division = ?" . ($mandal !== '' ? ' AND SchMandal = ?' : '') . " AND SchName = ? ORDER BY schcode";
        if ($stmtc = $conn->prepare($sqlc)) {
            if ($mandal !== '') { $stmtc->bind_param('sss', $division, $mandal, $schoolParam); } else { $stmtc->bind_param('ss', $division, $schoolParam); }
            $stmtc->execute(); $res2 = $stmtc->get_result(); while($r=$res2->fetch_assoc()) $out['schcodes'][] = $r['schcode']; $stmtc->close();
        }
    }
}

// Legacy support: if caller passes 'district' and the vmdistrict column exists, use it as a fallback
if ($district !== '' && column_exists($conn, 'vmdistrict')) {
    $stmt = $conn->prepare("SELECT DISTINCT COALESCE(NULLIF(SchMandal,''),'Unknown') AS schmandal FROM teacherdata WHERE vmdistrict = ? ORDER BY schmandal");
    if ($stmt) { $stmt->bind_param('s', $district); $stmt->execute(); $res = $stmt->get_result(); while($r=$res->fetch_assoc()) $out['schmandals'][] = $r['schmandal']; $stmt->close(); }

    $sql = "SELECT DISTINCT COALESCE(NULLIF(SchName,''),'Unknown') AS school FROM teacherdata WHERE vmdistrict = ?" . ($mandal !== '' && column_exists($conn, 'vmmandal') ? ' AND vmmandal = ?' : '') . " ORDER BY school";
    if ($stmt = $conn->prepare($sql)) {
        if ($mandal !== '' && column_exists($conn, 'vmmandal')) { $stmt->bind_param('ss', $district, $mandal); } else { $stmt->bind_param('s', $district); }
        $stmt->execute(); $res = $stmt->get_result(); while($r=$res->fetch_assoc()) $out['schools'][] = $r['school']; $stmt->close();
    }

    // legacy schcode support
    if ($schoolParam !== '' && column_exists($conn, 'SchCode')) {
        $sqlc = "SELECT DISTINCT COALESCE(NULLIF(SchCode,''),'Unknown') AS schcode FROM teacherdata WHERE vmdistrict = ?" . ($mandal !== '' && column_exists($conn, 'vmmandal') ? ' AND vmmandal = ?' : '') . " AND SchName = ? ORDER BY schcode";
        if ($stmtc = $conn->prepare($sqlc)) {
            if ($mandal !== '' && column_exists($conn, 'vmmandal')) { $stmtc->bind_param('sss', $district, $mandal, $schoolParam); } else { $stmtc->bind_param('ss', $district, $schoolParam); }
            $stmtc->execute(); $res2 = $stmtc->get_result(); while($r=$res2->fetch_assoc()) $out['schcodes'][] = $r['schcode']; $stmtc->close();
        }
    }
}

// dedupe results
$out['schmandals'] = array_values(array_unique($out['schmandals']));
$out['schools'] = array_values(array_unique($out['schools']));

echo json_encode($out, JSON_UNESCAPED_UNICODE);
exit;
