<?php
require_once 'includes/db_connect.php';
$result = [];
try {
    $q = $conn->query("SELECT COUNT(*) AS c FROM teacherdata");
    $result['total'] = $q ? (int)$q->fetch_assoc()['c'] : 0;

    $rows = [];
    $q = $conn->query("SELECT DISTINCT COALESCE(NULLIF(Designation,''),'(empty)') AS desig FROM teacherdata ORDER BY desig LIMIT 50");
    $rows = [];
    if ($q) { while ($r = $q->fetch_assoc()) $rows[] = $r['desig']; }
    $result['designations_sample'] = $rows;

    $q = $conn->query("SELECT DISTINCT COALESCE(NULLIF(division,''),'(empty)') AS division FROM teacherdata ORDER BY division");
    $divs = [];
    if ($q) { while ($r = $q->fetch_assoc()) $divs[] = $r['division']; }
    $result['divisions'] = $divs;

    $target = 'HANUMAKONDA';
    $stmt = $conn->prepare("SELECT DISTINCT COALESCE(NULLIF(SchMandal,''),'(empty)') AS schmandal FROM teacherdata WHERE division = ? ORDER BY schmandal");
    $stmt->bind_param('s', $target);
    $stmt->execute();
    $qr = $stmt->get_result();
    $sm = [];
    while ($r = $qr->fetch_assoc()) $sm[] = $r['schmandal'];
    $result['hanumakonda_schmandals'] = $sm;

    // sample schools for Hanumakonda
    $stmt2 = $conn->prepare("SELECT DISTINCT COALESCE(NULLIF(SchName,''),'(empty)') AS school FROM teacherdata WHERE division = ? ORDER BY school LIMIT 50");
    $stmt2->bind_param('s', $target);
    $stmt2->execute();
    $qr2 = $stmt2->get_result();
    $schs = [];
    while ($r = $qr2->fetch_assoc()) $schs[] = $r['school'];
    $result['hanumakonda_schools_sample'] = $schs;

} catch (Exception $e) {
    $result['error'] = $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT);
