<?php
// ============================================================
// AJAX SAFETY LAYER — Must be FIRST, before any output
// Ensures AJAX requests ALWAYS receive JSON, even on fatal errors
// ============================================================
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);

// Detect AJAX as early as possible (before any output)
$__IS_AJAX = (
    (isset($_POST['ajax']) && $_POST['ajax'] === '1') ||
    (isset($_GET['ajax'])  && $_GET['ajax']  === '1')
);

if ($__IS_AJAX) {
    // Start output buffering immediately so any accidental HTML is caught
    ob_start();

    // Register a shutdown function: if we exit without sending JSON,
    // capture whatever was buffered (HTML error page, fatal error, etc.)
    // and replace it with a JSON error response.
    register_shutdown_function(function () {
        // Check for a fatal error
        $err = error_get_last();
        $isFatal = $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR]);

        // Get whatever was buffered so far
        $buffered = '';
        while (ob_get_level() > 0) {
            $buffered .= ob_get_clean();
        }

        // If the buffered output is already valid JSON, let it through
        if ($buffered !== '') {
            $decoded = json_decode($buffered, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // It's valid JSON — send it as-is
                header('Content-Type: application/json; charset=utf-8');
                echo $buffered;
                return;
            }
        }

        // Output was HTML or empty — only act if headers not yet sent
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            $errMsg = 'Server error';
            if ($isFatal) {
                $errMsg = 'PHP Fatal Error: ' . ($err['message'] ?? 'Unknown') . ' in ' . basename($err['file'] ?? '') . ':' . ($err['line'] ?? '');
            } elseif ($buffered !== '') {
                // Strip HTML tags to get a cleaner error message
                $stripped = trim(strip_tags($buffered));
                $stripped = preg_replace('/\s+/', ' ', $stripped);
                if (strlen($stripped) > 300) $stripped = substr($stripped, 0, 300) . '...';
                $errMsg = $stripped ?: 'Server returned unexpected output';
            }
            echo json_encode(['success' => false, 'message' => $errMsg, 'debug_html' => substr($buffered, 0, 500)]);
        }
    });
}

// Ensure a session is started only when none is active to avoid "session_start(): Ignoring session_start()" notices
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
} else {
    // Fallback for very old PHP versions (unlikely here) - attempt to start session
    @session_start();
}
// Initialize upload configuration and limits
require_once 'config/init.php';
require_once 'includes/db_connect.php';
require_once 'includes/permissions.php';

// Check if user is logged in and has permission to edit teacher data
if (!is_logged_in()) {
    $msg = 'Please login to access this page.';
    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
        while (ob_get_level())
            ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
    include 'includes/header.php';
    echo '<div class="container main-body"><main class="main-content">';
    echo '<script>';
    echo 'alert("' . addslashes($msg) . '");';
    echo 'window.location.href = "index.php";';
    echo '</script>';
    echo '</main></div>';
    include 'includes/footer.php';
    exit;
}

// HM users cannot edit teacher data at all
if (get_user_role() === 'HM') {
    $msg = 'Head Masters do not have permission to edit teacher data.';
    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
        while (ob_get_level())
            ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
    include 'includes/header.php';
    echo '<script>';
    echo 'alert("' . addslashes($msg) . '");';
    echo 'window.history.back();';
    echo '</script>';
    include 'includes/footer.php';
    exit;
}

// Only DEO and MEO can edit (ADMIN too, but they use admin panel)
if (!has_permission('edit_teacher_data')) {
    $msg = 'You do not have permission to edit teacher data.';
    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
        while (ob_get_level())
            ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
    include 'includes/header.php';
    echo '<div class="container main-body"><main class="main-content">';
    echo '<script>';
    echo 'alert("' . addslashes($msg) . '");';
    echo 'window.location.href = "index.php";';
    echo '</script>';
    echo '</main></div>';
    include 'includes/footer.php';
    exit;
}

// Get user role for service tab restrictions
$user_role = get_user_role();
$can_edit_service = has_permission('edit_service_particulars');
$can_edit_service_school = $can_edit_service || $user_role === 'MEO';

// Define image compression function early (required for file uploads)
if (!function_exists('compressUploadedImageFile')) {
    function compressUploadedImageFile($tmpTmpName, $destPath, $mimeType, $maxBytes = 102400) {
        try {
            if (!file_exists($tmpTmpName) || !is_readable($tmpTmpName)) {
                return false;
            }
            if (!function_exists('imagecreatetruecolor')) {
                $fileSize = @filesize($tmpTmpName);
                if ($fileSize > 0 && $fileSize <= $maxBytes) {
                    return copy($tmpTmpName, $destPath);
                }
                return false;
            }
            $source = null;
            if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
                $source = @imagecreatefromjpeg($tmpTmpName);
            } elseif ($mimeType === 'image/png') {
                $source = @imagecreatefrompng($tmpTmpName);
            }
            if (!$source) {
                return false;
            }
            $width = imagesx($source);
            $height = imagesy($source);
            $quality = 70;
            $compression = 9;
            $bestData = null;
            $bestSize = PHP_INT_MAX;
            $scaleDownCount = 0;
            for ($attempt = 0; $attempt < 25; $attempt++) {
                ob_start();
                if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
                    imagejpeg($source, null, $quality);
                } else {
                    imagepng($source, null, $compression);
                }
                $data = ob_get_clean();
                $size = strlen($data);
                if ($size <= $maxBytes) {
                    file_put_contents($destPath, $data);
                    imagedestroy($source);
                    return true;
                }
                if ($size < $bestSize) {
                    $bestSize = $size;
                    $bestData = $data;
                }
                if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
                    if ($quality > 20) {
                        $quality = max(20, $quality - 15);
                        continue;
                    }
                } else {
                    if ($compression < 9) {
                        $compression = 9;
                        continue;
                    }
                }
                if ($scaleDownCount < 8) {
                    $scaleDownCount++;
                    $newWidth = max(150, intval($width * (1 - $scaleDownCount * 0.12)));
                    $newHeight = max(150, intval($height * (1 - $scaleDownCount * 0.12)));
                    if ($newWidth >= $width * 0.5 && $newHeight >= $height * 0.5) {
                        $scaled = imagecreatetruecolor($newWidth, $newHeight);
                        if ($mimeType === 'image/png') {
                            imagealphablending($scaled, false);
                            imagesavealpha($scaled, true);
                            $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
                            imagefilledrectangle($scaled, 0, 0, $newWidth, $newHeight, $transparent);
                        }
                        imagecopyresampled($scaled, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                        imagedestroy($source);
                        $source = $scaled;
                        $width = $newWidth;
                        $height = $newHeight;
                        if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
                            $quality = 70;
                        } else {
                            $compression = 9;
                        }
                        continue;
                    }
                }
                if ($scaleDownCount >= 8 && (($mimeType === 'image/jpeg' && $quality <= 20) || ($mimeType !== 'image/jpeg' && $compression >= 9))) {
                    break;
                }
            }
            if ($bestData !== null) {
                file_put_contents($destPath, $bestData);
                imagedestroy($source);
                return true;
            }
            imagedestroy($source);
            return false;
        } catch (Exception $e) {
            error_log('compressUploadedImageFile error: ' . $e->getMessage());
            return false;
        } catch (Throwable $t) {
            error_log('compressUploadedImageFile fatal error: ' . $t->getMessage());
            return false;
        }
    }
}

// Note: AJAX output buffering started at top of file in AJAX SAFETY LAYER.
// Log the AJAX trigger for debugging purposes.
if ($__IS_AJAX) {
    $triggerPath = __DIR__ . '/debug_ajax_trigger.txt';
    $snap = ['time' => date('c'), 'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown', 'post_keys' => array_keys($_POST), 'cookie_keys' => array_keys($_COOKIE)];
    @file_put_contents($triggerPath, json_encode($snap) . "\n", FILE_APPEND | LOCK_EX);
}

// Avoid sending full page chrome for AJAX saves (AJAX expects JSON)
if (!(isset($_POST['ajax']) && $_POST['ajax'] === '1')) {
    include 'includes/header.php';
    ?>
    <link rel="stylesheet" href="assets/css/teacher_edit.css">
    <?php
}
// For AJAX requests, header/footer and inline CSS are omitted so responses are pure JSON

// Determine treasury code
$treasury = '';
if (isset($_GET['treasury_code']))
    $treasury = trim($_GET['treasury_code']);
if (isset($_POST['treasury_code']))
    $treasury = trim($_POST['treasury_code']);

if ($treasury === '') {
    echo '<div class="container main-body"><main class="main-content"><div class="notice">No Treasury Code provided.</div></main></div>';
    include 'includes/footer.php';
    exit;
}

$sessionKey = 'verified_teacher_' . $treasury;
$isVerified = (!empty($_SESSION['admin_loggedin']) || (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true));

if ($user_role === 'ADMIN' || $user_role === 'DEO' || $user_role === 'MEO') {
    $isVerified = true;
    $_SESSION[$sessionKey] = true;
}

if (!$isVerified) {
    echo '<div class="container main-body"><main class="main-content"><div class="notice">You are not authorized to edit this record. Please verify identity first.</div></main></div>';
    include 'includes/footer.php';
    exit;
}

// Load label mapping same as particulars
$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'label_map.json';
$labelMap = [];
if (file_exists($configPath) && is_readable($configPath)) {
    $json = file_get_contents($configPath);
    $decoded = json_decode($json, true);
    if (is_array($decoded))
        $labelMap = $decoded;
}
if (empty($labelMap)) {
    // minimal fallback
    $labelMap = ['TreasuryCode' => 'TreasuryCode', 'TchSurName' => 'TchSurName', 'TchFullName' => 'TchFullName', 'mobile' => 'mobile', 'dob' => 'dob'];
}

// Load pay-scale options from CSV if available. Expected CSV path: data/pay_scales.csv
// CSV format (header row): pay_scale,basic_pay,inc_month
$payOptions = [
    'pay_scale' => [],
    'basic_pay' => [],
    'inc_month' => []
];
$payCsv = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'pay_scales.csv';
if (is_readable($payCsv)) {
    if (($h = fopen($payCsv, 'r')) !== false) {
        $hdr = fgetcsv($h);
        // normalize header to lowercase
        $map = [];
        if ($hdr) {
            foreach ($hdr as $i => $hcol)
                $map[strtolower(trim($hcol))] = $i;
        }
        while (($row = fgetcsv($h)) !== false) {
            if (isset($map['pay_scale'])) {
                $v = trim($row[$map['pay_scale']] ?? '');
                if ($v !== '' && !in_array($v, $payOptions['pay_scale']))
                    $payOptions['pay_scale'][] = $v;
            }
            if (isset($map['basic_pay'])) {
                $v = trim($row[$map['basic_pay']] ?? '');
                if ($v !== '' && !in_array($v, $payOptions['basic_pay']))
                    $payOptions['basic_pay'][] = $v;
            }
            if (isset($map['inc_month'])) {
                $v = trim($row[$map['inc_month']] ?? '');
                if ($v !== '' && !in_array($v, $payOptions['inc_month']))
                    $payOptions['inc_month'][] = $v;
            }
        }
        fclose($h);
    }
}

// Build an ordered mapping array similar to particulars renderer
$rawMapping = [];
// Try Book1.csv for column order if present
$csvPaths = [__DIR__ . '/Book1.csv', __DIR__ . '/teacher_structure.csv'];
foreach ($csvPaths as $p) {
    if (is_readable($p)) {
        $h = fopen($p, 'r');
        if ($h) {
            $hdr = fgetcsv($h);
            while (($r = fgetcsv($h)) !== false) {
                if (count($r) !== count($hdr))
                    continue;
                $rawMapping[] = array_combine($hdr, $r);
            }
            fclose($h);
        }
    }
    if (!empty($rawMapping))
        break;
}
// fallback to label_map.json as simple mapping
if (empty($rawMapping) && is_array($labelMap)) {
    $order = 1;
    foreach ($labelMap as $k => $v) {
        if (is_array($v)) {
            $rawMapping[] = ['column_key_order' => $v['column_key_order'] ?? $order++, 'column_key_name' => $v['column_key_name'] ?? $k, 'column_key_label' => $v['column_key_label'] ?? ($v['label'] ?? $k), 'section_name_for_column_key' => $v['section_name_for_column_key'] ?? ($v['section'] ?? 'Miscellaneous')];
        } else {
            $rawMapping[] = ['column_key_order' => $order++, 'column_key_name' => $k, 'column_key_label' => $v, 'section_name_for_column_key' => 'Miscellaneous'];
        }
    }
}

// Normalize mapping into sections and grouped sections
$sections = [];
$groupedSections = [];
$groupOrder = [];
$groupTitles = [
    'personal_info_1' => 'Personal Information',
    'personal_info_2' => 'General Details (Spouse, Address, Bank)',
    'academic_qualifications' => 'Academic Qualifications',
    'professional_qualifications' => 'Professional Qualifications',
    'Departmental_qualifications' => 'Departmental Test Details',
    'service_particulars' => 'Service Particulars'
];

foreach ($rawMapping as $m) {
    $cn = trim($m['column_key_name'] ?? '');
    if ($cn === '')
        continue;
    $lbl = trim($m['column_key_label'] ?? $cn);
    $sec = trim($m['section_name_for_column_key'] ?? 'Miscellaneous');
    $grp = trim($m['grouped_section'] ?? 'miscellaneous_group'); // Default group if missing
    $ord = isset($m['column_key_order']) ? (int) $m['column_key_order'] : 0;

    // Store in flat sections array (kept for compatibility if needed, but we will mostly use groupedSections)
    if (!isset($sections[$sec]))
        $sections[$sec] = [];
    $sections[$sec][] = ['name' => $cn, 'label' => $lbl, 'order' => $ord];

    // Build grouped structure
    if (!isset($groupedSections[$grp])) {
        $groupedSections[$grp] = ['label' => $groupTitles[$grp] ?? ucwords(str_replace('_', ' ', $grp)), 'subsections' => []];
        $groupOrder[] = $grp; // Keep track of insertion order
    }
    if (!isset($groupedSections[$grp]['subsections'][$sec])) {
        $groupedSections[$grp]['subsections'][$sec] = [];
    }
    $groupedSections[$grp]['subsections'][$sec][] = ['name' => $cn, 'label' => $lbl, 'order' => $ord];
}

//Sort fields within subsections
foreach ($sections as $s => &$flds) {
    usort($flds, function ($a, $b) {
        return $a['order'] <=> $b['order'];
    });
}
unset($flds);

foreach ($groupedSections as $gKey => &$gData) {
    foreach ($gData['subsections'] as $sKey => &$sFlds) {
        usort($sFlds, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });
    }
}
unset($gData);
unset($sFlds);
$groupOrder = array_unique($groupOrder);

// Select/dropdown options (keys are lowercase column names)
$selectOptions = [
    'caste' => ['OC', 'BC A', 'BC B', 'BC C', 'BC D', 'BC E', 'SC GR.I', 'SC GR.II', 'SC GR.III', 'ST'],
    'designation' => [
        'GHM-Gr.II',
        'SA TELUGU',
        'SA URDU',
        'SA HINDI',
        'SA ENGLISH',
        'SA MATHS',
        'SA MATHS UM',
        'SA PHY SCI',
        'SA PHY SCI UM',
        'SA BIO SCI',
        'SA BIO SCI UM',
        'SA SOCIAL',
        'SA SOCIAL UM',
        'SA PD',
        'SA SPL_EDN',
        'PSHM',
        'SGT',
        'SGT UM',
        'LP TELUGU',
        'LP HINDI',
        'LP URDU',
        'SGT SPL_EDN',
        'PET',
        'VOC',
        'CI',
        'DM',
        'MUSIC'
    ],
    'gender' => ['MALE', 'FEMALE'],
    'phcyn' => ['YES', 'NO'],
    'phctype' => ['VH', 'HH', 'OH', 'MD'],
    'phcauth' => ['SADAREM', 'MEDICAL BOARD', 'OTHER'],
    'phccertreassess' => ['YES', 'NO'],
    'maritalstatus' => ['MARRIED', 'UN-MARRIED', 'WIDOW', 'LEGALLY SEPERATED'],
    'spgovtempyn' => ['YES', 'NO'],
    'spworkarea' => ['DISTRICT', 'ZONAL', 'MULTI-ZONAL', 'STATE'],
    'spdepttype' => ['SCH. EDN. DEPT.(TG).', 'STATE GOVT.', 'CENTRAL GOVT.', 'AIDED', 'CORPORATION'],
    'posttype' => ['TRANSFERRABLE', 'NON-TRANSFERRABLE'],
    'assmblyconstdist' => [], // Will be populated from aclist.csv
    'assmblyconstname' => [], // Will be populated based on district selection
    'rsconstname' => [], // Will be populated from aclist.csv Constt column
    'nativeconstname' => [], // Will be populated from aclist.csv Constt column
    'workconstname' => [], // Will be populated from aclist.csv Constt column
    'ssctype' => ['REGULAR', 'OPEN (TOSS)', 'VOCATIONAL'],
    'sscmed' => ['TELUGU', 'ENGLISH', 'URDU', 'HINDI'],
    'ssclang1' => ['TELUGU', 'ENGLISH', 'URDU', 'HINDI', 'SANSKRIT'],
    'ssclang2' => ['TELUGU', 'ENGLISH', 'URDU', 'HINDI', 'SANSKRIT'],
    'intertype' => ['REGULAR', 'OPEN (TOSS)', 'VOCATIONAL'],
    'intermed' => ['TELUGU', 'ENGLISH', 'URDU', 'HINDI'],
    'interlang1' => ['TELUGU', 'ENGLISH', 'URDU', 'HINDI', 'SANSKRIT'],
    'interlang2' => ['TELUGU', 'ENGLISH', 'URDU', 'HINDI', 'SANSKRIT'],
    'deg1type' => ['REGULAR', 'OPEN']
];

// Additional dropdowns requested
$selectOptions['deg2type'] = ['REGULAR', 'OPEN'];
$selectOptions['deg1med'] = ['TELUGU', 'ENGLISH', 'URDU', 'HINDI'];
$selectOptions['deg2med'] = ['TELUGU', 'ENGLISH', 'URDU', 'HINDI'];
$selectOptions['ugtrngcourse'] = ['TTC', 'DEd', 'UGPEd', 'D.El.Ed.', 'Spl. DEd.'];
$selectOptions['ugtrngmedium'] = ['TELUGU', 'ENGLISH', 'URDU', 'HINDI'];
$selectOptions['grad1trngcourse'] = ['BEd', 'BPEd', 'Spl. BEd.', 'LPT', 'LPH', 'LPU'];
$selectOptions['grad1trngmed'] = ['TELUGU', 'ENGLISH', 'URDU', 'HINDI'];
$selectOptions['grad2trngcourse'] = ['BEd', 'BPEd', 'Spl. BEd.', 'LPT', 'LPH', 'LPU'];
$selectOptions['grad2trngmed'] = ['TELUGU', 'ENGLISH', 'URDU', 'HINDI'];
$selectOptions['medcourse'] = ['MEd', 'MPEd'];

// Sgt and SA related dropdowns
$selectOptions['sgtapptype'] = ['DSC/ TRT', 'Spl DSC (398)', 'UNTRAINED/ Spl VV', 'DSC (CONTRACTUAL)', 'COMPASSIONATE'];
$selectOptions['sgtcadredesign'] = ['SGT', 'SGT (Spl.Edn.)', 'PET', 'LPT', 'LPH', 'LPU', 'CI', 'DM', 'Voc. Ins.', 'MUSIC-Tr'];
$selectOptions['sgtmgmnt'] = ['GOVT', 'LB'];
$selectOptions['sgtrendered'] = ['YES', 'NO'];
$selectOptions['sgtapptype'] = ['DSC/ TRT', 'Spl DSC (398)', 'UNTRAINED/ Spl VV', 'DSC (CONTRACTUAL)', 'COMPASSIONATE'];
$selectOptions['sgtcadredesign'] = ['SGT', 'SGT (Spl.Edn.)', 'PET', 'LPT', 'LPH', 'LPU', 'CI', 'DM', 'Voc. Ins.', 'MUSIC-Tr'];
$selectOptions['sgtdsclist'] = ['GENERAL', 'BC-A', 'BC-B', 'BC-C', 'BC-D', 'BC-E', 'SC', 'ST', 'EWS', 'PH'];
$selectOptions['sgtmgmnt'] = ['GOVT', 'LB'];

$selectOptions['sacadredesign'] = ['SA BIO-SCI', 'SA ENGLISH', 'SA HINDI', 'SA MATHS', 'SA PD', 'SA PHY-SCI', 'SA SOCIAL', 'SA SPL_EDN', 'SA TELUGU', 'SA URDU', 'PSHM', 'JL', 'MEO', 'PGT'];
$selectOptions['saapptype'] = ['DSC/ TRT', 'PROMOTION', 'UNTRAINED/ Spl VV'];
$selectOptions['salist'] = ['GENERAL', 'BC-A', 'BC-B', 'BC-C', 'BC-D', 'BC-E', 'SC', 'ST', 'EWS', 'PH'];
$selectOptions['samgmnt'] = ['GOVT', 'LB'];
$selectOptions['saapptype'] = ['DSC/ TRT', 'PROMOTION', 'UNTRAINED/ Spl VV'];
$selectOptions['samgmnt'] = ['GOVT', 'LB'];

$selectOptions['ghmgriiapp'] = ['PROMOTION'];
$selectOptions['ghmgriidesign'] = ['GHM Gr.II'];

$selectOptions['idtmutualyn'] = ['YES', 'NO'];
$selectOptions['idtmutualcdr'] = ['SA BIO SCI', 'SA ENGLISH', 'SA HINDI', 'SA MATHS', 'SA PD', 'SA PHY SCI', 'SA SOCIAL', 'SA SPL_EDN', 'SA TELUGU', 'SA URDU', 'LP HINDI', 'LP TELUGU', 'LP URDU', 'PET', 'VOC', 'CI', 'DM', 'MUSIC', 'PSHM', 'SGT', 'SGT SPL_EDN'];

// Load constituency data from aclist.csv
$acListFile = __DIR__ . '/aclist.csv';
$districtOptions = [];
$constituencyOptions = [];
$districtConstMap = []; // For cascaded dropdowns

if (file_exists($acListFile)) {
    $handle = fopen($acListFile, 'r');
    $headers = fgetcsv($handle); // Skip header row

    while (($row = fgetcsv($handle)) !== false) {
        $dist = trim($row[0]);
        $constt = trim($row[1]);

        // Collect unique districts
        if (!in_array($dist, $districtOptions)) {
            $districtOptions[] = $dist;
        }

        // Collect all constituencies
        if (!in_array($constt, $constituencyOptions)) {
            $constituencyOptions[] = $constt;
        }

        // Map districts to their constituencies
        if (!isset($districtConstMap[$dist])) {
            $districtConstMap[$dist] = [];
        }
        $districtConstMap[$dist][] = $constt;
    }
    fclose($handle);
}

// Helper function to extract number from constituency name for sorting
if (!function_exists('extractNumberFromConstituency')) {
    function extractNumberFromConstituency($constituency)
    {
        // Extract the number before the hyphen (e.g., "1-Sirpur" -> 1)
        if (preg_match('/^(\d+)-/', $constituency, $matches)) {
            return (int) $matches[1];
        }
        // If no number found, return 999 to sort at the end
        return 999;
    }
}

// Sort the arrays
sort($districtOptions);

// Sort constituencies numerically by the number before the hyphen
usort($constituencyOptions, function ($a, $b) {
    $numA = extractNumberFromConstituency($a);
    $numB = extractNumberFromConstituency($b);
    return $numA - $numB;
});

// Populate selectOptions
$selectOptions['assmblyconstdist'] = $districtOptions;
$selectOptions['assmblyconstname'] = $constituencyOptions; // Initially populate with all constituencies, JavaScript will filter
$selectOptions['rsconstname'] = $constituencyOptions;
$selectOptions['nativeconstname'] = $constituencyOptions;
$selectOptions['workconstname'] = $constituencyOptions;

// eligible promotions
$promoOpts = ['GHM-Gr.II', 'SA BIO SCI', 'SA ENGLISH', 'SA HINDI', 'SA MATHS', 'SA PD', 'SA PHY SCI', 'SA SOCIAL', 'SA SPL_EDN', 'SA TELUGU', 'SA URDU', 'PSHM', 'JL', 'MEO', 'PGT'];
$selectOptions['eligible_promotion_1'] = $promoOpts;
$selectOptions['eligible_promotion_2'] = $promoOpts;
$selectOptions['eligible_promotion_3'] = $promoOpts;
$selectOptions['eligible_promotion_4'] = $promoOpts;

// Handle form submit: update all fields submitted (safe whitelist)
$msg = '';
// generate CSRF token for form if not present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// Note: Removed automatic caste certificate deletion when caste changes from SC/ST to non-SC/ST
// Files are now preserved and only hidden/shown based on caste selection

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_all']) || isset($_POST['save_tab']) || isset($_POST['save_section']))) {
    // verify CSRF token
    $postedToken = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        $msg = 'CSRF token mismatch. Please reload the form and try again.';
    } else {
        // Early MEO mandal validation: prevent MEO users from saving edits
        if ($user_role === 'MEO') {
            // Use the current treasury code (from GET/POST parsing earlier)
            $checkTreasury = $treasury;
            if (!empty($checkTreasury)) {
                if ($mstmt = $conn->prepare('SELECT SchMandal FROM teacherdata WHERE TreasuryCode = ? LIMIT 1')) {
                    $mstmt->bind_param('s', $checkTreasury);
                    $mstmt->execute();
                    $mres = $mstmt->get_result();
                    $teacher_mandal = '';
                    if ($mres && $mres->num_rows) {
                        $trow = $mres->fetch_assoc();
                        $teacher_mandal = $trow['SchMandal'] ?? '';
                    }
                    $mstmt->close();
                    $user_info = get_user_info();
                    $meo_mandal = $user_info['mandal'] ?? '';
                    if ($teacher_mandal !== '' && $meo_mandal !== '' && trim($teacher_mandal) !== trim($meo_mandal)) {
                        $msg = 'Access Denied: MEO users can only edit teachers within their mandal.';
                        // If AJAX, return JSON immediately
                        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                            while (ob_get_level())
                                ob_end_clean();
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'message' => $msg]);
                            exit;
                        }
                        // Otherwise stop processing; later code will show the message
                    }
                }
            }
        }

        // Check if MEO user is trying to save Service tab
        $isTabSave = isset($_POST['save_tab']);
        $currentTab = $isTabSave ? $_POST['save_tab'] : '';

        if ($user_role === 'MEO' && ($currentTab === 'Service' || isset($_POST['save_all']))) {
            // MEO cannot save service tab fields - block if trying to save Service tab
            if ($currentTab === 'Service') {
                $msg = 'MEO users do not have permission to edit Service Particulars. Service tab fields are locked.';
            }
            // If saving all, we'll skip service fields in the update process below
        }

        if (empty($msg)) {
            // --- Compatibility helper: provide lowercase aliases for POST keys ---
            // This allows gradual migration where code can use lowercase keys without changing DB column names yet.
            if (!empty($_POST) && !isset($__POST_LOWER_DONE)) {
                $__POST_LOWER_DONE = true;
                foreach ($_POST as $pk => $pv) {
                    $lk = strtolower($pk);
                    if (!array_key_exists($lk, $_POST)) {
                        // only create alias if lowercase key does not already exist
                        $_POST[$lk] = $pv;
                    }
                }
            }

            // Helper function to fetch POST values case-insensitively (preferred)
            if (!function_exists('post_val')) {
                function post_val($key, $default = '')
                {
                    // prefer exact key, else lowercase alias
                    if (array_key_exists($key, $_POST))
                        return trim((string) $_POST[$key]);
                    $lk = strtolower($key);
                    if (array_key_exists($lk, $_POST))
                        return trim((string) $_POST[$lk]);
                    return $default;
                }
            }
            // Server-side validation for required fields
            $isTabSave = isset($_POST['save_tab']) || isset($_POST['save_section']);
            $currentTab = isset($_POST['save_tab']) ? $_POST['save_tab'] : (isset($_POST['save_section']) ? $_POST['save_section'] : '');

            // Define which fields are required for each Group (using Group Labels as keys)
            $tabRequiredFields = [
                'Personal Information' => ['TreasuryCode', 'TchSurName', 'TchFullName', 'FatherName', 'Designation', 'dob', 'dort', 'gender', 'caste', 'subcaste', 'PhcYN', 'adhaar', 'mobile', 'MaritalStatus'],

                'General Details (Spouse, Address, Bank)' => ['ResHno', 'ResStreet', 'ResMandal', 'ResDist', 'NatHno', 'NatStreet', 'NatMandal', 'NatDist', 'AccountNo', 'IfscCode', 'branch', 'bank', 'acstate', 'gpftype', 'gpfacno', 'tsgliacno', 'EpicNo', 'PartNo', 'SerialNo', 'AssmblyConstDist', 'AssmblyConstName', 'RsConstName', 'NativeConstName', 'WorkConstName'],

                'Academic Qualifications' => ['SscType', 'SscYear', 'SscMed', 'SscLang1', 'SscLang2', 'InterType', 'InterYear', 'InterCourse', 'InterMed', 'InterLang1', 'InterLang2'],

                'Professional Qualifications' => [], // Dynamic checks handle specific course details

                'Departmental Test Details' => [], // Mostly optional or dynamic

                'Service Particulars' => $can_edit_service ? ['division', 'SchMandal', 'SchName', 'SchCode', 'school_type', 'SchJoinDate', 'sgtrendered', 'SgtAppType', 'SgtCadreDesign', 'SgtJoinDate', 'SaCadreDesign', 'SaAppType', 'SaJoinDate', 'DiesNonDays', 'EOLDays'] : []
            ];

            $serviceFields = ['division', 'SchMandal', 'SchName', 'SchCode', 'school_type', 'SchJoinDate', 'sgtrendered', 'SgtAppType', 'SgtCadreDesign', 'SgtJoinDate', 'SaCadreDesign', 'SaAppType', 'SaJoinDate', 'DiesNonDays', 'EOLDays'];

            // Allow legacy keys for safety (in case old JS cached or fallback)
            $tabRequiredFields['Personal Info'] = $tabRequiredFields['Personal Information'];
            $tabRequiredFields['Address-EPIC'] = $tabRequiredFields['General Details (Spouse, Address, Bank)'];
            $tabRequiredFields['Academics'] = $tabRequiredFields['Academic Qualifications'];
            $tabRequiredFields['Professional'] = $tabRequiredFields['Professional Qualifications'];
            $tabRequiredFields['Service'] = $tabRequiredFields['Service Particulars'];

            // Map internal group keys (matches logic in JS and $groupTitles)
            $tabRequiredFields['personal_info_1'] = $tabRequiredFields['Personal Information'];
            $tabRequiredFields['personal_info_2'] = $tabRequiredFields['General Details (Spouse, Address, Bank)'];
            $tabRequiredFields['academic_qualifications'] = $tabRequiredFields['Academic Qualifications'];
            $tabRequiredFields['professional_qualifications'] = $tabRequiredFields['Professional Qualifications'];
            $tabRequiredFields['Departmental_qualifications'] = $tabRequiredFields['Departmental Test Details'];
            $tabRequiredFields['service_particulars'] = $tabRequiredFields['Service Particulars'];

            // All required fields for full form save
            $allRequiredFields = ['TreasuryCode', 'TchSurName', 'TchFullName', 'FatherName', 'Designation', 'dob', 'dort', 'gender', 'caste', 'subcaste', 'adhaar', 'mobile', 'AccountNo', 'IfscCode', 'branch', 'bank', 'acstate', 'gpftype', 'gpfacno', 'tsgliacno', 'MaritalStatus', 'PhcYN', 'division', 'SchMandal', 'SchName', 'SchCode', 'school_type', 'SchJoinDate', 'ResHno', 'ResStreet', 'ResMandal', 'ResDist', 'NatHno', 'NatStreet', 'NatMandal', 'NatDist', 'EpicNo', 'PartNo', 'SerialNo', 'AssmblyConstDist', 'AssmblyConstName', 'RsConstName', 'NativeConstName', 'WorkConstName', 'SscType', 'SscYear', 'SscMed', 'SscLang1', 'SscLang2', 'InterType', 'InterYear', 'InterCourse', 'InterMed', 'InterLang1', 'InterLang2'];
            if (!$can_edit_service) {
                $allRequiredFields = array_values(array_diff($allRequiredFields, $serviceFields));
            }

            // Determine which fields to validate based on save type
            if ($isTabSave && isset($tabRequiredFields[$currentTab])) {
                $requiredFields = $tabRequiredFields[$currentTab];
            } else {
                $requiredFields = $allRequiredFields;
            }

            // If DEd/TTC trainings are explicitly set to 0, do not enforce related fields as required
            $dedTrainingsAcquired = '';
            if (isset($_POST['ded_trainings_acquired'])) {
                $dedTrainingsAcquired = trim($_POST['ded_trainings_acquired']);
            }

            // Fetch existing row to allow previously-saved values to satisfy required checks
            $curRow = [];
            if ($cstmt = $conn->prepare('SELECT * FROM teacherdata WHERE TreasuryCode = ? LIMIT 1')) {
                $cstmt->bind_param('s', $treasury);
                $cstmt->execute();
                $cres = $cstmt->get_result();
                if ($cres && $cres->num_rows)
                    $curRow = $cres->fetch_assoc();
                $cstmt->close();
            }
            // normalize existing row keys to lowercase for case-insensitive lookup
            $curLower = [];
            if (!empty($curRow))
                foreach ($curRow as $ck => $cv)
                    $curLower[strtolower($ck)] = $cv;

            if ($dedTrainingsAcquired === '' && isset($curLower['ded_trainings_acquired'])) {
                $dedTrainingsAcquired = trim((string) $curLower['ded_trainings_acquired']);
            }
            if ($dedTrainingsAcquired === '0') {
                $dedRelaxFields = ['ugtrngcourse', 'ugtrngmedium', 'ugtrngboarduniv', 'ugtrngpassyr', 'ugtrngpercent'];
                foreach ($requiredFields as $idx => $field) {
                    if (in_array(strtolower($field), $dedRelaxFields, true)) {
                        unset($requiredFields[$idx]);
                    }
                }
                $requiredFields = array_values($requiredFields);
            }

            $missingFields = [];

            foreach ($requiredFields as $field) {
                // prefer posted value (case-insensitive), else fallback to existing DB value
                if (function_exists('post_val')) {
                    $value = post_val($field, '');
                } else {
                    $value = isset($_POST[$field]) ? trim($_POST[$field]) : (isset($_POST[strtolower($field)]) ? trim($_POST[strtolower($field)]) : '');
                }
                if ($value === '' || $value === '-- Select --') {
                    $lk = strtolower($field);
                    if (isset($curLower[$lk]) && trim((string) $curLower[$lk]) !== '') {
                        $value = trim((string) $curLower[$lk]);
                    }
                }
                if ($value === '' || $value === '-- Select --') {
                    $missingFields[] = $field;
                }
            }

            // Additional server-side enforcement for Service tab fields (prevent silent saves)
            // Only run when saving the Service tab or saving the full form
            $serviceCheckNeeded = (!$isTabSave) || ($isTabSave && $currentTab === 'Service');
            if ($serviceCheckNeeded) {
                $svcMissing = [];
                // canonicalize posted values with fallback to DB values
                if (function_exists('post_val')) {
                    $sgtrendered = strtoupper(trim(post_val('sgtrendered', post_val('SgtRendered', ''))));
                } else {
                    $sgtrendered = isset($_POST['sgtrendered']) ? strtoupper(trim($_POST['sgtrendered'])) : (isset($_POST['SgtRendered']) ? strtoupper(trim($_POST['SgtRendered'])) : '');
                    if ($sgtrendered === '') {
                        $sgtrendered = isset($curLower['sgtrendered']) ? strtoupper(trim((string) $curLower['sgtrendered'])) : '';
                    }
                }
                // If SGT rendered is YES, ensure key SGT fields are present
                if ($sgtrendered === 'YES' || $sgtrendered === 'Y') {
                    $needed = ['SgtAppType' => 'SGT Appointment Type', 'SgtCadreDesign' => 'SGT Cadre Designation', 'SgtJoinDate' => 'SGT Cadre Joining Date'];
                    foreach ($needed as $k => $label) {
                        $v = isset($_POST[$k]) ? trim((string) $_POST[$k]) : '';
                        if ($v === '' || $v === '-- Select --')
                            $svcMissing[] = $label;
                    }
                }
                // If SA designation present (non-empty), ensure SA appointment type and join date
                if (function_exists('post_val')) {
                    $saDesig = trim((string) post_val('SaCadreDesign', post_val('saCadreDesign', '')));
                } else {
                    $saDesig = isset($_POST['SaCadreDesign']) ? trim((string) $_POST['SaCadreDesign']) : (isset($_POST['saCadreDesign']) ? trim((string) $_POST['saCadreDesign']) : '');
                    if ($saDesig === '' && isset($curLower['sacadredesign']))
                        $saDesig = trim((string) $curLower['sacadredesign']);
                }
                if ($saDesig !== '') {
                    $v = isset($_POST['SaAppType']) ? trim((string) $_POST['SaAppType']) : '';
                    $vj = isset($_POST['SaJoinDate']) ? trim((string) $_POST['SaJoinDate']) : '';
                    if ($v === '' || $v === '-- Select --')
                        $svcMissing[] = 'SA Appointment Type';
                    if ($vj === '')
                        $svcMissing[] = 'SA Cadre Joining Date';
                }

                // If any service-specific required fields are missing, block save
                if (!empty($svcMissing)) {
                    $msg = 'Please fill all required Service fields before saving: ' . implode(', ', $svcMissing);
                    $missingFields = array_merge($missingFields, $svcMissing);
                }
            }

            // Check caste certificate for SC/ST
            $postedCaste = isset($_POST['caste']) ? trim($_POST['caste']) : '';
            if (preg_match('/(SC|ST)/i', $postedCaste)) {
                // Check if certificate exists in database or is being uploaded
                $stmt = $conn->prepare('SELECT CasteCert FROM teacherdata WHERE TreasuryCode = ? LIMIT 1');
                $stmt->bind_param('s', $treasury);
                $stmt->execute();
                $result = $stmt->get_result();
                $existingCert = '';
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $existingCert = trim($row['CasteCert'] ?? '');
                }
                $stmt->close();

                // Accept files of any size - validation will occur during processing
                $hasUpload = isset($_FILES['CasteCert_file']) && $_FILES['CasteCert_file']['error'] !== UPLOAD_ERR_NO_FILE;
                if (!$existingCert && !$hasUpload) {
                    $missingFields[] = 'CasteCert (required for SC/ST)';
                }
            }

            // Chec k PHC fields if PhcYN is YES
            $postedPhcYN = isset($_POST['PhcYN']) ? trim($_POST['PhcYN']) : '';
            if (strtoupper($postedPhcYN) === 'YES') {
                $phcRequiredFields = ['PhcType', 'PhcPercent', 'PhcAuth', 'PhcCertNo', 'PhcCertDate', 'PhcCertValidity', 'PhcCertReassess'];
                foreach ($phcRequiredFields as $phcField) {
                    $value = isset($_POST[$phcField]) ? trim($_POST[$phcField]) : '';
                    if ($value === '' || $value === '-- Select --') {
                        $missingFields[] = $phcField . ' (required when PHC = YES)';
                    }
                }

                // Check PHC certificate upload
                $stmt = $conn->prepare('SELECT PhcUpload FROM teacherdata WHERE TreasuryCode = ? LIMIT 1');
                $stmt->bind_param('s', $treasury);
                $stmt->execute();
                $result = $stmt->get_result();
                $existingPhcCert = '';
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $existingPhcCert = $row['PhcUpload'] ?? '';
                }
                $stmt->close();

                // Accept files of any size - validation will occur during processing
                $hasPhcUpload = isset($_FILES['Phcupload_file']) && $_FILES['Phcupload_file']['error'] !== UPLOAD_ERR_NO_FILE;

                if (!$existingPhcCert && !$hasPhcUpload) {
                    $missingFields[] = 'PHC Certificate (required when PHC = YES)';
                }
            }

            // Check spouse information requirements
            $maritalStatus = isset($_POST['MaritalStatus']) ? trim($_POST['MaritalStatus']) : '';
            if (strtoupper($maritalStatus) === 'MARRIED') {
                // SpGovtEmpYN is required when married
                $spGovtEmpYN = isset($_POST['SpGovtEmpYN']) ? trim($_POST['SpGovtEmpYN']) : '';
                if ($spGovtEmpYN === '' || $spGovtEmpYN === '-- Select --') {
                    $missingFields[] = 'Spouse Government Employee Status (required when married)';
                }

                // If spouse is government employee, all spouse details are required
                if (strtoupper($spGovtEmpYN) === 'YES') {
                    $spouseRequiredFields = ['SpTreasuryCode', 'SpDesign', 'SpName', 'SpDeptName', 'SpOfficeName', 'SpWorkArea', 'SpDeptType', 'PostType'];
                    foreach ($spouseRequiredFields as $spField) {
                        $value = isset($_POST[$spField]) ? trim($_POST[$spField]) : '';
                        if ($value === '' || $value === '-- Select --') {
                            $missingFields[] = $spField . ' (required when spouse is government employee)';
                        }
                    }
                }
            }

            // Check degree requirements based on degrees_acquired dropdown
            $degreesAcquired = isset($_POST['degrees_acquired']) ? trim($_POST['degrees_acquired']) : '';
            if ($degreesAcquired !== '') {
                $degreesCount = intval($degreesAcquired);

                if ($degreesCount >= 1) {
                    // D1 (Main degree) fields are required
                    $d1RequiredFields = ['Deg1Type', 'Deg1Course', 'Deg1Med', 'Deg1Opt1', 'Deg1Univ', 'Deg1PassYr', 'Deg1Percent'];
                    foreach ($d1RequiredFields as $d1Field) {
                        $value = isset($_POST[$d1Field]) ? trim($_POST[$d1Field]) : '';
                        if ($value === '' || $value === '-- Select --') {
                            $missingFields[] = $d1Field . ' (required when 1+ degrees selected)';
                        }
                    }
                }

                if ($degreesCount >= 2) {
                    // D2 (Additional degree) fields are required
                    $d2RequiredFields = ['Deg2Type', 'Deg2Course', 'Deg2Med', 'Deg2Opt1', 'Deg2Univ', 'Deg2PassYr', 'Deg2Percent'];
                    foreach ($d2RequiredFields as $d2Field) {
                        $value = isset($_POST[$d2Field]) ? trim($_POST[$d2Field]) : '';
                        if ($value === '' || $value === '-- Select --') {
                            $missingFields[] = $d2Field . ' (required when 2 degrees selected)';
                        }
                    }
                }
            }

            // Check PG degree requirements based on pg_degrees_acquired dropdown
            $pgDegreesAcquired = isset($_POST['pg_degrees_acquired']) ? trim($_POST['pg_degrees_acquired']) : '';
            if ($pgDegreesAcquired !== '') {
                $pgDegreesCount = intval($pgDegreesAcquired);

                if ($pgDegreesCount >= 1) {
                    // PG1 fields are required
                    $pg1RequiredFields = ['Pg1Course', 'Pg1Subject', 'Pg1Univ', 'Pg1PassYr', 'Pg1Percent'];
                    foreach ($pg1RequiredFields as $pg1Field) {
                        $value = isset($_POST[$pg1Field]) ? trim($_POST[$pg1Field]) : '';
                        if ($value === '' || $value === '-- Select --') {
                            $missingFields[] = $pg1Field . ' (required when 1+ PG degrees selected)';
                        }
                    }
                }

                if ($pgDegreesCount >= 2) {
                    // PG2 fields are required
                    $pg2RequiredFields = ['Pg2Course', 'Pg2Subject', 'Pg2Univ', 'Pg2PassYr', 'Pg2Percent'];
                    foreach ($pg2RequiredFields as $pg2Field) {
                        $value = isset($_POST[$pg2Field]) ? trim($_POST[$pg2Field]) : '';
                        if ($value === '' || $value === '-- Select --') {
                            $missingFields[] = $pg2Field . ' (required when 2 PG degrees selected)';
                        }
                    }
                }
            }

            // Check PT training requirements based on pt_trainings_acquired dropdown
            $ptTrainingsAcquired = isset($_POST['pt_trainings_acquired']) ? trim($_POST['pt_trainings_acquired']) : '';
            if ($ptTrainingsAcquired !== '') {
                $ptTrainingsCount = intval($ptTrainingsAcquired);

                if ($ptTrainingsCount >= 1) {
                    // PT1 fields are required
                    $pt1RequiredFields = ['Grad1TrngCourse', 'Grad1TrngMed', 'Grad1TrngMthd1', 'Grad1TrngMthd2', 'Grad1TrngUniv', 'Grad1TrngPassYr', 'Grad1TrngPercent'];
                    foreach ($pt1RequiredFields as $pt1Field) {
                        $value = isset($_POST[$pt1Field]) ? trim($_POST[$pt1Field]) : '';
                        if ($value === '' || $value === '-- Select --') {
                            $missingFields[] = $pt1Field . ' (required when 1+ PT trainings selected)';
                        }
                    }
                }

                if ($ptTrainingsCount >= 2) {
                    // PT2 fields are required
                    $pt2RequiredFields = ['Grad2TrngCourse', 'Grad2TrngMed', 'Grad2TrngMthd1', 'Grad2TrngMthd2', 'Grad2TrngUniv', 'Grad2TrngPassYr', 'Grad2TrngPercent'];
                    foreach ($pt2RequiredFields as $pt2Field) {
                        $value = isset($_POST[$pt2Field]) ? trim($_POST[$pt2Field]) : '';
                        if ($value === '' || $value === '-- Select --') {
                            $missingFields[] = $pt2Field . ' (required when 2 PT trainings selected)';
                        }
                    }
                }
            }

            // Check PT PG training requirements based on pt_pg_trainings_acquired dropdown
            $ptPgTrainingsAcquired = isset($_POST['pt_pg_trainings_acquired']) ? trim($_POST['pt_pg_trainings_acquired']) : '';
            if ($ptPgTrainingsAcquired !== '') {
                $ptPgTrainingsCount = intval($ptPgTrainingsAcquired);

                if ($ptPgTrainingsCount >= 1) {
                    // PT PG fields are required
                    $ptpgRequiredFields = ['MedCourse', 'MedUniv', 'MedPassYr', 'MedPercent'];
                    foreach ($ptpgRequiredFields as $ptpgField) {
                        $value = isset($_POST[$ptpgField]) ? trim($_POST[$ptpgField]) : '';
                        if ($value === '' || $value === '-- Select --') {
                            $missingFields[] = $ptpgField . ' (required when PT PG training selected)';
                        }
                    }
                }
            }

            if (!empty($missingFields)) {
                $msg = 'Please fill all required fields: ' . implode(', ', $missingFields);
            } else {
                // Determine allowed columns to update from mapping
                $allowed = [];
                foreach ($sections as $sec => $flds)
                    foreach ($flds as $f)
                        $allowed[] = $f['name'];

                // Add dropdown columns for degree/training selections
                $allowed[] = 'degrees_acquired';
                $allowed[] = 'pg_degrees_acquired';
                $allowed[] = 'pt_trainings_acquired';
                $allowed[] = 'pt_pg_trainings_acquired';
                $allowed[] = 'ded_trainings_acquired';

                // remove TreasuryCode from update list
                $allowed = array_filter(array_unique($allowed));
                $updates = [];
                $params = [];
                $types = '';
                // --- Compute Section W (Cadre Seniority) server-side and ensure values are stored ---
                // Use posted values when available, otherwise fall back to DB current value
                try {
                    // fetch existing row to use as fallback
                    $curRow = [];
                    if ($cstmt = $conn->prepare('SELECT * FROM teacherdata WHERE TreasuryCode = ? LIMIT 1')) {
                        $cstmt->bind_param('s', $treasury);
                        $cstmt->execute();
                        $cres = $cstmt->get_result();
                        if ($cres && $cres->num_rows)
                            $curRow = $cres->fetch_assoc();
                        $cstmt->close();
                    }
                    $posted = function ($k) use (&$curRow) {
                        if (isset($_POST[$k]) && trim((string) $_POST[$k]) !== '')
                            return trim((string) $_POST[$k]);
                        $k2 = strtolower($k);
                        foreach ($curRow as $rk => $rv) {
                            if (strtolower($rk) === $k2 && $rv !== null && trim((string) $rv) !== '')
                                return trim((string) $rv);
                        }
                        return '';
                    };

                    $designation = $posted('Designation') ?: $posted('designation');
                    // Normalize designation using label_map if available: map human label -> canonical DB key
                    try {
                        if (!empty($designation) && !empty($labelMap) && is_array($labelMap)) {
                            foreach ($labelMap as $k => $v) {
                                if (strcasecmp(trim($v), trim($designation)) === 0) {
                                    $designation = $k;
                                    break;
                                }
                            }
                        }
                    } catch (Throwable $t) { /* ignore mapping errors */
                    }
                    $sgtApp = $posted('SgtAppType') ?: $posted('sgtapptype');
                    $saApp = $posted('SaAppType') ?: $posted('saapptype');
                    $sgtJoin = $posted('SgtJoinDate') ?: $posted('sgtjoindate');
                    $sgtReg = $posted('SgtRegDate') ?: $posted('sgtregdate');
                    $sgtAbs = $posted('SgtAbsrpDate') ?: $posted('sgtabsrpdate');
                    $saJoin = $posted('SaJoinDate') ?: $posted('sajoindate');
                    $saReg = $posted('SaRegDate') ?: $posted('saregdate');
                    $ghmDoj = $posted('GHMGrIIDOJ') ?: $posted('ghmgriidoj');

                    // helper
                    $inList = function ($name, $list) {
                        if (!$name)
                            return false;
                        $n = strtoupper(trim($name));
                        foreach ($list as $it)
                            if (strtoupper(trim($it)) === $n)
                                return true;
                        return false;
                    };

                    $sgtDesigns = ['LP HINDI', 'LP TELUGU', 'LP URDU', 'PET', 'VOC', 'CI', 'DM', 'MUSIC', 'SGT', 'SGT UM', 'SGT SPL_EDN'];
                    $saDesigns = ['SA BIO SCI', 'SA ENGLISH', 'SA HINDI', 'SA MATHS', 'SA PD', 'SA PHY SCI', 'SA SOCIAL', 'SA SPL_EDN', 'SA TELUGU', 'SA URDU', 'PSHM'];

                    $computedDofapp = '';
                    $computedDofdr = '';
                    $computedDojpc = '';

                    if ($inList($designation, $sgtDesigns)) {
                        $computedDofdr = 'NULL';
                        $s = strtoupper($sgtApp);
                        if ($s === 'DSC/ TRT' || $s === 'COMPASSIONATE') {
                            $computedDofapp = $sgtJoin;
                            $computedDojpc = $sgtJoin;
                        } elseif ($s === 'UNTRAINED/ SPL VV') {
                            $computedDofapp = $sgtJoin;
                            $computedDojpc = $sgtReg;
                        } elseif (stripos($s, 'SPL DSC') !== false || stripos($s, 'CONTRACTUAL') !== false || stripos($s, 'DSC (CONTRACTUAL)') !== false) {
                            $computedDofapp = $sgtJoin;
                            $computedDojpc = $sgtAbs;
                        } else {
                            $computedDofapp = $sgtJoin;
                            $computedDojpc = $sgtJoin;
                        }
                    }

                    if ($inList($designation, $saDesigns)) {
                        $s = strtoupper($saApp);
                        if ($s === 'DSC/ TRT' || $s === 'COMPASSIONATE') {
                            $computedDofdr = 'NULL';
                            $computedDofapp = $saJoin;
                            $computedDojpc = $saJoin;
                        } elseif ($s === 'UNTRAINED/ SPL VV') {
                            $computedDofdr = 'NULL';
                            $computedDofapp = $saJoin;
                            $computedDojpc = $saReg;
                        } elseif ($s === 'PROMOTION') {
                            $computedDofapp = $sgtJoin;
                            $computedDojpc = $saJoin;
                            $ss = strtoupper($sgtApp);
                            if ($ss === 'DSC/ TRT' || $ss === 'COMPASSIONATE')
                                $computedDofdr = $sgtJoin;
                            elseif ($ss === 'UNTRAINED/ SPL VV')
                                $computedDofdr = $sgtReg;
                            elseif (stripos($ss, 'SPL DSC') !== false || stripos($ss, 'CONTRACTUAL') !== false)
                                $computedDofdr = $sgtAbs;
                        }
                    }

                    // GHM Gr.II cases
                    if ($designation && strtoupper(str_replace(' ', '', $designation)) === strtoupper(str_replace(' ', '', 'GHMGr.II')) || (stripos($designation, 'GHM') !== false && stripos($designation, 'GR') !== false)) {
                        $computedDojpc = $ghmDoj ?: $computedDojpc;
                        $s = strtoupper($saApp);
                        if ($s === 'DSC/ TRT' || $s === 'COMPASSIONATE') {
                            $computedDofdr = $saJoin;
                            $computedDofapp = $saJoin;
                        } elseif ($s === 'UNTRAINED/ SPL VV') {
                            $computedDofdr = $saReg;
                            $computedDofapp = $saJoin;
                        } elseif ($s === 'PROMOTION') {
                            $computedDofdr = $saJoin;
                            $computedDofapp = $sgtJoin;
                        }
                    }

                    // Helper to convert dd-mm-yyyy or other human date to Y-m-d for DB storage
                    $toYmd = function ($d) {
                        $d = trim((string) $d);
                        if ($d === '')
                            return '';
                        // dd-mm-yyyy
                        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $d, $m))
                            return $m[3] . '-' . $m[2] . '-' . $m[1];
                        // yyyy-mm-dd
                        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d))
                            return $d;
                        $ts = strtotime($d);
                        if ($ts !== false)
                            return date('Y-m-d', $ts);
                        return $d;
                    };

                    // Enqueue computed updates so they override any submitted values
                    if (strtoupper($computedDofdr) === 'NULL') {
                        $updates[] = '`dofdr` = NULL';
                    } else {
                        $dofdrDB = $toYmd($computedDofdr);
                        $updates[] = '`dofdr` = ?';
                        $params[] = $dofdrDB;
                        $types .= 's';
                    }
                    $dofappDB = $toYmd($computedDofapp);
                    $dojpcDB = $toYmd($computedDojpc);
                    $updates[] = '`dofapp` = ?';
                    $params[] = $dofappDB;
                    $types .= 's';
                    $updates[] = '`dojpc` = ?';
                    $params[] = $dojpcDB;
                    $types .= 's';

                } catch (Exception $e) { /* Error computing cadre seniority */
                }
                // simple alias map: posted form field name => actual DB column name
                $aliasMap = [
                    'scode' => 'SchCode',
                    'schcode' => 'SchCode',
                    'sch_name' => 'SchName',
                    'category' => 'category_ofthe_school',
                    'mgt' => 'management',
                    'medium' => 'medium_ofthe_school',
                    'hra' => 'hra'
                ];

                // --- Caste certificate upload handling ---
                // We expect a file input named 'CasteCert_file'. Only allowed for SC or ST category.
                // If upload succeeds, we'll add an update for `CasteCert` with the relative path.
                $postedCaste = isset($_POST['caste']) ? trim($_POST['caste']) : null;
                if ($postedCaste === null) {
                    $cstmt = $conn->prepare('SELECT caste FROM teacherdata WHERE TreasuryCode = ? LIMIT 1');
                    $cstmt->bind_param('s', $treasury);
                    $cstmt->execute();
                    $cres = $cstmt->get_result();
                    $curCaste = '';
                    if ($cres && $cres->num_rows) {
                        $crow = $cres->fetch_assoc();
                        $curCaste = $crow['caste'] ?? '';
                    }
                    $cstmt->close();
                    $postedCaste = $curCaste;
                }

                // DEBUG: Log file upload attempt
                $dbgLog = [];
                $dbgLog['time'] = date('c');
                $dbgLog['treasury'] = $treasury;
                $dbgLog['_FILES_keys'] = array_keys($_FILES);
                $dbgLog['CasteCert_file_isset'] = isset($_FILES['CasteCert_file']);
                if (isset($_FILES['CasteCert_file'])) {
                    $dbgLog['CasteCert_file_error'] = $_FILES['CasteCert_file']['error'];
                    $dbgLog['CasteCert_file_size'] = $_FILES['CasteCert_file']['size'] ?? 0;
                    $dbgLog['CasteCert_file_name'] = $_FILES['CasteCert_file']['name'] ?? '';
                    $dbgLog['CasteCert_file_tmp_name'] = $_FILES['CasteCert_file']['tmp_name'] ?? '';
                    $dbgLog['CasteCert_file_tmp_exists'] = file_exists($_FILES['CasteCert_file']['tmp_name'] ?? '') ? 'YES' : 'NO';
                }
@file_put_contents(__DIR__ . '/uploads_debug.log', date('c') . ' ' . json_encode($dbgLog) . "\n", FILE_APPEND | LOCK_EX);

                if (isset($_FILES['CasteCert_file']) && $_FILES['CasteCert_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $fileErr = $_FILES['CasteCert_file']['error'];
                    
                    // Handle file upload errors
                    if ($fileErr === UPLOAD_ERR_INI_SIZE || $fileErr === UPLOAD_ERR_FORM_SIZE) {
                        // File size limit exceeded - this is expected, we'll compress it
                        $fileErr = UPLOAD_ERR_OK;
                    }
                    
                    if ($fileErr !== UPLOAD_ERR_OK) {
                        $errorMap = [
                            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary directory on server',
                            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk'
                        ];
                        $msg = 'Caste certificate upload error: ' . ($errorMap[$fileErr] ?? 'Unknown error (code ' . $fileErr . ')');
                        @file_put_contents(__DIR__ . '/uploads_debug.log', date('c') . ' CASTE_ERR: ' . $msg . "\n", FILE_APPEND | LOCK_EX);
                    } else {
                        if (!preg_match('/(SC|ST)/i', $postedCaste)) {
                            $msg = 'Caste certificate upload allowed only for SC/ST teachers.';
                        } else {
                            $maxBytes = 102400;  // Target 100KB
                            $allowedExt = ['jpg', 'jpeg', 'png'];
                            $origName = $_FILES['CasteCert_file']['name'];
                            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                            if (!in_array($ext, $allowedExt)) {
                                $msg = 'Only JPG or PNG files are allowed for caste certificate uploads.';
                            } else {
                                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                                $mime = $finfo ? finfo_file($finfo, $_FILES['CasteCert_file']['tmp_name']) : '';
                                if ($finfo)
                                    finfo_close($finfo);
                                $allowedMime = ['image/jpeg', 'image/png'];
                                if (!in_array($mime, $allowedMime)) {
                                    $msg = 'Uploaded file type not recognized as JPEG/PNG. Please check the file.';
                                } else {
                                    $uploadBase = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'caste_certificates';
                                    if (!is_dir($uploadBase)) {
                                        $mkdirResult = mkdir($uploadBase, 0755, true);
                                        if (!$mkdirResult) {
                                            error_log("Failed to create directory: " . $uploadBase);
                                            $msg = 'Failed to create upload directory for caste certificates.';
                                            // Handle this error appropriately, e.g., send JSON response and exit
                                        }
                                    }
                                    $cleanCaste = preg_replace('/[^A-Z0-9_\-]/i', '_', strtoupper($postedCaste));
                                    $destName = $treasury . '_' . $cleanCaste . '.' . $ext;
                                    $absPath = $uploadBase . DIRECTORY_SEPARATOR . $destName;

                                    if (!compressUploadedImageFile($_FILES['CasteCert_file']['tmp_name'], $absPath, $mime, $maxBytes)) {
                                        $msg = 'Failed to process caste certificate. The file may be corrupted or an unsupported format.';
                                        @file_put_contents(__DIR__ . '/uploads_debug.log', date('c') . ' CASTE_COMPRESS_FAIL: treasury=' . $treasury . ' path=' . $absPath . "\n", FILE_APPEND | LOCK_EX);
                                    } else {
                                        // Successfully compressed and saved
                                        @file_put_contents(__DIR__ . '/uploads_debug.log', date('c') . ' CASTE_SUCCESS: treasury=' . $treasury . ' file=' . $destName . "\n", FILE_APPEND | LOCK_EX);
                                        $updates[] = '`CasteCert` = ?';
                                        $params[] = $destName;
                                        $types .= 's';
                                        $uactor = (isset($_SESSION['admin_loggedin']) && $_SESSION['admin_loggedin']) ? 'admin' : 'user';
                                        $originalSize = isset($_FILES['CasteCert_file']['size']) ? intval($_FILES['CasteCert_file']['size']) : 0;
                                        $finalSize = @filesize($absPath);
                                        $auditLine = date('c') . "\t" . $uactor . "\t" . $treasury . "\tUPLOAD\tCasteCert\t" . $destName . "\tOriginal:" . $originalSize . "B\tFinal:" . $finalSize . "B\n";
                                        @file_put_contents(__DIR__ . '/edits.log', $auditLine, FILE_APPEND | LOCK_EX);
                                    }
                                }
                            }
                        }
                    }
                }

                // --- PHC certificate upload handling ---
                // Similar to caste certificate but for PHC uploads
                $postedPhcYN = isset($_POST['PhcYN']) ? trim($_POST['PhcYN']) : '';
                if (isset($_FILES['Phcupload_file']) && $_FILES['Phcupload_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    if (strtoupper($postedPhcYN) !== 'YES') {
                        $msg = 'PHC certificate upload allowed only when PHC = YES.';
                    } else {
                        $f = $_FILES['Phcupload_file'];
                        
                        // Handle file size errors gracefully - we can compress images, but not PDFs
                        $fileErr = $f['error'];
                        if ($fileErr === UPLOAD_ERR_INI_SIZE || $fileErr === UPLOAD_ERR_FORM_SIZE) {
                            // Check if it's a PDF - PDFs cannot be compressed
                            $name = strtolower($f['name']);
                            if (strpos($name, '.pdf') !== false) {
                                $msg = 'PHC PDF file is too large. Maximum 100 KB allowed. Please compress the PDF first.';
                                $fileErr = UPLOAD_ERR_OK;  // Mark as OK to prevent other errors, we'll handle this below
                            } else {
                                // For images, size limit exceeded is expected - we'll compress
                                $fileErr = UPLOAD_ERR_OK;
                            }
                        }
                        
                        if ($fileErr !== UPLOAD_ERR_OK) {
                            $errorMap = [
                                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary directory on server',
                                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk'
                            ];
                            $msg = 'PHC file upload error: ' . ($errorMap[$fileErr] ?? 'Unknown error (code ' . $fileErr . ')');
                        } else {
                            $ext = '';
                            $name = strtolower($f['name']);
                            if (strpos($name, '.jpg') !== false || strpos($name, '.jpeg') !== false)
                                $ext = 'jpg';
                            elseif (strpos($name, '.png') !== false)
                                $ext = 'png';
                            elseif (strpos($name, '.pdf') !== false)
                                $ext = 'pdf';

                            if ($ext) {
                                // Check if file exceeded size limit AND it's a PDF
                                if (($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) && $ext === 'pdf') {
                                    $msg = 'PHC PDF file is too large. Maximum 100 KB allowed. Please compress the PDF first.';
                                } else {
                                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                                    $mime = $finfo ? finfo_file($finfo, $f['tmp_name']) : '';
                                    if ($finfo)
                                        finfo_close($finfo);
                                    $allowedMime = ['image/jpeg', 'image/png', 'application/pdf'];
                                    if (!in_array($mime, $allowedMime)) {
                                        $msg = 'Uploaded PHC file type not recognized. Only JPEG/PNG/PDF are accepted.';
                                    } else {
                                        $uploadBase = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'phc_certificates';
                                        if (!is_dir($uploadBase)) {
                                            $mkdirResult = mkdir($uploadBase, 0755, true);
                                            if (!$mkdirResult) {
                                                error_log("Failed to create directory: " . $uploadBase);
                                                $msg = 'Failed to create upload directory for PHC certificates.';
                                                // Handle this error appropriately, e.g., send JSON response and exit
                                            }
                                        }
                                        $cleanPhc = preg_replace('/[^A-Z0-9_\-]/i', '_', 'PHC');
                                        $destName = $treasury . '_' . $cleanPhc . '.' . $ext;
                                        $absPath = $uploadBase . DIRECTORY_SEPARATOR . $destName;

                                        $success = false;
                                        if ($mime === 'application/pdf') {
                                            // For PDF, check the actual file size (not the POST size which might be limited)
                                            $actualSize = @filesize($f['tmp_name']);
                                            if ($actualSize > 102400) {
                                                $msg = 'PHC PDF file is too large (' . number_format($actualSize / 1024, 0) . ' KB). Maximum 100 KB allowed.';
                                            } else if (!move_uploaded_file($f['tmp_name'], $absPath)) {
                                                error_log("Failed to move uploaded PHC PDF file from " . $f['tmp_name'] . " to " . $absPath);
                                                $msg = 'Failed to move uploaded PHC PDF file to uploads directory.';
                                            } else {
                                                $success = true;
                                            }
                                        } else {
                                            // For images, compress them to fit within 100KB
                                            if (!compressUploadedImageFile($f['tmp_name'], $absPath, $mime, 102400)) {
                                                $msg = 'Failed to process PHC certificate image. The file may be corrupted or an unsupported format.';
                                            } else {
                                                $success = true;
                                            }
                                        }

                                        if ($success && !$msg) {
                                            $updates[] = '`PhcUpload` = ?';
                                            $params[] = $destName;
                                            $types .= 's';
                                            $uactorPhc = (isset($_SESSION['admin_loggedin']) && $_SESSION['admin_loggedin']) ? 'admin' : 'user';
                                            $auditLinePhc = date('c') . "\t" . $uactorPhc . "\t" . $treasury . "\tUPLOAD\tPhcUpload\t" . $destName . "\n";
                                            @file_put_contents(__DIR__ . '/edits.log', $auditLinePhc, FILE_APPEND | LOCK_EX);
                                        }
                                    }
                                }
                            } else {
                                $msg = 'Invalid PHC file extension. Only JPG/JPEG, PNG or PDF allowed.';
                            }
                        }
                    }
                }

                // expand allowed to include alias values too
                foreach ($aliasMap as $posted => $real) {
                    if (!in_array($real, $allowed))
                        $allowed[] = $real;
                }

                foreach ($allowed as $col) {
                    if ($col === 'TreasuryCode')
                        continue;
                    // skip cadence fields here ï¿½ they are computed server-side and
                    // should not be overwritten by submitted (possibly empty) inputs.
                    $skipComputed = array('dofapp', 'dofdr', 'dojpc');
                    if (in_array(strtolower($col), $skipComputed, true))
                        continue;
                    // accept submitted value if present, else skip
                    // check for alias names in POST as well
                    $postKey = $col;
                    // allow lowercase/alternative keys
                    foreach ($aliasMap as $posted => $real) {
                        if ($real === $col && isset($_POST[$posted])) {
                            $postKey = $posted;
                            break;
                        }
                    }
                    if (array_key_exists($postKey, $_POST)) {
                        $val = trim((string) $_POST[$postKey]);
                        // mobile normalization
                        if (strcasecmp($col, 'mobile') === 0)
                            $val = preg_replace('/\D+/', '', $val);
                        // normalize dob to Y-m-d when possible
                        if (in_array(strtolower($col), ['dob', 'd_o_b', 'dateofbirth'])) {
                            $ts = strtotime($val);
                            if ($ts !== false)
                                $val = date('Y-m-d', $ts);
                        }
                        // normalize PHC date fields from dd-mm-yyyy to Y-m-d format for database storage
                        if (in_array(strtolower($col), ['phccertdate', 'phccertvalidity'])) {
                            if ($val && preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $val, $matches)) {
                                // Convert dd-mm-yyyy to yyyy-mm-dd
                                $val = $matches[3] . '-' . $matches[2] . '-' . $matches[1];
                            } else {
                                // Try to parse as any other date format and convert to Y-m-d
                                $ts = strtotime($val);
                                if ($ts !== false)
                                    $val = date('Y-m-d', $ts);
                            }
                        }
                        $updates[] = '`' . str_replace('`', '', $col) . '` = ?';
                        $params[] = $val;
                        $types .= 's';
                    }
                }

                // PHC server-side rules: if PhcYN is provided
                $postedPhc = null;
                if (isset($_POST['PhcYN']))
                    $postedPhc = strtoupper(trim($_POST['PhcYN']));
                elseif (isset($_POST['phcyn']))
                    $postedPhc = strtoupper(trim($_POST['phcyn']));
                $phcCols = ['PhcType', 'PhcPercent', 'PhcAuth', 'PhcCertNo', 'PhcCertDate', 'PhcCertValidity', 'PhcCertReassess'];
                if ($postedPhc !== null) {
                    if ($postedPhc === 'NO') {
                        // ensure PHC columns will be cleared (set to empty) regardless of submitted values
                        foreach ($phcCols as $pc) {
                            $updates[] = '`' . $pc . '` = ?';
                            $params[] = '';
                            $types .= 's';
                        }
                    } elseif ($postedPhc === 'YES') {
                        // require all PHC fields to be present and non-empty
                        $missing = [];
                        foreach ($phcCols as $pc) {
                            // check POST for either exact key or lowercase variation
                            $postKey = array_key_exists($pc, $_POST) ? $pc : strtolower($pc);
                            $val = isset($_POST[$postKey]) ? trim((string) $_POST[$postKey]) : '';
                            if ($val === '')
                                $missing[] = $pc;
                        }
                        if (!empty($missing)) {
                            $msg = 'PHC marked YES ï¿½ missing required PHC fields: ' . implode(', ', $missing);
                            // prevent update by clearing updates array
                            $updates = [];
                        }
                    }
                }

                if (!empty($updates)) {
                    $sql = 'UPDATE teacherdata SET ' . implode(', ', $updates) . ' WHERE TreasuryCode = ? LIMIT 1';
                    $types .= 's';
                    $params[] = $treasury;

                    if ($stmt = $conn->prepare($sql)) {
                        // PHP 8.1+ compatible bind_param using spread operator
                        // The old call_user_func_array + references pattern causes a TypeError in PHP 8.1+
                        $stmt->bind_param($types, ...$params);
                        if ($stmt->execute()) {
                            $msg = 'Saved successfully. Updated ' . count($updates) . ' fields.';
                        } else {
                            $msg = 'Save failed: ' . $stmt->error;
                        }
                        $stmt->close();
                        // audit log
                        $changed = implode(', ', array_map('trim', $updates));
                        $auditLine = date('c') . "\t" . (isset($_SESSION['admin_loggedin']) && $_SESSION['admin_loggedin'] ? 'admin' : 'user') . "\t" . $treasury . "\t" . $changed . "\n";
                        @file_put_contents(__DIR__ . '/edits.log', $auditLine, FILE_APPEND | LOCK_EX);
                    } else {
                        $msg = 'Server error preparing update: ' . $conn->error;
                    }
                } else {
                    $msg = 'No editable fields submitted.';
                }

                // Return JSON response if AJAX request
                if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                    // Clear ALL output buffers before setting header
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    
                    // Set JSON headers
                    header('Content-Type: application/json; charset=utf-8');
                    header('Cache-Control: no-cache, must-revalidate');
                    
                    // Simple response
                    $response = [
                        'success' => (strpos($msg, 'saved') !== false || strpos($msg, 'success') !== false || strpos($msg, 'No editable fields') !== false),
                        'message' => $msg
                    ];
                    
                    // Debug logging (non-fatal)
                    $debugPath = __DIR__ . '/debug_ajax_output.html';
                    $treas = isset($treasury) ? $treasury : '';
                    $logLine = "=== " . date('c') . " treasury:" . $treas . " ===\n";
                    $logLine .= "SUCCESS:" . ($response['success'] ? 'YES' : 'NO') . "\n";
                    $logLine .= "MESSAGE:" . $msg . "\n\n";
                    @file_put_contents($debugPath, $logLine, FILE_APPEND | LOCK_EX);
                    
                    echo json_encode($response);
                    exit;
                }

            } // End if(empty($msg)) validation block
        } // End CSRF else block
    }
}
// Fallback AJAX handler: If we are here and it's an AJAX request (e.g. CSRF failed or other logic skipped), return JSON error.
if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    while (ob_get_level())
        ob_end_clean();
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode(['success' => false, 'message' => $msg ?: 'Request processing failed (possibly CSRF or validation error).']);
    exit;
}

// Fetch current row
$row = [];
if ($stmt = $conn->prepare('SELECT * FROM teacherdata WHERE TreasuryCode = ? LIMIT 1')) {
    $stmt->bind_param('s', $treasury);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows)
        $row = $res->fetch_assoc();
    $stmt->close();
}

// Check if MEO user is trying to edit a teacher outside their mandal
if ($user_role === 'MEO' && !empty($row)) {
    $user_info = get_user_info();
    $teacher_mandal = $row['SchMandal'] ?? '';
    $meo_mandal = $user_info['mandal'] ?? '';

    if ($teacher_mandal !== $meo_mandal) {
        $msg = 'Access Denied: MEO users can only edit teachers within their mandal. Teacher Mandal: ' . htmlspecialchars($teacher_mandal) . ' Your Mandal: ' . htmlspecialchars($meo_mandal);
        // If this is an AJAX/save request, return JSON; otherwise show alert and stop
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            while (ob_get_level())
                ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        } else {
            include 'includes/header.php';
            echo '<div class="container main-body"><main class="main-content">';
            echo '<script>';
            echo 'alert("' . addslashes($msg) . '");';
            echo 'window.history.back();';
            echo '</script>';
            echo '</main></div>';
            include 'includes/footer.php';
            exit;
        }
    }
}

// Provide lowercase aliases for fetched DB columns so both 'SchCode' and 'schcode' are available
if (!empty($row)) {
    $temp = [];
    foreach ($row as $k => $v) {
        $lk = strtolower($k);
        $temp[$lk] = $v;
    }
    // Merge lowercase keys back into row
    $row = array_merge($row, $temp);
}

echo '<div class="container main-body">';
include 'includes/left_sidebar.php';
echo '<main class="main-content">';
echo '<h2>Edit Particulars: ' . htmlspecialchars($treasury) . '</h2>';

// Initialize JS variables for script tag (even if no message)
$jsMsg = 'null';
$jsIsError = 'false';

if ($msg) {
    $isError = (strpos($msg, 'required') !== false || strpos($msg, 'failed') !== false || strpos($msg, 'error') !== false || strpos($msg, 'missing') !== false);
    $color = $isError ? 'red' : 'green';
    echo '<div style="margin-bottom:8px;color:' . $color . ';font-weight:bold;padding:8px;border:1px solid ' . $color . ';border-radius:4px;background-color:' . ($isError ? '#fff5f5' : '#f0fff4') . ';">' . htmlspecialchars($msg) . '</div>';
    // Also expose the server message to client JS so we can display it in the modal after reload
    $jsMsg = json_encode($msg);
    $jsIsError = $isError ? 'true' : 'false';
}

// Render form with sections and fields (inputs instead of plain text)
echo '<form method="post" action="teacher_edit.php" enctype="multipart/form-data">';
echo '<input type="hidden" name="treasury_code" value="' . htmlspecialchars($treasury) . '">';
echo '<input type="hidden" name="save_all" value="1">';
// CSRF token hidden field
echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '') . '">';
echo '<div class="required-legend"><span style="color:red;">*</span> indicates required fields. All marked fields must be filled before submission.</div>';
// Modal for validation messages (partial)
include __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'validation_modal.php';
// Define tabs according to requested grouping (letters refer to section ordinals)
$tabs = [
    'Personal Info' => ['A', 'B', 'C', 'D'],
    'Address-EPIC' => ['E', 'F', 'G'],
    'Academics' => ['H', 'I', 'J', 'K'],
    'Professional' => ['L', 'M', 'N'],
    'Tests - Promotion' => ['O', 'P', 'Q'],
    'Service' => ['R', 'S', 'T', 'U', 'V', 'W']
];

// build reverse map from letter to tab index/label
$letterToTab = [];
$tabLabels = array_keys($tabs);
foreach ($tabLabels as $ti => $label) {
    foreach ($tabs[$label] as $ltr)
        $letterToTab[$ltr] = $label;
}

// Start accordion-style layout
echo '<div style="max-width:1200px;margin:0 auto;padding:0 15px">';
echo '<div class="tp-wrapper accordion-wrapper" style="margin-top:0;padding-top:0">';

// Accordion container
// Accordion container for Groups
echo '<div class="accordion-container">';
$groupIndex = 0;

foreach ($groupOrder as $grpKey) {
    if (!isset($groupedSections[$grpKey]))
        continue;
    $grpData = $groupedSections[$grpKey];
    $grpLabel = $grpData['label'];
    $subSections = $grpData['subsections'];

    if (empty($subSections))
        continue;

    $groupIndex++;
    $ordinalLetter = chr(64 + ($groupIndex <= 26 ? $groupIndex : ($groupIndex % 26 ?: 26))); // A, B, C... for Groups

    // Group Accordion Item
    $accordionItemClass = 'accordion-item tp-section group-section';
    $isFirstGroup = ($groupIndex === 1);

    // Determine the tab label from the first section in the group (compatibility)
    $firstSecKey = array_key_first($subSections);
    // Rough mapping for tabs
    $tabLabelForThis = 'Personal Info'; // Default
    if (stripos($firstSecKey, 'PERSONAL') !== false)
        $tabLabelForThis = 'Personal Info';
    elseif (stripos($firstSecKey, 'ADDRESS') !== false || stripos($firstSecKey, 'BANK') !== false || stripos($firstSecKey, 'SPOUSE') !== false || stripos($firstSecKey, 'EPIC') !== false)
        $tabLabelForThis = 'Address-EPIC';
    elseif (stripos($firstSecKey, 'ACADEMIC') !== false)
        $tabLabelForThis = 'Academics';
    elseif (stripos($firstSecKey, 'PROFESSIONAL') !== false)
        $tabLabelForThis = 'Professional';
    elseif (stripos($firstSecKey, 'TESTS') !== false || stripos($firstSecKey, 'TET') !== false)
        $tabLabelForThis = 'Tests - Promotion';
    elseif (stripos($firstSecKey, 'SERVICE') !== false || stripos($firstSecKey, 'PROMOTION') !== false || stripos($grpKey, 'service') !== false)
        $tabLabelForThis = 'Service';

    echo '<div class="' . $accordionItemClass . '" data-group="' . $grpKey . '" data-tab="' . $tabLabelForThis . '" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);transition:all 0.3s ease">';

    // Group Header
    $expandedClass = $isFirstGroup ? 'accordion-expanded' : '';
    // Horizontal menu blue style (Navbar blue)
    echo '<div class="accordion-header ' . $expandedClass . '" data-group-toggle="' . $grpKey . '" style="background: linear-gradient(to right, #1e3c72, #2a5298); color: white; padding: 12px 20px; border-radius: 8px 8px 0 0;">';
    echo '<div style="display:flex;align-items:center;gap:12px;">';
    echo '<span class="section-title" style="font-size:16px;font-weight:bold;letter-spacing:0.5px;text-transform:uppercase;">' . $ordinalLetter . '. ' . htmlspecialchars($grpLabel) . '</span>';
    echo '</div>';
    echo '<div style="display:flex;align-items:center;gap:12px;">';
    echo '<span class="group-status" style="font-size:12px;font-weight:600;margin-right:8px;opacity:0;transition:opacity 0.3s ease;"></span>';
    echo '<svg class="accordion-icon" style="width:20px;height:20px;transition:transform 0.3s ease;' . ($isFirstGroup ? 'transform:rotate(180deg);' : '') . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>';
    echo '</div>';
    echo '</div>'; // End Group Header

    // Group Content
    $contentDisplay = $isFirstGroup ? 'block' : 'none';
    echo '<div class="accordion-content" style="display:' . $contentDisplay . ';padding:20px;background:#fff;transition:all 0.3s ease">';

    // Show save success message if relevant
    if (isset($_GET['section_saved']) && $_GET['section_saved'] == $grpLabel) {
        echo '<div class="alert alert-success section-save-success" style="display:block;margin:10px 0 15px 0;padding:15px 20px;background:#d1ecf1;border:2px solid #bee5eb;color:#0c5460;border-radius:6px;font-weight:bold;font-size:14px;"><i class="fas fa-check-circle"></i> Group "' . htmlspecialchars($grpLabel) . '" saved successfully!</div>';
    }

    // Iterate Sub-Sections
    $isServiceGroup = stripos($grpKey, 'service_particulars') !== false;
    $serviceDisabled = ($isServiceGroup && !$can_edit_service) ? ' disabled' : '';
    foreach ($subSections as $secName => $flds) {
        if (empty($flds))
            continue;

        // Static Header for Sub-Section with Green styling
        echo '<div class="subsection-header" style="background: linear-gradient(to right, #e8f5e9, #c8e6c9); padding:10px 15px; border-left:4px solid #2e7d32; margin-bottom:15px; margin-top:20px; border-radius:4px;">';

        echo '<div style="display:flex;align-items:center;justify-content:space-between;">';
        echo '<h4 style="margin:0;font-size:15px;color:#1b5e20;font-weight:700;">' . htmlspecialchars($secName) . '</h4>';

        // Inject Dropdowns for specific sections (reusing logic)
        if (stripos($secName, 'GRADUATION (DEGREE)') !== false) {
            // ... existing gradation dropdown logic ...
            echo '<div style="display: flex; align-items: center; gap: 8px;">';
            echo '<span style="font-size: 13px; color: #fbbf24; font-weight: 500;">No. of degrees:</span>';
            echo '<select name="degrees_acquired" class="degree-dropdown" onchange="handleDegreeSelection(this)" style="padding:4px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;">';
            $savedDegrees = isset($row['degrees_acquired']) ? trim($row['degrees_acquired']) : '1';
            echo '<option value="0"' . ($savedDegrees == '0' ? ' selected' : '') . '>0</option><option value="1"' . ($savedDegrees == '1' ? ' selected' : '') . '>1</option><option value="2"' . ($savedDegrees == '2' ? ' selected' : '') . '>2</option></select></div>';
        } elseif (stripos($secName, 'POST GRADUATION (PG)') !== false) {
            echo '<div style="display: flex; align-items: center; gap: 8px;">';
            echo '<span style="font-size: 13px; color: #fbbf24; font-weight: 500;">No. of PG:</span>';
            echo '<select name="pg_degrees_acquired" class="degree-dropdown" onchange="handlePGDegreeSelection(this)" style="padding:4px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;">';
            $saved = isset($row['pg_degrees_acquired']) ? trim($row['pg_degrees_acquired']) : '0';
            echo '<option value="0"' . ($saved == '0' ? ' selected' : '') . '>0</option><option value="1"' . ($saved == '1' ? ' selected' : '') . '>1</option><option value="2"' . ($saved == '2' ? ' selected' : '') . '>2</option></select></div>';
        } elseif (stripos($secName, 'PROFESSIONAL TRAINING : (BEd') !== false) {
            echo '<div style="display: flex; align-items: center; gap: 8px;">';
            echo '<span style="font-size: 13px; color: #fbbf24; font-weight: 500;">No of trainings:</span>';
            echo '<select name="pt_trainings_acquired" class="degree-dropdown" onchange="handlePTTrainingSelection(this)" style="padding:4px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;">';
            $saved = isset($row['pt_trainings_acquired']) ? trim($row['pt_trainings_acquired']) : '0';
            echo '<option value="0"' . ($saved == '0' ? ' selected' : '') . '>0</option><option value="1"' . ($saved == '1' ? ' selected' : '') . '>1</option><option value="2"' . ($saved == '2' ? ' selected' : '') . '>2</option></select></div>';
        } elseif (stripos($secName, 'PROFESSIONAL TRAINING : (DEd/ TTC/ Spl DEd/UGPEd)') !== false) {
            echo '<div style="display: flex; align-items: center; gap: 8px;">';
            echo '<span style="font-size: 13px; color: #fbbf24; font-weight: 500;">No of trainings:</span>';
            echo '<select name="ded_trainings_acquired" class="degree-dropdown" onchange="handleDEDTrainingSelection(this)" style="padding:4px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;">';
            $saved = isset($row['ded_trainings_acquired']) ? trim($row['ded_trainings_acquired']) : '0';
            echo '<option value="0"' . ($saved == '0' ? ' selected' : '') . '>0</option><option value="1"' . ($saved == '1' ? ' selected' : '') . '>1</option></select></div>';
        } elseif (stripos($secName, 'PROFESSIONAL TRAINING - PG') !== false) {
            echo '<div style="display: flex; align-items: center; gap: 8px;">';
            echo '<span style="font-size: 13px; color: #fbbf24; font-weight: 500;">PG trainings:</span>';
            echo '<select name="pt_pg_trainings_acquired" class="degree-dropdown" onchange="handlePTPGTrainingSelection(this)" style="padding:4px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;">';
            $saved = isset($row['pt_pg_trainings_acquired']) ? trim($row['pt_pg_trainings_acquired']) : '0';
            echo '<option value="0"' . ($saved == '0' ? ' selected' : '') . '>0</option><option value="1"' . ($saved == '1' ? ' selected' : '') . '>1</option></select></div>';
        }

        echo '</div></div>'; // End subsection header



        // Use optimized column layout for sections to fit all fields in fewer rows
        if (stripos($secName, 'PERSONAL INFORMATION') !== false) {
            $gridColumns = 'repeat(5,1fr)'; // 5 fields in Personal Information section
        } elseif (stripos($secName, 'PHC INFORMATION') !== false) {
            $gridColumns = 'repeat(5,1fr)'; // 5 fields in PHC Information section
        } elseif (stripos($secName, 'BANK INFORMATION') !== false || stripos($secName, 'PAY PARTICULARS') !== false) {
            $gridColumns = 'repeat(4,1fr)'; // 11 fields in Pay Particulars and Bank Information section (AccountNo, branch, bank, IFSC, acstate, pay_scale, basic_pay, inc_month, gpftype, gpfacno, tsgliacno)
        } elseif (stripos($secName, 'SSC/ X CLASS') !== false) {
            $gridColumns = 'repeat(5,1fr)'; // 5 fields in SSC section
        } elseif (stripos($secName, 'INTERMEDIATE') !== false) {
            $gridColumns = 'repeat(6,1fr)'; // 6 fields in Intermediate section  
        } elseif (stripos($secName, 'GRADUATION (DEGREE)') !== false) {
            $gridColumns = 'repeat(5,1fr)'; // 5 fields in Graduation section
        } elseif (stripos($secName, 'POST GRADUATION (PG)') !== false) {
            $gridColumns = 'repeat(5,1fr)'; // 5 fields in Post Graduation section
        } elseif (stripos($secName, 'PROFESSIONAL TRAINING : (DEd/ TTC/ Spl DEd/UGPEd)') !== false) {
            $gridColumns = 'repeat(5,1fr)'; // L. 5 columns for DEd/TTC Professional Training section
        } elseif (stripos($secName, 'PROFESSIONAL TRAINING : (BEd/Spl BEd/BPEd/TPT/HPT/UPT)') !== false) {
            $gridColumns = 'repeat(4,1fr)'; // M. 4 columns for BEd Professional Training section
        } elseif (stripos($secName, 'PROFESSIONAL TRAINING - PG : (MEd/ MPEd)') !== false) {
            $gridColumns = 'repeat(4,1fr)'; // N. 4 columns for MEd Professional Training PG section
        } elseif (stripos($secName, 'DEPARTMENTAL_TESTS') !== false) {
            $gridColumns = 'repeat(2,1fr)'; // O. 2 columns for Departmental Tests section
        } elseif (stripos($secName, 'TET_INFORMATION') !== false) {
            $gridColumns = 'repeat(3,1fr)'; // V. 3 columns for TET Information section
        } elseif (stripos($secName, 'PROMOTIONS') !== false) {
            $gridColumns = 'repeat(4,1fr)'; // W. 4 columns for Promotions section
        } else {
            $gridColumns = 'repeat(4,1fr)'; // Default 4-column layout
        }
        echo '<div style="display:grid;grid-template-columns:' . $gridColumns . ';gap:10px">';


        // If this is the Working School Particulars section, inject the school-related selects/fields here
        if (stripos($secName, 'working') !== false && stripos($secName, 'school') !== false) {
            // Use exact teacherdata column names here to avoid mismatches and duplicates
            $curDiv = htmlspecialchars($row['division'] ?? '', ENT_QUOTES);
            $serviceRequired = $can_edit_service_school ? ' required' : '';
            $schoolDisabled = !$can_edit_service_school ? ' disabled' : '';
            // Fetch distinct division values from school_list table
            $divisions = [];
            $divRes = $conn->query('SELECT DISTINCT division FROM school_list ORDER BY division');
            if ($divRes) {
                while ($drow = $divRes->fetch_assoc()) {
                    $divisions[] = $drow['division'];
                }
            }
            if ($curDiv !== '' && !in_array($curDiv, $divisions, true)) {
                array_unshift($divisions, $curDiv);
            }

            echo '<div class="tp-col"><label class="field-label">School District <span style="color:red;">*</span></label>';
            echo '<select name="division" class="edit-input" data-current="' . $curDiv . '"' . $serviceRequired . $schoolDisabled . '><option value="">-- Select --</option>';
            foreach ($divisions as $dist) {
                $sel = ($curDiv === $dist) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($dist, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($dist) . '</option>';
            }
            echo '</select></div>';

            $curMandal = htmlspecialchars($row['SchMandal'] ?? '', ENT_QUOTES);
            echo '<div class="tp-col"><label class="field-label">School Mandal <span style="color:red;">*</span></label>';
            echo '<select name="SchMandal" class="edit-input" data-current="' . $curMandal . '"' . $serviceRequired . $schoolDisabled . '><option value="">-- Select --</option>';
            if ($curMandal !== '') {
                echo '<option value="' . $curMandal . '" selected>' . $curMandal . '</option>';
            }
            echo '</select></div>';

            $curSname = htmlspecialchars($row['SchName'] ?? '', ENT_QUOTES);
            // make School Name span two columns for extra width
            echo '<div class="tp-col" style="grid-column:span 2"><label class="field-label">School Name <span style="color:red;">*</span></label>';
            echo '<select name="SchName" class="edit-input" data-current="' . $curSname . '"' . $serviceRequired . $schoolDisabled . '><option value="">-- Select --</option>';
            if ($curSname !== '') {
                echo '<option value="' . $curSname . '" selected>' . $curSname . '</option>';
            }
            echo '</select></div>';

            $curScodeRaw = $row['SchCode'] ?? ($row['schcode'] ?? '');
            $curScode = htmlspecialchars($curScodeRaw, ENT_QUOTES);

            $schoolCategory = $row['category_ofthe_school'] ?? '';
            $schoolManagement = $row['management'] ?? '';
            $schoolMedium = $row['medium_ofthe_school'] ?? '';
            $schoolHra = $row['hra'] ?? '';
            $curDivRaw = $row['division'] ?? '';
            $curMandalRaw = $row['SchMandal'] ?? '';
            $curSnameRaw = $row['SchName'] ?? '';

            if ($curScodeRaw !== '' && ($schoolCategory === '' || $schoolManagement === '' || $schoolMedium === '' || $schoolHra === '')) {
                if ($sstmt = $conn->prepare('SELECT category, mgt, medium_sch, ps_ups FROM school_list WHERE scode = ? LIMIT 1')) {
                    $sstmt->bind_param('s', $curScodeRaw);
                    $sstmt->execute();
                    $sres = $sstmt->get_result();
                    if ($sres && $sres->num_rows) {
                        $srow = $sres->fetch_assoc();
                        if ($schoolCategory === '') {
                            $schoolCategory = $srow['category'] ?? '';
                        }
                        if ($schoolManagement === '') {
                            $schoolManagement = $srow['mgt'] ?? '';
                        }
                        if ($schoolMedium === '') {
                            $schoolMedium = $srow['medium_sch'] ?? '';
                        }
                        if ($schoolHra === '') {
                            $schoolHra = $srow['ps_ups'] ?? '';
                        }
                    }
                    $sstmt->close();
                }
            }

            if ($curScodeRaw === '' && $curDivRaw !== '' && $curMandalRaw !== '' && $curSnameRaw !== '' && ($schoolCategory === '' || $schoolManagement === '' || $schoolMedium === '' || $schoolHra === '')) {
                if ($sstmt = $conn->prepare('SELECT category, mgt, medium_sch, ps_ups FROM school_list WHERE division = ? AND mandal = ? AND sname = ? LIMIT 1')) {
                    $sstmt->bind_param('sss', $curDivRaw, $curMandalRaw, $curSnameRaw);
                    $sstmt->execute();
                    $sres = $sstmt->get_result();
                    if ($sres && $sres->num_rows) {
                        $srow = $sres->fetch_assoc();
                        if ($schoolCategory === '') {
                            $schoolCategory = $srow['category'] ?? '';
                        }
                        if ($schoolManagement === '') {
                            $schoolManagement = $srow['mgt'] ?? '';
                        }
                        if ($schoolMedium === '') {
                            $schoolMedium = $srow['medium_sch'] ?? '';
                        }
                        if ($schoolHra === '') {
                            $schoolHra = $srow['ps_ups'] ?? '';
                        }
                    }
                    $sstmt->close();
                }
            }

            echo '<div class="tp-col"><label class="field-label">School Code <span style="color:red;">*</span></label>';
            echo '<select name="SchCode" class="edit-input" data-current="' . $curScode . '"' . $serviceRequired . $schoolDisabled . '><option value="">-- Select --</option>';
            if ($curScode !== '') {
                echo '<option value="' . $curScode . '" selected>' . $curScode . '</option>';
            }
            echo '</select></div>';


            if ($schoolDisabled !== '') {
                echo '<input type="hidden" name="division" value="' . $curDiv . '" />';
                echo '<input type="hidden" name="SchMandal" value="' . $curMandal . '" />';
                echo '<input type="hidden" name="SchName" value="' . $curSname . '" />';
                echo '<input type="hidden" name="SchCode" value="' . $curScode . '" />';
            }



            // Category, Management, Medium, HRA (read-only display) - map to teacherdata columns
            echo '<div class="tp-col"><label class="field-label">Category of the school</label>';
            echo '<input type="text" name="category_ofthe_school" class="edit-input" readonly value="' . htmlspecialchars($schoolCategory, ENT_QUOTES) . '" /></div>';
            echo '<div class="tp-col"><label class="field-label">School Management</label>';
            echo '<input type="text" name="management" class="edit-input" readonly value="' . htmlspecialchars($schoolManagement, ENT_QUOTES) . '" /></div>';
            echo '<div class="tp-col"><label class="field-label">School Medium</label>';
            echo '<input type="text" name="medium_ofthe_school" class="edit-input" readonly value="' . htmlspecialchars($schoolMedium, ENT_QUOTES) . '" /></div>';
            echo '<div class="tp-col"><label class="field-label">School HRA</label>';
            echo '<input type="text" name="hra" class="edit-input" readonly value="' . htmlspecialchars($schoolHra, ENT_QUOTES) . '" /></div>';

            // School Type select (default CO-ED)
            $curType = strtoupper($row['school_type'] ?? 'CO-ED');
            echo '<div class="tp-col"><label class="field-label">School Type <span style="color:red;">*</span></label>';
            echo '<select name="school_type" class="edit-input"' . $serviceRequired . $schoolDisabled . '>';
            echo '<option value="CO-ED"' . ($curType === 'CO-ED' ? ' selected' : '') . '>CO-ED</option>';
            echo '<option value="BOYS"' . ($curType === 'BOYS' ? ' selected' : '') . '>BOYS</option>';
            echo '<option value="GIRLS"' . ($curType === 'GIRLS' ? ' selected' : '') . '>GIRLS</option>';
            echo '</select></div>';

            // SchJoinDate (date input)
            $curJoin = htmlspecialchars($row['SchJoinDate'] ?? '', ENT_QUOTES);
            echo '<div class="tp-col"><label class="field-label">SchJoinDate <span style="color:red;">*</span></label>';
            echo '<input type="date" name="SchJoinDate" class="edit-input" value="' . $curJoin . '"' . $serviceRequired . $schoolDisabled . ' /></div>';

            if ($schoolDisabled !== '') {
                echo '<input type="hidden" name="school_type" value="' . $curType . '" />';
                echo '<input type="hidden" name="SchJoinDate" value="' . $curJoin . '" />';
            }

            // mark these columns to be skipped in the generic renderer to avoid duplicates
            $school_section_cols = ['division', 'SchMandal', 'SchName', 'SchCode', 'category_ofthe_school', 'management', 'medium_ofthe_school', 'hra', 'school_type', 'SchJoinDate'];
        }
        // track if we're rendering inside an SGT section and whether sgtrendered field has been emitted
        $inSgtSection = (stripos($secName, 'SERVICE_PARTICULARS_SGT CADRE') !== false);
        $seenSgtRendered = false;
        $savedDedTrainingsAcquired = isset($_POST['ded_trainings_acquired']) ? trim($_POST['ded_trainings_acquired']) : (isset($row['ded_trainings_acquired']) ? trim($row['ded_trainings_acquired']) : '0');
        foreach ($flds as $f) {
            $col = $f['name'];
            $label = $f['label'];
            // mark fields after sgtrendered as sgt-following when in SGT section
            if ($inSgtSection && $seenSgtRendered) {
                // ensure the renderer adds sgt-following wrapper class via $degreeFieldClass below
                $extra_following = true;
            } else {
                $extra_following = false;
            }
            // Skip school-section columns when we've already injected them above
            if (!empty($school_section_cols) && in_array(strtolower($col), array_map('strtolower', $school_section_cols)))
                continue;
            $val = array_key_exists($col, $row) ? $row[$col] : '';

            // Debug dort field specifically
            if (strtolower($col) === 'dort') {
                error_log("DORT RENDER: col='$col', array_key_exists=" . (array_key_exists($col, $row) ? 'YES' : 'NO') . ", val='$val', row[dort]=" . ($row['dort'] ?? 'NOT_SET'));
            }

            // Add section labels for address fields and graduation degree sections
            $sectionLabel = '';
            if (strtolower($col) === 'reshno') {
                // Add Residential Address label
                $sectionLabel = '<div style="grid-column: 1 / -1; background-color: #e3f2fd; padding: 8px; margin: 5px 0; border-left: 4px solid #2196f3; font-weight: bold;"><i class="fas fa-home" style="margin-right:8px;"></i> Residential Address Details</div>';
            } elseif (strtolower($col) === 'deg1type') {
                // Add Main Degree subheading for graduation section
                $sectionLabel = '<div class="d1-field" style="grid-column: 1 / -1; background-color: #f3e5f5; padding: 8px; margin: 5px 0; border-left: 4px solid #9c27b0; font-weight: bold; font-size: 16px;"><i class="fas fa-graduation-cap" style="margin-right:8px;"></i> Main Degree</div>';
            } elseif (strtolower($col) === 'deg2type') {
                // Add Additional Degree subheading for D2 graduation section
                $sectionLabel = '<div class="d2-field" style="grid-column: 1 / -1; background-color: #fff3cd; padding: 8px; margin: 5px 0; border-left: 4px solid #ffc107; font-weight: bold; font-size: 16px;"><i class="fas fa-plus-circle" style="margin-right:8px;"></i> Additional Degree</div>';
            } elseif (strtolower($col) === 'pg1course') {
                // Add Post Graduation degree - 1 subheading
                $sectionLabel = '<div class="pg1-field" style="grid-column: 1 / -1; background-color: #eaf6ff; padding: 8px; margin: 5px 0; border-left: 4px solid #2196f3; font-weight: bold; font-size: 16px;"><i class="fas fa-user-graduate" style="margin-right:8px;"></i> Post Graduation degree - 1</div>';
            } elseif (strtolower($col) === 'pg2course') {
                // Add Post Graduation degree - 2 subheading
                $sectionLabel = '<div class="pg2-field" style="grid-column: 1 / -1; background-color: #e3f2fd; padding: 8px; margin: 5px 0; border-left: 4px solid #2196f3; font-weight: bold; font-size: 16px;"><i class="fas fa-user-graduate" style="margin-right:8px;"></i> Post Graduation degree - 2</div>';
            } elseif (strtolower($col) === 'grad1trngcourse') {
                // Add PT-1 Professional Training subheading
                $sectionLabel = '<div class="pt1-field" style="grid-column: 1 / -1; background-color: #fff8e1; padding: 8px; margin: 5px 0; border-left: 4px solid #ff9800; font-weight: bold; font-size: 16px;"><i class="fas fa-chalkboard-teacher" style="margin-right:8px;"></i> BEd/Spl BEd/BPEd/TPT/HPT/UPT details</div>';
            } elseif (strtolower($col) === 'ugtrngcourse') {
                // Add DEd/TTC training section subheading
                $sectionLabel = '<div class="ded-field" style="grid-column: 1 / -1; background-color: #e8f5e9; padding: 8px; margin: 5px 0; border-left: 4px solid #4caf50; font-weight: bold; font-size: 16px;"><i class="fas fa-book-reader" style="margin-right:8px;"></i> DEd/TTC Training Details</div>';
            } elseif (strtolower($col) === 'grad2trngcourse') {
                // Add PT-2 Additional Professional Training subheading
                $sectionLabel = '<div class="pt2-field" style="grid-column: 1 / -1; background-color: #f3e5f5; padding: 8px; margin: 5px 0; border-left: 4px solid #9c27b0; font-weight: bold; font-size: 16px;"><i class="fas fa-chalkboard-teacher" style="margin-right:8px;"></i> Additional BEd/Spl BEd/BPEd/TPT/HPT/UPT details</div>';
            } elseif (strtolower($col) === 'nathno') {
                // Add Native Address label with radio button option
                $sectionLabel = '<div style="grid-column: 1 / -1; background-color: #eaf6ff; padding: 8px; margin: 5px 0; border-left: 4px solid #2196f3; font-weight: bold;"><i class="fas fa-map-marker-alt" style="margin-right:8px;"></i> Native Address Details</div>';
                $sectionLabel .= '<div style="grid-column: 1 / -1; background-color: #f0f8ff; padding: 10px; margin: 5px 0; border: 1px solid #ddd; border-radius: 4px;">';
                $sectionLabel .= '<label style="font-weight: bold; margin-bottom: 8px; display: block;">Is Native Address same as Residential Address?</label>';
                $sectionLabel .= '<div style="display: flex; gap: 15px;">';
                $sectionLabel .= '<label><input type="radio" name="native_same_as_residential" value="yes" onchange="handleAddressCopy(this)"> <strong>Yes</strong> - Copy Residential Address</label>';
                $sectionLabel .= '<label><input type="radio" name="native_same_as_residential" value="no" onchange="handleAddressCopy(this)" checked> <strong>No</strong> - Enter different address</label>';
                $sectionLabel .= '</div>';
                $sectionLabel .= '</div>';
            }

            // Special grid control for fields that need extra space
            $gridBreakAfter = '';
            $gridColumnSpan = '';
            if (strtolower($col) === 'tchfullname') {
                // TchFullName spans 2 columns for longer names
                $gridColumnSpan = ' style="grid-column: span 2;"';
            } elseif (strtolower($col) === 'spofficename') {
                // SpOfficeName spans 2 columns and forces grid break after
                $gridColumnSpan = ' style="grid-column: span 2;"';
                $gridBreakAfter = '<div style="grid-column: 1 / -1; height: 0;"></div>';
            } elseif (strtolower($col) === 'castecert') {
                // CasteCert upload field spans 2 columns for better file upload display
                $gridColumnSpan = ' style="grid-column: span 2;"';
            } elseif (strtolower($col) === 'phcyn') {
                // PhcYN field spans 2 columns for better visibility
                $gridColumnSpan = ' style="grid-column: span 2;"';
            } elseif (strtolower($col) === 'photo') {
                // Place photo on the right side (column 5) and span multiple rows
                if (stripos($secName, 'PERSONAL') !== false) {
                    $gridColumnSpan = ' style="grid-column: 5; grid-row: 1 / span 3;"';
                }
            }

            // Output section label if needed
            if ($sectionLabel) {
                echo $sectionLabel;
            }

            // Add CSS classes for D1, D2, PG1, PG2, PT1 and PT2 fields for conditional display
            $degreeFieldClass = '';
            if (strpos(strtolower($col), 'deg1') === 0) {
                $degreeFieldClass = ' d1-field';
            } elseif (strpos(strtolower($col), 'deg2') === 0) {
                $degreeFieldClass = ' d2-field';
            } elseif (strpos(strtolower($col), 'pg1') === 0) {
                $degreeFieldClass = ' pg1-field';
            } elseif (strpos(strtolower($col), 'pg2') === 0) {
                $degreeFieldClass = ' pg2-field';
            } elseif (strpos(strtolower($col), 'grad1trng') === 0) {
                $degreeFieldClass = ' pt1-field';
            } elseif (strpos(strtolower($col), 'ugtrng') === 0) {
                $degreeFieldClass = ' ded-field';
            } elseif (strpos(strtolower($col), 'grad2trng') === 0) {
                $degreeFieldClass = ' pt2-field';
            } elseif (in_array(strtolower($col), ['medcourse', 'meduniv', 'medpassyr', 'medpercent'])) {
                $degreeFieldClass = ' ptpg-field';
            } elseif (strtolower($col) === 'eotpass') {
                $degreeFieldClass = ' eot-dependent';
            } elseif (strtolower($col) === 'gotpass') {
                $degreeFieldClass = ' got-dependent';
            } elseif (strtolower($col) === 'lttpass') {
                $degreeFieldClass = ' ltt-dependent';
            } elseif (strtolower($col) === 'httpass') {
                $degreeFieldClass = ' htt-dependent';
            } elseif (strtolower($col) === 'othertestpassyear') {
                $degreeFieldClass = ' other-dependent';
            } elseif (in_array(strtolower($col), ['tetp1htno', 'tetp1passyr'])) {
                $degreeFieldClass = ' tet1-dependent';
            } elseif (in_array(strtolower($col), ['tetp2htno', 'tetp2passyr'])) {
                $degreeFieldClass = ' tet2-dependent';
            } elseif (strpos(strtolower($col), 'sgt') === 0) {
                if (strtolower($col) === 'sgtrendered') {
                    // this is the controller field; after rendering this, subsequent sgt fields become sgt-following
                    $degreeFieldClass = ' sgt-section';
                    $seenSgtRendered = true;
                } elseif (in_array(strtolower($col), ['sgtregdate'])) {
                    $degreeFieldClass = ' sgt-reg-dependent';
                } elseif (in_array(strtolower($col), ['sgtdschtno', 'sgtdsclist', 'sgtdscrank', 'sgtdscmarks'])) {
                    // SGT DSC related fields which should be hidden for COMPASSIONATE appointments
                    $degreeFieldClass = ' sgt-dsc-dependent';
                } elseif (in_array(strtolower($col), ['sgtabsrpdate'])) {
                    $degreeFieldClass = ' sgt-absorp-dependent';
                } else {
                    // regular SGT field; if it's after the sgtrendered controller, mark as following
                    $degreeFieldClass = ' sgt-section' . ($extra_following ? ' sgt-following' : '');
                }
                // server-side: if the saved sgtrendered value is not YES, hide non-controller SGT fields
                $savedSgtRendered = isset($row['sgtrendered']) ? strtoupper(trim($row['sgtrendered'])) : '';
                if ($savedSgtRendered !== 'YES' && strtolower($col) !== 'sgtrendered') {
                    $degreeFieldClass .= ' hide-manual';
                }
            } elseif (strpos(strtolower($col), 'sa') === 0) {
                if (in_array(strtolower($col), ['sadscyr', 'sadschtno', 'salist', 'sarank', 'sadscmarks', 'samgmnt'])) {
                    $degreeFieldClass = ' sa-dsc-dependent';
                } elseif (in_array(strtolower($col), ['saregdate'])) {
                    $degreeFieldClass = ' sa-reg-dependent';
                } else {
                    $degreeFieldClass = ' sa-section';
                }
            } elseif (strpos(strtolower($col), 'ghmgrii') === 0) {
                $degreeFieldClass = ' gaz-section';
            }
            echo '<div class="tp-col' . $degreeFieldClass . '"' . $gridColumnSpan . '>';
            // choose input type by column name heuristics
            $lc = strtolower($col);
            $requiredFieldNames = ['treasurycode', 'tchsurname', 'tchfullname', 'fathername', 'designation', 'dob', 'dort', 'gender', 'caste', 'subcaste', 'adhaar', 'mobile', 'phcyn', 'reshno', 'resstreet', 'resmandal', 'resdist', 'nathno', 'natstreet', 'natmandal', 'natdist', 'epicno', 'partno', 'serialno', 'assmblyconstdist', 'assmblyconstname', 'rsconstname', 'nativeconstname', 'workconstname', 'ssctype', 'sscyear', 'sscmed', 'ssclang1', 'ssclang2', 'intertype', 'interyear', 'intercourse', 'intermed', 'interlang1', 'interlang2', 'ugtrngcourse', 'ugtrngmedium', 'ugtrngboarduniv', 'ugtrngpassyr', 'ugtrngpercent', 'eotyn', 'gotyn', 'lttyn', 'httyn', 'othertestyn', 'tet1passed', 'tet2passed', 'eligible_promotion_1', 'eligible_promotion_2', 'eligible_promotion_3', 'eligible_promotion_4'];

            // SGT required fields when sgtrendered = YES
            $currentSgtRendered = isset($row['sgtrendered']) ? trim($row['sgtrendered']) : '';
            // base SGT required fields
            $sgtRequiredFields = ['sgtapptype', 'sgtdscyr', 'sgtcadredesign', 'sgtdschtno', 'sgtdsclist', 'sgtdscrank', 'sgtdscmarks', 'sgtmgmnt', 'sgtjoindate'];
            // server-side: mark SgtRegDate and SgtAbsrpDate required when saved SgtAppType matches
            $currentSgtAppType = isset($row['SgtAppType']) ? trim($row['SgtAppType']) : '';
            if (strtoupper($currentSgtAppType) === strtoupper('UNTRAINED/ Spl VV')) {
                $sgtRequiredFields[] = 'sgtregdate';
            }
            if (strtoupper($currentSgtAppType) === strtoupper('Spl DSC (398)')) {
                $sgtRequiredFields[] = 'sgtabsrpdate';
            }
            $isSgtRequired = (strtoupper($currentSgtRendered) === 'YES' && in_array($lc, $sgtRequiredFields));

            // SA required fields when designation is not in specific list
            $currentDesignation = isset($row['designation']) ? trim($row['designation']) : '';
            $excludedDesignations = ['LP HINDI', 'LP TELUGU', 'LP URDU', 'PET', 'VOC', 'CI', 'DM', 'MUSIC', 'SGT', 'SGT UM', 'SGT SPL_EDN'];
            $saRequiredFields = ['sacadredesign', 'saapptype', 'sajoindate'];
            $isSaRequired = (!in_array($currentDesignation, $excludedDesignations) && in_array($lc, $saRequiredFields));

            // GAZ required fields when designation is GHM-Gr.II
            $gazRequiredFields = ['ghmgriiapp', 'ghmgriidesign', 'ghmgriidoj'];
            $isGazRequired = ($currentDesignation === 'GHM-Gr.II' && in_array($lc, $gazRequiredFields));
            // PHC fields are conditionally required based on PhcYN value
            $currentPhcYN = isset($row['PhcYN']) ? trim($row['PhcYN']) : '';
            $phcConditionalFields = ['phctype', 'phcpercent', 'phcauth', 'phccertno', 'phccertdate', 'phccertvalidity', 'phccertreassess', 'phcupload'];
            $isPhcRequired = (strtoupper($currentPhcYN) === 'YES' && in_array($lc, $phcConditionalFields));
            $isRequired = in_array($lc, $requiredFieldNames) || $isPhcRequired || $isSgtRequired || $isSaRequired || $isGazRequired;
            if ($savedDedTrainingsAcquired === '0' && in_array($lc, ['ugtrngcourse', 'ugtrngmedium', 'ugtrngboarduniv', 'ugtrngpassyr', 'ugtrngpercent'])) {
                $isRequired = false;
            }
            if (!$can_edit_service && isset($isServiceGroup) && $isServiceGroup) {
                $isRequired = false;
            }
            $requiredMark = $isRequired ? ' <span style="color:red;">*</span>' : '';
            echo '<label class="field-label">' . htmlspecialchars($label) . $requiredMark . '</label>';
            // Special handling for CasteCert: show current file path/link and a file input to upload
            if (strtolower($col) === 'castecert') {
                $filePath = $val;
                // determine current caste for this teacher (server-side truth)
                $currentCaste = isset($row['caste']) ? trim($row['caste']) : '';
                // allow when caste contains SC or ST anywhere (SQL-like %SC% or %ST%)
                $allowedForUpload = preg_match('/(SC|ST)/i', $currentCaste);

                $dataAttr = $filePath ? ' data-existing-file="' . htmlspecialchars(basename($filePath)) . '"' : '';
                echo '<div id="castecert-wrapper"' . $dataAttr . '>';
                if ($filePath && $allowedForUpload) {
                    // $filePath currently stores basename (filename saved in uploads/caste_certificates)
                    $fileName = htmlspecialchars(basename($filePath));
                    $dlUrl = '/uploads/caste_certificates/' . rawurlencode($fileName);
                    echo '<div id="castecert-link" style="margin-bottom:6px"><a id="castecert-anchor" href="' . htmlspecialchars($dlUrl) . '" target="_blank" rel="noopener"><i class="fas fa-file-alt"></i> ' . $fileName . '</a></div>';
                } else {
                    if ($filePath && !$allowedForUpload) {
                        echo '<div id="castecert-none" style="margin-bottom:6px;color:#666"><i class="fas fa-info-circle"></i> Certificate available (shown only for SC/ST caste).</div>';
                    } else {
                        echo '<div id="castecert-none" style="margin-bottom:6px;color:#666">No caste certificate uploaded.</div>';
                    }
                }

                // File input area: shown only when caste is SC/ST. Kept in DOM otherwise hidden so JS can toggle it.
                $displayStyle = $allowedForUpload ? '' : 'display:none';
                echo '<div id="caste-upload-area" style="margin-top:6px;' . $displayStyle . '">';
                echo '<input type="file" name="CasteCert_file" id="CasteCert_file" accept=".jpg,.jpeg,.png" />';
                // friendly file-name / instruction area (will be updated by JS on selection)
                $instr = 'No file chosen. Allowed: JPG or PNG. Max 100 KB.';
                echo '<div id="caste-file-name" style="font-size:11px;color:#666;margin-top:4px">' . htmlspecialchars($instr) . '</div>';
                echo '</div>';

                if (!$allowedForUpload) {
                    echo '<div style="font-size:11px;color:#666;margin-top:6px">Upload allowed only for SC/ST teachers.</div>';
                }

                echo '</div>'; // wrapper
                echo '</div>';
                continue;
            }
            // Special handling for Phcupload: show current file path/link and a file input to upload
            if (strtolower($col) === 'phcupload') {
                // Handle both Phcupload and PhcUpload field name variations
                $filePath = $val ?: ($row['PhcUpload'] ?? '');
                // determine current PHC status for this teacher (server-side truth)
                $currentPhcYN = isset($row['PhcYN']) ? trim($row['PhcYN']) : '';
                // allow when PhcYN is YES
                $allowedForUpload = strtoupper($currentPhcYN) === 'YES';

                $dataAttr = $filePath ? ' data-existing-file="' . htmlspecialchars(basename($filePath)) . '"' : '';
                echo '<div id="phcupload-wrapper"' . $dataAttr . '>';
                if ($filePath && $allowedForUpload) {
                    // Show downloadable link only when PhcYN = YES and file exists
                    $fileName = htmlspecialchars(basename($filePath));
                    $dlUrl = '/uploads/phc_certificates/' . rawurlencode($fileName);
                    echo '<div id="phcupload-link" style="margin-bottom:6px"><a id="phcupload-anchor" href="' . htmlspecialchars($dlUrl) . '" target="_blank" rel="noopener"><i class="fas fa-file-alt"></i> ' . $fileName . '</a></div>';
                } else {
                    if ($filePath && !$allowedForUpload) {
                        echo '<div id="phcupload-none" style="margin-bottom:6px;color:#666"><i class="fas fa-info-circle"></i> PHC Certificate available (shown only when PHC = YES).</div>';
                    } else {
                        echo '<div id="phcupload-none" style="margin-bottom:6px;color:#666">No PHC certificate uploaded.</div>';
                    }
                }

                // File input area: always in DOM so JS can toggle it
                echo '<div id="phc-upload-area" style="margin-top:6px;">';
                echo '<input type="file" name="Phcupload_file" id="Phcupload_file" accept=".jpg,.jpeg,.png,.pdf" />';
                // friendly file-name / instruction area (will be updated by JS on selection)
                $instr = 'No file chosen. (JPG, PNG or PDF. Max 500 KB)';
                echo '<div id="phcupload-filename" style="font-size:11px;color:#666;margin-top:4px">' . htmlspecialchars($instr) . '</div>';
                echo '</div>';

                if (!$allowedForUpload) {
                    echo '<div style="font-size:11px;color:#666;margin-top:6px">Upload allowed only for PHC = YES.</div>';
                }

                echo '</div>'; // wrapper
                echo '</div>';
                continue;
            }

            // Special handling for photo: show current photo and upload button
            if (strtolower($col) === 'photo') {
                $photoPath = $val;

                // Fallback: If no photo in DB or file missing, look for it in uploads/photos matching pattern
                if (empty($photoPath) || !file_exists(__DIR__ . DIRECTORY_SEPARATOR . $photoPath)) {
                    if ($treasury) {
                        // Look for photo_{TreasuryCode}_*.*
                        $pattern = __DIR__ . '/uploads/photos/photo_' . $treasury . '_*.*';
                        $matches = glob($pattern);
                        if ($matches && count($matches) > 0) {
                            // Take the first match
                            $photoPath = 'uploads/photos/' . basename($matches[0]);
                        }
                    }
                }
                echo '<div id="photo-wrapper">';

                // Display current photo or placeholder
                if ($photoPath && file_exists(__DIR__ . DIRECTORY_SEPARATOR . $photoPath)) {
                    echo '<div style="text-align:center;margin-bottom:8px;">';
                    echo '<img id="teacherPhotoEdit" src="' . htmlspecialchars($photoPath) . '?t=' . time() . '" alt="Teacher Photo" style="width:130px;height:160px;object-fit:cover;border:2px solid #3b5998;border-radius:4px;" />';
                    echo '</div>';
                } else {
                    echo '<div id="teacherPhotoEdit" style="width:130px;height:160px;background:#e5e7eb;border:2px dashed #9ca3af;border-radius:4px;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">';
                    echo '<span style="color:#6b7280;font-size:12px;">No Photo</span>';
                    echo '</div>';
                }

                // Upload button
                echo '<div style="text-align:center;">';
                echo '<button type="button" onclick="openPhotoUploadDialogEdit()" style="padding:6px 12px;background:#3b5998;color:white;border:none;border-radius:4px;cursor:pointer;font-size:12px;">Upload New Photo</button>';
                echo '</div>';

                echo '<input type="hidden" name="photo" id="photoHiddenInput" value="' . htmlspecialchars($photoPath) . '" />';
                echo '</div>'; // wrapper
                echo '</div>';
                continue;
            }
            // Debug dort before rendering
            if ($lc === 'dort') {
                echo '<!-- DORT DEBUG: lc=' . $lc . ', col=' . $col . ', val=' . htmlspecialchars($val) . ', isset_selectOptions=' . (isset($selectOptions[$lc]) ? 'YES' : 'NO') . ', row[dort]=' . ($row['dort'] ?? 'NOT_SET') . ' -->';
            }

            // render select if we have predefined options for this column
            if (isset($selectOptions[$lc]) && is_array($selectOptions[$lc])) {
                $requiredFields = ['designation', 'gender', 'caste', 'phcyn', 'maritalstatus', 'ssctype', 'sscmed', 'ssclang1', 'ssclang2', 'intertype', 'intermed', 'interlang1', 'interlang2'];
                $required = in_array($lc, $requiredFields) ? ' required' : '';

                // Render free-text input instead of a dropdown for these two fields
                if ($lc === 'sgtdsclist' || $lc === 'salist') {
                    echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '"' . $required . $serviceDisabled . ' />';
                } else {
                    echo '<select class="edit-input" name="' . htmlspecialchars($col) . '"' . $required . $serviceDisabled . '>';
                    echo '<option value="">-- Select --</option>';
                    foreach ($selectOptions[$lc] as $opt) {
                        $sel = ((string) $opt === (string) $val) ? ' selected' : '';
                        echo '<option value="' . htmlspecialchars($opt) . '"' . $sel . '>' . htmlspecialchars($opt) . '</option>';
                    }
                    echo '</select>';
                }
            } elseif ($lc === 'dort') {
                // Date of Retirement: Use database value if available, otherwise calculate from DOB
                $dortValue = '';

                // Get value with robust fallback chain
                if (!empty($val) && $val !== '0000-00-00') {
                    $dortValue = $val;
                } elseif (!empty($row['dort']) && $row['dort'] !== '0000-00-00') {
                    $dortValue = $row['dort'];
                } elseif (!empty($row['Dort']) && $row['Dort'] !== '0000-00-00') {
                    $dortValue = $row['Dort'];
                } elseif (!empty($row['DORT']) && $row['DORT'] !== '0000-00-00') {
                    $dortValue = $row['DORT'];
                }

                // If dort is still empty, calculate from DOB (retirement at age 61)
                // Logic: If DOB day is 1, retire last day of previous month when turning 62
                //        Otherwise, retire last day of birth month when turning 62
                if (empty($dortValue)) {
                    $dob = $row['dob'] ?? $row['DOB'] ?? $row['Dob'] ?? '';
                    if (!empty($dob) && $dob !== '0000-00-00') {
                        try {
                            $dobDate = new DateTime($dob);
                            $dobDay = (int) $dobDate->format('d');

                            if ($dobDay === 1) {
                                // If DOB day is 1: last day of previous month when turning 62
                                $dobDate->modify('+61 years');
                                $dobDate->modify('-1 month');
                                $dortValue = $dobDate->format('Y-m-t');
                            } else {
                                // If DOB day is not 1: last day of birth month when turning 62
                                $dobDate->modify('+61 years');
                                $dortValue = $dobDate->format('Y-m-t');
                            }
                        } catch (Exception $e) {
                            // If date parsing fails, leave empty
                            $dortValue = '';
                        }
                    }
                }

                echo '<!-- DORT: calculated from DOB (age 61 logic), value=' . htmlspecialchars($dortValue) . ' -->';
                echo '<input class="edit-input" type="date" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($dortValue) . '" required' . $serviceDisabled . ' />';
            } elseif ($lc === 'phccertdate' || $lc === 'phccertvalidity') {
                // Format PHC certificate dates as dd-mm-yyyy
                $formattedDate = '';
                if ($val && $val !== '0000-00-00') {
                    $date = DateTime::createFromFormat('Y-m-d', $val);
                    if ($date) {
                        $formattedDate = $date->format('d-m-Y');
                    }
                }
                echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($formattedDate) . '" placeholder="dd-mm-yyyy"' . $serviceDisabled . ' />';
            } elseif ($lc === 'dob' || strpos($lc, 'date') !== false) {
                $required = ($lc === 'dob') ? ' required' : '';
                echo '<input class="edit-input" type="date" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '"' . $required . $serviceDisabled . ' />';
            } elseif ($lc === 'mobile' || strpos($lc, 'phone') !== false) {
                $required = ($lc === 'mobile') ? ' required' : '';
                echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '"' . $required . $serviceDisabled . ' />';
            } elseif (strpos($lc, 'email') !== false) {
                echo '<input class="edit-input" type="email" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '" />';
            } elseif ($lc === 'accountno') {
                // Bank Account Number - required field
                echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '" required placeholder="Enter bank account number"' . $serviceDisabled . ' />';
            } elseif ($lc === 'ifsccode') {
                // IFSC Code - required field with auto-lookup
                echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" id="ifsc-input" value="' . htmlspecialchars($val) . '" required placeholder="Enter IFSC code" maxlength="11" style="text-transform:uppercase"' . $serviceDisabled . ' />';
                echo '<div id="ifsc-status" style="font-size:11px;margin-top:4px;color:#666;"></div>';
            } elseif ($lc === 'branch' || $lc === 'bank' || $lc === 'acstate') {
                // Bank details - readonly, populated from IFSC lookup, but required
                $placeholder = '';
                if ($lc === 'branch')
                    $placeholder = 'Branch will be auto-filled from IFSC';
                if ($lc === 'bank')
                    $placeholder = 'Bank name will be auto-filled from IFSC';
                if ($lc === 'acstate')
                    $placeholder = 'State will be auto-filled from IFSC';
                echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" id="bank-' . $lc . '" value="' . htmlspecialchars($val) . '" readonly required style="background-color:#f5f5f5" placeholder="' . $placeholder . '" />';
            } elseif ($lc === 'pay_scale') {
                // Pay scale - render select if options available, otherwise text input
                if (!empty($payOptions['pay_scale'])) {
                    echo '<select class="edit-input" name="' . htmlspecialchars($col) . '"' . $serviceDisabled . '>';
                    if ($val === '') {
                        echo '<option value="">-- Select Pay Scale --</option>';
                    }
                    foreach ($payOptions['pay_scale'] as $opt) {
                        $sel = ((string) $opt === (string) $val) ? ' selected' : '';
                        echo '<option value="' . htmlspecialchars($opt) . '"' . $sel . '>' . htmlspecialchars($opt) . '</option>';
                    }
                    echo '</select>';
                } else {
                    echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '" placeholder="Pay Scale (e.g., 7th CPC)"' . $serviceDisabled . ' />';
                }
            } elseif ($lc === 'basic_pay') {
                // Basic pay (numeric)
                if (!empty($payOptions['basic_pay'])) {
                    echo '<select class="edit-input" name="' . htmlspecialchars($col) . '"' . $serviceDisabled . '>';
                    if ($val === '') {
                        echo '<option value="">-- Select Basic Pay --</option>';
                    }
                    foreach ($payOptions['basic_pay'] as $opt) {
                        $sel = ((string) $opt === (string) $val) ? ' selected' : '';
                        echo '<option value="' . htmlspecialchars($opt) . '"' . $sel . '>' . htmlspecialchars($opt) . '</option>';
                    }
                    echo '</select>';
                } else {
                    $defaultVal = $val !== '' ? $val : '';
                    echo '<input class="edit-input" type="number" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($defaultVal) . '" step="0.01" placeholder="Basic Pay"' . $serviceDisabled . ' />';
                }
            } elseif ($lc === 'inc_month') {
                // Increment month - free text or MM-YYYY
                if (!empty($payOptions['inc_month'])) {
                    echo '<select class="edit-input" name="' . htmlspecialchars($col) . '"' . $serviceDisabled . '>';
                    if ($val === '') {
                        echo '<option value="">-- Select Increment Month --</option>';
                    }
                    foreach ($payOptions['inc_month'] as $opt) {
                        $sel = ((string) $opt === (string) $val) ? ' selected' : '';
                        echo '<option value="' . htmlspecialchars($opt) . '"' . $sel . '>' . htmlspecialchars($opt) . '</option>';
                    }
                    echo '</select>';
                } else {
                    echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '" placeholder="Increment Month (MM-YYYY)"' . $serviceDisabled . ' />';
                }
            } elseif ($lc === 'gpftype') {
                // GPF Type - dropdown with Govt and ZP options (required)
                echo '<select class="edit-input" name="' . htmlspecialchars($col) . '" required' . $serviceDisabled . '>';
                echo '<option value="">-- Select GPF Type --</option>';
                echo '<option value="Govt"' . ($val === 'Govt' ? ' selected' : '') . '>Govt</option>';
                echo '<option value="ZP"' . ($val === 'ZP' ? ' selected' : '') . '>ZP</option>';
                echo '</select>';
            } elseif ($lc === 'gpfacno') {
                // GPF Account No - required text field
                echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '" placeholder="GPF Account No." required' . $serviceDisabled . ' />';
            } elseif ($lc === 'tsgliacno') {
                // TSGLI Account No - required text field
                echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '" placeholder="TSGLI Account No." required' . $serviceDisabled . ' />';
            } elseif ($lc === 'sgtdschtno') {
                // SGT DSC Hall Ticket No - required field with default 0
                $defaultVal = $val ?: '0';
                echo '<input class="edit-input" type="number" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($defaultVal) . '" min="0"' . $serviceDisabled . ' />';
            } elseif ($lc === 'sgtdscmarks') {
                // SGT DSC Marks - required field with default 0
                $defaultVal = $val ?: '0';
                echo '<input class="edit-input" type="number" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($defaultVal) . '" min="0" step="0.01"' . $serviceDisabled . ' />';
            } elseif ($lc === 'sadscmarks') {
                // SA DSC Marks - required field with default 0
                $defaultVal = $val ?: '0';
                echo '<input class="edit-input" type="number" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($defaultVal) . '" min="0" step="0.01" />';
            } elseif ($lc === 'ghmgriiapp') {
                // GHM Gr.II App - default PROMOTION
                $defaultVal = $val ?: 'PROMOTION';
                echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($defaultVal) . '" readonly style="background-color:#f5f5f5" />';
            } elseif ($lc === 'ghmgriidesign') {
                // GHM Gr.II Design - default GHM-Gr.II
                $defaultVal = $val ?: 'GHM-Gr.II';
                echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($defaultVal) . '" readonly style="background-color:#f5f5f5" />';
            } elseif (strlen($val) > 180) {
                echo '<textarea class="edit-input" name="' . htmlspecialchars($col) . '"' . $serviceDisabled . '>' . htmlspecialchars($val) . '</textarea>';
            } elseif (in_array($lc, ['eotyn', 'gotyn', 'lttyn', 'httyn', 'othertestyn', 'tet1passed', 'tet2passed'])) {
                // Special Y/N dropdown fields with conditional visibility for dependent fields
                echo '<select class="edit-input test-dropdown" name="' . htmlspecialchars($col) . '" onchange="handleTestDropdownChange(this)"' . $serviceDisabled . '>';
                echo '<option value="">-- Select --</option>';
                echo '<option value="YES"' . (strtoupper($val) === 'YES' ? ' selected' : '') . '>YES</option>';
                echo '<option value="NO"' . (strtoupper($val) === 'NO' ? ' selected' : '') . '>NO</option>';
                echo '</select>';
            } else {
                $ro = ($col === 'TreasuryCode') ? ' readonly' : '';
                $requiredTextFields = ['treasurycode', 'tchsurname', 'tchfullname', 'fathername', 'subcaste', 'adhaar', 'reshno', 'resstreet', 'resmandal', 'resdist', 'nathno', 'natstreet', 'natmandal', 'natdist', 'epicno', 'partno', 'serialno', 'assmblyconstdist', 'assmblyconstname', 'rsconstname', 'nativeconstname', 'workconstname', 'sscyear', 'interyear', 'intercourse'];
                $required = in_array($lc, $requiredTextFields) ? ' required' : '';
                echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '"' . $ro . $required . $serviceDisabled . ' />';
            }
            echo '</div>';



            // Add grid break after SpOfficeName to force 4-3-3 layout pattern
            if (isset($gridBreakAfter) && $gridBreakAfter) {
                echo $gridBreakAfter;
            }
        }
        echo '</div>'; // grid for subsection

    } // End foreach ($subSections)

    // Add Group Save Button
    echo '<div style="margin-top:20px;padding-top:20px;border-top:2px solid #e5e7eb;text-align:right;background:#fafafa;padding:15px;border-radius:0 0 8px 8px;">';
    echo '<button type="button" class="section-save-btn" data-group-key="' . htmlspecialchars($grpKey) . '" data-save-label="' . htmlspecialchars($grpLabel) . '" style="padding:10px 24px;background:linear-gradient(to bottom, #4e73df 0%, #224abe 100%);color:#fff;border:1px solid #1f42aa;border-radius:6px;font-weight:600;font-size:14px;cursor:pointer;transition:all 0.2s ease;box-shadow:0 2px 4px rgba(0,0,0,0.1);">';
    echo '<i class="fas fa-save" style="margin-right:8px;"></i> Save ' . htmlspecialchars($grpLabel);
    echo '</button>';
    // Status message container next to button
    echo '<span class="section-save-msg" style="margin-left:15px;font-size:13px;font-weight:600;"></span>';
    echo '</div>';

    echo '</div>'; // End Group Accordion Content
    echo '</div>'; // End Group Accordion Item

} // End foreach ($groupOrder)
// close accordion-container
echo '</div>'; // accordion-container

// Important message and Save All button - placed inside form after all sections
echo '<div id="save-all-warning" style="margin:30px 0;padding:20px;background:#fff3cd;border-left:4px solid #ffc107;border-radius:4px;">';
echo '<div style="margin-bottom:12px;font-size:14px;font-weight:600;color:#856404;">';
echo '<span style="font-size:16px;">âš ï¸</span> <strong>Important:</strong> Please save each section (A to F) individually before submitting the final form.';
echo '</div>';
echo '<div style="font-size:13px;color:#856404;margin-bottom:0;">';
echo 'Progress: <span id="sections-saved-count">0</span> of <span id="sections-total-count">0</span> sections saved';
echo '</div>';
echo '</div>';

// Save All button - initially disabled
echo '<div style="margin:20px 0;text-align:center;">';
echo '<button type="submit" name="save_all" id="save-all-btn" disabled style="padding:12px 32px;background:#6c757d;color:#fff;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:not-allowed;opacity:0.6;box-shadow:none;">Save All Changes</button> ';
echo '<a href="teacher_particulars.php?treasury_code=' . rawurlencode($treasury) . '" style="margin-left:12px;padding:12px 24px;background:#fff;border:1px solid #6c757d;border-radius:8px;text-decoration:none;color:#6c757d;font-weight:600;">Cancel</a>';
echo '</div>';

// end tp-wrapper and left column
echo '</div>'; // tp-wrapper
echo '</div>'; // left column

// right column removed; top tabs are rendered above sections

echo '</div>'; // overall flex

echo '</form>';

// Add JS for cascading school selects and loading dependent fields
?>




















<script>
    window.__SERVER_MSG = { text: $jsMsg, isError: $jsIsError };
    window.__ALL_GROUPS = <?php echo json_encode($groupOrder); ?>;
</script>
<script>
    // Disable Service tab fields for MEO users
    window.__USER_ROLE = '<?php echo $user_role; ?>';
    window.__CAN_EDIT_SERVICE = <?php echo $can_edit_service ? 'true' : 'false'; ?>;
    window.__CAN_EDIT_SERVICE_SCHOOL = <?php echo $can_edit_service_school ? 'true' : 'false'; ?>;

    // Define which sections are editable based on permissions
    // The service tab group in teacher_edit is keyed by `service_particulars`
    window.__SERVICE_GROUPS = ['service_particulars'];
    window.__EDITABLE_SECTIONS = [];

    // Get all section letters from accordion items
    document.addEventListener('DOMContentLoaded', function () {
        var nonEditableGroups = [];

        document.querySelectorAll('.accordion-item').forEach(function (item) {
            var groupKey = item.getAttribute('data-group');
            var isServiceSection = window.__SERVICE_GROUPS.includes(groupKey);

            // If not a service section, or user can edit service sections, or MEO can edit school details, mark as editable
            if (!isServiceSection || window.__CAN_EDIT_SERVICE || window.__CAN_EDIT_SERVICE_SCHOOL) {
                window.__EDITABLE_SECTIONS.push(groupKey);
            } else {
                // Mark non-editable sections - hide save button and mark as automatically saved
                item.setAttribute('data-non-editable', 'true');
                var saveBtn = item.querySelector('.section-save-btn');
                if (saveBtn) {
                    saveBtn.style.display = 'none';
                }

                // Track non-editable group to automatically mark as saved
                if (groupKey && !nonEditableGroups.includes(groupKey)) {
                    nonEditableGroups.push(groupKey);
                }

                // Add note that section is not editable
                var content = item.querySelector('.accordion-content');
                if (content) {
                    var note = document.createElement('div');
                    note.style.cssText = 'margin-bottom:16px;padding:12px;background:#f3f4f6;border-left:3px solid #9ca3af;border-radius:4px;font-size:13px;color:#4b5563;';
                    note.innerHTML = '<strong>â„¹ï¸ Not Editable:</strong> You do not have permission to edit this section.';
                    content.insertBefore(note, content.firstChild);
                }
            }
        });

        // Automatically mark non-editable groups as saved for MEO users
        if (nonEditableGroups.length > 0 && typeof window.getSavedGroups === 'function' && typeof window.setSavedGroups === 'function') {
            var savedGroups = window.getSavedGroups();
            var updated = false;
            nonEditableGroups.forEach(function (grp) {
                if (!savedGroups.includes(grp)) {
                    savedGroups.push(grp);
                    updated = true;
                }
            });
            if (updated) {
                window.setSavedGroups(savedGroups);
                // Update progress indicator after marking groups as saved
                if (typeof window.updateProgressIndicator === 'function') {
                    window.updateProgressIndicator();
                }
            }
        }

        // Progress indicator will be updated by teacher_edit_main.js after it loads
    });

    // Function to lock all service fields
    function lockServiceFields() {
        if (!window.__CAN_EDIT_SERVICE) {
            var skipSchoolFields = window.__CAN_EDIT_SERVICE_SCHOOL ? ['division', 'SchMandal', 'SchName', 'SchCode'] : [];
            // Disable service section fields except the school cascade fields when MEO can edit them
            var serviceSections = document.querySelectorAll('.tp-section[data-group="service_particulars"], .tp-section[data-tab="Service"]');
            serviceSections.forEach(function (section) {
                // Find ALL inputs, selects, textareas in this section (even hidden ones)
                var inputs = section.querySelectorAll('input, select, textarea');
                inputs.forEach(function (input) {
                    if (skipSchoolFields.includes(input.name)) {
                        return;
                    }
                    input.disabled = true;
                    input.readOnly = true;
                    input.style.backgroundColor = '#f0f0f0';
                    input.style.cursor = 'not-allowed';
                    input.style.pointerEvents = 'none';
                    // Remove required attribute for MEO users
                    input.removeAttribute('required');
                    // Prevent any value changes
                    input.setAttribute('data-locked', 'true');
                });

                // Also lock file inputs
                var fileInputs = section.querySelectorAll('input[type="file"]');
                fileInputs.forEach(function (input) {
                    if (skipSchoolFields.includes(input.name)) {
                        return;
                    }
                    input.disabled = true;
                    input.style.pointerEvents = 'none';
                });

                // Lock buttons in service sections only when no school-specific edits are allowed
                if (!window.__CAN_EDIT_SERVICE_SCHOOL) {
                    var buttons = section.querySelectorAll('button');
                    buttons.forEach(function (btn) {
                        btn.disabled = true;
                        btn.style.pointerEvents = 'none';
                    });
                }

                // Add visual indicator only when service section is fully locked
                if (!window.__CAN_EDIT_SERVICE_SCHOOL) {
                    var header = section.querySelector('.tp-section-header, .accordion-header');
                    if (header && !header.querySelector('.locked-indicator')) {
                        header.innerHTML += ' <span class="locked-indicator" style="color:#f44336;font-size:12px;font-weight:bold;margin-left:10px;">LOCKED (MEO cannot edit)</span>';
                    }
                }
            });

            // Find Service tab and add indicator only when fully locked
            if (!window.__CAN_EDIT_SERVICE_SCHOOL) {
                var tabs = document.querySelectorAll('.tp-tab');
                tabs.forEach(function (tab) {
                    var tabLabel = tab.getAttribute('data-tab-label');
                    if (tabLabel && tabLabel.toLowerCase() === 'service') {
                        tab.style.opacity = '0.6';
                        tab.title = 'Service tab fields are locked for MEO users';
                    }
                });
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Lock fields on page load
        lockServiceFields();

        // Re-lock after a delay to catch dynamically shown fields
        setTimeout(lockServiceFields, 100);
        setTimeout(lockServiceFields, 500);
        setTimeout(lockServiceFields, 1000);

        // Set up MutationObserver to lock fields when they become visible
        if (!window.__CAN_EDIT_SERVICE) {
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    if (mutation.type === 'attributes' || mutation.type === 'childList') {
                        // Check if any service section fields were modified
                        var serviceSections = document.querySelectorAll('.tp-section[data-group="service_particulars"], .tp-section[data-tab="Service"]');
                        serviceSections.forEach(function (section) {
                            var inputs = section.querySelectorAll('input:not([data-locked="true"]), select:not([data-locked="true"]), textarea:not([data-locked="true"])');
                            if (inputs.length > 0) {
                                lockServiceFields();
                            }
                        });
                    }
                });
            });

            // Start observing the entire form
            var form = document.querySelector('form');
            if (form) {
                observer.observe(form, {
                    attributes: true,
                    childList: true,
                    subtree: true,
                    attributeFilter: ['style', 'class', 'disabled', 'readonly']
                });
            }

            console.log('Service tab fields disabled for ' + window.__USER_ROLE + ' user');
        }
    });

    // Accordion functionality
    document.querySelectorAll('.accordion-header').forEach(function (header) {
        header.addEventListener('click', function (e) {
            // Don't toggle if clicking on dropdown inside header
            if (e.target.tagName === 'SELECT' || e.target.tagName === 'OPTION') {
                return;
            }

            var accordionItem = this.closest('.accordion-item');
            var content = accordionItem.querySelector('.accordion-content');
            var icon = this.querySelector('.accordion-icon');
            var isExpanded = this.classList.contains('accordion-expanded');

            // Close all other accordions
            document.querySelectorAll('.accordion-header').forEach(function (h) {
                if (h !== header) {
                    h.classList.remove('accordion-expanded');
                    var otherContent = h.closest('.accordion-item').querySelector('.accordion-content');
                    var otherIcon = h.querySelector('.accordion-icon');
                    otherContent.style.display = 'none';
                    otherIcon.style.transform = 'rotate(0deg)';
                }
            });

            // Toggle current accordion
            if (isExpanded) {
                this.classList.remove('accordion-expanded');
                content.style.display = 'none';
                icon.style.transform = 'rotate(0deg)';
            } else {
                this.classList.add('accordion-expanded');
                content.style.display = 'block';
                icon.style.transform = 'rotate(180deg)';

                // Scroll to accordion after opening
                setTimeout(function () {
                    accordionItem.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 300);
            }
        });
    });



    // Photo upload functionality for edit form
    const currentTreasuryCodeEdit = '<?php echo htmlspecialchars($treasury ?? ''); ?>';

    function openPhotoUploadDialogEdit() {
        // Create modal if it doesn't exist
        let modal = document.getElementById('photoUploadModalEdit');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'photoUploadModalEdit';
            modal.className = 'photo-modal';
            modal.innerHTML = `
            <div class="photo-modal-content">
                <span class="photo-modal-close" onclick="closePhotoUploadDialogEdit()">&times;</span>
                <div class="photo-modal-header">Upload Employee Photo</div>
                <div>
                    <p style="color:#666; font-size:14px; margin-bottom:15px;">
                        Select a JPG or PNG image. The photo will be automatically compressed to approximately 100KB.
                    </p>
                    <input type="file" id="photoFileInputEdit" accept="image/jpeg,image/png,image/jpg" style="margin-bottom:15px;" />
                    <div class="photo-preview" id="photoPreviewEdit" style="display:none;">
                        <img id="photoPreviewImgEdit" src="" alt="Preview" />
                        <p style="margin-top:10px; font-size:12px; color:#666;">
                            <span id="photoSizeInfoEdit"></span>
                        </p>
                    </div>
                    <div style="text-align:center; margin-top:20px;">
                        <button class="upload-btn" id="uploadPhotoBtnEdit" onclick="uploadPhotoEdit()" disabled>Upload Photo</button>
                    </div>
                    <div id="uploadMessageEdit" style="margin-top:15px; padding:10px; border-radius:4px; display:none;"></div>
                </div>
            </div>
        `;
            document.body.appendChild(modal);

            // Add event listener for file input
            document.getElementById('photoFileInputEdit').addEventListener('change', function (e) {
                const file = e.target.files[0];
                if (!file) {
                    document.getElementById('photoPreviewEdit').style.display = 'none';
                    document.getElementById('uploadPhotoBtnEdit').disabled = true;
                    return;
                }

                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Please select a JPG or PNG image file.');
                    e.target.value = '';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function (event) {
                    document.getElementById('photoPreviewImgEdit').src = event.target.result;
                    document.getElementById('photoPreviewEdit').style.display = 'block';
                    document.getElementById('photoSizeInfoEdit').textContent = 'Original size: ' + (file.size / 1024).toFixed(2) + ' KB';
                    document.getElementById('uploadPhotoBtnEdit').disabled = false;
                };
                reader.readAsDataURL(file);
            });
        }

        modal.style.display = 'block';
        document.getElementById('photoFileInputEdit').value = '';
        document.getElementById('photoPreviewEdit').style.display = 'none';
        document.getElementById('uploadPhotoBtnEdit').disabled = true;
        document.getElementById('uploadMessageEdit').style.display = 'none';
    }

    function closePhotoUploadDialogEdit() {
        document.getElementById('photoUploadModalEdit').style.display = 'none';
    }

    function uploadPhotoEdit() {
        const fileInput = document.getElementById('photoFileInputEdit');
        const file = fileInput.files[0];

        if (!file) {
            alert('Please select a photo to upload.');
            return;
        }

        if (!currentTreasuryCodeEdit) {
            alert('Treasury code not found.');
            return;
        }

        const uploadBtn = document.getElementById('uploadPhotoBtnEdit');
        const messageDiv = document.getElementById('uploadMessageEdit');

        uploadBtn.disabled = true;
        uploadBtn.textContent = 'Uploading...';
        messageDiv.style.display = 'none';

        const formData = new FormData();
        formData.append('photo', file);
        formData.append('treasury_code', currentTreasuryCodeEdit);

        fetch('ajax_upload_photo.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                uploadBtn.disabled = false;
                uploadBtn.textContent = 'Upload Photo';

                if (data.success) {
                    messageDiv.style.display = 'block';
                    messageDiv.style.background = '#d4edda';
                    messageDiv.style.color = '#155724';
                    messageDiv.style.border = '1px solid #c3e6cb';
                    messageDiv.textContent = data.message;

                    // Update photo display
                    const photoImg = document.getElementById('teacherPhotoEdit');
                    if (photoImg.tagName === 'IMG') {
                        photoImg.src = data.photo_url + '?t=' + new Date().getTime();
                    } else {
                        // Replace placeholder div with img
                        const newImg = document.createElement('img');
                        newImg.id = 'teacherPhotoEdit';
                        newImg.src = data.photo_url + '?t=' + new Date().getTime();
                        newImg.alt = 'Teacher Photo';
                        newImg.style.cssText = 'width:120px; height:120px; object-fit:cover; border:2px solid #3b5998; border-radius:8px;';
                        photoImg.parentNode.replaceChild(newImg, photoImg);
                    }

                    // Update hidden input
                    document.getElementById('photoHiddenInput').value = data.photo_url;

                    // Close modal after 2 seconds
                    setTimeout(() => {
                        closePhotoUploadDialogEdit();
                    }, 2000);
                } else {
                    messageDiv.style.display = 'block';
                    messageDiv.style.background = '#f8d7da';
                    messageDiv.style.color = '#721c24';
                    messageDiv.style.border = '1px solid #f5c6cb';
                    messageDiv.textContent = data.message;
                }
            })
            .catch(error => {
                uploadBtn.disabled = false;
                uploadBtn.textContent = 'Upload Photo';
                messageDiv.style.display = 'block';
                messageDiv.style.background = '#f8d7da';
                messageDiv.style.color = '#721c24';
                messageDiv.style.border = '1px solid #f5c6cb';
                messageDiv.textContent = 'Upload failed: ' + error.message;
            });
    }
</script>
<script src="assets/js/teacher_edit_main.js"></script>
<?php
include 'includes/footer.php';
exit;
?>
