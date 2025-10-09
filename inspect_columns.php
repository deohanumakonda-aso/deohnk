<?php
require_once 'includes/db_connect.php';
$res = $conn->query("SHOW COLUMNS FROM teacherdata");
$cols = [];
if ($res) { while($r = $res->fetch_assoc()) $cols[] = $r['Field']; }
echo json_encode($cols, JSON_PRETTY_PRINT);
