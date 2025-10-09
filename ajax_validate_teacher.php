<?php
require_once 'includes/db_connect.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

$treasury = isset($_POST['treasury_code']) ? trim($_POST['treasury_code']) : '';
$dob_in = isset($_POST['dob']) ? trim($_POST['dob']) : '';
$mobile_in = isset($_POST['mobile']) ? trim($_POST['mobile']) : '';

if ($treasury === '') {
    echo json_encode(['ok' => false, 'msg' => 'Missing TreasuryCode']); exit;
}

$stmt = $conn->prepare('SELECT dob, mobile FROM teacherdata WHERE TreasuryCode = ? LIMIT 1');
if (!$stmt) {
    echo json_encode(['ok'=>false,'msg'=>'Server error']); exit;
}
$stmt->bind_param('s', $treasury);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    echo json_encode(['ok'=>false,'msg'=>'No record found for this TreasuryCode']); exit;
}
$row = $res->fetch_assoc();
$stmt->close();

$db_dob = isset($row['dob']) ? trim($row['dob']) : '';
$db_mobile = isset($row['mobile']) ? trim($row['mobile']) : '';

// normalize mobile: keep digits

function digits($s){ return preg_replace('/\D+/', '', $s); }
$m_in = digits($mobile_in);
$m_db = digits($db_mobile);

// normalize dob: return Y-m-d if fully parseable, else empty
function norm_full_dob($s){
    $s = trim($s);
    if ($s === '') return '';
    // try explicit DD/MM/YYYY or DD-MM-YYYY (or 2-digit year)
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $s, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = $m[3];
        if (strlen($y) === 2) { $y = '20'.$y; }
        if (checkdate($mo, $d, (int)$y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    // fallback to strtotime for other formats
    $ts = strtotime($s);
    if ($ts !== false) return date('Y-m-d', $ts);
    return '';
}

// return day-month (DD-MM) if parseable
function dob_day_month($s){
    $s = trim($s);
    if ($s === '') return '';
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})/', $s, $m)) {
        $d = sprintf('%02d', (int)$m[1]); $mo = sprintf('%02d', (int)$m[2]); return $d.'-'.$mo;
    }
    $ts = strtotime($s);
    if ($ts !== false) return date('d-m', $ts);
    return '';
}

$d_in_full = norm_full_dob($dob_in);
$d_db_full = norm_full_dob($db_dob);
$d_in_dm = dob_day_month($dob_in);
$d_db_dm = dob_day_month($db_dob);

$ok = true;
$errors = [];

// Now require full DOB (with year) and full mobile exact match
$d_in_full_req = norm_full_dob($dob_in);
$d_db_full_req = norm_full_dob($db_dob);
if ($d_in_full_req === '' || $d_db_full_req === '' || $d_in_full_req !== $d_db_full_req) { $ok = false; $errors[] = 'Full date of birth does not match (please provide DD/MM/YYYY)'; }

// mobile: require full-digit match
if ($m_in === '' || $m_db === '' || $m_in !== $m_db) { $ok = false; $errors[] = 'Full mobile number does not match'; }

if ($ok) {
    // set session verification flag so user won't need to re-verify for this TreasuryCode
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['verified_teacher_' . $treasury] = true;
    echo json_encode(['ok'=>true]);
} else {
    echo json_encode(['ok'=>false,'msg'=>implode('; ',$errors)]);
}
exit;
