<?php
// teacher_view.php
// Fetches a teacher row by Employee ID (TreasuryCode) and renders a sectioned view
// Expects: GET parameter 'id' or 'employee_id' or 'TreasuryCode'

ini_set('display_errors', 1);
error_reporting(E_ALL);
// ensure session is started for verification and print-token checks
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// --- Helpers & configuration ---
// Reuse project's DB connection if available
if (is_readable(__DIR__ . '/includes/db_connect.php')) {
  require_once __DIR__ . '/includes/db_connect.php';
  // db_connect.php exposes $conn (mysqli)
  if (isset($conn) && $conn instanceof mysqli) {
    $mysqli = $conn;
  } else {
    // fallback: try local defaults (adjust if necessary)
    $mysqli = new mysqli('127.0.0.1', 'root', '', 'deo_hanumakonda_db');
  }
} else {
  // fallback: create a local mysqli (update credentials if needed)
  $mysqli = new mysqli('127.0.0.1', 'root', '', 'deo_hanumakonda_db');
}

$mysqli->set_charset('utf8mb4');

// Try CSVs first, then JSON mapping as a fallback
$csvFilesToTry = [__DIR__ . '/teacher_structure.csv', __DIR__ . '/Book1.csv'];

function load_csv_structure($path)
{
  if (!is_readable($path)) return [];
  $fh = fopen($path, 'r');
  if (!$fh) return [];
  $header = fgetcsv($fh);
  if (!$header) { fclose($fh); return []; }
  $rows = [];
  while (($r = fgetcsv($fh)) !== false) {
    // Guard: if columns mismatch, skip
    if (count($r) !== count($header)) continue;
    $rows[] = array_combine($header, $r);
  }
  fclose($fh);
  return $rows;
}

$mapping = [];
foreach ($csvFilesToTry as $try) {
  if (is_readable($try) && strtolower(pathinfo($try, PATHINFO_EXTENSION)) === 'csv') {
    $mapping = load_csv_structure($try);
    if (!empty($mapping)) break;
  }
}

// If CSV mapping still empty, try JSON label map
if (empty($mapping) && is_readable(__DIR__ . '/config/label_map.json')) {
  $json = file_get_contents(__DIR__ . '/config/label_map.json');
  $jm = json_decode($json, true);
  if (is_array($jm)) {
    // Expecting an array of mappings. Normalize into same CSV-like row structure
    $mapping = [];
    $order = 1;
    foreach ($jm as $k => $v) {
      // v may be label or structured object; try sensible defaults
      if (is_array($v)) {
        $mapping[] = [
          'column_key_order' => $v['column_key_order'] ?? $order++,
          'column_key_name' => $v['column_key_name'] ?? $k,
          'column_key_label' => $v['column_key_label'] ?? ($v['label'] ?? $k),
          'section_name_for_column_key' => $v['section_name_for_column_key'] ?? ($v['section'] ?? 'Miscellaneous'),
        ];
      } else {
        $mapping[] = [
          'column_key_order' => $order++,
          'column_key_name' => $k,
          'column_key_label' => $v,
          'section_name_for_column_key' => 'Miscellaneous'
        ];
      }
    }
  }
}

if (empty($mapping)) {
  echo "<p style='color:red'>No mapping found. Please provide teacher_structure.csv, Book1.csv, or config/label_map.json.</p>";
  exit;
}

// Normalize mapping into sections => ordered list of fields
$sections = [];
foreach ($mapping as $row) {
  $col = trim($row['column_key_name'] ?? '');
  if ($col === '') continue;
  $label = trim($row['column_key_label'] ?? $row['column_key_name'] ?? $col);
  $section = trim($row['section_name_for_column_key'] ?? $row['section'] ?? 'Miscellaneous');
  $order = isset($row['column_key_order']) ? (int)$row['column_key_order'] : 0;
  if (!isset($sections[$section])) $sections[$section] = [];
  $sections[$section][] = ['name' => $col, 'label' => $label, 'order' => $order];
}

// Sort fields within each section by order
foreach ($sections as $sec => &$fields) {
  usort($fields, function ($a, $b) { return $a['order'] <=> $b['order']; });
}
unset($fields);

// Determine requested employee id (accept GET or POST)
$employeeId = $_REQUEST['id'] ?? $_REQUEST['employee_id'] ?? $_REQUEST['TreasuryCode'] ?? null;
// Demo mode disabled for security; require verification instead
if (!$employeeId) {
  echo "<p>Please provide an employee id in the URL or POST as ?id=xxxxx or ?employee_id=xxxxx or ?TreasuryCode=xxxxx</p>";
  exit;
}

// Allow caller to specify which column to match via ?by=ColumnName (safe-guarded)
$requestedBy = $_REQUEST['by'] ?? 'TreasuryCode';
// Build a whitelist of available columns from the CSV mapping
$availableCols = [];
foreach ($mapping as $m) {
  $cname = trim($m['column_key_name'] ?? '');
  if ($cname !== '') $availableCols[$cname] = true;
}
// Ensure the requested column exists in the CSV mapping; otherwise default to TreasuryCode
if (!isset($availableCols[$requestedBy])) {
  $requestedBy = 'TreasuryCode';
}

// Ensure $mysqli is available (was attempted to be set earlier from includes/db_connect.php)
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  // fallback: create a local mysqli (update credentials if needed)
  $mysqli = new mysqli('127.0.0.1', 'root', '', 'deo_hanumakonda_db');
}
if ($mysqli->connect_errno) {
  echo "<p>DB connection failed: " . htmlspecialchars($mysqli->connect_error) . "</p>";
  exit;
}
$mysqli->set_charset('utf8mb4');

// Prepare and execute query to fetch the teacher row using the validated column name
$colNameSafe = $requestedBy; // already validated against mapping
$sql = "SELECT * FROM teacherdata WHERE `$colNameSafe` = ? LIMIT 1";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
  echo "<p>Prepare failed: " . htmlspecialchars($mysqli->error) . "</p>";
  exit;
}
// Require verification similar to teacher_particulars.php
$sessionKey = 'verified_teacher_' . $employeeId;
$isVerified = isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true;
$verifyError = '';
$providedMobile = $_REQUEST['mobile'] ?? null;
$providedDob = $_REQUEST['dob'] ?? null;
// Print-token bypass removed. Verification requires mobile + DOB.
if (!$isVerified && $providedMobile && $providedDob) {
    $vstmt = $mysqli->prepare("SELECT TreasuryCode FROM teacherdata WHERE TreasuryCode = ? AND mobile = ? AND dob = ? LIMIT 1");
    if ($vstmt) {
        $vstmt->bind_param('sss', $employeeId, $providedMobile, $providedDob);
        $vstmt->execute();
        $vres = $vstmt->get_result();
        if ($vres && $vres->num_rows > 0) {
            $_SESSION[$sessionKey] = true;
            $isVerified = true;
        } else {
            $verifyError = 'Verification failed. Please check Treasury Code, Mobile and Date of Birth.';
        }
        $vstmt->close();
    } else {
        $verifyError = 'Verification query could not be prepared.';
    }
}

if (!$isVerified) {
    // Show verification form and stop
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Verify</title></head><body>';
    if ($verifyError) echo '<div style="color:#a00;padding:8px;">' . htmlspecialchars($verifyError) . '</div>';
    echo '<form method="post" action="' . htmlspecialchars($_SERVER['REQUEST_URI']) . '">';
    echo '<div style="margin-bottom:8px;"><label>Treasury Code</label><br/><input name="id" value="' . htmlspecialchars($employeeId) . '" readonly style="padding:6px;width:260px;"/></div>';
    echo '<div style="margin-bottom:8px;"><label>Mobile (full)</label><br/><input name="mobile" type="text" required style="padding:6px;width:200px;"/></div>';
    echo '<div style="margin-bottom:8px;"><label>Date of Birth</label><br/><input name="dob" type="date" required style="padding:6px;width:180px;"/></div>';
    echo '<div><button type="submit" style="padding:6px 10px;background:#28a745;color:#fff;border:none;border-radius:4px;">Verify & View</button></div>';
    echo '</form></body></html>';
    exit;
}

// After verification, fetch the teacher record
$stmt->bind_param('s', $employeeId);
$stmt->execute();
$result = $stmt->get_result();
$teacher = $result->fetch_assoc();
if (!$teacher) {
    echo "<p>No teacher found for id: " . htmlspecialchars($employeeId) . "</p>";
    exit;
}

// Utility to safely get value
function safe_val($arr, $key)
{
    if (!isset($arr[$key]) || $arr[$key] === null || $arr[$key] === '') return '&ndash;';
    return htmlspecialchars($arr[$key]);
}

// Mask mobile for non-admins: show first 2 and last 2 digits with stars in middle
function mask_mobile($m)
{
  $m = trim((string)$m);
  if ($m === '') return '&ndash;';
  // Admins see full number
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (!empty($_SESSION['admin_loggedin'])) return htmlspecialchars($m);
  $len = strlen($m);
  if ($len <= 4) return htmlspecialchars(str_repeat('*', $len));
  $start = substr($m, 0, 2);
  $end = substr($m, -2);
  $mid = str_repeat('*', max(0, $len - 4));
  return htmlspecialchars($start . $mid . $end);
}

// --- Render HTML ---
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Teacher Particulars - <?php echo htmlspecialchars($teacher['TchFullName'] ?? $employeeId); ?></title>
  <style>
    :root{--section-bg:#f5f7fb;--label-color:#333;--value-color:#111}
    body{font-family:Arial,Helvetica,sans-serif;margin:20px;color:var(--value-color)}
  .header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:6px}
  .logo{width:200px;height:auto;max-height:160px;object-fit:contain}
  /* print helpers */
  .no-print{display:block}
  .print-logo{display:none}
    h1{font-size:20px;margin:0}
  .section{border-radius:6px;padding:4px;margin-bottom:4px;background:#fff;box-shadow:0 0 0 1px rgba(0,0,0,0.03)}
  .section-head{background:var(--section-bg);padding:3px 5px;margin:-4px -4px 4px;border-radius:6px 6px 0 0;font-weight:700;display:flex;align-items:center;gap:8px}
  .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:3px}
  .field{padding:2px;border-radius:2px}
    .label{display:block;color:var(--label-color);font-weight:600;margin-bottom:4px}
    .value{display:block}
    /* Empty cell placeholder for layout balance */
    .empty{visibility:hidden}
    /* Responsive */
  @media (max-width:900px){.grid{grid-template-columns:repeat(2,1fr);gap:3px}}
  @media (max-width:600px){.grid{grid-template-columns:1fr;gap:3px}}
    /* Print / Export buttons */
    .controls{margin-bottom:8px;display:flex;gap:8px;justify-content:flex-end}
    .action-btn{padding:10px 14px;background:#007bff;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:700;box-shadow:0 2px 4px rgba(0,0,0,0.08)}
    .action-btn.secondary{background:#28a745}
    .action-btn:active{transform:translateY(1px)}
  .cert-section{border:1px solid #cfcfcf;padding:10px;margin-top:12px}
  .cert-head{background:#0b74a6;color:#fff;padding:6px;font-weight:700;margin:-10px -10px 10px -10px}
  .sign-row{display:flex;justify-content:space-between;margin-top:28px;font-size:13px}
  .sign-row div{width:30%;text-align:left}
  @media print{
    /* hide interactive UI but keep the header visible in print */
    .print-btn,.controls,.no-print{display:none !important}
  /* ensure header is shown and logo positioned top-right on print (not fixed/repeated) */
  .header{display:flex !important; visibility:visible !important}
  .header img.logo{display:block !important}
  .print-logo{display:none !important}
  /* position logo inside header and align right; avoid fixed so it does not repeat on every printed page */
  .header img.logo{position:static; margin-left:auto; width:180px; height:auto}
    /* tighter print margins */
    body{margin-top:32mm;margin-bottom:12mm}
    .section{margin-bottom:6px;padding:6px}
    .section-head{padding:6px}
    .cert-section{margin-top:10px;padding:8px}
    /* do not show repeated header/footer on every printed page */
    .print-page-header, .print-page-footer { display:none !important; }
  }
  </style>
</head>
<body>
  <?php // print header/footer text removed as requested ?>
  <div class="header">
    <div style="flex:1">
  <h1 style="margin:0;font-size:18px;">Teacher Particulars</h1>
  <div style="margin-top:4px;font-weight:700;font-size:13px;">Particulars of <?php echo htmlspecialchars($teacher['TreasuryCode'] ?? $employeeId); ?> - <?php echo htmlspecialchars(trim(($teacher['TchSurName'] ?? '') . ' ' . ($teacher['TchFullName'] ?? ''))); ?></div>
    </div>
    <div style="flex:0 0 auto;text-align:right">
      <img src="assets/images/logo.png" alt="logo" class="logo">
    </div>
  </div>

  <!-- top controls (screen only) -->
  <div class="controls no-print">
    <button class="action-btn" id="printTopBtn">Print</button>
  </div>

  <!-- print-only logo (appears only on printouts) -->
  <div class="print-logo"><img src="assets/images/logo.png" alt="logo" style="max-height:110px; transform:scale(.5);" /></div>

  <!-- Fixed print header/footer (visible only in print) -->
  <div class="print-page-header" style="display:none;">&nbsp;</div>
  <div class="print-page-footer" style="display:none;">&nbsp;</div>

  <?php
  // Render each section
  foreach ($sections as $sectionName => $fields) :
      // count fields and break into rows of 3
      $total = count($fields);
      if ($total === 0) continue;
  ?>
    <div class="section">
      <div class="section-head"><?php echo htmlspecialchars($sectionName); ?></div>
      <div class="grid">
        <?php
        $i = 0;
        foreach ($fields as $f) {
            $i++;
      $col = $f['name'];
      $lbl = $f['label'];
      $valueHtml = safe_val($teacher, $col);
      if (strcasecmp($col, 'mobile') === 0) {
        $valueHtml = mask_mobile($teacher[$col] ?? '');
      }
      echo '<div class="field">';
      echo '<span class="label">' . htmlspecialchars($lbl) . ':</span>';
      echo '<span class="value">' . $valueHtml . '</span>';
      echo '</div>' . "\n";
        }
        // If not multiple of 3, pad with empty divs
        $rem = $total % 3;
        if ($rem !== 0) {
            $pad = 3 - $rem;
            for ($k = 0; $k < $pad; $k++) {
                echo '<div class="field empty">&nbsp;</div>';
            }
        }
        ?>
      </div>
    </div>
  <?php endforeach; ?>
  <!-- Certification / Declaration block -->
  <div class="cert-section">
    <div class="cert-head">DECLARATION</div>
    <div>
      <p>I declare that the above particulars submitted by me are true and correct and if any false information found, I will be personally held responsible as per CCA Rules</p>
      <div style="text-align:right;margin-top:20px;">Signature of the Teacher</div>
    </div>
  </div>

  <div class="cert-section">
    <div class="cert-head">CERTIFICATE</div>
    <div>
      <p>I certify that the above particulars submitted by the candidate are verified with the Original Certificates and the service register of the individual and found correct.</p>
      <div class="sign-row">
        <div>Signature of the DDO</div>
        <div>Signature of the Mandal Educational Officer</div>
      </div>
    </div>
  </div>

  <div class="cert-section">
    <div class="cert-head">DECLARATION BY CLUSTER RESOURCE PERSON / COMPUTER OPERATOR / MIS COORDINATOR</div>
    <div>
      <p>Certified that all the details submitted by the teacher in the signed hard copy are verified. The corrections provided by the teacher and the authorities in the hard copy are uploaded to website server.</p>
      <div class="sign-row">
        <div>Signature of the CRP</div>
        <div>Signature of the Computer operator</div>
        <div>Signature of the MIS Coordinator</div>
      </div>
    </div>
  </div>

  <!-- bottom controls (screen only) -->
  <div class="controls no-print" style="margin-top:12px;">
    <button class="action-btn" id="printBottomBtn">Print</button>
  </div>

</body>
</html>

<script>
// Wire print buttons
document.getElementById('printTopBtn').addEventListener('click', function(){ window.print(); });
document.getElementById('printBottomBtn').addEventListener('click', function(){ window.print(); });
</script>

<?php // auto-print behavior removed ?>
<?php // removed print header/footer injection per user request ?>
