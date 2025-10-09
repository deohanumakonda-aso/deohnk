<?php
require_once 'includes/db_connect.php';
header('Content-Type: application/json; charset=utf-8');

$scode = isset($_GET['scode']) ? trim($_GET['scode']) : '';
$out = ['found'=>false,'ps_ups'=>'','mgt'=>'','medium_sch'=>'','category'=>''];
if ($scode !== ''){
    $sql = "SELECT ps_ups, mgt, medium_sch, category FROM school_list WHERE scode = ? LIMIT 1";
    if ($stmt = $conn->prepare($sql)){
        $stmt->bind_param('s', $scode);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows){
            $r = $res->fetch_assoc();
            $out['found'] = true;
            $out['ps_ups'] = $r['ps_ups'];
            $out['mgt'] = $r['mgt'];
            $out['medium_sch'] = $r['medium_sch'];
            $out['category'] = $r['category'];
        }
        $stmt->close();
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
exit;