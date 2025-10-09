<?php
// debug_select.php - small helper to inspect a teacherdata row by TreasuryCode
// Usage: http://localhost/deo-hanumakonda/debug_select.php?treasury_code=ABC123
// This file is temporary and safe for local debugging. Remove it after use.

require_once __DIR__ . '/includes/db_connect.php';
session_start();
header('Content-Type: text/plain; charset=utf-8');

// restrict access to admin sessions only
if (empty($_SESSION['admin_loggedin'])) {
    http_response_code(403);
    echo "Access denied. Admin login required.\n";
    exit;
}

$treasury = isset($_GET['treasury_code']) ? trim($_GET['treasury_code']) : '';
if ($treasury === '') {
    echo "Provide ?treasury_code=...\n";
    exit;
}

$stmt = $conn->prepare('SELECT * FROM teacherdata WHERE TreasuryCode = ? LIMIT 1');
if (!$stmt) {
    echo "Prepare failed: " . $conn->error . "\n";
    exit;
}
$stmt->bind_param('s', $treasury);
$stmt->execute();
$res = $stmt->get_result();
if (!$res) {
    echo "Query error: " . $stmt->error . "\n";
    $stmt->close();
    exit;
}

if ($res->num_rows === 0) {
    echo "No row found for TreasuryCode={$treasury}\n";
    $stmt->close();
    exit;
}

$row = $res->fetch_assoc();
$stmt->close();

// Pretty print
foreach ($row as $k => $v) {
    echo "$k: $v\n";
}

echo "\nTo test an update, submit a POST to this same URL with param save_test=1 and one or more column=value pairs.\n";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_test'])) {
    $updates = [];
    $params = [];
    $types = '';
    foreach ($_POST as $k => $v) {
        if (in_array($k, ['save_test','treasury_code'])) continue;
        // only allow safe column names (alphanumeric and underscore)
        if (!preg_match('/^[A-Za-z0-9_]+$/', $k)) continue;
        $updates[] = "`$k` = ?";
        $params[] = $v;
        $types .= 's';
    }
    if (!empty($updates)) {
        $sql = 'UPDATE teacherdata SET ' . implode(', ', $updates) . ' WHERE TreasuryCode = ? LIMIT 1';
        $types .= 's'; $params[] = $treasury;
        $stmt2 = $conn->prepare($sql);
        if (!$stmt2) { echo "Prepare failed: " . $conn->error . "\n"; exit; }
        $bind = array_merge([$types], $params);
        // bind_param requires references
        $refs = [];
        foreach ($bind as $i => $value) $refs[$i] = &$bind[$i];
        call_user_func_array([$stmt2, 'bind_param'], $refs);
        if ($stmt2->execute()) {
            echo "Update OK\n";
        } else {
            echo "Update failed: " . $stmt2->error . "\n";
        }
        $stmt2->close();
    } else {
        echo "No valid columns provided for update.\n";
    }
}
