<?php
require_once 'includes/db_connect.php';
$res = $conn->query('DESCRIBE teacherdata');
if (!$res) {
    echo "Error: " . $conn->error;
    exit(1);
}
$cols = [];
while ($r = $res->fetch_assoc()) {
    $cols[] = $r['Field'];
}
echo "Column count: " . count($cols) . "\n\n";
foreach ($cols as $c) echo $c . "\n";
