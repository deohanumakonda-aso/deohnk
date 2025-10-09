<?php
require_once 'includes/db_connect.php';
header('Content-Type: application/json; charset=utf-8');

$division = isset($_GET['division']) ? trim($_GET['division']) : '';
$mandal = isset($_GET['mandal']) ? trim($_GET['mandal']) : '';

$out = ['divisions'=>[], 'mandals'=>[], 'schools'=>[]];

// 1) divisions (distinct)
if (true) {
    $sql = "SELECT DISTINCT COALESCE(NULLIF(division,''),'Unknown') AS division FROM school_list ORDER BY division";
    if ($res = $conn->query($sql)){
        while($r = $res->fetch_assoc()){
            // normalize/trim division values
            $div = isset($r['division']) ? trim($r['division']) : '';
            if ($div === '') $div = 'Unknown';
            $out['divisions'][] = $div;
        }
        // dedupe preserving order
        $out['divisions'] = array_values(array_unique($out['divisions']));
    }
}

// 2) mandals for a given division
if ($division !== ''){
    $sql = "SELECT DISTINCT COALESCE(NULLIF(mandal,''),'Unknown') AS mandal FROM school_list WHERE division = ? ORDER BY mandal";
    if ($stmt = $conn->prepare($sql)){
        $stmt->bind_param('s', $division);
        $stmt->execute();
        $res = $stmt->get_result();
        while($r = $res->fetch_assoc()) {
            $m = isset($r['mandal']) ? trim($r['mandal']) : '';
            if ($m === '') $m = 'Unknown';
            $out['mandals'][] = $m;
        }
        $out['mandals'] = array_values(array_unique($out['mandals']));
        $stmt->close();
    }

    // 3) schools for a given division (and optionally mandal)
    $sql2 = "SELECT DISTINCT COALESCE(NULLIF(scode,''),'') AS scode, COALESCE(NULLIF(sname,''),'') AS sname, COALESCE(NULLIF(mandal,''),'') AS mandal FROM school_list WHERE division = ?";
    if ($mandal !== '') { $sql2 .= " AND mandal = ?"; }
    $sql2 .= " ORDER BY sname, scode";
    if ($stmt2 = $conn->prepare($sql2)){
        if ($mandal !== ''){ $stmt2->bind_param('ss', $division, $mandal); }
        else { $stmt2->bind_param('s', $division); }
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while($r = $res2->fetch_assoc()){
            // normalize fields
            $scode = isset($r['scode']) ? trim($r['scode']) : '';
            $sname = isset($r['sname']) ? trim($r['sname']) : '';
            $mandalVal = isset($r['mandal']) ? trim($r['mandal']) : '';
            $label = trim($scode . ' - ' . $sname);
            $out['schools'][] = ['scode'=>$scode,'sname'=>$sname,'mandal'=>$mandalVal,'label'=>$label];
        }
        // ensure uniqueness by scode+sname
        $seen = [];
        $unique = [];
        foreach ($out['schools'] as $s) {
            $k = ($s['scode'] ?? '') . '|' . ($s['sname'] ?? '');
            if (!isset($seen[$k])) { $seen[$k]=true; $unique[] = $s; }
        }
        $out['schools'] = $unique;
        $stmt2->close();
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
exit;
