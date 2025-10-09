<?php
require_once 'includes/db_connect.php';
header('Content-Type: application/json; charset=utf-8');
$out = ['divisions'=>[]];
// Try primary column 'division'
$res = $conn->query("SELECT DISTINCT COALESCE(NULLIF(division,''),'Unknown') AS division FROM teacherdata ORDER BY division");
if ($res) { while($r = $res->fetch_assoc()){ $out['divisions'][] = $r['division']; } }
// Fallback to vmdistrict if divisions empty
if (empty($out['divisions'])){
    $res2 = $conn->query("SELECT DISTINCT COALESCE(NULLIF(vmdistrict,''),'Unknown') AS division FROM teacherdata ORDER BY division");
    if ($res2) { while($r = $res2->fetch_assoc()){ $out['divisions'][] = $r['division']; } }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
exit;
