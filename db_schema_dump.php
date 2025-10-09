<?php
require_once 'includes/db_connect.php';

$tbl = 'teacherdata';
$res = $conn->query("DESCRIBE `$tbl`");
if (!$res) {
    echo "Failed to describe table: " . $conn->error;
    exit;
}
$cols = [];
while ($row = $res->fetch_assoc()) {
    $cols[] = $row['Field'];
}
header('Content-Type: application/json');
echo json_encode(['table' => $tbl, 'columns' => $cols], JSON_PRETTY_PRINT);
