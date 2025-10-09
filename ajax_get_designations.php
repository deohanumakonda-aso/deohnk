<?php
require_once 'includes/db_connect.php';
header('Content-Type: application/json; charset=utf-8');
$out = ['designations' => []];
try {
    $res = $conn->query("SELECT DISTINCT COALESCE(NULLIF(Designation,''),'Unknown') AS desig FROM teacherdata ORDER BY desig");
    if ($res) {
        while ($r = $res->fetch_assoc()) $out['designations'][] = $r['desig'];
    }
} catch (Exception $e) {
    // ignore
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
exit;
