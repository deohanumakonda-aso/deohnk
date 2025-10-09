<?php
require_once 'includes/db_connect.php';

if (!isset($_GET['treasury_code']) || $_GET['treasury_code'] === '') {
    die('Missing treasury code');
}
$code = $_GET['treasury_code'];
$stmt = $conn->prepare("SELECT * FROM teacherdata WHERE TreasuryCode = ? LIMIT 1");
if (!$stmt) { die('Query prepare failed'); }
$stmt->bind_param('s', $code);
$stmt->execute();
$result = $stmt->get_result();
if (!$result || $result->num_rows === 0) {
    die('No data');
}
$row = $result->fetch_assoc();
$stmt->close();

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'label_map.json';
$labelMap = [];
if (file_exists($configPath) && is_readable($configPath)) {
    $json = file_get_contents($configPath);
    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        $labelMap = $decoded;
    }
}

// Build CSV
$filename = 'teacher_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $code) . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
$output = fopen('php://output', 'w');
// Ordered layout keys and friendly labels (matches teacher_particulars.php layout)
// keep a preferred key order for important columns; labels will be taken from $labelMap when present
$orderedKeys = [
    'TreasuryCode','TchSurName','TchFullName','FatherName','Designation','dob','gender','caste','adhaar','mobile','email','SchCode','SchName'
    // extend this array as necessary to control column order in CSV
];

// Build header row using friendly labels when available
$headers = [];
$values = [];
// Build headers/values using orderedKeys first (using mapping if available)
foreach ($orderedKeys as $key) {
    $label = array_key_exists($key, $labelMap) ? $labelMap[$key] : $key;
    $headers[] = $label;
    $values[] = array_key_exists($key, $row) ? $row[$key] : '';
}
// include any remaining keys not listed in ordered map
// include any remaining keys not listed in orderedKeys
foreach ($row as $k => $v) {
    if (!in_array($k, $orderedKeys, true)) {
        $headers[] = (array_key_exists($k, $labelMap) ? $labelMap[$k] : $k);
        $values[] = $v;
    }
}

fputcsv($output, $headers);
fputcsv($output, $values);
fclose($output);
exit;
